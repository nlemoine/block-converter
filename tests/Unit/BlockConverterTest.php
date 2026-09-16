<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Unit;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\BlockConverter;
use n5s\BlockConverter\PostProcessors\PostProcessorInterface;
use n5s\BlockConverter\PreProcessors\PreProcessorInterface;
use n5s\BlockConverter\Tests\TestCase;
use Psr\Log\AbstractLogger;
use voku\helper\HtmlDomParser;
use voku\helper\SimpleHtmlDomInterface;
use WP_Post;

final class BlockConverterTest extends TestCase
{
    private BlockConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = self::createDefaultConverter();
    }

    public function testConvertsParagraph(): void
    {
        $result = $this->converter->convert('<p>Hello world</p>');
        $this->assertStringContainsString('wp:paragraph', $result);
        $this->assertStringContainsString('Hello world', $result);
    }

    public function testConvertsHeading(): void
    {
        $result = $this->converter->convert('<h2>My Title</h2>');
        $this->assertStringContainsString('<!-- wp:heading -->', $result);
        $this->assertStringContainsString('wp-block-heading', $result);
        $this->assertStringContainsString('My Title', $result);
    }

    public function testConvertsMultipleElements(): void
    {
        $result = $this->converter->convert('<p>Paragraph</p><h1>Heading</h1>');
        $this->assertStringContainsString('wp:paragraph', $result);
        $this->assertStringContainsString('wp:heading', $result);
    }

    public function testConvertsSeparator(): void
    {
        $result = $this->converter->convert('<hr>');
        $this->assertStringContainsString('wp:separator', $result);
    }

    public function testConvertsBlockquoteWithInnerBlocks(): void
    {
        $result = $this->converter->convert('<blockquote><p>A quote</p></blockquote>');
        $this->assertStringContainsString('wp:quote', $result);
        $this->assertStringContainsString('wp:paragraph', $result);
        $this->assertStringContainsString('wp-block-quote', $result);
    }

    public function testBareTextBecomeParagraph(): void
    {
        $result = $this->converter->convert('just text');
        $this->assertStringContainsString('wp:paragraph', $result);
        $this->assertStringContainsString('just text', $result);
    }

    public function testUnknownTagsBecomeHtmlBlocks(): void
    {
        $result = $this->converter->convert('<div>Custom content</div>');
        $this->assertStringContainsString('wp:html', $result);
    }

    public function testEmptyHtmlReturnsEmptyString(): void
    {
        $this->assertSame('', $this->converter->convert(''));
    }

    public function testImageStandalone(): void
    {
        $result = $this->converter->convert('<img src="https://example.com/image.jpg" alt="test">');
        $this->assertStringContainsString('wp:image', $result);
        $this->assertStringContainsString('wp-block-image', $result);
    }

    public function testTableConversion(): void
    {
        $result = $this->converter->convert('<table><tr><td>Cell</td></tr></table>');
        $this->assertStringContainsString('wp:table', $result);
        $this->assertStringContainsString('wp-block-table', $result);
    }

    public function testSkippedTagsProduceNoOutput(): void
    {
        $result = $this->converter->convert('<br>');
        $this->assertSame('', $result);
    }

    public function testMixedContentPreservesExistingBlocks(): void
    {
        $input = "<!-- wp:paragraph -->\n<p>Already a block</p>\n<!-- /wp:paragraph -->\n\n<p>Raw HTML</p>";
        $result = $this->converter->convert($input);

        $this->assertStringContainsString('Already a block', $result);
        $this->assertStringContainsString('Raw HTML', $result);
        // Should have two wp:paragraph blocks
        $this->assertSame(2, \substr_count($result, '<!-- wp:paragraph -->'));
    }

    public function testMixedContentDoesNotDoubleConvertBlocks(): void
    {
        $input = "<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">Existing</h3>\n<!-- /wp:heading -->";
        $result = $this->converter->convert($input);

        // Should appear exactly once (opening comment), not nested/doubled
        $this->assertSame(1, \substr_count($result, '<!-- wp:heading'));
        $this->assertStringContainsString('Existing', $result);
    }

    public function testExistingEmbedBlockIsNotDuplicated(): void
    {
        $input = <<<'HTML'
<!-- wp:paragraph -->
<p>Some text</p>
<!-- /wp:paragraph -->

<!-- wp:embed {"url":"https://www.youtube.com/watch?v=xY1zA_bCdEf","type":"video","providerNameSlug":"youtube","responsive":true,"className":"wp-embed-aspect-16-9 wp-has-aspect-ratio"} -->
<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">
https://www.youtube.com/watch?v=xY1zA_bCdEf
</div></figure>
<!-- /wp:embed -->

<!-- wp:paragraph -->
<p>More text</p>
<!-- /wp:paragraph -->
HTML;

        $result = $this->converter->convert($input);

        // Embed should appear exactly once, not duplicated.
        $this->assertSame(1, \substr_count($result, '<!-- wp:embed'));
        $this->assertStringContainsString('xY1zA_bCdEf', $result);
    }

    public function testPreProcessorIsCalledBeforeConversion(): void
    {
        $registry = $this->converter->getRegistry();
        $registry->registerPreProcessor(new class implements PreProcessorInterface {
            public function priority(): int
            {
                return 10;
            }

            public function runsBeforeBlockParsing(): bool
            {
                return false;
            }

            public function process(string $html, ?WP_Post $post = null): string
            {
                return \str_replace('REPLACE_ME', 'Replaced', $html);
            }
        });

        $result = $this->converter->convert('<p>REPLACE_ME</p>');
        $this->assertStringContainsString('Replaced', $result);
        $this->assertStringNotContainsString('REPLACE_ME', $result);
    }

    public function testParagraphWithImageUnwraps(): void
    {
        $result = $this->converter->convert('<p><img src="https://example.com/photo.jpg" alt="test"></p>');
        $this->assertStringContainsString('wp:image', $result);
        $this->assertStringNotContainsString('wp:paragraph', $result);
    }

    public function testParagraphWithOnlyInlineContentStaysParagraph(): void
    {
        $result = $this->converter->convert('<p>Hello <strong>world</strong> and <em>more</em></p>');
        $this->assertStringContainsString('wp:paragraph', $result);
        $this->assertStringNotContainsString('wp:html', $result);
        $this->assertStringContainsString('Hello <strong>world</strong> and <em>more</em>', $result);
    }

    public function testParagraphWithNestedImgInLinkUnwraps(): void
    {
        $result = $this->converter->convert('<p><a href="https://example.com/photo.jpg"><img src="https://example.com/photo.jpg" alt="test"></a></p>');
        $this->assertStringContainsString('wp:image', $result);
        $this->assertStringNotContainsString('wp:paragraph', $result);
    }

    public function testParagraphWithImageAndTextUnwrapsToMultipleBlocks(): void
    {
        $result = $this->converter->convert('<p>Before text <img src="https://example.com/photo.jpg" alt="test"> after text</p>');
        $this->assertStringContainsString('wp:image', $result);
        $this->assertStringContainsString('wp:paragraph', $result);
        $this->assertStringContainsString('Before text', $result);
        $this->assertStringContainsString('after text', $result);
    }

    public function testUnwrappedParagraphKeepsWhitespaceAroundInlineElements(): void
    {
        // Stringifying a #text child went through html(), whose output is
        // trimmed, so the spaces on either side of an inline element vanished:
        // "Voici, <strong>bravo</strong> à" came out as "Voici,<strong>bravo</strong>à".
        $result = $this->converter->convert('<p>Voici, <strong>bravo</strong> à <a href="#">Jon</a>. <img src="https://example.com/a.jpg"></p>');

        $this->assertStringContainsString('<p>Voici, <strong>bravo</strong> à <a href="#">Jon</a>.</p>', $result);
    }

    public function testParagraphWithObjectUnwraps(): void
    {
        $result = $this->converter->convert('<p><object data="https://example.com/flash.swf" type="application/x-shockwave-flash"></object></p>');

        // Asserting only the absence of wp:paragraph was green on an empty
        // result while the object was being deleted outright.
        $this->assertStringNotContainsString('wp:paragraph', $result);
        $this->assertSame(['core/html'], $this->blockNames($result));
        $this->assertStringContainsString('<object data="https://example.com/flash.swf"', $result);
    }

    public function testParagraphWithLinkWrappedImageUnwraps(): void
    {
        $result = $this->converter->convert('<p><a href="https://example.com"><img src="https://example.com/photo.jpg" alt=""></a></p>');
        $this->assertStringContainsString('wp:image', $result);
        $this->assertStringNotContainsString('wp:paragraph', $result);
    }

    public function testParagraphWithDeepNestedImgUnwraps(): void
    {
        $result = $this->converter->convert('<p><span><a href="https://example.com"><img src="https://example.com/photo.jpg" alt=""></a></span></p>');
        $this->assertStringNotContainsString('wp:paragraph', $result);
    }

    public function testParagraphWithOnlyTextAndLinksStaysParagraph(): void
    {
        $result = $this->converter->convert('<p>Visit <a href="https://example.com">our site</a> for more.</p>');
        $this->assertStringContainsString('wp:paragraph', $result);
        $this->assertStringContainsString('Visit <a href="https://example.com">our site</a> for more.', $result);
    }

    public function testEmptyParagraphReturnsNull(): void
    {
        $result = $this->converter->convert('<p></p>');
        $this->assertSame('', $result);
    }

    public function testImageInParagraphFixtureStillWorks(): void
    {
        $result = $this->converter->convert('<p><img src="https://example.com/img.jpg" alt="test"></p>');
        $this->assertStringContainsString('wp:image', $result);
        $this->assertStringNotContainsString('wp:paragraph', $result);
    }

    public function testParagraphWithTextAlignCenterGetsAlignAttribute(): void
    {
        $result = $this->converter->convert('<p style="text-align: center">Centered text</p>');
        $this->assertStringContainsString('"align":"center"', $result);
        $this->assertStringContainsString('has-text-align-center', $result);
    }

    public function testParagraphWithTextAlignLeftGetsNoAttribute(): void
    {
        $result = $this->converter->convert('<p style="text-align: left">Left text</p>');
        $this->assertStringNotContainsString('"align"', $result);
    }

    public function testHeadingWithTextAlignCenterGetsTextAlignAttribute(): void
    {
        $result = $this->converter->convert('<h2 style="text-align: center">Centered Heading</h2>');
        $this->assertStringContainsString('"textAlign":"center"', $result);
        $this->assertStringContainsString('has-text-align-center', $result);
        $this->assertStringContainsString('wp-block-heading', $result);
    }

    public function testBlockquoteWithTextAlignCenterGetsTextAlignAttribute(): void
    {
        $result = $this->converter->convert('<blockquote style="text-align: center"><p>A quote</p></blockquote>');
        $this->assertStringContainsString('"textAlign":"center"', $result);
        $this->assertStringContainsString('has-text-align-center', $result);
        $this->assertStringContainsString('wp-block-quote', $result);
    }

    public function testTableWithAlignedCellsGetsDataAlign(): void
    {
        $result = $this->converter->convert('<table><tr><td style="text-align: center">Cell</td></tr></table>');
        $this->assertStringContainsString('wp:table', $result);
        $this->assertStringContainsString('data-align="center"', $result);
    }

    public function testContainerInnerContentHasNewlineSeparatorsBetweenBlocks(): void
    {
        // A blockquote with multiple <p> children should produce "\n\n"
        // separators between null placeholders, matching parse_blocks() output.
        $blocks = $this->converter->convertToBlocks('<blockquote><p>First</p><p>Second</p><p>Third</p></blockquote>');

        $this->assertCount(1, $blocks);
        $quote = $blocks[0];
        $this->assertSame('quote', $quote->blockName);
        $this->assertCount(3, $quote->innerBlocks);

        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- shows the array shape being asserted.
        // innerContent should be: [opening, null, "\n\n", null, "\n\n", null, closing]
        $nullCount = 0;
        foreach ($quote->innerContent as $i => $chunk) {
            if ($chunk === null) {
                $nullCount++;
                // Every non-first null should be preceded by a "\n\n" string
                if ($nullCount > 1) {
                    $this->assertSame("\n\n", $quote->innerContent[$i - 1], "Expected \\n\\n separator before null placeholder #{$nullCount}");
                }
            }
        }

        $this->assertSame(3, $nullCount, 'Expected 3 null placeholders for 3 inner blocks');
    }

    public function testContainerInnerContentHasSeparatorsBetweenUnwrappedBlocks(): void
    {
        // A blockquote containing a <p> with block-level content (img + text)
        // triggers unwrapParagraph, returning multiple blocks. Consecutive nulls
        // from these must be separated by "\n\n".
        $blocks = $this->converter->convertToBlocks(
            '<blockquote><p>Text before <img src="https://example.com/img.jpg" alt=""> text after</p></blockquote>'
        );

        $this->assertCount(1, $blocks);
        $quote = $blocks[0];
        $this->assertGreaterThanOrEqual(2, \count($quote->innerBlocks), 'unwrapParagraph should produce multiple inner blocks');

        // Verify no two consecutive nulls exist (each pair must have "\n\n" between them)
        $prev = 'string';
        foreach ($quote->innerContent as $chunk) {
            if ($chunk === null && $prev === null) {
                $this->fail('Found consecutive null entries in innerContent without a separator');
            }
            $prev = $chunk;
        }

        // Verify all string entries between nulls are "\n\n"
        for ($i = 1; $i < \count($quote->innerContent) - 1; $i++) {
            if (
                \is_string($quote->innerContent[$i])
                && ($quote->innerContent[$i - 1] === null || $quote->innerContent[$i + 1] === null)
                && $quote->innerContent[$i - 1] === null && $quote->innerContent[$i + 1] === null
            ) {
                $this->assertSame("\n\n", $quote->innerContent[$i], "Separator between nulls at index {$i} should be \\n\\n");
            }
        }
    }

    public function testPostProcessorMutatesBlocks(): void
    {
        $registry = $this->converter->getRegistry();
        $registry->registerPostProcessor(new class implements PostProcessorInterface {
            public function priority(): int
            {
                return 10;
            }

            /**
             * @param  list<Block> $blocks
             * @return list<Block>
             */
            public function process(array $blocks, ?WP_Post $post = null): array
            {
                foreach ($blocks as $block) {
                    if ($block->blockName === 'paragraph') {
                        $block->attributes['custom'] = true;
                    }
                }

                return $blocks;
            }
        });

        $result = $this->converter->convert('<p>Hello</p>');
        $this->assertStringContainsString('"custom":true', $result);
    }

    public function testEmWithImageUnwrapsToImageBlock(): void
    {
        $result = $this->converter->convert('<em><img src="photo.jpg"></em>');
        $this->assertStringContainsString('wp:image', $result);
        $this->assertStringNotContainsString('wp:html', $result);
    }

    public function testStrongWithImageAndTextUnwrapsToMultipleBlocks(): void
    {
        $result = $this->converter->convert('<strong><img src="photo.jpg"> Question</strong>');
        $this->assertStringContainsString('wp:image', $result);
        $this->assertStringContainsString('wp:paragraph', $result);
        $this->assertStringContainsString('Question', $result);
    }

    public function testSpanWithImageUnwrapsToImageBlock(): void
    {
        $result = $this->converter->convert('<span id="x"><img src="photo.jpg"></span>');
        $this->assertStringContainsString('wp:image', $result);
        $this->assertStringNotContainsString('wp:html', $result);
    }

    public function testEmWithLinkWrappedImageUnwraps(): void
    {
        $result = $this->converter->convert('<em><a href="https://example.com"><img src="photo.jpg"></a></em>');
        $this->assertStringContainsString('wp:image', $result);
        $this->assertStringNotContainsString('wp:html', $result);
    }

    public function testStrongWithOnlyTextStaysParagraph(): void
    {
        $result = $this->converter->convert('<strong>just text</strong>');
        $this->assertStringNotContainsString('wp:image', $result);
        // Should not produce wp:html — inline-only content wraps in paragraph
        $this->assertStringNotContainsString('wp:html', $result);
    }

    public function testUnknownTagsAreLogged(): void
    {
        $records = [];
        $logger = new class ($records) extends AbstractLogger {
            /** @param list<array{level: mixed, message: string, context: array<string, mixed>}> $records */
            public function __construct(private array &$records)
            {
            }

            public function log(mixed $level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $converter = new BlockConverter(BlockConverter::createDefault()->getRegistry(), $logger);
        $converter->convert('<article>Balise sans convertisseur</article>');

        $this->assertCount(1, $records);
        $this->assertSame('warning', $records[0]['level']);
        $this->assertSame('No converter for tag, using HTML fallback', $records[0]['message']);
        $this->assertSame(['tag' => 'article'], $records[0]['context']);
    }

    public function testNothingIsLoggedWithoutALogger(): void
    {
        // The null-safe call must stay null-safe.
        $converter = new BlockConverter(BlockConverter::createDefault()->getRegistry());

        $this->assertStringContainsString('wp:html', $converter->convert('<article>Sans logger</article>'));
    }

    public function testConvertNodeDeclinesTextNodes(): void
    {
        // convertNode() is public, so a caller can hand it anything the parser
        // produced — including the text nodes the internal walk filters out.
        $dom = HtmlDomParser::str_get_html('<body><p>Du texte</p></body>');
        $paragraph = $dom->findOne('p');

        $textNode = null;

        foreach ($paragraph->childNodes() as $child) {
            if ($child->getTag() === '#text') {
                $textNode = $child;
            }
        }

        $this->assertInstanceOf(SimpleHtmlDomInterface::class, $textNode, 'Expected the paragraph to hold a text node.');
        $this->assertNull($this->converter->convertNode($textNode));
    }

    /**
     * parse_blocks() answers an opener nothing closes by absorbing the rest
     * of the document as that block, and a closer nothing opened by turning
     * the rest of the document into raw HTML. One stray comment used to
     * no-op the conversion of everything after it, silently.
     */
    public function testAnUnmatchedOpenerDoesNotSwallowTheRestOfThePost(): void
    {
        $result = BlockConverter::createDefault()->convert(
            "<p>before</p>\n<!-- wp:paragraph -->\n<p>orphan</p>\n<h2>after</h2>",
        );

        $this->assertSame(['core/paragraph', 'core/paragraph', 'core/heading'], $this->blockNames($result));
        $this->assertStringNotContainsString("<!-- wp:paragraph -->\n<p>orphan</p>\n<h2>", $result);
    }

    /** @return list<string> */
    private function blockNames(string $markup): array
    {
        $names = [];

        foreach (\parse_blocks($markup) as $block) {
            if ($block['blockName'] !== null) {
                $names[] = $block['blockName'];
            }
        }

        return $names;
    }

    public function testAnUnmatchedCloserDoesNotTurnTheRestOfThePostIntoRawHtml(): void
    {
        $result = BlockConverter::createDefault()->convert(
            "<p>before</p>\n<!-- /wp:paragraph -->\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">kept</h2>\n<!-- /wp:heading -->",
        );

        $this->assertSame(['core/paragraph', 'core/heading'], $this->blockNames($result));
        $this->assertStringContainsString("<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">kept</h2>\n<!-- /wp:heading -->", $result);
    }

    public function testUnmatchedDelimitersAreLogged(): void
    {
        $records = [];
        $logger = new class ($records) extends AbstractLogger {
            /** @param list<array{level: mixed, message: string, context: array<string, mixed>}> $records */
            public function __construct(private array &$records)
            {
            }

            public function log(mixed $level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $converter = new BlockConverter(BlockConverter::createDefault()->getRegistry(), $logger);
        $converter->convert("<!-- /wp:image -->\n<p>a</p>\n<!--  wp:paragraph -->");

        $this->assertSame(['warning', 'warning'], \array_column($records, 'level'));
        $this->assertSame('Unmatched block delimiter dropped', $records[0]['message']);
        // The parser's own tokenizer decides what a delimiter is: two spaces
        // after the comment opener are one, whatever str_contains() thinks.
        $this->assertSame([['block' => 'core/paragraph', 'delimiter' => '<!--  wp:paragraph -->'], ['block' => 'core/image', 'delimiter' => '<!-- /wp:image -->']], \array_column($records, 'context'));
    }

    public function testACloserClosesWhateverIsOpenAsTheParserDoes(): void
    {
        // WP_Block_Parser does not compare names, so neither does the guard:
        // this pair is balanced and parse_blocks() reads it as one block.
        $result = BlockConverter::createDefault()->convert("<!-- wp:paragraph -->\n<p>a</p>\n<!-- /wp:image -->");

        $this->assertSame(['core/paragraph'], $this->blockNames($result));
        $this->assertStringContainsString('<p>a</p>', $result);
    }

    public function testNestedBlocksAreNotMistakenForUnmatchedDelimiters(): void
    {
        $input = "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><!-- wp:paragraph -->\n<p>a</p>\n<!-- /wp:paragraph --></blockquote>\n<!-- /wp:quote -->";

        $this->assertSame($input, BlockConverter::createDefault()->convert($input));
    }

    /**
     * libxml stops building the tree 256 elements deep and voku hides the
     * error, so everything past that point used to vanish from the output
     * while the conversion reported success.
     */
    public function testDeeplyNestedMarkupIsNotTruncated(): void
    {
        $converter = BlockConverter::createDefault();

        foreach ([256, 3000] as $depth) {
            $result = $converter->convert('<p>' . \str_repeat('<span>', $depth) . 'IMPORTANT' . \str_repeat('</span>', $depth) . '</p>');

            $this->assertStringContainsString('IMPORTANT', $result, "depth {$depth}");
        }

        $result = $converter->convert(\str_repeat('<div>', 300) . '<p>Article body</p><img src="/photo.jpg">' . \str_repeat('</div>', 300));

        $this->assertStringContainsString('Article body', $result);
        $this->assertStringContainsString('photo.jpg', $result);
    }
}
