<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Unit\Support;

use n5s\BlockConverter\Support\HtmlUtils;
use n5s\BlockConverter\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class HtmlUtilsTest extends TestCase
{
    public function testAddClass(): void
    {
        $html = '<img src="test.jpg">';
        $result = HtmlUtils::addClass($html, 'img', 'wp-image-123');
        $this->assertStringContainsString('wp-image-123', $result);
    }

    public function testAddMultipleClasses(): void
    {
        $html = '<img src="test.jpg" class="existing">';
        $result = HtmlUtils::addClass($html, 'img', 'class-a class-b');
        $this->assertStringContainsString('existing', $result);
        $this->assertStringContainsString('class-a', $result);
        $this->assertStringContainsString('class-b', $result);
    }

    public function testRemoveClass(): void
    {
        $html = '<img src="test.jpg" class="keep remove-me">';
        $result = HtmlUtils::removeClass($html, 'img', 'remove-me');
        $this->assertStringContainsString('keep', $result);
        $this->assertStringNotContainsString('remove-me', $result);
    }

    public function testRemoveAttr(): void
    {
        $html = '<a href="url" title="remove this">text</a>';
        $result = HtmlUtils::removeAttr($html, 'a', 'title');
        $this->assertStringNotContainsString('title', $result);
        $this->assertStringContainsString('href', $result);
    }

    public function testNoMatchingTagReturnsUnchanged(): void
    {
        $html = '<p>no images here</p>';
        $result = HtmlUtils::addClass($html, 'img', 'test');
        $this->assertSame($html, $result);
    }

    public function testRemoveAttrLeavesNoWhitespaceBehind(): void
    {
        $this->assertSame(
            '<img src="/a.jpg" alt="A">',
            HtmlUtils::removeAttr(
                HtmlUtils::removeAttr('<img src="/a.jpg" width="10" height="20" alt="A">', 'img', 'width'),
                'img',
                'height',
            ),
        );
    }

    public function testRemoveClassLeavesNoWhitespaceBehindWhenTheLastOneGoes(): void
    {
        $this->assertSame(
            '<img src="/a.jpg" alt="A">',
            HtmlUtils::removeClass('<img class="alignleft" src="/a.jpg" alt="A">', 'img', 'alignleft'),
        );
    }

    public function testRemovalKeepsTheSpacingInsideAttributeValues(): void
    {
        $this->assertSame(
            '<img src="/a.jpg" alt="deux  espaces">',
            HtmlUtils::removeAttr('<img src="/a.jpg" width="10" alt="deux  espaces">', 'img', 'width'),
        );
    }

    public function testRemoveAttrReturnsTheSourceUntouchedWhenTheAttributeIsAbsent(): void
    {
        // Serialising would rewrite `<img />` as `<img>`, so an absent attribute
        // must hand the markup back byte for byte.
        $html = '<img src="/a.jpg" />';
        $this->assertSame($html, HtmlUtils::removeAttr($html, 'img', 'width'));
    }

    public function testRemoveClassReturnsTheSourceUntouchedWhenTheClassIsAbsent(): void
    {
        $html = '<img class="custom" src="/a.jpg" />';
        $this->assertSame($html, HtmlUtils::removeClass($html, 'img', 'alignleft'));
    }

    public function testRemoveClassKeepsTheOtherClasses(): void
    {
        $this->assertSame(
            '<img class="custom" src="/a.jpg">',
            HtmlUtils::removeClass('<img class="alignleft custom" src="/a.jpg">', 'img', 'alignleft'),
        );
    }

    public function testRemoveAttrOnHtmlWithoutThatTagIsAPassThrough(): void
    {
        $html = '<p class="x">Pas de balise img ici</p>';
        $this->assertSame($html, HtmlUtils::removeAttr($html, 'img', 'width'));
        $this->assertSame($html, HtmlUtils::removeClass($html, 'img', 'alignleft'));
    }

    #[DataProvider('accentedStrings')]
    public function testTrimLeavesMultibyteCharactersIntact(string $input): void
    {
        $trimmed = HtmlUtils::trim($input);

        $this->assertTrue(\mb_check_encoding($trimmed, 'UTF-8'), 'trim() produced invalid UTF-8.');
        $this->assertSame($input, $trimmed);
    }

    /**
     * Every one of these ends in a byte that the old byte-based character list
     * happened to name: U+00A0's \xC2 lead byte, or its \xA0 tail.
     *
     * @return \Iterator<string, array{0: string}>
     */
    public static function accentedStrings(): \Iterator
    {
        yield 'a grave' => ['Voilà'];
        yield 'two accents' => ['déjà'];
        yield 'dagger' => ['Paris †'];
        yield 'cyrillic er' => ['Река Р'];
        yield 'guillemet' => ['«citation»'];
        yield 'degree' => ['20°C'];
        yield 'copyright' => ['© 2026'];
        yield 'pound' => ['£10'];
    }

    public function testTrimStillRemovesSurroundingWhitespaceAndNbsp(): void
    {
        $this->assertSame('Été', HtmlUtils::trim("\u{00A0} \n\tÉté\t\n \u{00A0}"));
    }

    public function testTrimRemovesNonBreakingSpaces(): void
    {
        $this->assertSame('hello', HtmlUtils::trim("  hello  "));
        $this->assertSame('hello', HtmlUtils::trim("\xC2\xA0hello\xC2\xA0"));
    }

    public function testClassTokensSplitOnAnyAsciiWhitespace(): void
    {
        // The HTML spec: class is a set of space-separated tokens, where
        // "space" is any ASCII whitespace. explode(' ') saw this as one token.
        $this->assertSame(['alignleft', 'size-full', 'x'], HtmlUtils::classTokens("alignleft\n  size-full\tx "));
        $this->assertSame([], HtmlUtils::classTokens(null));
        $this->assertSame([], HtmlUtils::classTokens('  '));
    }

    public function testExtractTextAlignMatchesWholeTokensOnly(): void
    {
        $this->assertSame('center', HtmlUtils::extractTextAlign("foo\nhas-text-align-center"));
        $this->assertNull(HtmlUtils::extractTextAlign('has-text-align-center-2'), 'A \\b pattern matched this.');
        $this->assertNull(HtmlUtils::extractTextAlign('has-text-align-sideways'));
        $this->assertNull(HtmlUtils::extractTextAlign(null));
    }

    public function testRemoveClassSplitsOnAnyAsciiWhitespace(): void
    {
        $this->assertSame(
            '<img class="size-full" src="/a.jpg">',
            HtmlUtils::removeClass("<img class=\"alignleft\n  size-full\" src=\"/a.jpg\">", 'img', 'alignleft'),
        );
    }
}
