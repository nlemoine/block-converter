<?php

declare(strict_types=1);

namespace n5s\BlockConverter\TagConverters;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\Support\AttachmentResolver;
use voku\helper\SimpleHtmlDomInterface;
use WP_Post;

/**
 * @phpstan-type VideoBlockAttributes array{
 *     autoplay?: bool,
 *     loop?: bool,
 *     muted?: bool,
 *     playsInline?: bool,
 *     poster?: string,
 *     preload?: string,
 *     id?: int,
 * }
 */
class VideoConverter implements TagConverterInterface
{
    private const array ALLOWED_PRELOAD = ['none', 'metadata', 'auto'];

    public function __construct(
        private readonly AttachmentResolver $attachmentResolver = new AttachmentResolver(),
    ) {
    }

    public static function tags(): array
    {
        return ['video'];
    }

    public function convert(
        SimpleHtmlDomInterface $element,
        ?WP_Post $post = null,
    ): Block|array|null {

        $src = $this->resolveSource($element);

        if ($src === null) {
            return null;
        }

        /** @var VideoBlockAttributes $blockAttributes */
        $blockAttributes = [];

        // Attachment ID.
        $id = $this->attachmentResolver->fromUrl($src);
        if ($id !== null) {
            $blockAttributes['id'] = $id;
        }

        // Boolean attributes — valueless in HTML, e.g. <video autoplay muted>.
        foreach (['autoplay', 'loop', 'muted'] as $attr) {
            if ($element->hasAttribute($attr)) {
                $blockAttributes[$attr] = true;
            }
        }

        if ($element->hasAttribute('playsinline')) {
            $blockAttributes['playsInline'] = true;
        }

        // Poster.
        $poster = \trim($element->getAttribute('poster'));
        if ($poster !== '') {
            $blockAttributes['poster'] = $poster;
        }

        // Preload (the block default is 'metadata').
        $preload = $this->resolvePreload($element->getAttribute('preload'));
        if ($preload !== 'metadata') {
            $blockAttributes['preload'] = $preload;
        }

        $videoHtml = $this->buildVideoTag($src, $blockAttributes, $preload);

        return new Block(
            blockName: 'video',
            attributes: $blockAttributes,
            innerContent: [\sprintf('<figure class="wp-block-video">%s</figure>', $videoHtml)],
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

        return \in_array($value, self::ALLOWED_PRELOAD, true) ? $value : 'metadata';
    }

    /** @param VideoBlockAttributes $blockAttributes */
    private function buildVideoTag(string $src, array $blockAttributes, string $preload): string
    {
        $htmlAttrs = [];

        foreach (['autoplay', 'loop', 'muted'] as $attr) {
            if (isset($blockAttributes[$attr])) {
                $htmlAttrs[] = $attr;
            }
        }

        $htmlAttrs[] = 'controls';
        $htmlAttrs[] = \sprintf('src="%s"', \esc_url($src));

        if (isset($blockAttributes['poster'])) {
            $htmlAttrs[] = \sprintf('poster="%s"', \esc_url($blockAttributes['poster']));
        }

        if (isset($blockAttributes['playsInline'])) {
            $htmlAttrs[] = 'playsinline';
        }

        $htmlAttrs[] = \sprintf('preload="%s"', \esc_attr($preload));

        return \sprintf('<video %s></video>', \implode(' ', $htmlAttrs));
    }
}
