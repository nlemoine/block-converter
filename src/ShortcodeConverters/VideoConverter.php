<?php

declare(strict_types=1);

namespace n5s\BlockConverter\ShortcodeConverters;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\Support\AttachmentResolver;
use WP_Post;

/**
 * @phpstan-type VideoAtts array{
 *     src?: string,
 *     mp4?: string,
 *     webm?: string,
 *     ogv?: string,
 *     flv?: string,
 *     m4v?: string,
 *     wmv?: string,
 *     poster?: string,
 *     preload?: string,
 *     autoplay?: string,
 *     loop?: string,
 *     muted?: string,
 *     playsinline?: string,
 *     id?: string,
 *     align?: string,
 * }
 * @phpstan-type VideoBlockAttributes array{
 *     autoplay?: bool,
 *     loop?: bool,
 *     muted?: bool,
 *     playsInline?: bool,
 *     poster?: string,
 *     preload?: string,
 *     align?: string,
 *     id?: int,
 * }
 */
class VideoConverter extends AbstractShortcodeConverter
{
    private const array ALIGNMENT_VALUES = ['left', 'center', 'right', 'wide', 'full'];

    private const array SOURCE_TYPES = ['mp4', 'webm', 'ogv', 'flv', 'm4v', 'wmv'];

    private const array ALLOWED_PRELOAD = ['none', 'metadata', 'auto'];

    public function __construct(
        private readonly AttachmentResolver $attachmentResolver = new AttachmentResolver(),
    ) {
    }

    public static function shortcodes(): array
    {
        return ['video'];
    }

    /** @param VideoAtts $atts */
    public function convert(array $atts, ?string $content, string $tag, ?WP_Post $post = null): ?Block
    {
        $src = $this->resolveSource($atts);

        // Without a source, [video] plays the first video file attached to
        // the post, which do_shortcode() still resolves at render time.
        if ($src === null) {
            return $this->fallback($tag, $atts, $content);
        }

        /** @var VideoBlockAttributes $blockAttributes */
        $blockAttributes = [];

        // --- Attachment ID ---
        $id = $this->resolveId($atts, $src);
        if ($id !== null) {
            $blockAttributes['id'] = $id;
        }

        // --- Boolean attributes ---
        foreach (['autoplay', 'loop', 'muted'] as $boolAttr) {
            if ($this->isTruthy($atts[$boolAttr] ?? '')) {
                $blockAttributes[$boolAttr] = true;
            }
        }

        if ($this->isTruthy($atts['playsinline'] ?? '')) {
            $blockAttributes['playsInline'] = true;
        }

        // --- Poster ---
        if (isset($atts['poster']) && $atts['poster'] !== '') {
            $blockAttributes['poster'] = $atts['poster'];
        }

        // --- Preload ---
        $preload = $this->resolvePreload($atts['preload'] ?? '');
        if ($preload !== 'metadata') {
            $blockAttributes['preload'] = $preload;
        }

        // --- Alignment ---
        // Allowlisted rather than escaped: the value lands in a CSS class, and
        // core/video only understands these four.
        $figureClasses = ['wp-block-video'];
        $align = \strtolower(\trim($atts['align'] ?? ''));

        if (\in_array($align, self::ALIGNMENT_VALUES, true)) {
            $blockAttributes['align'] = $align;
            $figureClasses[] = 'align' . $align;
        }

        // --- Build video HTML ---
        $videoHtml = $this->buildVideoTag($src, $blockAttributes, $preload);

        // --- Caption ---
        $captionHtml = '';
        if ($content !== null && $content !== '') {
            $captionHtml = \sprintf('<figcaption class="wp-element-caption">%s</figcaption>', \wp_kses_post($content));
        }

        return new Block(
            blockName: 'video',
            attributes: $blockAttributes,
            innerContent: [\sprintf(
                '<figure class="%s">%s%s</figure>',
                \implode(' ', $figureClasses),
                $videoHtml,
                $captionHtml,
            ),],
        );
    }

    /** @param VideoAtts $atts */
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

    /** @param VideoAtts $atts */
    private function resolveId(array $atts, string $src): ?int
    {
        if (isset($atts['id']) && $atts['id'] !== '') {
            $id = $this->attachmentResolver->fromId((int) $atts['id']);
            if ($id !== null) {
                return $id;
            }
        }

        return $this->attachmentResolver->fromUrl($src);
    }

    private function resolvePreload(string $value): string
    {
        $value = \strtolower(\trim($value));

        return \in_array($value, self::ALLOWED_PRELOAD, true) ? $value : 'metadata';
    }

    /**
     * Parse shortcode boolean values: 'on', 'true', '1', 'yes' -> true.
     */
    private function isTruthy(string $value): bool
    {
        return \in_array(\strtolower(\trim($value)), ['on', 'true', '1', 'yes'], true);
    }

    /** @param VideoBlockAttributes $blockAttributes */
    private function buildVideoTag(string $src, array $blockAttributes, string $preload): string
    {
        $htmlAttrs = [];

        // Boolean HTML attributes (valueless)
        foreach (['autoplay', 'loop', 'muted'] as $attr) {
            if (isset($blockAttributes[$attr])) {
                $htmlAttrs[] = $attr;
            }
        }

        // controls is always present (block default)
        $htmlAttrs[] = 'controls';

        // src
        $htmlAttrs[] = \sprintf('src="%s"', \esc_url($src));

        // poster
        if (isset($blockAttributes['poster'])) {
            $htmlAttrs[] = \sprintf('poster="%s"', \esc_url($blockAttributes['poster']));
        }

        // playsinline (HTML attribute is lowercase)
        if (isset($blockAttributes['playsInline'])) {
            $htmlAttrs[] = 'playsinline';
        }

        // preload (always on HTML, even when default)
        $htmlAttrs[] = \sprintf('preload="%s"', \esc_attr($preload));

        return \sprintf('<video %s></video>', \implode(' ', $htmlAttrs));
    }
}
