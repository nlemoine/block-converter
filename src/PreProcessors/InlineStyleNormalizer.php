<?php

declare(strict_types=1);

namespace n5s\BlockConverter\PreProcessors;

use WP_HTML_Tag_Processor;
use WP_Post;

/**
 * Converts inline text-align styles to Gutenberg alignment classes.
 *
 * Legacy TinyMCE content uses `style="text-align: center"` on elements.
 * Gutenberg expects `has-text-align-{value}` CSS classes instead.
 *
 * Also handles `float: left|right` on `<img>` tags, converting to
 * `alignleft`/`alignright` classes.
 */
class InlineStyleNormalizer implements PreProcessorInterface
{
    private const array ALIGN_VALUES = ['left', 'center', 'right', 'justify'];

    public function priority(): int
    {
        return 6;
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

        $processor = new WP_HTML_Tag_Processor($html);

        while ($processor->next_tag()) {
            $style = $processor->get_attribute('style');

            if (!\is_string($style) || $style === '') {
                continue;
            }

            $tag = \strtolower((string) $processor->get_tag());
            $modified = false;

            // Extract text-align
            $textAlign = $this->extractStyleProperty($style, 'text-align');

            if ($textAlign !== null && \in_array($textAlign, self::ALIGN_VALUES, true)) {
                $class = 'has-text-align-' . $textAlign;
                $this->addClass($processor, $class);
                $style = $this->removeStyleProperty($style, 'text-align');
                $modified = true;
            }

            // Extract float on <img> tags
            if ($tag === 'img') {
                $float = $this->extractStyleProperty($style, 'float');

                if ($float === 'left' || $float === 'right') {
                    $this->addClass($processor, 'align' . $float);
                    $style = $this->removeStyleProperty($style, 'float');
                    $modified = true;
                }
            }

            if (!$modified) {
                continue;
            }

            $style = \trim($style);

            if ($style === '') {
                $processor->remove_attribute('style');

                continue;
            }

            $processor->set_attribute('style', $style);
        }

        return $processor->get_updated_html();
    }

    /**
     * Extract a CSS property value from an inline style string.
     *
     * The last declaration wins, as it does in the cascade: for
     * `text-align:left;text-align:center` the browser centres the text.
     */
    private function extractStyleProperty(string $style, string $property): ?string
    {
        $found = null;

        foreach ($this->declarations($style) as $declaration) {
            if ($this->propertyOf($declaration) === $property) {
                $found = \strtolower(\trim(\substr($declaration, \strpos($declaration, ':') + 1)));
            }
        }

        return $found;
    }

    /**
     * Remove a CSS property from an inline style string.
     *
     * The other declarations are kept as written, not re-serialised: an
     * earlier version rebuilt each one from its parsed name and value, so a
     * fragment it could not parse — anything the split had cut in two —
     * was silently dropped.
     */
    private function removeStyleProperty(string $style, string $property): string
    {
        $kept = [];

        foreach ($this->declarations($style) as $declaration) {
            if ($this->propertyOf($declaration) !== $property) {
                $kept[] = \trim($declaration);
            }
        }

        return \implode('; ', $kept);
    }

    /**
     * Split an inline style into its declarations, as written.
     *
     * A semicolon inside a quoted string or a url() — a data URI, a
     * font-family with a semicolon in its name — is part of the value, so
     * the split walks the text and tracks both instead of cutting on every
     * semicolon.
     *
     * A parenthesis or quote still open at the end of the string means the
     * rule could not be read that way: everything after it would have been
     * folded into one declaration, and `background:url(x;text-align:center`
     * lost its alignment. safecss_filter_attr() cuts on every semicolon and
     * recovers it, so that is what the fallback does too.
     *
     * @return list<string> declarations, whitespace untouched, empty ones dropped
     */
    private function declarations(string $style): array
    {
        $declarations = $this->balancedDeclarations($style) ?? \explode(';', $style);

        return \array_values(\array_filter($declarations, static fn (string $declaration): bool => \trim($declaration) !== ''));
    }

    /**
     * @return list<string>|null null when a parenthesis or quote is left open
     */
    private function balancedDeclarations(string $style): ?array
    {
        $declarations = [];
        $current = '';
        $quote = null;
        $depth = 0;

        foreach (\mb_str_split($style) as $char) {
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')' && $depth > 0) {
                $depth--;
            } elseif ($char === ';' && $depth === 0) {
                $declarations[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        if ($quote !== null || $depth > 0) {
            return null;
        }

        $declarations[] = $current;

        return $declarations;
    }

    /**
     * The property a declaration sets, or null when it has no colon to set
     * one with.
     */
    private function propertyOf(string $declaration): ?string
    {
        $colon = \strpos($declaration, ':');

        return $colon === false ? null : \strtolower(\trim(\substr($declaration, 0, $colon)));
    }

    /**
     * Add a CSS class to the current tag via WP_HTML_Tag_Processor.
     */
    private function addClass(WP_HTML_Tag_Processor $processor, string $class): void
    {
        $processor->add_class($class);
    }
}
