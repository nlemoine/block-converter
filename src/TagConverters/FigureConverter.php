<?php

declare(strict_types=1);

namespace n5s\BlockConverter\TagConverters;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\ConverterRegistry;
use n5s\BlockConverter\RegistryAwareInterface;
use n5s\BlockConverter\RegistryAwareTrait;
use n5s\BlockConverter\Support\HtmlUtils;
use voku\helper\SimpleHtmlDomInterface;
use voku\helper\SimpleHtmlDomNodeInterface;
use WP_Post;

/**
 * Converts <figure> wrappers around an image into core/image.
 *
 * This is the rendered form of [caption]: WordPress itself emits
 * <figure><img><figcaption> when it renders the shortcode, so content that has
 * already been through the_content arrives here rather than as a shortcode.
 *
 * Figures wrapping anything else (video, iframe, code, …) fall back to
 * core/html, which is what an unregistered tag would have produced anyway.
 */
class FigureConverter implements RegistryAwareInterface, TagConverterInterface
{
    use RegistryAwareTrait;

    private const array ALIGNMENT_CLASSES = ['alignleft', 'alignright', 'aligncenter', 'alignnone', 'alignwide', 'alignfull'];

    /**
     * @param ImageConverter $imageConverter Used only when this converter is
     *                                       driven on its own. Once registered,
     *                                       the registry's img converter wins,
     *                                       so overriding it also changes what
     *                                       a <figure> produces.
     */
    public function __construct(
        private readonly ImageConverter $imageConverter = new ImageConverter(),
    ) {
    }

    public static function tags(): array
    {
        return ['figure'];
    }

    public function convert(
        SimpleHtmlDomInterface $element,
        ?WP_Post $post = null,
    ): Block|array|null {

        $children = $this->significantChildren($element);
        $media = $children[0] ?? null;

        // Nothing to keep; emitting an empty core/html block would be noise.
        if ($media === null) {
            return null;
        }

        if (!$this->isImageOrLinkedImage($media)) {
            return $this->htmlFallback($element);
        }

        // Only an optional <figcaption> may accompany the image.
        if (isset($children[1]) && $children[1]->getTag() !== 'figcaption') {
            return $this->htmlFallback($element);
        }

        if (isset($children[2])) {
            return $this->htmlFallback($element);
        }

        // Alignment and size usually sit on the <figure>, not the <img>. Move
        // them onto the image so ImageConverter resolves them as it always does.
        $media = $this->hoistFigureClasses($element, $media);

        $imageConverter = $this->imageConverter();

        if (!$imageConverter instanceof TagConverterInterface) {
            // img was deliberately removed from the registry; honour that
            // rather than converting the image anyway.
            return $this->htmlFallback($element);
        }

        $block = $imageConverter->convert($media, $post);

        if (!$block instanceof Block) {
            return $this->htmlFallback($element);
        }

        $caption = isset($children[1]) ? HtmlUtils::trim($children[1]->innerHtml()) : '';

        return $caption === '' ? $block : $this->withCaption($block, $caption);
    }

    /**
     * The converter that turns the inner image into a block.
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

    /**
     * Element children, ignoring whitespace-only text nodes.
     *
     * @return list<SimpleHtmlDomInterface>
     */
    private function significantChildren(SimpleHtmlDomInterface $element): array
    {
        $children = $element->childNodes();

        // @codeCoverageIgnoreStart
        // The parser always hands back a node list; the check only satisfies
        // the union in its signature.
        if (!$children instanceof SimpleHtmlDomNodeInterface) {
            return [];
        }
        // @codeCoverageIgnoreEnd

        $significant = [];

        foreach ($children as $child) {
            $tag = $child->getTag();

            if ($tag === '' || $tag === '#text') {
                if (HtmlUtils::trim($child->plaintext) !== '') {
                    // Bare text alongside the image: not a plain figure.
                    $significant[] = $child;
                }

                continue;
            }

            $significant[] = $child;
        }

        return $significant;
    }

    private function isImageOrLinkedImage(SimpleHtmlDomInterface $element): bool
    {
        if ($element->getTag() === 'img') {
            return true;
        }

        return $element->getTag() === 'a' && $element->findOneOrFalse('img') !== false;
    }

    /**
     * Copy alignment and size classes from the <figure> onto the <img>.
     *
     * voku 4.x hands out detached copies from nested find*() calls, so
     * mutating the child in place does not reach the markup ImageConverter
     * serialises; 5.0 made those nodes live. Both majors are supported, so
     * the HTML is rewritten and re-parsed, which works the same under either.
     */
    private function hoistFigureClasses(
        SimpleHtmlDomInterface $figure,
        SimpleHtmlDomInterface $media,
    ): SimpleHtmlDomInterface {

        $figureClasses = HtmlUtils::classTokens($figure->getAttribute('class'));

        $hoisted = \array_filter(
            $figureClasses,
            static fn (string $class): bool => \in_array($class, self::ALIGNMENT_CLASSES, true)
                || \str_starts_with($class, 'size-'),
        );

        if ($hoisted === []) {
            return $media;
        }

        $html = HtmlUtils::addClass((string) $media, 'img', \implode(' ', $hoisted));
        $reparsed = HtmlUtils::parse($html)->findOneOrFalse($media->getTag());

        return $reparsed === false ? $media : $reparsed;
    }

    private function withCaption(Block $block, string $caption): Block
    {
        $figure = $block->innerHTML();
        $closing = \strrpos($figure, '</figure>');

        // ImageConverter always wraps its output in a <figure>.
        // @codeCoverageIgnoreStart
        if ($closing === false) {
            return $block;
        }
        // @codeCoverageIgnoreEnd

        $block->innerContent = [\substr_replace(
            $figure,
            \sprintf('<figcaption class="wp-element-caption">%s</figcaption>', $caption),
            $closing,
            0,
        ),];

        return $block;
    }

    /**
     * Same shape as BlockConverter's own fallback for unregistered tags, so a
     * figure this converter declines looks exactly as it did before.
     */
    private function htmlFallback(SimpleHtmlDomInterface $element): Block
    {
        return new Block('html', innerContent: [$element->html()]);
    }
}
