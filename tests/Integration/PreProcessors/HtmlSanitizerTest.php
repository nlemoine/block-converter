<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Integration\PreProcessors;

use n5s\BlockConverter\PreProcessors\HtmlSanitizer;
use n5s\BlockConverter\PreProcessors\PreProcessorInterface;
use n5s\BlockConverter\Tests\WpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class HtmlSanitizerTest extends WpTestCase
{
    private HtmlSanitizer $decoder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->decoder = new HtmlSanitizer();
    }

    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(PreProcessorInterface::class, $this->decoder);
    }

    public function testPriorityRunsAfterUtf8Fixer(): void
    {
        $this->assertSame(30, $this->decoder->priority());
    }

    public function testReturnsEmptyStringForEmptyInput(): void
    {
        $this->assertSame('', $this->decoder->process(''));
    }

    public function testDecodesNamedEntitiesInText(): void
    {
        $result = $this->decoder->process(
            '<p>Les cr&ecirc;pes au caf&eacute; sont d&eacute;licieuses</p>'
        );
        $this->assertStringContainsString('crêpes au café sont délicieuses', $result);
        $this->assertStringNotContainsString('&eacute;', $result);
    }

    public function testDecodesNumericSingleQuoteEntity(): void
    {
        $result = $this->decoder->process('<p>l&#39;ap&eacute;ro</p>');
        $this->assertStringContainsString("l'apéro", $result);
    }

    public function testDecodesSpecialFrenchCharacters(): void
    {
        $result = $this->decoder->process(
            '<p>&oelig;ufs brouill&eacute;s, gar&ccedil;on, &agrave; No&euml;l</p>'
        );
        $this->assertStringContainsString('œufs brouillés', $result);
        $this->assertStringContainsString('garçon', $result);
        $this->assertStringContainsString('à Noël', $result);
    }

    public function testPreservesQuotesInAttributes(): void
    {
        $result = $this->decoder->process(
            '<img alt="cr&ecirc;pe &quot;Suzette&quot;" src="http://example.com/img.jpg" />'
        );
        // The sanitizer must not break the attribute boundary
        $this->assertStringContainsString('alt=', $result);
        $this->assertStringContainsString('src=', $result);
    }

    public function testPreservesAllowedTags(): void
    {
        $result = $this->decoder->process(
            '<p>La <strong>baguette</strong> est <em>croustillante</em></p>'
        );
        $this->assertStringContainsString('<p>', $result);
        $this->assertStringContainsString('<strong>', $result);
        $this->assertStringContainsString('<em>', $result);
    }

    public function testPreservesLinks(): void
    {
        $result = $this->decoder->process(
            '<p>Voir <a href="http://example.com">la recette</a></p>'
        );
        $this->assertStringContainsString('<a href="http://example.com">', $result);
    }

    public function testPreservesImages(): void
    {
        $result = $this->decoder->process(
            '<p><img src="http://example.com/croissant.jpg" alt="croissant" /></p>'
        );
        $this->assertStringContainsString('src="http://example.com/croissant.jpg"', $result);
        $this->assertStringContainsString('alt="croissant"', $result);
    }

    public function testLeavesCleanUtf8Untouched(): void
    {
        $input = '<p>Héllo wörld! Les œuvres françaises</p>';
        $result = $this->decoder->process($input);
        $this->assertStringContainsString('Héllo wörld!', $result);
        $this->assertStringContainsString('œuvres françaises', $result);
    }

    public function testDecodesEntitiesInRealWorldContent(): void
    {
        $input = '<p>Le march&eacute; de No&euml;l &agrave; Strasbourg propose des '
            . 'sp&eacute;cialit&eacute;s r&eacute;gionales : br&ecirc;dele, '
            . 'bredele au beurre, et d&#39;excellents vins chauds &eacute;pic&eacute;s '
            . 'aux ar&ocirc;mes de cannelle et de girofle.</p>';

        $result = $this->decoder->process($input);

        $this->assertStringContainsString('marché de Noël à Strasbourg', $result);
        $this->assertStringContainsString('spécialités régionales', $result);
        $this->assertStringContainsString('brêdele', $result);
        $this->assertStringContainsString("d'excellents vins chauds épicés", $result);
        $this->assertStringContainsString('arômes', $result);
        $this->assertStringNotContainsString('&eacute;', $result);
        $this->assertStringNotContainsString('&egrave;', $result);
        $this->assertStringNotContainsString('&ocirc;', $result);
        $this->assertStringNotContainsString('&#39;', $result);
        $this->assertStringNotContainsString('&#039;', $result);
    }

    // ---------------------------------------------------------------
    // Security: XSS vectors
    // ---------------------------------------------------------------

    public function testStripsScriptTags(): void
    {
        $result = $this->decoder->process('<p>Hello</p><script>alert("xss")</script>');
        $this->assertStringNotContainsString('<script', $result);
        $this->assertStringNotContainsString('alert(', $result);
    }

    public function testEntityEncodedScriptTagsStayEncoded(): void
    {
        // Entity-encoded <script> must never be decoded into a real tag
        $result = $this->decoder->process(
            '<p>Hello</p>&lt;script&gt;alert("xss")&lt;/script&gt;'
        );
        $this->assertStringNotContainsString('<script', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    public function testDoubleEncodedScriptTagsNeverBecomeRealTags(): void
    {
        // Double-encoded: &amp;lt; → &lt; (stays encoded, never becomes <)
        $result = $this->decoder->process(
            '<p>Hello</p>&amp;lt;script&amp;gt;alert("xss")&amp;lt;/script&amp;gt;'
        );
        $this->assertStringNotContainsString('<script', $result);
    }

    public function testStripsEventHandlerAttributes(): void
    {
        $vectors = [
            '<p onmouseover="alert(1)">hover me</p>',
            '<img src="x" onerror="alert(1)" />',
            '<a href="#" onclick="alert(1)">click</a>',
            '<div onload="alert(1)">loaded</div>',
            '<body onfocus="alert(1)">focus</body>',
        ];

        foreach ($vectors as $vector) {
            $result = $this->decoder->process($vector);
            $this->assertDoesNotMatchRegularExpression(
                '/\bon\w+\s*=/i',
                $result,
                "Event handler survived in: {$vector}"
            );
        }
    }

    public function testStripsEntityEncodedEventHandlers(): void
    {
        // Entities inside attribute names/values
        $result = $this->decoder->process(
            '<p &#111;&#110;mouseover="alert(1)">test</p>'
        );
        $this->assertDoesNotMatchRegularExpression('/\bon\w+\s*=/i', $result);
    }

    public function testStripsJavascriptProtocolInHref(): void
    {
        $vectors = [
            '<a href="javascript:alert(1)">click</a>',
            '<a href="JAVASCRIPT:alert(1)">click</a>',
            '<a href="&#106;&#97;&#118;&#97;&#115;&#99;&#114;&#105;&#112;&#116;:alert(1)">click</a>',
            '<a href="java&#115;cript:alert(1)">click</a>',
            '<a href=" javascript:alert(1)">click</a>',
            '<a href="&#x6A;avascript:alert(1)">click</a>',
        ];

        foreach ($vectors as $vector) {
            $result = $this->decoder->process($vector);
            $this->assertStringNotContainsString(
                'javascript:',
                strtolower($result),
                "javascript: protocol survived in: {$vector}"
            );
        }
    }

    public function testStripsJavascriptProtocolInImageSrc(): void
    {
        $result = $this->decoder->process(
            '<img src="javascript:alert(1)" />'
        );
        $this->assertStringNotContainsString('javascript:', strtolower($result));
    }

    public function testStripsDataProtocolInHref(): void
    {
        $result = $this->decoder->process(
            '<a href="data:text/html,<script>alert(1)</script>">click</a>'
        );
        $this->assertStringNotContainsString('data:', strtolower($result));
    }

    public function testStripsVbscriptProtocol(): void
    {
        $result = $this->decoder->process(
            '<a href="vbscript:MsgBox(1)">click</a>'
        );
        $this->assertStringNotContainsString('vbscript:', strtolower($result));
    }

    // ---------------------------------------------------------------
    // Security: disallowed tags
    // ---------------------------------------------------------------

    public function testStripsIframeTags(): void
    {
        $result = $this->decoder->process(
            '<p>Hello</p><iframe src="http://evil.com"></iframe>'
        );
        $this->assertStringNotContainsString('<iframe', $result);
    }

    public function testStripsEmbedAndAppletTags(): void
    {
        // <embed> and <applet> are not in WP's allowedposttags
        $result = $this->decoder->process('<p>Hello</p><embed src="evil.swf" />');
        $this->assertStringNotContainsString('<embed', $result);

        $result = $this->decoder->process('<p>Hello</p><applet code="Evil.class"></applet>');
        $this->assertStringNotContainsString('<applet', $result);
    }

    public function testStripsFormTags(): void
    {
        $result = $this->decoder->process(
            '<form action="http://evil.com/steal"><input type="text" name="password" /><button>Submit</button></form>'
        );
        $this->assertStringNotContainsString('<form', $result);
        $this->assertStringNotContainsString('<input', $result);
        $this->assertStringNotContainsString('<button', $result);
    }

    public function testStripsBaseTags(): void
    {
        $result = $this->decoder->process(
            '<base href="http://evil.com/" /><p>content</p>'
        );
        $this->assertStringNotContainsString('<base', $result);
    }

    public function testStripsSvgWithInlineScript(): void
    {
        $result = $this->decoder->process(
            '<svg onload="alert(1)"><circle r="40" /></svg>'
        );
        $this->assertStringNotContainsString('<svg', $result);
        $this->assertStringNotContainsString('alert(', $result);
    }

    public function testStripsMathMlXss(): void
    {
        $result = $this->decoder->process(
            '<math><mtext><table><mglyph><style><!--</style><img src=x onerror=alert(1)>--></table></mtext></math>'
        );
        $this->assertStringNotContainsString('onerror', $result);
        $this->assertStringNotContainsString('alert(', $result);
    }

    // ---------------------------------------------------------------
    // Security: style-based attacks
    // ---------------------------------------------------------------

    public function testStripsStyleTagsWithExpressions(): void
    {
        $result = $this->decoder->process(
            '<style>body { background: url("javascript:alert(1)") }</style><p>text</p>'
        );
        $this->assertStringNotContainsString('<style', $result);
        $this->assertStringNotContainsString('javascript:', $result);
    }

    // ---------------------------------------------------------------
    // Security: attribute injection / mutation
    // ---------------------------------------------------------------

    public function testStyleAttributeWithExpressionDoesNotCreateScript(): void
    {
        // expression() was an IE < 8 attack vector, irrelevant in modern browsers.
        // The style attribute is WP-allowed, so it passes through — but it never
        // becomes executable JavaScript in any current browser.
        $result = $this->decoder->process(
            '<p style="width:expression(alert(1))">text</p>'
        );
        // The content stays within the style attribute, no script tag is injected
        $this->assertStringNotContainsString('<script', $result);
        $this->assertDoesNotMatchRegularExpression('/\bon\w+\s*=/i', $result);
    }

    public function testDoesNotDecodeAmpLtGtBackIntoMarkup(): void
    {
        // Structural entities must stay encoded so they don't become real tags
        $input = '<p>Use &lt;strong&gt; for bold and &amp; for ampersand</p>';
        $result = $this->decoder->process($input);
        $this->assertStringContainsString('&lt;strong&gt;', $result);
        $this->assertStringContainsString('&amp;', $result);
    }

    // ---------------------------------------------------------------
    // Security: null bytes and control characters
    // ---------------------------------------------------------------

    public function testStripsNullByteInjection(): void
    {
        // Null byte can confuse parsers: <scr\0ipt>
        $result = $this->decoder->process("<p>Hello</p><scr\x00ipt>alert(1)</script>");
        $this->assertStringNotContainsString('alert(', $result);
    }

    public function testStripsEntityEncodedNullByteInTag(): void
    {
        $result = $this->decoder->process(
            '<p>Hello</p><scr&#0;ipt>alert(1)</script>'
        );
        $this->assertStringNotContainsString('alert(', $result);
    }

    // ---------------------------------------------------------------
    // Security: meta/link/comment injection
    // ---------------------------------------------------------------

    public function testStripsMetaRefresh(): void
    {
        $result = $this->decoder->process(
            '<meta http-equiv="refresh" content="0;url=http://evil.com" /><p>content</p>'
        );
        $this->assertStringNotContainsString('<meta', $result);
        $this->assertStringNotContainsString('evil.com', $result);
    }

    public function testStripLinkTagForStylesheetInjection(): void
    {
        $result = $this->decoder->process(
            // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- the attack vector this test asserts is stripped.
            '<link rel="stylesheet" href="http://evil.com/steal.css" /><p>content</p>'
        );
        $this->assertStringNotContainsString('evil.com', $result);
    }

    // ---------------------------------------------------------------
    // Security: DECODE_MAP does not open attribute-escape holes
    // ---------------------------------------------------------------

    public function testDecodedQuotesDoNotBreakAttributeBoundaries(): void
    {
        // If &#34; is decoded to " inside an attribute, verify it doesn't
        // create a new attribute injection point.
        $result = $this->decoder->process(
            '<a href="http://example.com/page?a=1&#38;b=2" title="a &#34;quoted&#34; title">link</a>'
        );
        // The link should remain well-formed with a single href
        $this->assertStringContainsString('href=', $result);
        // Should not contain any unquoted attribute or event handler injection
        $this->assertDoesNotMatchRegularExpression('/\bon\w+\s*=/i', $result);
    }

    public function testBacktickDecodingDoesNotBreakAttributeBoundary(): void
    {
        // Backticks were historically attribute delimiters in IE.
        // After decoding, the backtick content must remain inside the
        // double-quoted title attribute — not become a separate attribute.
        $result = $this->decoder->process(
            '<p title="test &#96;onmouseover=alert(1)&#96;">text</p>'
        );
        // Verify there's exactly one title attribute, properly quoted
        $this->assertMatchesRegularExpression('/title="[^"]*"/', $result);
        // The "onmouseover" text is trapped inside the attribute value, not parsed as attr
        $this->assertStringNotContainsString('<script', $result);
    }

    // ---------------------------------------------------------------
    // Security: ensure legitimate content survives sanitization
    // ---------------------------------------------------------------

    public function testLegitimateContentWithEntitiesIsPreserved(): void
    {
        $input = '<p>Price: 5 &lt; 10 &amp; tax = 20%. Email: user&#64;example.com</p>';
        $result = $this->decoder->process($input);
        $this->assertStringContainsString('&lt;', $result);
        $this->assertStringContainsString('&amp;', $result);
        $this->assertStringContainsString('user@example.com', $result);
    }

    /**
     * Symfony encodes " and = inside attribute values, and that encoding is the
     * only thing keeping the value from being closed early. The decode map used
     * to reverse it across the whole serialised document, turning a quoted value
     * back into markup. Both characters are needed to escape, which is why the
     * two older tests here could not catch it.
     */
    #[DataProvider('attributeBreakoutPayloads')]
    public function testAttributeValuesCannotBeClosedEarly(string $payload, string $expectedAttribute): void
    {
        $processor = new \WP_HTML_Tag_Processor($this->decoder->process($payload));

        $this->assertTrue($processor->next_tag(), 'Expected the element to survive sanitization.');

        $attributes = $processor->get_attribute_names_with_prefix('') ?? [];

        $this->assertSame([$expectedAttribute], $attributes);
        $this->assertSame(
            [],
            \array_values(\array_filter($attributes, static fn (string $name): bool => \str_starts_with($name, 'on'))),
        );
    }

    /** @return \Iterator<string, array{0: string, 1: string}> */
    public static function attributeBreakoutPayloads(): \Iterator
    {
        yield 'title' => ['<p title=\'" onfocus=alert(1) autofocus="\'>hi</p>', 'title'];
        yield 'class' => ['<p class=\'" onmouseover=alert(1) x="\'>hi</p>', 'class'];
        yield 'id' => ['<p id=\'" onfocus=alert(1) autofocus="\'>hi</p>', 'id'];
        yield 'equals alone' => ['<p title=\'a=b onfocus=alert(1)\'>hi</p>', 'title'];
        yield 'backtick alone' => ['<p title=\'`onfocus=alert(1)`\'>hi</p>', 'title'];
        yield 'apostrophe alone' => ['<p title="it&#039;s onfocus=alert(1)">hi</p>', 'title'];
    }

    public function testTextEntitiesAreStillDecoded(): void
    {
        // The point of this pre-processor: legacy named entities become characters.
        $this->assertSame(
            '<p>café à Château</p>',
            $this->decoder->process('<p>caf&eacute; &agrave; Ch&acirc;teau</p>'),
        );
    }

    public function testDoubleQuotesInTextStayEncoded(): void
    {
        // The trade-off of the fix: a flat string cannot tell text from an
        // attribute value, so the quote stays encoded everywhere. It renders as
        // a quote in the browser and in the editor.
        $this->assertSame(
            '<p>Il a dit &#34;bonjour&#34;</p>',
            $this->decoder->process('<p>Il a dit "bonjour"</p>'),
        );
    }

    // ------------------------------------------------------------------
    //  Inline CSS
    // ------------------------------------------------------------------

    /**
     * $allowedposttags lists style as a global attribute, but WordPress never
     * honours that alone: kses pairs it with safecss_filter_attr(). Mirroring
     * the element and attribute lists without that filter left this config
     * strictly more permissive than the kses it is built from, which matters
     * because converting arbitrary external HTML is a supported use.
     */
    #[DataProvider('dangerousStyles')]
    public function testDangerousDeclarationsAreFilteredOut(string $input, string $mustNotSurvive): void
    {
        $result = $this->decoder->process($input);

        $this->assertStringNotContainsString($mustNotSurvive, $result);
    }

    /** @return \Iterator<string, array{0: string, 1: string}> */
    public static function dangerousStyles(): \Iterator
    {
        yield 'script url' => ['<p style="background:url(javascript:alert(1))">x</p>', 'javascript'];
        yield 'ie behavior' => ['<p style="behavior:url(evil.htc)">x</p>', 'behavior'];
        yield 'ie expression' => ['<p style="width:expression(alert(1))">x</p>', 'expression'];
        yield 'viewport overlay' => [
            '<span style="position:fixed;inset:0;z-index:2147483647">x</span>',
            'inset',
        ];
    }

    #[DataProvider('legitimateStyles')]
    public function testOrdinaryDeclarationsAreKept(string $input, string $mustSurvive): void
    {
        $this->assertStringContainsString($mustSurvive, $this->decoder->process($input));
    }

    /** @return \Iterator<string, array{0: string, 1: string}> */
    public static function legitimateStyles(): \Iterator
    {
        yield 'colour' => ['<p style="color:red;font-size:14px">x</p>', 'color:red'];
        // InlineStyleNormalizer reads these before the sanitizer runs, and would
        // have nothing left to lift if the filter ate them.
        yield 'text align' => ['<p style="text-align:center">x</p>', 'text-align:center'];
        yield 'float' => ['<img style="float:left" src="/a.jpg">', 'float:left'];
    }

    public function testAStyleLeftEmptyByTheFilterIsRemovedEntirely(): void
    {
        $result = $this->decoder->process('<p style="behavior:url(evil.htc)">x</p>');

        $this->assertStringNotContainsString('style', $result);
    }
}
