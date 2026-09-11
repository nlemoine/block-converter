<?php

declare(strict_types=1);

namespace n5s\BlockConverter\TagConverters;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\Support\EmbedBlockFactory;
use voku\helper\SimpleHtmlDomInterface;
use WP_Post;

class IframeConverter implements TagConverterInterface
{
    private readonly EmbedBlockFactory $embedFactory;

    public function __construct(?EmbedBlockFactory $embedFactory = null)
    {
        $this->embedFactory = $embedFactory ?? new EmbedBlockFactory();
    }

    public static function tags(): array
    {
        return ['iframe'];
    }

    public function convert(
        SimpleHtmlDomInterface $element,
        ?WP_Post $post = null,
    ): Block|array|null {

        $src = \trim($element->getAttribute('src'));

        if ($src === '') {
            return null;
        }

        $url = self::normalizeEmbedUrl($src);

        $block = $this->embedFactory->fromUrl($url);

        // If oEmbed resolved a provider, return the embed block.
        if (($block->attributes['providerNameSlug'] ?? '') !== '') {
            return $block;
        }

        // Unknown URL: fall back to core/html preserving the iframe as-is.
        return new Block('html', innerContent: [(string) $element]);
    }

    /**
     * Normalize known embed URLs to their canonical (non-embed) form.
     *
     * This allows oEmbed resolution to succeed for URLs that use
     * platform-specific embed/player endpoints.
     */
    public static function normalizeEmbedUrl(string $url): string
    {
        // youtube.com/embed/ID → youtube.com/watch?v=ID
        if (\preg_match('#youtube\.com/embed/([a-zA-Z0-9_-]+)#', $url, $m)) {
            return 'https://www.youtube.com/watch?v=' . $m[1];
        }

        // player.vimeo.com/video/ID → vimeo.com/ID
        if (\preg_match('#player\.vimeo\.com/video/(\d+)#', $url, $m)) {
            return 'https://vimeo.com/' . $m[1];
        }

        // dailymotion.com/embed/video/ID → dailymotion.com/video/ID
        if (\preg_match('#dailymotion\.com/embed/video/([a-zA-Z0-9]+)#', $url, $m)) {
            return 'https://www.dailymotion.com/video/' . $m[1];
        }

        // open.spotify.com/embed/TYPE/ID → open.spotify.com/TYPE/ID
        if (\preg_match('#open\.spotify\.com/embed/(track|album|playlist|episode|show)/([a-zA-Z0-9]+)#', $url, $m)) {
            return 'https://open.spotify.com/' . $m[1] . '/' . $m[2];
        }

        // w.soundcloud.com/player/?url=ENCODED_URL → decoded URL
        if (\preg_match('#w\.soundcloud\.com/player/.*[?&]url=([^&]+)#', $url, $m)) {
            return \urldecode($m[1]);
        }

        // embed.ted.com/talks/SLUG → ted.com/talks/SLUG
        if (\preg_match('#embed\.ted\.com/talks/([a-zA-Z0-9_]+)#', $url, $m)) {
            return 'https://www.ted.com/talks/' . $m[1];
        }

        return $url;
    }
}
