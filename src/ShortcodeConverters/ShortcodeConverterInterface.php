<?php

declare(strict_types=1);

namespace n5s\BlockConverter\ShortcodeConverters;

use n5s\BlockConverter\Block;
use WP_Post;

interface ShortcodeConverterInterface
{
    /**
     * The shortcode tags this converter handles.
     *
     * @return list<string>
     */
    public static function shortcodes(): array;

    /**
     * Convert shortcode to a Gutenberg block.
     *
     * Return null to remove the shortcode from output entirely. That is a
     * deliberate choice, not a way to decline: a shortcode the converter
     * cannot turn into its block should come back as a core/shortcode block
     * (see AbstractShortcodeConverter::fallback()), which is what every
     * built-in converter does, so do_shortcode() still gets the text and
     * the author still sees it.
     *
     * @param array<string, string> $atts
     */
    public function convert(array $atts, ?string $content, string $tag, ?WP_Post $post = null): ?Block;
}
