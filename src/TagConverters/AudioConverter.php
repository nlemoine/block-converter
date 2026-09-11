<?php

declare(strict_types=1);

namespace n5s\BlockConverter\TagConverters;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\Support\AttachmentResolver;
use voku\helper\SimpleHtmlDomInterface;
use WP_Post;

/**
 * @phpstan-type AudioBlockAttributes array{
 *     autoplay?: bool,
 *     loop?: bool,
 *     preload?: string,
 *     id?: int,
 * }
 */
class AudioConverter implements TagConverterInterface
{
    private const array ALLOWED_PRELOAD = ['none', 'metadata', 'auto'];

    public function __construct(
        private readonly AttachmentResolver $attachmentResolver = new AttachmentResolver(),
    ) {
    }

    public static function tags(): array
    {
        return ['audio'];
    }

    public function convert(
        SimpleHtmlDomInterface $element,
        ?WP_Post $post = null,
    ): Block|array|null {

        $src = $this->resolveSource($element);

        if ($src === null) {
            return null;
        }

        /** @var AudioBlockAttributes $blockAttributes */
        $blockAttributes = [];

        // Attachment ID.
        $id = $this->attachmentResolver->fromUrl($src);
        if ($id !== null) {
            $blockAttributes['id'] = $id;
        }

        // Boolean attributes — getAttribute returns null when absent,
        // empty string for valueless attributes like <audio autoplay>.
        foreach (['autoplay', 'loop'] as $attr) {
            if ($element->hasAttribute($attr)) {
                $blockAttributes[$attr] = true;
            }
        }

        // Preload (default is 'none').
        $preload = $this->resolvePreload($element->getAttribute('preload'));
        if ($preload !== 'none') {
            $blockAttributes['preload'] = $preload;
        }

        // Build audio HTML.
        $audioHtml = $this->buildAudioTag($src, $blockAttributes, $preload);

        return new Block(
            blockName: 'audio',
            attributes: $blockAttributes,
            innerContent: [\sprintf('<figure class="wp-block-audio">%s</figure>', $audioHtml)],
        );
    }

    private function resolveSource(SimpleHtmlDomInterface $element): ?string
    {
        $src = \trim($element->getAttribute('src'));

        if ($src !== '') {
            return $src;
        }

        // Fall back to first <source> child.
        $sources = $element->findMultiOrFalse('source');

        if ($sources === false) {
            return null;
        }

        foreach ($sources as $source) {
            $sourceSrc = \trim((string) $source->getAttribute('src'));

            if ($sourceSrc !== '') {
                return $sourceSrc;
            }
        }

        return null;
    }

    private function resolvePreload(string $value): string
    {
        $value = \strtolower(\trim($value));

        return \in_array($value, self::ALLOWED_PRELOAD, true) ? $value : 'none';
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
