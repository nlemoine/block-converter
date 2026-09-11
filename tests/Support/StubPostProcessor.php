<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Support;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\PostProcessors\PostProcessorInterface;
use WP_Post;

/**
 * Inert post-processor for registry tests.
 *
 * Extend it anonymously to get a distinct class name, which is what the
 * registry matches on when removing or replacing.
 */
class StubPostProcessor implements PostProcessorInterface
{
    public function __construct(
        private readonly int $priority = 10,
    ) {
    }

    public function priority(): int
    {
        return $this->priority;
    }

    /**
     * @param  list<Block> $blocks
     * @return list<Block>
     */
    public function process(array $blocks, ?WP_Post $post = null): array
    {
        return $blocks;
    }
}
