<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Support;

use voku\helper\HtmlDomParser;
use voku\helper\SimpleHtmlDomInterface;
use WP_HTML_Tag_Processor;

class HtmlUtils
{
    /**
     * Parse an HTML string the way every DOM stage of the pipeline must.
     *
     * libxml stops building the tree 256 elements deep and says nothing the
     * caller can see — voku clears its error list — so the text past that
     * point simply vanished, and 300 nested <div> around an article deleted
     * the article while reporting a successful conversion. LIBXML_PARSEHUGE
     * lifts the limit (verified to 5000 levels); the input's own size is
     * then the only bound, as it already is for every other stage.
     */
    public static function parse(string $html): HtmlDomParser
    {
        return HtmlDomParser::str_get_html($html, \LIBXML_PARSEHUGE);
    }

    /**
     * The tokens of a class attribute.
     *
     * Split on ASCII whitespace, as the HTML spec defines the attribute:
     * `class="alignleft\n  size-full"` is two tokens. There used to be three
     * readings of that in the code base — explode() on a single space,
     * preg_split() on \s+ and a \b in a pattern — and the first saw that
     * attribute as one token matching nothing.
     *
     * @return list<string>
     */
    public static function classTokens(?string $class): array
    {
        if ($class === null || $class === '') {
            return [];
        }

        $tokens = \explode(' ', \str_replace(["\t", "\n", "\f", "\r"], ' ', $class));

        return \array_values(\array_filter($tokens, static fn (string $token): bool => $token !== ''));
    }

    public static function addClass(string $html, string $tag, string $class): string
    {
        $processor = new WP_HTML_Tag_Processor($html);
        $changed = false;

        while ($processor->next_tag(['tag_name' => $tag, 'tag_closers' => 'skip'])) {
            foreach (self::classTokens($class) as $c) {
                $processor->add_class($c);
                $changed = true;
            }
        }

        return $changed ? $processor->get_updated_html() : $html;
    }

    /**
     * Removal goes through the DOM parser rather than WP_HTML_Tag_Processor:
     * the latter edits the source in place and drops the attribute without the
     * space that separated it, so `<img a b c>` minus `b` comes out as
     * `<img a  c>`. The parser re-serialises the tag instead, and leaves
     * quoted values exactly as they were written.
     */
    public static function removeClass(string $html, string $tag, string $class): string
    {
        $remove = self::classTokens($class);

        return self::mapElements($html, $tag, static function (SimpleHtmlDomInterface $element) use ($remove): bool {
            $present = self::classTokens($element->getAttribute('class'));
            $kept = \array_diff($present, $remove);

            if ($kept === $present) {
                return false;
            }

            if ($kept === []) {
                $element->removeAttribute('class');

                return true;
            }

            $element->setAttribute('class', \implode(' ', $kept));

            return true;
        });
    }

    public static function removeAttr(string $html, string $tag, string $attr): string
    {
        return self::mapElements($html, $tag, static function (SimpleHtmlDomInterface $element) use ($attr): bool {
            if (!$element->hasAttribute($attr)) {
                return false;
            }

            $element->removeAttribute($attr);

            return true;
        });
    }

    /**
     * Apply a mutation to every matching element, serialising only if one took.
     *
     * Re-serialising rewrites the whole fragment (`<img />` becomes `<img>`,
     * for one), so markup nothing touched is handed back byte for byte.
     *
     * @param callable(SimpleHtmlDomInterface): bool $mutate returns whether it changed the element
     */
    private static function mapElements(string $html, string $tag, callable $mutate): string
    {
        $dom = self::parse($html);
        $elements = $dom->findMultiOrFalse($tag);

        if ($elements === false) {
            return $html;
        }

        $changed = false;

        foreach ($elements as $element) {
            $changed = $mutate($element) || $changed;
        }

        return $changed ? (string) $dom : $html;
    }

    /**
     * Make text safe to carry inside a block delimiter.
     *
     * Block delimiters are HTML comments, so a comment terminator anywhere in
     * the content closes the block early and everything after it is parsed as
     * markup. Shortcode text passes KSES untouched, so without this a
     * Contributor can smuggle `-->` into a post and have the conversion turn it
     * into a real core/html block.
     *
     * `--!>` is included because a browser's parser accepts it as a terminator
     * even though parse_blocks() only looks for `-->`.
     */
    public static function neutraliseBlockDelimiters(string $text): string
    {
        return \str_replace(['-->', '--!>'], ['--&gt;', '--!&gt;'], $text);
    }

    /**
     * Trim whitespace, including a non-breaking space, from both ends.
     *
     * trim()'s character list is bytes, not characters, so listing the two
     * bytes of U+00A0 also stripped them from any other character built on
     * them: "Voilà" lost the \xA0 tail of its à and came back as invalid
     * UTF-8. mb_trim() works on codepoints, which is what was meant all along.
     */
    public static function trim(string $content): string
    {
        // phpcs:ignore PHPCompatibility.FunctionUse.NewFunctions.mb_trimFound -- provided by symfony/polyfill-php84 below PHP 8.4.
        return \mb_trim($content, " \n\r\t\v\0\u{00A0}");
    }

    /**
     * Extract text alignment value from a class string containing has-text-align-*.
     */
    public static function extractTextAlign(?string $classes): ?string
    {
        foreach (self::classTokens($classes) as $token) {
            if (\str_starts_with($token, 'has-text-align-')) {
                $value = \substr($token, \strlen('has-text-align-'));

                if (\in_array($value, ['left', 'center', 'right', 'justify'], true)) {
                    return $value;
                }
            }
        }

        return null;
    }
}
