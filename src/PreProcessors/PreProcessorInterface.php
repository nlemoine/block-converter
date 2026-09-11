<?php

declare(strict_types=1);

namespace n5s\BlockConverter\PreProcessors;

use WP_Post;

/**
 * Pre-processor: transforms raw HTML string before DOM parsing and block conversion.
 *
 * Lower priority values run first (like WordPress hooks).
 */
interface PreProcessorInterface
{
    /**
     * Priority determines execution order. Lower runs first.
     */
    public function priority(): int;

    /**
     * Whether this processor runs on full post content before parse_blocks().
     *
     * Early processors produce block markup (<!-- wp:… -->) that parse_blocks()
     * must recognise.  Late processors (the default) run on individual raw HTML
     * fragments after parse_blocks() has split the content.
     */
    public function runsBeforeBlockParsing(): bool;

    public function process(string $html, ?WP_Post $post = null): string;
}
