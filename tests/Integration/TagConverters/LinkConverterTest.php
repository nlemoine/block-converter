<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Integration\TagConverters;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\TagConverters\LinkConverter;
use n5s\BlockConverter\Tests\WpTestCase;
use voku\helper\HtmlDomParser;
use voku\helper\SimpleHtmlDomInterface;

final class LinkConverterTest extends WpTestCase
{
    private LinkConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new LinkConverter();
    }

    public function testTagNames(): void
    {
        $this->assertSame(['a'], LinkConverter::tags());
    }

    public function testPlainLinkFallsBackToHtml(): void
    {
        $block = $this->convert('<a href="https://example.org/">Un lien</a>');

        $this->assertInstanceOf(Block::class, $block);
        $this->assertSame('html', $block->blockName);
        $this->assertStringContainsString('Un lien', $block->innerHTML());
    }

    public function testEmptyLinkStillBecomesAnHtmlBlock(): void
    {
        // The anchor markup itself is never blank, so the null branch below the
        // trim() in LinkConverter is unreachable from the parser.
        $block = $this->convert('<a href="https://example.org/"></a>');

        $this->assertInstanceOf(Block::class, $block);
        $this->assertSame('html', $block->blockName);
    }

    public function testSingleImageWithoutTextBecomesAnImageBlock(): void
    {
        $block = $this->convert('<a href="https://example.org/full.jpg"><img src="/thumb.jpg" alt="A"></a>');

        $this->assertInstanceOf(Block::class, $block);
        $this->assertSame('image', $block->blockName);

        // The whole <a> is delegated, so the link survives inside the figure.
        $this->assertStringContainsString('<a href="https://example.org/full.jpg">', $block->innerHTML());
    }

    public function testSingleImageLinkingToMediaSetsLinkDestination(): void
    {
        $block = $this->convert('<a href="https://example.org/full.jpg"><img src="/thumb.jpg"></a>');

        $this->assertInstanceOf(Block::class, $block);
        $this->assertSame('media', $block->attributes['linkDestination']);
    }

    public function testSeveralImagesWithoutTextBecomeSeveralImageBlocks(): void
    {
        $blocks = $this->convert(
            '<a href="https://example.org/"><img src="/one.jpg"><img src="/two.jpg"><img src="/three.jpg"></a>',
        );

        $this->assertIsArray($blocks);
        $this->assertCount(3, $blocks);
        $this->assertContainsOnlyInstancesOf(Block::class, $blocks);

        foreach ($blocks as $block) {
            $this->assertSame('image', $block->blockName);
        }
    }

    /**
     * ImageConverter returns null without a src. Reading that as "emit
     * nothing" dropped the link, its href and its images together.
     */
    public function testImagesTheConverterDeclinesKeepTheAnchor(): void
    {
        foreach (['<a href="/important-page"><img alt="logo"></a>', '<a href="/p"><img alt="a"><img alt="b"></a>'] as $html) {
            $block = $this->convert($html);

            $this->assertInstanceOf(Block::class, $block);
            $this->assertSame('html', $block->blockName);
            $this->assertSame($html, $block->innerHTML());
        }
    }

    public function testImagesMixedWithTextFallBackToHtml(): void
    {
        $block = $this->convert('<a href="https://example.org/"><img src="/one.jpg">Légende du lien</a>');

        $this->assertInstanceOf(Block::class, $block);
        $this->assertSame('html', $block->blockName);
        $this->assertStringContainsString('Légende du lien', $block->innerHTML());
        $this->assertStringContainsString('<img src="/one.jpg">', $block->innerHTML());
    }

    public function testNonBreakingSpaceDoesNotCountAsText(): void
    {
        // HtmlUtils::trim() strips nbsp, so this is still an image-only link.
        $block = $this->convert("<a href=\"https://example.org/\"><img src=\"/one.jpg\">\u{00A0}</a>");

        $this->assertInstanceOf(Block::class, $block);
        $this->assertSame('image', $block->blockName);
    }

    private function convert(string $html): Block|array|null
    {
        return $this->converter->convert($this->parseElement($html));
    }

    private function parseElement(string $html): SimpleHtmlDomInterface
    {
        $dom = HtmlDomParser::str_get_html('<body>' . $html . '</body>');
        $elements = $dom->findMultiOrFalse('//body/*');

        if ($elements === false) {
            throw new \RuntimeException('No element found in the test markup.');
        }

        return $elements[0];
    }
}
