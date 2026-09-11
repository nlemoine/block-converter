<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Support;

use n5s\BlockConverter\Block;

class EmbedBlockFactory
{
    /** Aspect ratios from largest to smallest, matching Gutenberg's ASPECT_RATIOS constant. */
    private const array ASPECT_RATIOS = [
        ['ratio' => 2.33, 'className' => 'wp-embed-aspect-21-9'],
        ['ratio' => 2.00, 'className' => 'wp-embed-aspect-18-9'],
        ['ratio' => 1.78, 'className' => 'wp-embed-aspect-16-9'],
        ['ratio' => 1.33, 'className' => 'wp-embed-aspect-4-3'],
        ['ratio' => 1.00, 'className' => 'wp-embed-aspect-1-1'],
        ['ratio' => 0.56, 'className' => 'wp-embed-aspect-9-16'],
        ['ratio' => 0.50, 'className' => 'wp-embed-aspect-1-2'],
    ];

    /**
     * oEmbed responses keyed by URL, plus the URLs no provider claims.
     *
     * Every hit costs an HTTP request, and legacy content repeats the same
     * video across posts. A URL without a provider is remembered too, as
     * null: matching it again is cheap without discovery, but with discovery
     * on it would be fetched again to look for a <link> tag.
     *
     * A URL whose provider answered nothing is not remembered. WP_oEmbed
     * reports a timeout, a 503 and a deleted video alike as false, so the
     * failure cannot be told from a permanent one, and memoising it would
     * let one flaky minute degrade every later occurrence of that provider
     * for the rest of the run. The cost is one request per occurrence of a
     * dead URL, which is what WordPress itself pays until its own cache
     * entry is written.
     *
     * @var array<string, object|null>
     */
    private array $dataCache = [];

    /**
     * @param bool $discover Resolve unknown URLs by fetching them and reading
     *                       their <link rel="alternate"> tags. Off by default:
     *                       it turns every unrecognised URL in the corpus into
     *                       an outbound request to an address the content — not
     *                       the caller — chose. This is the only discovery
     *                       setting; everything that embeds goes through here.
     */
    public function __construct(
        private readonly bool $discover = false,
    ) {
    }

    /**
     * Build a core/embed block from a URL via oEmbed lookup.
     *
     * The block is built even when nothing resolves: the editor re-resolves
     * the URL when the post is opened, so a URL the caller chose to embed is
     * still worth an embed block.
     */
    public function fromUrl(string $url): Block
    {
        return $this->fromData($url, $this->lookup($url));
    }

    /**
     * Build a core/embed block from a URL, only if a provider answered.
     *
     * For callers that must leave the URL alone otherwise, as WordPress'
     * own autoembed does.
     */
    public function resolve(string $url): ?Block
    {
        $data = $this->lookup($url);

        return $data === null ? null : $this->fromData($url, $data);
    }

    /**
     * How many URLs the cache is holding.
     */
    public function cachedUrlCount(): int
    {
        return \count($this->dataCache);
    }

    public function forget(): void
    {
        $this->dataCache = [];
    }

    private function lookup(string $url): ?object
    {
        if (\array_key_exists($url, $this->dataCache)) {
            return $this->dataCache[$url];
        }

        $oembed = \_wp_oembed_get_object();

        // get_provider() defaults discover to true when the key is absent, so
        // passing it explicitly is the only way to keep it off.
        $provider = $oembed->get_provider($url, ['discover' => $this->discover]);

        if ($provider === false) {
            return $this->dataCache[$url] = null;
        }

        $data = $oembed->fetch($provider, $url);

        if ($data === false) {
            return null;
        }

        return $this->dataCache[$url] = $data;
    }

    /**
     * The response types oEmbed defines, and the only ones core/embed knows.
     *
     * The response is remote data: with discovery on, the content decides
     * which host is fetched and which endpoint that host names, so every
     * field in it is attacker-chosen. The provider name goes through
     * sanitize_title() and the dimensions are cast; the type used to go
     * straight into the figure's class attribute, which nothing downstream
     * sanitizes when the block comes from a late pre-processor.
     */
    private const array TYPES = ['photo', 'video', 'link', 'rich'];

    /**
     * Build a core/embed block from pre-fetched oEmbed response data.
     */
    public function fromData(string $url, ?object $data = null): Block
    {
        $type = isset($data->type) && \is_string($data->type) && \in_array($data->type, self::TYPES, true) ? $data->type : '';
        $providerSlug = isset($data->provider_name) && \is_string($data->provider_name) && $data->provider_name !== '' ? \sanitize_title($data->provider_name) : '';
        $width = isset($data->width) && \is_numeric($data->width) ? (int) $data->width : 0;
        $height = isset($data->height) && \is_numeric($data->height) ? (int) $data->height : 0;

        $blockAttributes = [
            'url' => $url,
            'type' => $type,
            'providerNameSlug' => $providerSlug,
            'responsive' => true,
        ];

        $figureClasses = ['wp-block-embed'];

        if ($type !== '') {
            $figureClasses[] = 'is-type-' . $type;
        }

        if ($providerSlug !== '') {
            $figureClasses[] = 'is-provider-' . $providerSlug;
            $figureClasses[] = 'wp-block-embed-' . $providerSlug;
        }

        $aspectClass = self::resolveAspectRatioClass($width, $height);
        if (\is_string($aspectClass)) {
            $figureClasses[] = $aspectClass;
            $figureClasses[] = 'wp-has-aspect-ratio';
        }

        return new Block(
            blockName: 'embed',
            attributes: $blockAttributes,
            innerContent: [\sprintf(
                '<figure class="%s"><div class="wp-block-embed__wrapper">
%s
</div></figure>',
                \implode(' ', $figureClasses),
                $url,
            ),],
        );
    }

    /**
     * Build an embed block for a URL a registered embed handler claims.
     *
     * A core/embed block stores the URL and nothing else — the handler's
     * markup is regenerated at render time — so the block carries the URL
     * under a generic "embed-handler" provider slug for the editor to
     * re-resolve.
     */
    public function fromHandler(string $url): Block
    {
        return new Block(
            blockName: 'embed',
            attributes: [
                'url' => $url,
                'type' => 'rich',
                'providerNameSlug' => 'embed-handler',
                'responsive' => true,
            ],
            innerContent: [\sprintf(
                '<figure class="wp-block-embed is-type-rich is-provider-embed-handler wp-block-embed-embed-handler"><div class="wp-block-embed__wrapper">
%s
</div></figure>',
                $url,
            ),],
        );
    }

    /**
     * Match aspect ratio using the same algorithm as Gutenberg's getClassNames().
     *
     * Walks ratios from largest to smallest. If computed ratio >= candidate and
     * the difference is within 0.1 tolerance, the candidate class is returned.
     */
    public static function resolveAspectRatioClass(int $width, int $height): ?string
    {
        if ($width <= 0 || $height <= 0) {
            return null;
        }

        $aspectRatio = \round($width / $height, 2);

        foreach (self::ASPECT_RATIOS as $candidate) {
            if ($aspectRatio >= $candidate['ratio']) {
                return $aspectRatio - $candidate['ratio'] > 0.1 ? null : $candidate['className'];
            }
        }

        return null;
    }
}
