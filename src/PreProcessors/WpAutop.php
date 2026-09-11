<?php

declare(strict_types=1);

namespace n5s\BlockConverter\PreProcessors;

use WP_Post;

/**
 * Applies WordPress's wpautop() to wrap loose text in <p> tags
 * and convert newlines to <br>, then shortcode_unautop() to
 * unwrap shortcodes that were incorrectly wrapped.
 *
 * Mirrors the WordPress the_content filter chain order:
 * wpautop → shortcode_unautop, both before do_shortcode.
 */
class WpAutop implements PreProcessorInterface
{
    public function priority(): int
    {
        return 40;
    }

    public function runsBeforeBlockParsing(): bool
    {
        return false;
    }

    public function process(string $html, ?WP_Post $post = null): string
    {
        if ($html === '') {
            return '';
        }

        $html = \wpautop($html);

        return \shortcode_unautop($html);
    }
}
