<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Support;

use n5s\BlockConverter\Block;

/**
 * Rebuilds a shortcode as text and wraps it in a core/shortcode block.
 *
 * The one place this happens. ShortcodeProcessor and the converters' own
 * fallback each had a builder, and the two drifted on the lines that matter:
 * one guarded the content with is_string() and appended a closing tag to
 * every self-closing shortcode, because WordPress hands a handler '' rather
 * than null when there is no content; the other had no branch for a bare
 * attribute and rebuilt [gallery link] as 0="link".
 */
final class ShortcodeBlockBuilder
{
    /**
     * @param array<int|string, string> $atts As shortcode_parse_atts() returns
     *                                        them: an integer key is a bare
     *                                        attribute such as [gallery link].
     */
    public static function build(string $tag, array $atts, ?string $content): Block
    {
        $shortcode = '[' . $tag;

        foreach ($atts as $key => $value) {
            $shortcode .= \is_int($key) ? ' ' . $value : ' ' . $key . '=' . self::quote($value);
        }

        $shortcode .= ']';

        // WordPress passes '' for a self-closing shortcode, not null. An
        // enclosing shortcode with nothing inside comes back self-closing,
        // which do_shortcode() reads the same way.
        if ($content !== null && $content !== '') {
            $shortcode .= $content . '[/' . $tag . ']';
        }

        return new Block('shortcode', innerContent: [HtmlUtils::neutraliseBlockDelimiters($shortcode)]);
    }

    /**
     * Shortcode syntax has no escape for a quote inside a value: the closing
     * quote is simply the next one of the same kind. A value holding a double
     * quote goes in single quotes, as it must have been written to parse at
     * all. One holding both cannot be written back — the double quotes are
     * encoded, which is the closest text do_shortcode() will still read as
     * one value.
     */
    private static function quote(string $value): string
    {
        if (!\str_contains($value, '"')) {
            return '"' . $value . '"';
        }

        if (!\str_contains($value, "'")) {
            return "'" . $value . "'";
        }

        return '"' . \str_replace('"', '&quot;', $value) . '"';
    }
}
