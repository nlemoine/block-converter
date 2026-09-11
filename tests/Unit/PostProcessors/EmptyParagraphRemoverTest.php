<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Unit\PostProcessors;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\PostProcessors\EmptyParagraphRemover;
use n5s\BlockConverter\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class EmptyParagraphRemoverTest extends TestCase
{
    private EmptyParagraphRemover $processor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new EmptyParagraphRemover();
    }

    public function testPriority(): void
    {
        $this->assertSame(10, $this->processor->priority());
    }

    public function testRemovesTrulyEmptyParagraph(): void
    {
        $blocks = [
            new Block('paragraph', innerContent: ['<p></p>']),
            new Block('paragraph', innerContent: ['<p>Keep me</p>']),
        ];

        $blocks = $this->processor->process($blocks);

        $this->assertCount(1, $blocks);
        $this->assertStringContainsString('Keep me', $blocks[0]->innerHTML());
    }

    public function testRemovesWhitespaceOnlyParagraph(): void
    {
        $blocks = [
            new Block('paragraph', innerContent: ['<p>   </p>']),
            new Block('paragraph', innerContent: ['<p>Keep me</p>']),
        ];

        $blocks = $this->processor->process($blocks);

        $this->assertCount(1, $blocks);
    }

    public function testRemovesNbspOnlyParagraph(): void
    {
        $blocks = [
            new Block('paragraph', innerContent: ['<p>&nbsp;</p>']),
            new Block('paragraph', innerContent: ['<p>Keep me</p>']),
        ];

        $blocks = $this->processor->process($blocks);

        $this->assertCount(1, $blocks);
    }

    public function testRemovesMultipleNbspParagraph(): void
    {
        $blocks = [
            new Block('paragraph', innerContent: ['<p>&nbsp; &nbsp;</p>']),
        ];

        $blocks = $this->processor->process($blocks);

        $this->assertCount(0, $blocks);
    }

    public function testRemovesUtf8NbspParagraph(): void
    {
        $blocks = [
            new Block('paragraph', innerContent: ["<p>\xC2\xA0</p>"]),
        ];

        $blocks = $this->processor->process($blocks);

        $this->assertCount(0, $blocks);
    }

    public function testKeepsNonEmptyParagraph(): void
    {
        $blocks = [
            new Block('paragraph', innerContent: ['<p>Hello world</p>']),
        ];

        $blocks = $this->processor->process($blocks);

        $this->assertCount(1, $blocks);
        $this->assertStringContainsString('Hello world', $blocks[0]->innerHTML());
    }

    public function testKeepsParagraphWithInlineFormatting(): void
    {
        $blocks = [
            new Block('paragraph', innerContent: ['<p><strong>Bold</strong></p>']),
        ];

        $blocks = $this->processor->process($blocks);

        $this->assertCount(1, $blocks);
    }

    public function testDoesNotRemoveNonParagraphBlocks(): void
    {
        $blocks = [
            new Block('heading', innerContent: ['']),
            new Block('separator', []),
        ];

        $blocks = $this->processor->process($blocks);

        $this->assertCount(2, $blocks);
    }

    public function testRemovesEmptyParagraphInsideContainer(): void
    {
        $innerBlocks = [
            new Block('paragraph', innerContent: ['<p></p>']),
            new Block('paragraph', innerContent: ['<p>Quoted text</p>']),
        ];

        $container = new Block('quote', container: true);
        $container->innerBlocks = $innerBlocks;

        $blocks = [$container];

        $blocks = $this->processor->process($blocks);

        $this->assertCount(1, $blocks);
        $this->assertCount(1, $blocks[0]->innerBlocks);
        $this->assertStringContainsString('Quoted text', $blocks[0]->innerBlocks[0]->innerHTML());
    }

    public function testRemovesMultipleConsecutiveEmptyParagraphs(): void
    {
        $blocks = [
            new Block('paragraph', innerContent: ['<p></p>']),
            new Block('paragraph', innerContent: ['<p>&nbsp;</p>']),
            new Block('paragraph', innerContent: ['<p>   </p>']),
            new Block('paragraph', innerContent: ['<p>Actual content</p>']),
            new Block('paragraph', innerContent: ['<p></p>']),
        ];

        $blocks = $this->processor->process($blocks);

        $this->assertCount(1, $blocks);
        $this->assertStringContainsString('Actual content', $blocks[0]->innerHTML());
    }

    public function testPreservesBlocksArrayKeys(): void
    {
        $blocks = [
            new Block('paragraph', innerContent: ['<p></p>']),
            new Block('heading', innerContent: ['<h2>Title</h2>']),
            new Block('paragraph', innerContent: ['<p></p>']),
            new Block('paragraph', innerContent: ['<p>Text</p>']),
        ];

        $blocks = $this->processor->process($blocks);

        // Keys should be re-indexed after removal
        $this->assertCount(2, $blocks);
        $this->assertSame('heading', $blocks[0]->blockName);
        $this->assertSame('paragraph', $blocks[1]->blockName);
    }

    public function testParagraphWithNullContentIsRemoved(): void
    {
        $blocks = [
            new Block('paragraph', []),
            new Block('paragraph', innerContent: ['<p>Keep</p>']),
        ];

        $blocks = $this->processor->process($blocks);

        $this->assertCount(1, $blocks);
    }

    public function testRemovingEmptyParagraphFromContainerSyncsInnerContent(): void
    {
        // Simulate a blockquote whose innerBlocks were split by unwrapParagraph:
        //   block 0: image
        //   block 1: empty paragraph (<p><br></p>) — should be removed
        //   block 2: html (<font>...)
        //   block 3: paragraph ("Paragraph 2")
        //   block 4: paragraph ("Paragraph 3")
        $innerBlocks = [
            new Block('image', innerContent: ['<figure><img src="image.jpg" /></figure>']),
            new Block('paragraph', innerContent: ['<p><br></p>']),
            new Block('html', innerContent: ['<font size="-2">Some text<br /> More text</font>']),
            new Block('paragraph', innerContent: ['<p>Paragraph 2</p>']),
            new Block('paragraph', innerContent: ['<p>Paragraph 3</p>']),
        ];

        $container = new Block('quote', container: true);
        // Build innerContent with interleaved string chunks and null placeholders.
        $container->innerContent = ["\n", null, "\n", null, "\n", null, "\n", null, "\n", null, "\n"];
        $container->innerBlocks = $innerBlocks;

        $blocks = [$container];

        $blocks = $this->processor->process($blocks);

        // The empty paragraph (block 1) should be removed.
        $this->assertCount(4, $blocks[0]->innerBlocks);

        // innerContent null count must match innerBlocks count.
        $nullCount = \count(\array_filter($blocks[0]->innerContent, static fn (?string $c): bool => $c === null));
        $this->assertSame(
            \count($blocks[0]->innerBlocks),
            $nullCount,
            'innerContent null placeholders must match innerBlocks count after removal',
        );

        // Rendering must not crash.
        $rendered = $blocks[0]->render();
        $this->assertStringContainsString('image.jpg', $rendered);
        $this->assertStringContainsString('Paragraph 2', $rendered);
    }

    #[DataProvider('exoticWhitespaceProvider')]
    public function testRemovesExoticWhitespaceOnlyParagraph(string $label, string $content): void
    {
        $blocks = [
            new Block('paragraph', innerContent: ["<p>{$content}</p>"]),
        ];

        $blocks = $this->processor->process($blocks);

        $this->assertCount(0, $blocks, "Paragraph with {$label} should be removed");
    }

    /** @return \Generator<string, array{string, string}> */
    public static function exoticWhitespaceProvider(): \Generator
    {
        // HTML entities
        yield 'numeric nbsp &#160;' => ['&#160;', '&#160;'];
        yield 'hex nbsp &#xA0;' => ['&#xA0;', '&#xA0;'];
        yield 'thin space &#8201;' => ['&#8201;', '&#8201;'];
        yield 'zero-width space &#8203;' => ['&#8203;', '&#8203;'];
        yield 'zero-width joiner &#8205;' => ['&#8205;', '&#8205;'];
        yield 'zero-width non-joiner &#8204;' => ['&#8204;', '&#8204;'];
        yield 'soft hyphen &shy;' => ['&shy;', '&shy;'];
        yield 'numeric soft hyphen &#173;' => ['&#173;', '&#173;'];

        // UTF-8 encoded characters
        yield 'UTF-8 thin space U+2009' => ['UTF-8 thin space', "\xE2\x80\x89"];
        yield 'UTF-8 hair space U+200A' => ['UTF-8 hair space', "\xE2\x80\x8A"];
        yield 'UTF-8 zero-width space U+200B' => ['UTF-8 zero-width space', "\xE2\x80\x8B"];
        yield 'UTF-8 zero-width non-joiner U+200C' => ['UTF-8 ZWNJ', "\xE2\x80\x8C"];
        yield 'UTF-8 zero-width joiner U+200D' => ['UTF-8 ZWJ', "\xE2\x80\x8D"];
        yield 'UTF-8 soft hyphen U+00AD' => ['UTF-8 soft hyphen', "\xC2\xAD"];
        yield 'UTF-8 en space U+2002' => ['UTF-8 en space', "\xE2\x80\x82"];
        yield 'UTF-8 em space U+2003' => ['UTF-8 em space', "\xE2\x80\x83"];
        yield 'UTF-8 narrow no-break space U+202F' => ['UTF-8 narrow NBSP', "\xE2\x80\xAF"];
        yield 'UTF-8 ideographic space U+3000' => ['UTF-8 ideographic space', "\xE3\x80\x80"];
        yield 'UTF-8 word joiner U+2060' => ['UTF-8 word joiner', "\xE2\x81\xA0"];
        yield 'UTF-8 figure space U+2007' => ['UTF-8 figure space', "\xE2\x80\x87"];
        yield 'UTF-8 punctuation space U+2008' => ['UTF-8 punctuation space', "\xE2\x80\x88"];

        // Mixed
        yield 'nbsp + soft hyphen mix' => ['mixed entities', '&nbsp;&shy;'];
        yield 'tabs and newlines' => ['tabs/newlines', "\t\n\r"];
    }

    /**
     * A paragraph holding only an anchor has no text, but it is the
     * destination of every "#section-2" link in the document.
     */
    public function testAParagraphHoldingAnAnchorTargetIsKept(): void
    {
        foreach (['<p><a name="section-2"></a></p>', '<p><a id="section-2"></a></p>'] as $markup) {
            $blocks = [new Block('paragraph', innerContent: [$markup])];

            $this->assertCount(1, $this->processor->process($blocks), $markup);
        }
    }

    public function testAParagraphHoldingALinkWithoutATargetIsStillJudgedOnItsText(): void
    {
        // An <a href> with no text is not an anchor target, just an empty link.
        $blocks = [new Block('paragraph', innerContent: ['<p><a href="/x"></a></p>'])];

        $this->assertSame([], $this->processor->process($blocks));
    }
}
