<?php

declare(strict_types=1);

namespace n5s\BlockConverter\PreProcessors;

use voku\helper\UTF8;
use WP_Post;

/**
 * Repairs broken UTF-8 encoding in content.
 *
 * Old content from database migrations or legacy CMS imports
 * often contains mojibake (e.g. "DÃ¼sseldorf" instead of "Düsseldorf")
 * or invalid byte sequences. This processor recovers the original
 * characters using voku/portable-utf8's lookup table of known
 * broken patterns, then strips any remaining invalid bytes.
 *
 * Opt-in: createDefault() does not register this processor. Install
 * voku/portable-utf8 and register it yourself when your content needs it.
 */
class Utf8Fixer implements PreProcessorInterface
{
    public function __construct()
    {
        // Unreachable while the suggested package is installed, which it is
        // for the test suite. Covered instead by removing the package.
        // @codeCoverageIgnoreStart
        if (!\class_exists(UTF8::class)) {
            throw new \LogicException(
                'To repair broken UTF-8 encoding you must install the voku/portable-utf8 package. '
                . 'Try running "composer require voku/portable-utf8".',
            );
        }
        // @codeCoverageIgnoreEnd
    }

    public function priority(): int
    {
        return 1;
    }

    public function runsBeforeBlockParsing(): bool
    {
        return true;
    }

    public function process(string $html, ?WP_Post $post = null): string
    {
        if ($html === '') {
            return '';
        }

        // Recover mojibake (ISO-8859-1 misencoded as UTF-8)
        $html = UTF8::fix_simple_utf8($html);

        // Strip any remaining invalid byte sequences
        return UTF8::clean($html);
    }
}
