<?php

declare(strict_types=1);

namespace n5s\BlockConverter\ShortcodeConverters;

use n5s\BlockConverter\Block;
use WP_Post;

/**
 * @phpstan-type GalleryAtts array{
 *     ids?: string,
 *     columns?: string,
 *     size?: string,
 *     link?: string,
 *     orderby?: string,
 *     order?: string,
 * }
 */
class GalleryConverter extends AbstractShortcodeConverter
{
    private const array LINK_MAP = [
        'file' => 'media',
        'post' => 'attachment',
        'none' => 'none',
    ];

    public static function shortcodes(): array
    {
        return ['gallery'];
    }

    /** @param GalleryAtts $atts */
    public function convert(array $atts, ?string $content, string $tag, ?WP_Post $post = null): ?Block
    {
        $ids = \array_filter(\array_map(\intval(...), \explode(',', $atts['ids'] ?? '')));

        // Without ids, [gallery] means every attachment of the post it sits
        // in, which do_shortcode() can still resolve at render time; ids that
        // no longer exist are the author's to see. Neither is a reason to
        // drop the shortcode.
        if ($ids === []) {
            return $this->fallback($tag, $atts, $content);
        }

        $sizeSlug = $atts['size'] ?? 'thumbnail';
        $linkTo = self::LINK_MAP[$atts['link'] ?? ''] ?? 'attachment';
        $columns = isset($atts['columns']) ? (int) $atts['columns'] : 3;

        $imageBlocks = $this->buildImageBlocks($ids, $sizeSlug, $linkTo);

        if ($imageBlocks === []) {
            return $this->fallback($tag, $atts, $content);
        }

        $attributes = ['linkTo' => $linkTo];

        if ($columns !== 3) {
            $attributes['columns'] = $columns;
        }

        $gallery = new Block(
            blockName: 'gallery',
            attributes: $attributes,
            container: true,
        );

        $gallery->appendContent(
            \sprintf(
                '<figure class="wp-block-gallery has-nested-images columns-%d is-cropped">',
                $columns,
            ),
        );

        foreach ($imageBlocks as $imageBlock) {
            $gallery->appendInnerBlock($imageBlock);
        }

        $gallery->appendContent('</figure>');

        return $gallery;
    }

    /**
     * @param int[] $ids
     *
     * @return Block[]
     */
    private function buildImageBlocks(array $ids, string $sizeSlug, string $linkTo): array
    {
        $blocks = [];

        foreach ($ids as $id) {
            $url = \wp_get_attachment_url($id);

            if ($url === false) {
                continue;
            }

            $alt = \get_post_meta($id, '_wp_attachment_image_alt', true);
            $alt = \is_string($alt) ? $alt : '';
            $caption = \get_post($id)->post_excerpt ?? '';

            $imgHtml = \sprintf(
                '<img src="%s" alt="%s" class="wp-image-%d"/>',
                \esc_url($url),
                \esc_attr($alt),
                $id,
            );

            $captionHtml = '';
            if ($caption !== '') {
                $captionHtml = \sprintf(
                    '<figcaption class="wp-element-caption">%s</figcaption>',
                    \wp_kses_post($caption),
                );
            }

            $figureContent = \sprintf(
                '<figure class="wp-block-image size-%s">%s%s</figure>',
                \sanitize_html_class($sizeSlug),
                $imgHtml,
                $captionHtml,
            );

            $blocks[] = new Block(
                blockName: 'image',
                attributes: [
                    'id' => $id,
                    'sizeSlug' => $sizeSlug,
                    'linkDestination' => $linkTo,
                ],
                innerContent: [$figureContent],
            );
        }

        return $blocks;
    }
}
