<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Integration\TagConverters;

use n5s\BlockConverter\TagConverters\HeadingConverter;
use n5s\BlockConverter\TagConverters\ListItemConverter;
use n5s\BlockConverter\TagConverters\SkippedTagConverter;
use n5s\BlockConverter\TagConverters\TableConverter;
use n5s\BlockConverter\Tests\WpTestCase;
use voku\helper\HtmlDomParser;
use voku\helper\SimpleHtmlDomInterface;

/**
 * Converters return null for elements with nothing in them, which drops the
 * block rather than emitting an empty one.
 */
final class EmptyElementsTest extends WpTestCase
{
    public function testEmptyHeadingIsDropped(): void
    {
        $this->assertNull((new HeadingConverter())->convert($this->parseElement('<h2></h2>')));
    }

    public function testEmptyListItemIsKept(): void
    {
        // Deliberate: an empty <li> is a blank row the author asked for, and
        // dropping it would renumber an ordered list.
        $this->assertNotNull((new ListItemConverter())->convert($this->parseElement('<li></li>')));
    }

    public function testEmptyTableIsDropped(): void
    {
        $this->assertNull((new TableConverter())->convert($this->parseElement('<table></table>')));
    }

    public function testSkippedTagsAreAlwaysDropped(): void
    {
        $this->assertSame(['br', 'cite', 'source'], SkippedTagConverter::tags());

        $converter = new SkippedTagConverter();

        foreach (['<br>', '<cite>Auteur</cite>', '<source src="/a.mp3">'] as $html) {
            $this->assertNull($converter->convert($this->parseElement($html)));
        }
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
