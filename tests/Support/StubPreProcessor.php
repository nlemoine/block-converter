<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Support;

use n5s\BlockConverter\PreProcessors\PreProcessorInterface;
use WP_Post;

/**
 * Inert pre-processor for registry tests.
 *
 * Extend it anonymously to get a distinct class name, which is what the
 * registry matches on when removing or replacing.
 */
class StubPreProcessor implements PreProcessorInterface
{
    public function __construct(
        private readonly int $priority = 10,
        private readonly bool $early = true,
    ) {
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function runsBeforeBlockParsing(): bool
    {
        return $this->early;
    }

    public function process(string $html, ?WP_Post $post = null): string
    {
        return $html;
    }
}
