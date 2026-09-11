<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Unit\Support;

use n5s\BlockConverter\Support\ShortcodeBlockBuilder;
use n5s\BlockConverter\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ShortcodeBlockBuilderTest extends TestCase
{
    #[DataProvider('shortcodes')]
    public function testRebuildsTheShortcodeAsWordPressParsedIt(string $original, ?string $content = ''): void
    {
        \preg_match('/^\[(\w+)(.*)\]$/', $original, $m);
        [, $tag, $attributeText] = $m;
        $atts = \shortcode_parse_atts($attributeText);

        $block = ShortcodeBlockBuilder::build($tag, \is_array($atts) ? $atts : [], $content);

        $this->assertSame('shortcode', $block->blockName);
        $this->assertSame($original . ($content !== '' && $content !== null ? $content . '[/' . $tag . ']' : ''), $block->innerHTML());
    }

    /** @return \Iterator<string, array{0: string, 1?: ?string}> */
    public static function shortcodes(): \Iterator
    {
        // WordPress passes '' for a self-closing shortcode, not null.
        yield 'self-closing' => ['[playlist ids="1,2" style="light"]', ''];
        yield 'self-closing, null content' => ['[playlist ids="1,2"]', null];
        yield 'bare attribute' => ['[gallery link]'];
        yield 'bare and keyed attributes' => ['[gallery link ids="1"]'];
        yield 'no attributes' => ['[playlist]'];
        yield 'enclosing' => ['[playlist x="1"]', 'inner text'];
        yield 'double quote inside the value' => ['[playlist x=\'a"b\']'];
    }

    public function testAnEnclosingShortcodeWithNothingInsideComesBackSelfClosing(): void
    {
        $this->assertSame('[foo]', ShortcodeBlockBuilder::build('foo', [], '')->innerHTML());
    }

    public function testAValueHoldingBothQuotesStillParsesAsOneValue(): void
    {
        $block = ShortcodeBlockBuilder::build('foo', ['x' => 'a"b\'c'], null);
        $atts = \shortcode_parse_atts(\substr($block->innerHTML(), 5, -1));

        $this->assertSame(['x' => 'a&quot;b\'c'], $atts);
    }

    public function testBlockDelimitersInTheTextAreNeutralised(): void
    {
        $block = ShortcodeBlockBuilder::build('foo', ['x' => '<!-- /wp:shortcode -->'], null);

        $this->assertStringNotContainsString('-->', $block->innerHTML());
    }
}
