<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Integration\TagConverters;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\TagConverters\FigureConverter;
use n5s\BlockConverter\Tests\WpTestCase;
use voku\helper\HtmlDomParser;
use voku\helper\SimpleHtmlDomInterface;

use function Mantle\Testing\html_string;

final class FigureConverterTest extends WpTestCase
{
    private FigureConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new FigureConverter();
    }

    public function testTagNames(): void
    {
        $this->assertSame(['figure'], FigureConverter::tags());
    }

    public function testConvertsFigureWithCaption(): void
    {
        $block = $this->convert(
            '<figure><img src="https://example.org/a.jpg" alt="A" /><figcaption>La légende</figcaption></figure>',
        );

        $this->assertInstanceOf(Block::class, $block);
        $this->assertSame('image', $block->blockName);

        $html = html_string($block->innerHTML());
        $html->assertQuerySelectorExists('figure.wp-block-image');
        $html->assertQuerySelectorExists('figure > img');
        $html->assertQuerySelectorExists('figure > figcaption.wp-element-caption');
        $this->assertStringContainsString('La légende', $block->innerHTML());
    }

    public function testConvertsFigureWithoutCaption(): void
    {
        $block = $this->convert('<figure><img src="https://example.org/a.jpg" alt="A" /></figure>');

        $this->assertInstanceOf(Block::class, $block);
        $this->assertSame('image', $block->blockName);
        $this->assertStringNotContainsString('figcaption', $block->innerHTML());
    }

    public function testIgnoresWhitespaceOnlyCaption(): void
    {
        $block = $this->convert(
            '<figure><img src="https://example.org/a.jpg" alt="A" /><figcaption>   </figcaption></figure>',
        );

        $this->assertInstanceOf(Block::class, $block);
        $this->assertStringNotContainsString('figcaption', $block->innerHTML());
    }

    public function testKeepsLinkWrappingTheImage(): void
    {
        $block = $this->convert(
            '<figure><a href="https://example.org/full.jpg"><img src="https://example.org/a.jpg" alt="A" /></a></figure>',
        );

        $this->assertInstanceOf(Block::class, $block);
        $this->assertSame('media', $block->attributes['linkDestination']);
        html_string($block->innerHTML())->assertQuerySelectorExists('figure > a > img');
    }

    public function testHoistsAlignmentAndSizeFromTheFigure(): void
    {
        $block = $this->convert(
            '<figure class="alignleft size-large"><img src="https://example.org/a.jpg" alt="A" /></figure>',
        );

        $this->assertInstanceOf(Block::class, $block);
        $this->assertSame('left', $block->attributes['align']);
        $this->assertSame('large', $block->attributes['sizeSlug']);
        $this->assertStringContainsString('wp-block-image alignleft size-large', $block->innerHTML());
    }

    public function testFigureClassesDoNotLeakOntoTheImage(): void
    {
        $block = $this->convert(
            '<figure class="aligncenter"><img src="https://example.org/a.jpg" alt="A" /></figure>',
        );

        $this->assertInstanceOf(Block::class, $block);
        $this->assertStringNotContainsString('<img class="aligncenter"', $block->innerHTML());
    }

    public function testFallsBackToHtmlForNonImageFigure(): void
    {
        $block = $this->convert('<figure><table><tr><td>A</td></tr></table></figure>');

        $this->assertInstanceOf(Block::class, $block);
        $this->assertSame('html', $block->blockName);
    }

    public function testFallsBackToHtmlWhenAnUnexpectedSiblingFollowsTheImage(): void
    {
        $block = $this->convert('<figure><img src="https://example.org/a.jpg" /><p>Pas une légende</p></figure>');

        $this->assertInstanceOf(Block::class, $block);
        $this->assertSame('html', $block->blockName);
    }

    public function testFallsBackToHtmlWithMoreThanTwoChildren(): void
    {
        $block = $this->convert(
            '<figure><img src="https://example.org/a.jpg" /><figcaption>L</figcaption><span>extra</span></figure>',
        );

        $this->assertInstanceOf(Block::class, $block);
        $this->assertSame('html', $block->blockName);
    }

    public function testReturnsNullForEmptyFigure(): void
    {
        $this->assertNull($this->convert('<figure></figure>'));
    }

    public function testFallsBackToHtmlWhenTheImageHasNoSource(): void
    {
        // ImageConverter declines without a src, so the figure keeps its markup.
        $block = $this->convert('<figure><img alt="sans source"><figcaption>L</figcaption></figure>');

        $this->assertInstanceOf(Block::class, $block);
        $this->assertSame('html', $block->blockName);
    }

    public function testFallsBackToHtmlWhenTextSitsBesideTheImage(): void
    {
        $block = $this->convert('<figure>Texte libre<img src="https://example.org/a.jpg"></figure>');

        $this->assertInstanceOf(Block::class, $block);
        $this->assertSame('html', $block->blockName);
    }

    public function testWhitespaceAroundTheImageIsNotTreatedAsText(): void
    {
        $block = $this->convert("<figure>\n    <img src=\"https://example.org/a.jpg\">\n</figure>");

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
