<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Support;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\TagConverters\ImageConverter;
use voku\helper\SimpleHtmlDomInterface;
use WP_Post;

/**
 * Marks whatever it produces, so an override is visible in the output.
 */
final class MarkingImageConverter extends ImageConverter
{
    public function convert(SimpleHtmlDomInterface $element, ?WP_Post $post = null): Block|array|null
    {
        $block = parent::convert($element, $post);

        if ($block instanceof Block) {
            $block->attributes['marked'] = true;
        }

        return $block;
    }
}
