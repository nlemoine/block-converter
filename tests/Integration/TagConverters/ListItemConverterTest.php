<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Integration\TagConverters;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\TagConverters\ListItemConverter;
use n5s\BlockConverter\Tests\WpTestCase;
use voku\helper\HtmlDomParser;
use voku\helper\SimpleHtmlDomInterface;

final class ListItemConverterTest extends WpTestCase
{
    /**
     * The opening tag of a nested item is rebuilt from the parsed attributes.
     * Cutting the markup at the first `>` broke on a value holding one, which
     * the default sanitizer encodes but a replacement may not.
     */
    public function testANestedItemKeepsAnAttributeValueHoldingAClosingBracket(): void
    {
        $block = $this->convert('<li title="a>b" value="3"><ul><li>x</li></ul></li>');

        $this->assertInstanceOf(Block::class, $block);
        $this->assertTrue($block->container);
        $this->assertSame('<li title="a&gt;b" value="3">', $block->innerHTML());
    }

    public function testANestedItemWithoutAttributesOpensABareTag(): void
    {
        $block = $this->convert('<li><ol><li>x</li></ol></li>');

        $this->assertInstanceOf(Block::class, $block);
        $this->assertSame('<li>', $block->innerHTML());
    }

    private function convert(string $html): Block|array|null
    {
        $dom = HtmlDomParser::str_get_html('<body>' . $html . '</body>');
        $elements = $dom->findMultiOrFalse('//body/*');

        if ($elements === false) {
            throw new \RuntimeException('No element found in the test markup.');
        }

        /** @var SimpleHtmlDomInterface $element */
        $element = $elements[0];

        return (new ListItemConverter())->convert($element);
    }
}
