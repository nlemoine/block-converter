<?php

declare(strict_types=1);

namespace n5s\BlockConverter\ShortcodeConverters;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\Support\EmbedBlockFactory;
use WP_Post;

/**
 * @phpstan-type EmbedAtts array{src?: string, width?: string, height?: string}
 */
class EmbedConverter extends AbstractShortcodeConverter
{
    private readonly EmbedBlockFactory $embedFactory;

    public function __construct(?EmbedBlockFactory $embedFactory = null)
    {
        $this->embedFactory = $embedFactory ?? new EmbedBlockFactory();
    }

    public static function shortcodes(): array
    {
        return ['embed'];
    }

    /** @param EmbedAtts $atts */
    public function convert(array $atts, ?string $content, string $tag, ?WP_Post $post = null): ?Block
    {
        $url = $this->resolveUrl($atts, $content);

        // No URL, or one that does not parse as such: kept as the author
        // wrote it, for do_shortcode() to make of it what WordPress would.
        if ($url === null) {
            return $this->fallback($tag, $atts, $content);
        }

        return $this->embedFactory->fromUrl($url);
    }

    /** @param EmbedAtts $atts */
    private function resolveUrl(array $atts, ?string $content): ?string
    {
        if (isset($atts['src']) && $atts['src'] !== '') {
            return $this->validUrl($atts['src']);
        }

        if ($content !== null && $content !== '') {
            return $this->validUrl($content);
        }

        return null;
    }

    /**
     * A URL of a scheme WordPress itself would embed.
     *
     * FILTER_VALIDATE_URL is a syntax check, not a scheme allowlist:
     * javascript://a.test/%0aalert(1) passes it. The block sanitizer keeps
     * such a value out of the figure body, but the block's url attribute is
     * what the editor re-resolves, and there is nothing to resolve behind a
     * script scheme.
     */
    private function validUrl(string $candidate): ?string
    {
        $url = \trim($candidate);

        if (\filter_var($url, \FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = \strtolower((string) \parse_url($url, \PHP_URL_SCHEME));

        return \in_array($scheme, \wp_allowed_protocols(), true) ? $url : null;
    }
}
