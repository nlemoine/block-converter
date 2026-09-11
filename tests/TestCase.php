<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests;

use n5s\BlockConverter\BlockConverter;
use n5s\BlockConverter\Tests\Support\AssertsHtml;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use AssertsHtml;

    protected function fixturesPath(): string
    {
        return __DIR__ . '/fixtures';
    }

    /**
     * Build a data provider from paired fixture files in a directory.
     *
     * Scans for *.input.html files and pairs them with *.expected.html.
     * Each dataset is keyed by the fixture name (filename without extension).
     *
     * @return \Generator<string, array{string, string}>
     */
    protected static function fixtureProvider(string $subdir): \Generator
    {
        $dir = __DIR__ . '/fixtures/' . $subdir;
        $iterator = new \DirectoryIterator($dir);

        foreach ($iterator as $file) {
            if ($file->isDot() || $file->getExtension() !== 'html') {
                continue;
            }

            $filename = $file->getFilename();

            if (!\str_ends_with($filename, '.input.html')) {
                continue;
            }

            $name = \substr($filename, 0, -11); // strip '.input.html'
            $expectedFile = $dir . '/' . $name . '.expected.html';

            if (!\file_exists($expectedFile)) {
                continue;
            }

            yield $name => [
                \rtrim(\file_get_contents($file->getPathname())),
                \file_get_contents($expectedFile),
            ];
        }
    }

    protected static function fixtureProviderWip(string $subdir): \Generator
    {
        $dir = __DIR__ . '/fixtures/' . $subdir;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'html') {
                continue;
            }

            // Build a key like "shortcodes/gallery.html" from the relative path
            $relative = \ltrim(\substr($file->getPathname(), \strlen($dir)), '/');

            yield $relative => [
                \rtrim(\file_get_contents($file->getPathname())),
                '',
            ];
        }
    }

    protected static function createDefaultConverter(): BlockConverter
    {
        return BlockConverter::createDefault();
    }
}
