<?php

declare(strict_types=1);

namespace n5s\BlockConverter\TagConverters;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\Support\AttachmentResolver;
use n5s\BlockConverter\Support\HtmlUtils;
use voku\helper\SimpleHtmlDomInterface;
use WP_HTML_Tag_Processor;
use WP_Post;

class ImageConverter implements TagConverterInterface
{
    private const array ALIGNMENT_CLASSES = ['alignleft', 'alignright', 'aligncenter', 'alignnone', 'alignwide', 'alignfull'];

    public function __construct(
        private readonly AttachmentResolver $attachmentResolver = new AttachmentResolver(),
    ) {
    }

    public static function tags(): array
    {
        return ['img'];
    }

    public function convert(
        SimpleHtmlDomInterface $element,
        ?WP_Post $post = null,
    ): Block|array|null {

        $img = $element->tag === 'img' ? $element : $element->findOneOrFalse('img');

        if ($img === false) {
            return null;
        }

        $src = $img->getAttribute('src');
        if ($src === '') {
            return null;
        }

        $imgClasses = $this->parseClasses($img->getAttribute('class'));
        $attributes = [];
        $figureClasses = ['wp-block-image'];
        $imgClassesAdd = [];
        $imgClassesRemove = [];

        // --- ID resolution ---
        $id = $this->resolveAttachmentId($src, $imgClasses);
        if ($id !== null) {
            $attributes['id'] = $id;
            $imgClassesAdd[] = 'wp-image-' . $id;
        }

        // --- Alignment ---
        $align = $this->extractAlignment($imgClasses);
        if ($align !== null) {
            if ($align !== 'none') {
                $attributes['align'] = $align;
                $figureClasses[] = 'align' . $align;
            }
            $imgClassesRemove[] = 'align' . $align;
        }

        // --- Size ---
        $size = $this->extractSize($imgClasses);
        if ($size !== null) {
            $attributes['sizeSlug'] = $size;
            $figureClasses[] = 'size-' . $size;
            $imgClassesRemove[] = 'size-' . $size;
        }

        // --- Link destination ---
        if ($element->tag === 'a') {
            $href = $element->getAttribute('href');
            if ($this->isMediaLink($href)) {
                $attributes['linkDestination'] = 'media';
            }
        }

        // --- Build content HTML ---
        $content = (string) $element;

        // Add/remove img classes
        if (\count($imgClassesAdd) > 0) {
            $content = HtmlUtils::addClass($content, 'img', \implode(' ', \array_unique($imgClassesAdd)));
        }
        if (\count($imgClassesRemove) > 0) {
            $content = HtmlUtils::removeClass($content, 'img', \implode(' ', \array_unique($imgClassesRemove)));
        }

        // Remove title from wrapping link
        if ($element->tag === 'a') {
            $content = HtmlUtils::removeAttr($content, 'a', 'title');
        }

        // Check for explicit dimensions before removing
        $processor = new WP_HTML_Tag_Processor($content);
        if ($processor->next_tag(['tag_name' => 'img'])) {
            $width = $processor->get_attribute('width');
            $height = $processor->get_attribute('height');
            $hasWidth = \is_string($width) && \is_numeric($width);
            $hasHeight = \is_string($height) && \is_numeric($height);

            if ($hasWidth || $hasHeight) {
                $figureClasses[] = 'is-resized';
            }

            $content = HtmlUtils::removeAttr($content, 'img', 'width');
            $content = HtmlUtils::removeAttr($content, 'img', 'height');
        }

        return new Block(
            blockName: 'image',
            attributes: $attributes,
            innerContent: [\sprintf(
                '<figure class="%s">%s</figure>',
                \implode(' ', $figureClasses),
                $content,
            ),],
        );
    }

    /**
     * Resolve the WordPress attachment ID from CSS classes or URL.
     *
     * Strategy:
     * 1. Check for `wp-image-{id}` in img CSS classes (cheap, no DB)
     * 2. Fall back to URL-based lookup via `attachment_url_to_postid()` (DB query)
     *
     * @param string   $src        Image source URL
     * @param string[] $imgClasses Parsed CSS classes from the img tag
     */
    private function resolveAttachmentId(string $src, array $imgClasses): ?int
    {
        return $this->attachmentResolver->fromCssClass($imgClasses) ?? $this->attachmentResolver->fromUrl($src);
    }

    /**
     * Extract alignment value from CSS classes.
     *
     * @param string[] $classes
     */
    private function extractAlignment(array $classes): ?string
    {
        foreach ($classes as $class) {
            if (\in_array($class, self::ALIGNMENT_CLASSES, true)) {
                return \substr($class, 5); // strip 'align' prefix
            }
        }

        return null;
    }

    /**
     * Extract image size slug from CSS classes.
     *
     * @param string[] $classes
     */
    private function extractSize(array $classes): ?string
    {
        foreach ($classes as $class) {
            if (\str_starts_with($class, 'size-')) {
                // The sanitizer has already run; this is output hygiene, so
                // the slug that ends up in the block attributes and the
                // figure class is one WordPress itself would have produced.
                $size = \sanitize_html_class(\substr($class, 5));

                return $size === '' ? null : $size;
            }
        }

        return null;
    }

    /**
     * Check if a URL points to an internal media file.
     */
    private function isMediaLink(?string $href): bool
    {
        if ($href === null || $href === '') {
            return false;
        }

        if (!\wp_is_internal_link($href)) {
            return false;
        }

        $path = \wp_parse_url($href, \PHP_URL_PATH);
        if (!\is_string($path)) {
            return false;
        }

        return \pathinfo($path, \PATHINFO_EXTENSION) !== '';
    }

    /**
     * Parse a space-separated class string into an array.
     *
     * @return string[]
     */
    private function parseClasses(?string $classString): array
    {
        return HtmlUtils::classTokens($classString);
    }
}
