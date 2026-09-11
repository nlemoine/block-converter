<?php

declare(strict_types=1);

namespace n5s\BlockConverter\TagConverters;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\Support\EmbedBlockFactory;
use voku\helper\SimpleHtmlDomInterface;
use WP_Post;

class ObjectConverter implements TagConverterInterface
{
    private readonly EmbedBlockFactory $embedFactory;

    public function __construct(?EmbedBlockFactory $embedFactory = null)
    {
        $this->embedFactory = $embedFactory ?? new EmbedBlockFactory();
    }

    public static function tags(): array
    {
        return ['object', 'embed'];
    }

    public function convert(
        SimpleHtmlDomInterface $element,
        ?WP_Post $post = null,
    ): Block|array|null {

        $url = $this->extractUrl($element);

        // No URL, or one no oEmbed provider claims: the element is kept as it
        // came in rather than dropped. Returning null here deleted it with no
        // log line, since a converter that declines is not a missing
        // converter, and a kses-legal PDF object vanished from the post.
        if ($url === null) {
            return new Block('html', innerContent: [(string) $element]);
        }

        $block = $this->embedFactory->resolve(self::normalizeYouTubeUrl($url));

        return $block ?? new Block('html', innerContent: [(string) $element]);
    }

    /**
     * Extract URL from an <object> or <embed> element.
     */
    private function extractUrl(SimpleHtmlDomInterface $element): ?string
    {
        $tag = \strtolower($element->tag);

        if ($tag === 'embed') {
            return $this->extractFromEmbed($element);
        }

        // <object data>: the one URL attribute kses keeps on the element, and
        // the one legacy markup uses for anything that is not Flash. It was
        // never read, so every <object> the default sanitizer let through
        // came out without a URL.
        $data = \trim($element->getAttribute('data'));

        if ($data !== '') {
            return $data;
        }

        // Flash-era markup: <param name="src|movie"> first, then nested <embed>
        $params = $element->findMulti('param');
        if (\is_countable($params)) {
            foreach ($params as $param) {
                $name = \strtolower(\trim($param->getAttribute('name')));
                if (\in_array($name, ['src', 'movie'], true)) {
                    $value = \trim($param->getAttribute('value'));
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }

        // Fallback: nested <embed> inside <object>
        $nestedEmbed = $element->findOne('embed');
        if ($nestedEmbed->tag === 'embed') {
            return $this->extractFromEmbed($nestedEmbed);
        }

        return null;
    }

    private function extractFromEmbed(SimpleHtmlDomInterface $element): ?string
    {
        $src = \trim($element->getAttribute('src'));

        return $src !== '' ? $src : null;
    }

    /**
     * Normalize legacy YouTube Flash URLs to standard watch URLs.
     *
     * Converts patterns like:
     * - http://www.youtube.com/v/VIDEO_ID
     * - http://www.youtube.com/v/VIDEO_ID&hl=en
     * - https://youtube.com/v/VIDEO_ID?autoplay=1
     */
    public static function normalizeYouTubeUrl(string $url): string
    {
        if (!\preg_match('#youtube\.com/v/([a-zA-Z0-9_-]+)#', $url, $matches)) {
            return $url;
        }

        return 'https://www.youtube.com/watch?v=' . $matches[1];
    }
}
