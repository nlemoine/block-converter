<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests;

use Mantle\Testkit\TestCase as MantleTestCase;
use n5s\BlockConverter\Tests\Support\AssertsHtml;

/**
 * Base test case for tests that need WordPress database access (factories, WP functions).
 *
 * Use TestCase for pure unit tests, WpTestCase for integration tests needing WP.
 */
abstract class WpTestCase extends MantleTestCase
{
    use AssertsHtml;

    protected function fixturesPath(): string
    {
        return __DIR__ . '/fixtures';
    }
}
