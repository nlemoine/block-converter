<?php

declare(strict_types=1);

namespace n5s\BlockConverter\ShortcodeConverters;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\Support\ShortcodeBlockBuilder;

abstract class AbstractShortcodeConverter implements ShortcodeConverterInterface
{
    /**
     * Preserve the shortcode as a core/shortcode block, for content the
     * converter cannot turn into its target block.
     *
     * @param array<int|string, string> $atts
     */
    protected function fallback(string $tag, array $atts = [], ?string $content = null): Block
    {
        return ShortcodeBlockBuilder::build($tag, $atts, $content);
    }
}
