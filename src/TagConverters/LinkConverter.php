<?php

declare(strict_types=1);

namespace n5s\BlockConverter\TagConverters;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\ConverterRegistry;
use n5s\BlockConverter\RegistryAwareInterface;
use n5s\BlockConverter\RegistryAwareTrait;
use n5s\BlockConverter\Support\HtmlUtils;
use voku\helper\SimpleHtmlDomInterface;
use WP_Post;

class LinkConverter implements RegistryAwareInterface, TagConverterInterface
{
    use RegistryAwareTrait;

    /**
     * @param ImageConverter $imageConverter Used only when this converter is
     *                                       driven on its own. Once registered,
     *                                       the registry's img converter wins.
     */
    public function __construct(
        private readonly ImageConverter $imageConverter = new ImageConverter(),
    ) {
    }

    public static function tags(): array
    {
        return ['a'];
    }

    public function convert(
        SimpleHtmlDomInterface $element,
        ?WP_Post $post = null,
    ): Block|array|null {

        $imgs = $element->findMulti('img');
        $imgCount = \is_countable($imgs) ? \count($imgs) : 0;
        $hasText = HtmlUtils::trim($element->plaintext) !== '';
        $imageConverter = $this->imageConverter();

        if ($imgCount === 0 || !$imageConverter instanceof TagConverterInterface) {
            // A plain link, or img was deliberately removed from the registry:
            // either way the anchor is kept as-is rather than converted.
            $content = $element->outertext;

            return \trim($content) === '' ? null : new Block('html', innerContent: [$content]);
        }

        // Single image, no text → delegate whole <a> to ImageConverter (preserves link)
        if ($imgCount === 1 && !$hasText) {
            return $imageConverter->convert($element, $post) ?? $this->keep($element);
        }

        // Multiple images, no text → convert each individually
        if (!$hasText) {
            $blocks = [];

            foreach ($imgs as $img) {
                $result = $imageConverter->convert($img, $post);

                if ($result instanceof Block) {
                    $blocks[] = $result;
                }
            }

            return $blocks ?: $this->keep($element);
        }

        // Images mixed with text → wp:html fallback
        return $this->keep($element);
    }

    /**
     * The anchor as it came in.
     *
     * Also what an image the converter declines falls back to — a src-less
     * <img>, or whatever a custom img converter chooses not to handle. Reading
     * that null as "emit nothing" dropped the link, its href and its image
     * together, while the branch for a missing converter kept all three.
     */
    private function keep(SimpleHtmlDomInterface $element): Block
    {
        return new Block('html', innerContent: [$element->outertext]);
    }

    /**
     * The converter that turns the inner images into blocks.
     *
     * Resolved per call rather than held, so registering an override reaches
     * this path too. Guarded against resolving to this converter, which would
     * recurse forever if someone registered it for img.
     */
    private function imageConverter(): ?TagConverterInterface
    {
        if (!$this->registry instanceof ConverterRegistry) {
            return $this->imageConverter;
        }

        $registered = $this->registry->getTagConverter('img');

        // Only a subclass that overrides tags() to claim img can be
        // registered under it; for this class the comparison is always false.
        return $registered === $this ? $this->imageConverter : $registered;
    }
}
