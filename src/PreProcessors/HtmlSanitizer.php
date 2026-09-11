<?php

declare(strict_types=1);

namespace n5s\BlockConverter\PreProcessors;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer as SymfonySanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use WP_Post;

/**
 * Sanitizes HTML and decodes entities using Symfony HtmlSanitizer.
 *
 * Legacy content often has HTML entities stored in the database
 * (e.g. "&eacute;" instead of "é", "&#39;" instead of "'").
 * The Symfony HtmlSanitizer naturally decodes entities during its
 * parse/serialize cycle while also cleaning up invalid markup.
 *
 * After sanitization, Symfony's paranoid re-encoding (&#34;, &#43;,
 * &#61;, &#64;, &#96;, &#039;) is reversed since these characters are
 * safe inside double-quoted attributes or text content. Structural
 * entities (&amp;, &lt;, &gt;) are kept to preserve valid HTML.
 *
 * The sanitizer config is built from WordPress's $allowedposttags global by
 * default — elements and attributes, plus the CSS filter WordPress applies
 * alongside it, since kses never honours a style attribute on its own. It can
 * be overridden via constructor injection.
 * Use {@see defaultSanitizerConfig()} to get the default config and
 * extend it.
 */
class HtmlSanitizer implements PreProcessorInterface
{
    private ?SymfonySanitizer $sanitizer = null;

    /**
     * Reverses Symfony StringSanitizer::encodeHtmlEntities() extra replacements.
     *
     * Symfony applies that encoding to text nodes and to attribute values alike
     * (TextNode::render() and Node::render()), and this map runs over the
     * serialised document, so it cannot tell the two apart.
     *
     * &#34; is therefore NOT decoded: inside a value it is the only thing
     * keeping a double quote from closing the attribute, and decoding it turns
     * `title='" onfocus=alert(1) autofocus="'` back into a live handler. The
     * rest is inert once the quote stays encoded — a value cannot be escaped
     * with = or ` alone, ' cannot close a double-quoted value, and the
     * full-width characters are not tag delimiters.
     *
     * @var array<string, string>
     */
    private const array DECODE_MAP = [
        '&#039;' => "'",
        '&#43;' => '+',
        '&#61;' => '=',
        '&#64;' => '@',
        '&#96;' => '`',
        '&#xFF1C;' => "\u{FF1C}",
        '&#xFF1E;' => "\u{FF1E}",
        '&#xFF0B;' => "\u{FF0B}",
        '&#xFF1D;' => "\u{FF1D}",
        '&#xFF20;' => "\u{FF20}",
        '&#xFF40;' => "\u{FF40}",
    ];

    public function __construct(
        private readonly ?HtmlSanitizerConfig $config = null,
    ) {
    }

    public function priority(): int
    {
        return 30;
    }

    public function runsBeforeBlockParsing(): bool
    {
        return false;
    }

    public function process(string $html, ?WP_Post $post = null): string
    {
        if ($html === '') {
            return '';
        }

        $sanitized = $this->getSanitizer()->sanitize($html);

        return \strtr($sanitized, self::DECODE_MAP);
    }

    /**
     * Build the default HtmlSanitizerConfig from WordPress's $allowedposttags.
     *
     * Call this to get the default config and extend it:
     *
     *     $config = HtmlSanitizer::defaultSanitizerConfig()
     *         ->allowElement('iframe', ['src', 'width', 'height']);
     *
     *     $sanitizer = new HtmlSanitizer($config);
     */
    public static function defaultSanitizerConfig(): HtmlSanitizerConfig
    {
        /** @var array<string, array<string, true|array<string, bool>>> $allowedposttags */
        global $allowedposttags;

        $config = (new HtmlSanitizerConfig())
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->withMaxInputLength(\PHP_INT_MAX);

        /** @var array<string, true|array<string, bool>> $attributes */
        foreach ($allowedposttags as $tag => $attributes) {
            // kses expands data-* itself; Symfony matches attribute names
            // exactly (DomVisitor: isset($allowedAttributes[$name])), so a
            // literal 'data-*' key would never match a real data-id. Data
            // attributes are dropped rather than pretended to be kept — core
            // blocks would not carry them through the editor either.
            /** @var list<string> $attrNames */
            $attrNames = \array_keys(\array_filter(
                $attributes,
                static fn (string $name): bool => !str_contains($name, '*'),
                \ARRAY_FILTER_USE_KEY,
            ));

            $config = $config->allowElement($tag, $attrNames);
        }

        // $allowedposttags has no <source>, so legacy <audio>/<video> markup
        // that carries its URL in child sources rather than a src attribute
        // would lose it here and the media block could not be built at all.
        // The element is inert on its own and holds no script vector.
        $config = $config->allowElement('source', ['src', 'type', 'srcset', 'sizes', 'media']);

        // Nor <param>, which is only ever a name/value pair inside <object>
        // and holds no vector of its own. Flash-era embeds carry their URL
        // there (<param name="movie">), and ObjectConverter reads it. <embed>
        // itself stays out, as in kses: allow it explicitly if the corpus
        // needs it.
        $config = $config->allowElement('param', ['name', 'value']);

        // $allowedposttags allows style, but WordPress only ever honours that
        // alongside safecss_filter_attr(). Without it the configuration is
        // strictly weaker than the kses it mirrors.
        return $config->withAttributeSanitizer(new SafeCssAttributeSanitizer());
    }

    private function getSanitizer(): SymfonySanitizer
    {
        return $this->sanitizer ??= new SymfonySanitizer(
            $this->config ?? self::defaultSanitizerConfig(),
        );
    }
}
