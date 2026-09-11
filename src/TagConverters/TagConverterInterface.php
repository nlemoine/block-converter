<?php

declare(strict_types=1);

namespace n5s\BlockConverter\TagConverters;

use n5s\BlockConverter\Block;
use voku\helper\SimpleHtmlDomInterface;
use WP_Post;

interface TagConverterInterface
{
    /**
     * HTML tag names this converter handles.
     *
     * @return string[]
     */
    public static function tags(): array;

    /**
     * Convert an HTML element to one or more Gutenberg blocks.
     *
     * @return Block|Block[]|null Null to skip, Block for single, array for multiple.
     */
    public function convert(
        SimpleHtmlDomInterface $element,
        ?WP_Post $post = null,
    ): Block|array|null;
}
