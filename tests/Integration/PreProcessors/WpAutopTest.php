<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Integration\PreProcessors;

use n5s\BlockConverter\PreProcessors\PreProcessorInterface;
use n5s\BlockConverter\PreProcessors\WpAutop;
use n5s\BlockConverter\Tests\WpTestCase;

final class WpAutopTest extends WpTestCase
{
    private WpAutop $processor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new WpAutop();
    }

    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(PreProcessorInterface::class, $this->processor);
    }

    public function testPriority(): void
    {
        $this->assertSame(40, $this->processor->priority());
    }

    public function testReturnsEmptyStringForEmptyInput(): void
    {
        $this->assertSame('', $this->processor->process(''));
    }

    public function testWrapsLooseTextInParagraphs(): void
    {
        $input = "First paragraph\n\nSecond paragraph";
        $result = $this->processor->process($input);

        $this->assertStringContainsString('<p>First paragraph</p>', $result);
        $this->assertStringContainsString('<p>Second paragraph</p>', $result);
    }

    public function testConvertsSingleNewlinesToBr(): void
    {
        $input = "Line one\nLine two";
        $result = $this->processor->process($input);

        $this->assertStringContainsString('<br', $result);
    }

    public function testPreservesPreTags(): void
    {
        $input = "<pre>code\n  indented\n    more</pre>";
        $result = $this->processor->process($input);

        $this->assertStringContainsString("<pre>code\n  indented\n    more</pre>", $result);
    }

    public function testDoesNotDoubleWrapExistingParagraphs(): void
    {
        $input = '<p>Already wrapped</p>';
        $result = $this->processor->process($input);

        $this->assertSame(1, \substr_count($result, '<p>'));
    }

    public function testUnwrapsShortcodesFromParagraphs(): void
    {
        add_shortcode('gallery', '__return_empty_string');

        $input = "Some text\n\n[gallery ids=\"1,2,3\"]\n\nMore text";
        $result = $this->processor->process($input);

        $this->assertStringNotContainsString('<p>[gallery', $result);
        $this->assertStringContainsString('[gallery ids="1,2,3"]', $result);

        remove_shortcode('gallery');
    }

    public function testPreservesBlockLevelElements(): void
    {
        $input = "<h2>Title</h2>\n\nSome text\n\n<blockquote>Quote</blockquote>";
        $result = $this->processor->process($input);

        $this->assertStringNotContainsString('<p><h2>', $result);
        $this->assertStringNotContainsString('<p><blockquote>', $result);
        $this->assertStringContainsString('<p>Some text</p>', $result);
    }
}
