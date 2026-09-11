<?php

declare(strict_types=1);

namespace n5s\BlockConverter\PostProcessors;

use n5s\BlockConverter\Block;
use WP_Post;

/**
 * Post-processor: mutates the Block tree after conversion, before rendering.
 *
 * Lower priority values run first (like WordPress hooks).
 */
interface PostProcessorInterface
{
    /**
     * Priority determines execution order. Lower runs first.
     */
    public function priority(): int;

    /**
     * Return the block list to carry on with — the same one, or a new one.
     *
     * @param  list<Block> $blocks
     * @return list<Block>
     */
    public function process(array $blocks, ?WP_Post $post = null): array;
}
