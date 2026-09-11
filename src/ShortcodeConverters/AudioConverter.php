<?php

declare(strict_types=1);

namespace n5s\BlockConverter\ShortcodeConverters;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\Support\AttachmentResolver;
use WP_Post;

/**
 * @phpstan-type AudioAtts array{
 *     src?: string,
 *     mp3?: string,
 *     ogg?: string,
 *     flac?: string,
 *     m4a?: string,
 *     wav?: string,
 *     preload?: string,
 *     autoplay?: string,
 *     loop?: string,
 * }
 * @phpstan-type AudioBlockAttributes array{
 *     autoplay?: bool,
 *     loop?: bool,
 *     preload?: string,
 *     id?: int,
 * }
 */
class AudioConverter extends AbstractShortcodeConverter
{
    private const array SOURCE_TYPES = ['mp3', 'ogg', 'flac', 'm4a', 'wav'];

    private const array ALLOWED_PRELOAD = ['none', 'metadata', 'auto'];

    public function __construct(
        private readonly AttachmentResolver $attachmentResolver = new AttachmentResolver(),
    ) {
    }

    public static function shortcodes(): array
    {
        return ['audio'];
    }

    /** @param AudioAtts $atts */
    public function convert(array $atts, ?string $content, string $tag, ?WP_Post $post = null): ?Block
    {
        $src = $this->resolveSource($atts);

        // Without a source, [audio] plays the first audio file attached to
        // the post, which do_shortcode() still resolves at render time.
        if ($src === null) {
            return $this->fallback($tag, $atts, $content);
        }

        /** @var AudioBlockAttributes $blockAttributes */
        $blockAttributes = [];

        // --- Attachment ID ---
        $id = $this->attachmentResolver->fromUrl($src);
        if ($id !== null) {
            $blockAttributes['id'] = $id;
        }

        // --- Boolean attributes ---
        foreach (['autoplay', 'loop'] as $boolAttr) {
            if ($this->isTruthy($atts[$boolAttr] ?? '')) {
                $blockAttributes[$boolAttr] = true;
            }
        }

        // --- Preload (audio default is 'none') ---
        $preload = $this->resolvePreload($atts['preload'] ?? '');
        if ($preload !== 'none') {
            $blockAttributes['preload'] = $preload;
        }

        // --- Build audio HTML ---
        $audioHtml = $this->buildAudioTag($src, $blockAttributes, $preload);

        // --- Caption ---
        $captionHtml = '';
        if ($content !== null && $content !== '') {
            $captionHtml = \sprintf('<figcaption class="wp-element-caption">%s</figcaption>', \wp_kses_post($content));
        }

        return new Block(
            blockName: 'audio',
            attributes: $blockAttributes,
            innerContent: [\sprintf(
                '<figure class="wp-block-audio">%s%s</figure>',
                $audioHtml,
                $captionHtml,
            ),],
        );
    }

    /** @param AudioAtts $atts */
    private function resolveSource(array $atts): ?string
    {
        if (isset($atts['src']) && $atts['src'] !== '') {
            return $atts['src'];
        }

        foreach (self::SOURCE_TYPES as $type) {
            if (isset($atts[$type]) && $atts[$type] !== '') {
                return $atts[$type];
            }
        }

        return null;
    }

    private function resolvePreload(string $value): string
    {
        $value = \strtolower(\trim($value));

        return \in_array($value, self::ALLOWED_PRELOAD, true) ? $value : 'none';
    }

    private function isTruthy(string $value): bool
    {
        return \in_array(\strtolower(\trim($value)), ['on', 'true', '1', 'yes'], true);
    }

    /** @param AudioBlockAttributes $blockAttributes */
    private function buildAudioTag(string $src, array $blockAttributes, string $preload): string
    {
        $htmlAttrs = [];

        foreach (['autoplay', 'loop'] as $attr) {
            if (isset($blockAttributes[$attr])) {
                $htmlAttrs[] = $attr;
            }
        }

        $htmlAttrs[] = 'controls';
        $htmlAttrs[] = \sprintf('src="%s"', \esc_url($src));
        $htmlAttrs[] = \sprintf('preload="%s"', \esc_attr($preload));

        return \sprintf('<audio %s></audio>', \implode(' ', $htmlAttrs));
    }
}
