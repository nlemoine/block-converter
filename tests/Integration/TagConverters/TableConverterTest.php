<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Integration\TagConverters;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\TagConverters\TableConverter;
use n5s\BlockConverter\Tests\WpTestCase;
use voku\helper\HtmlDomParser;
use voku\helper\SimpleHtmlDomInterface;

final class TableConverterTest extends WpTestCase
{
    private TableConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new TableConverter();
    }

    public function testTagNames(): void
    {
        $this->assertSame(['table'], TableConverter::tags());
    }

    /**
     * The default pipeline never reaches this: Symfony's sanitizer already
     * normalises tables. It matters when HtmlSanitizer is removed or replaced,
     * which the README documents as supported.
     */
    public function testBareRowsAreWrappedInATbody(): void
    {
        $block = $this->convert('<table><tr><td>A</td><td>B</td></tr></table>');

        $this->assertInstanceOf(Block::class, $block);
        $html = $block->innerHTML();
        $this->assertStringContainsString('<tbody>', $html);
        $this->assertStringContainsString('</tbody>', $html);
        $this->assertStringContainsString('<td>A</td>', $html);
    }

    public function testAnExistingSectionIsLeftAlone(): void
    {
        $block = $this->convert('<table><thead><tr><th>H</th></tr></thead><tr><td>A</td></tr></table>');

        $this->assertInstanceOf(Block::class, $block);

        // A <thead> is enough to consider the table already sectioned, so no
        // <tbody> is invented around the loose row.
        $this->assertStringNotContainsString('<tbody>', $block->innerHTML());
    }

    public function testCellAlignmentClassesBecomeDataAlignAttributes(): void
    {
        $block = $this->convert('<table><tr><td class="has-text-align-center">A</td></tr></table>');

        $this->assertInstanceOf(Block::class, $block);
        $this->assertStringContainsString('data-align="center"', $block->innerHTML());
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

    /**
     * The guard used to match on the whole serialised table, so a nested
     * table's own <tbody> convinced it the outer one was already sectioned.
     */
    public function testANestedTableDoesNotSuppressWrappingOfTheOuterOne(): void
    {
        $block = $this->convert(
            '<table><tr><td><table><tbody><tr><td>inner</td></tr></tbody></table></td></tr></table>',
        );

        $this->assertInstanceOf(Block::class, $block);

        $html = $block->innerHTML();
        $outer = \substr($html, 0, \strpos($html, '<table>', (int) \strpos($html, '<table>') + 1) ?: \strlen($html));

        $this->assertStringContainsString('<tbody>', $outer);
    }
}
