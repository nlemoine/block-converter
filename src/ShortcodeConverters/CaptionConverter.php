<?php

declare(strict_types=1);

namespace n5s\BlockConverter\ShortcodeConverters;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\Support\AttachmentResolver;
use n5s\BlockConverter\Support\HtmlUtils;
use WP_HTML_Tag_Processor;
use WP_Post;

/**
 * @phpstan-type CaptionAtts array{
 *     id?: string,
 *     align?: string,
 *     width?: string,
 *     caption?: string,
 *     class?: string,
 * }
 */
class CaptionConverter extends AbstractShortcodeConverter
{
    private const array ALIGNMENT_VALUES = ['alignleft', 'alignright', 'aligncenter', 'alignnone', 'alignwide', 'alignfull'];

    public function __construct(
        private readonly AttachmentResolver $attachmentResolver = new AttachmentResolver(),
    ) {
    }

    public static function shortcodes(): array
    {
        return ['caption', 'wp_caption'];
    }

    /** @param CaptionAtts $atts */
    public function convert(array $atts, ?string $content, string $tag, ?WP_Post $post = null): ?Block
    {
        if ($content === null || \trim($content) === '') {
            return $this->fallback($tag, $atts, $content);
        }

        if (!\preg_match('#((?:<a [^>]+>\s*)?<img [^>]+>(?:\s*</a>)?)(.*)#is', $content, $matches)) {
            return $this->fallback($tag, $atts, $content);
        }

        $imgHtml = $matches[1];
        $captionText = $this->resolveCaptionText($atts, $matches[2]);

        $blockAttributes = [];
        $figureClasses = ['wp-block-image'];

        // --- Attachment ID ---
        $id = $this->resolveId($atts, $imgHtml);
        if ($id !== null) {
            $blockAttributes['id'] = $id;
        }

        // --- Alignment ---
        $align = $this->resolveAlignment($atts);
        if ($align !== null && $align !== 'none') {
            $blockAttributes['align'] = $align;
            $figureClasses[] = 'align' . $align;
        }

        // --- Process img tag ---
        $imgHtml = $this->processImg($imgHtml, $id);

        // --- Build output ---
        $captionHtml = '';
        if ($captionText !== '') {
            $captionHtml = \sprintf('<figcaption class="wp-element-caption">%s</figcaption>', $captionText);
        }

        return new Block(
            blockName: 'image',
            attributes: $blockAttributes,
            innerContent: [\sprintf(
                '<figure class="%s">%s%s</figure>',
                \implode(' ', $figureClasses),
                $imgHtml,
                $captionHtml,
            ),],
        );
    }

    /** @param CaptionAtts $atts */
    private function resolveId(array $atts, string $imgHtml): ?int
    {
        // From shortcode atts: id="attachment_123"
        if (isset($atts['id']) && \preg_match('/(\d+)/', $atts['id'], $m)) {
            $id = $this->attachmentResolver->fromId((int) $m[1]);
            if ($id !== null) {
                return $id;
            }
        }

        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- documents the attribute the regex below matches.
        // From img class: class="wp-image-123"
        if (\preg_match('/wp-image-(\d+)/', $imgHtml, $m)) {
            return $this->attachmentResolver->fromId((int) $m[1]);
        }

        return null;
    }

    /** @param CaptionAtts $atts */
    private function resolveCaptionText(array $atts, string $inlineCaption): string
    {
        if (isset($atts['caption']) && $atts['caption'] !== '') {
            // Stripped like the inline branch below: this attribute is plain
            // text by convention, and it was the one path left unfiltered.
            return \wp_strip_all_tags($atts['caption']);
        }

        return \wp_strip_all_tags($inlineCaption);
    }

    /** @param CaptionAtts $atts */
    private function resolveAlignment(array $atts): ?string
    {
        $align = $atts['align'] ?? '';

        if ($align === '') {
            return null;
        }

        if (\in_array($align, self::ALIGNMENT_VALUES, true)) {
            return \substr($align, 5);
        }

        if (\in_array('align' . $align, self::ALIGNMENT_VALUES, true)) {
            return $align;
        }

        return null;
    }

    private function processImg(string $imgHtml, ?int $attachmentId): string
    {
        $processor = new WP_HTML_Tag_Processor($imgHtml);

        // Callers already matched an <img> out of the shortcode content, so the
        // processor cannot fail to find one here.
        // @codeCoverageIgnoreStart
        if (!$processor->next_tag(['tag_name' => 'img'])) {
            return $imgHtml;
        }
        // @codeCoverageIgnoreEnd

        if ($attachmentId !== null) {
            $processor->add_class('wp-image-' . $attachmentId);
            $imgHtml = $processor->get_updated_html();
        }

        $imgHtml = HtmlUtils::removeAttr($imgHtml, 'img', 'width');

        return HtmlUtils::removeAttr($imgHtml, 'img', 'height');
    }
}
