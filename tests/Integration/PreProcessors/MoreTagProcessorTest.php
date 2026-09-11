<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Integration\PreProcessors;

use n5s\BlockConverter\BlockConverter;
use n5s\BlockConverter\PreProcessors\MoreTagProcessor;
use n5s\BlockConverter\PreProcessors\PreProcessorInterface;
use n5s\BlockConverter\Tests\WpTestCase;

final class MoreTagProcessorTest extends WpTestCase
{
    private MoreTagProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new MoreTagProcessor();
    }

    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(PreProcessorInterface::class, $this->processor);
    }

    public function testRunsBeforeBlockParsing(): void
    {
        $this->assertTrue($this->processor->runsBeforeBlockParsing());
    }

    public function testLeavesContentWithoutMarkersUntouched(): void
    {
        $input = '<p>Rien à voir</p>';
        $this->assertSame($input, $this->processor->process($input));
    }

    public function testConvertsMoreTag(): void
    {
        $result = $this->processor->process('<p>Chapô</p><!--more--><p>Suite</p>');

        $this->assertStringContainsString("<!-- wp:more -->\n<!--more-->\n<!-- /wp:more -->", $result);
    }

    public function testConvertsMoreTagWithCustomText(): void
    {
        $result = $this->processor->process('<p>Chapô</p><!--more Lire la suite--><p>Suite</p>');

        $this->assertStringContainsString('<!-- wp:more {"customText":"Lire la suite"} -->', $result);
        $this->assertStringContainsString('<!--more Lire la suite-->', $result);
    }

    public function testConvertsNoTeaser(): void
    {
        $result = $this->processor->process('<p>Chapô</p><!--more--><!--noteaser--><p>Suite</p>');

        $this->assertStringContainsString('<!-- wp:more {"noTeaser":true} -->', $result);
        $this->assertStringContainsString("<!--more-->\n<!--noteaser-->", $result);
    }

    public function testConvertsNextpageTag(): void
    {
        $result = $this->processor->process('<p>Page 1</p><!--nextpage--><p>Page 2</p>');

        $this->assertStringContainsString("<!-- wp:nextpage -->\n<!--nextpage-->\n<!-- /wp:nextpage -->", $result);
    }

    public function testConvertsEveryNextpageMarker(): void
    {
        $result = $this->processor->process('<p>1</p><!--nextpage--><p>2</p><!--nextpage--><p>3</p>');

        $this->assertSame(2, \substr_count($result, '<!-- wp:nextpage -->'));
    }

    public function testLiftsMarkersOutOfAWrappingParagraph(): void
    {
        $result = $this->processor->process('<p>Chapô</p><p><!--more--></p><p>Suite</p>');

        $this->assertStringContainsString('<!-- wp:more -->', $result);
        $this->assertStringNotContainsString('<p><!--', $result);
    }

    public function testKeepsParagraphsThatAlsoHoldText(): void
    {
        $result = $this->processor->process('<p>Texte <!--more--> encore</p>');

        // The paragraph is not unwrapped, only the marker itself is replaced.
        $this->assertStringContainsString('<p>Texte ', $result);
        $this->assertStringContainsString('<!-- wp:more -->', $result);
    }

    public function testMarkersSurviveTheDefaultPipeline(): void
    {
        $result = BlockConverter::createDefault()->convert(
            "<p>Chapô</p>\n<!--more-->\n<p>Suite</p>\n<!--nextpage-->\n<p>Fin</p>",
        );

        $this->assertStringContainsString('<!-- wp:more -->', $result);
        $this->assertStringContainsString('<!-- wp:nextpage -->', $result);
    }

    public function testConvertedMarkersParseBackAsCoreBlocks(): void
    {
        $result = BlockConverter::createDefault()->convert('<p>Chapô</p><!--more--><p>Suite</p>');

        $names = \array_column(\parse_blocks($result), 'blockName');

        $this->assertContains('core/more', $names);
    }
}
