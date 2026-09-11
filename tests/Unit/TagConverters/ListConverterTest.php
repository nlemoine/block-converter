<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Unit\TagConverters;

use n5s\BlockConverter\BlockConverter;
use n5s\BlockConverter\TagConverters\ListConverter;
use n5s\BlockConverter\TagConverters\ListItemConverter;
use n5s\BlockConverter\Tests\TestCase;

final class ListConverterTest extends TestCase
{
    private BlockConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = self::createDefaultConverter();
    }

    public function testTagNames(): void
    {
        $this->assertSame(['ul', 'ol'], ListConverter::tags());
    }

    public function testListItemTagNames(): void
    {
        $this->assertSame(['li'], ListItemConverter::tags());
    }

    public function testConvertsUnorderedList(): void
    {
        $result = $this->converter->convert('<ul><li>Item 1</li><li>Item 2</li></ul>');
        $this->assertStringContainsString('<!-- wp:list -->', $result);
        $this->assertStringContainsString('wp:list-item', $result);
        $this->assertStringContainsString('wp-block-list', $result);
        $this->assertStringContainsString('Item 1', $result);
    }

    public function testConvertsOrderedList(): void
    {
        $result = $this->converter->convert('<ol><li>First</li></ol>');
        $this->assertStringContainsString('"ordered":true', $result);
        $this->assertStringContainsString('wp:list-item', $result);
    }

    public function testOrderedListWithStartAttribute(): void
    {
        $result = $this->converter->convert('<ol start="3"><li>Item A</li><li>Item B</li></ol>');
        $this->assertStringContainsString('"start":3', $result);
        $this->assertStringContainsString('"ordered":true', $result);
    }

    public function testEmptyListItemsAreKept(): void
    {
        $result = $this->converter->convert('<ul><li></li><li>Only</li></ul>');
        // Empty <li> tags still produce list-item blocks (html() returns <li></li>)
        $this->assertSame(2, \substr_count($result, '<!-- wp:list-item -->'));
        $this->assertStringContainsString('Only', $result);
    }

    public function testExistingListBlockPreservesStructure(): void
    {
        $input = <<<'HTML'
<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>Item 1</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Item 2</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Item 3</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->
HTML;

        $result = $this->converter->convert($input);

        $this->assertStringContainsString('<ul class="wp-block-list">', $result);
        $ulPos = \strpos($result, '<ul class="wp-block-list">');
        $firstItemPos = \strpos($result, '<li>Item 1</li>');
        $closingUlPos = \strpos($result, '</ul>');
        $this->assertNotFalse($ulPos);
        $this->assertNotFalse($firstItemPos);
        $this->assertNotFalse($closingUlPos);
        $this->assertLessThan($firstItemPos, $ulPos, '<ul> must come before list items');
        $this->assertGreaterThan($firstItemPos, $closingUlPos, '</ul> must come after list items');
    }

    public function testNestedUnorderedList(): void
    {
        $html = '<ul><li>Item 1</li><li>Item 2<ul><li>Nested 1</li><li>Nested 2</li></ul></li><li>Item 3</li></ul>';

        $result = $this->converter->convert($html);

        // Outer list
        $this->assertStringContainsString('<!-- wp:list -->', $result);
        $this->assertStringContainsString('<ul class="wp-block-list">', $result);

        // All three top-level items
        $this->assertStringContainsString('Item 1', $result);
        $this->assertStringContainsString('Item 2', $result);
        $this->assertStringContainsString('Item 3', $result);

        // Inner list is itself a wp:list block nested inside a list-item
        $this->assertSame(2, \substr_count($result, '<!-- wp:list -->'), 'Should have two wp:list blocks (outer + nested)');
        $this->assertSame(2, \substr_count($result, '<!-- /wp:list -->'));

        // Five list-items total: 3 outer + 2 nested
        $this->assertSame(5, \substr_count($result, '<!-- wp:list-item -->'));

        // Nested items
        $this->assertStringContainsString('Nested 1', $result);
        $this->assertStringContainsString('Nested 2', $result);
    }

    public function testTriplyNestedList(): void
    {
        $html = '<ul><li>L1<ul><li>L2<ul><li>L3</li></ul></li></ul></li></ul>';

        $result = $this->converter->convert($html);

        $this->assertSame(3, \substr_count($result, '<!-- wp:list -->'), 'Three levels of wp:list');
        $this->assertSame(3, \substr_count($result, '<!-- wp:list-item -->'), 'Three list-items (one per level)');
        $this->assertStringContainsString('L1', $result);
        $this->assertStringContainsString('L2', $result);
        $this->assertStringContainsString('L3', $result);
    }

    public function testMixedOlInsideUl(): void
    {
        $html = '<ul><li>Unordered<ol><li>Ordered 1</li><li>Ordered 2</li></ol></li></ul>';

        $result = $this->converter->convert($html);

        // Outer unordered list has no ordered attribute
        // Outer: <!-- wp:list --> (unordered), Inner: <!-- wp:list {"ordered":true} -->
        $this->assertSame(1, \substr_count($result, '<!-- wp:list -->'));
        $this->assertStringContainsString('<!-- wp:list {"ordered":true} -->', $result);
        $this->assertSame(2, \substr_count($result, '<!-- /wp:list -->'));
        $this->assertStringContainsString('Unordered', $result);
        $this->assertStringContainsString('Ordered 1', $result);
    }

    public function testMixedUlInsideOl(): void
    {
        $html = '<ol><li>First<ul><li>Sub A</li><li>Sub B</li></ul></li><li>Second</li></ol>';

        $result = $this->converter->convert($html);

        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- shows the block markup being asserted.
        // Outer: <!-- wp:list {"ordered":true} --> , Inner: <!-- wp:list --> (unordered)
        $this->assertStringContainsString('<!-- wp:list {"ordered":true} -->', $result);
        $this->assertSame(1, \substr_count($result, '<!-- wp:list -->'));
        $this->assertSame(2, \substr_count($result, '<!-- /wp:list -->'));
        $this->assertSame(4, \substr_count($result, '<!-- wp:list-item -->'));
        $this->assertStringContainsString('First', $result);
        $this->assertStringContainsString('Sub A', $result);
        $this->assertStringContainsString('Second', $result);
    }

    public function testListItemWithInlineFormattingAndNestedList(): void
    {
        $html = '<ul><li><strong>Bold item</strong><ul><li>Child</li></ul></li></ul>';

        $result = $this->converter->convert($html);

        $this->assertStringContainsString('<strong>Bold item</strong>', $result);
        $this->assertSame(2, \substr_count($result, '<!-- wp:list -->'));
        $this->assertStringContainsString('Child', $result);
    }

    public function testListItemWithLinkAndNestedList(): void
    {
        $html = '<ul><li><a href="https://example.com">Link</a><ul><li>Nested</li></ul></li></ul>';

        $result = $this->converter->convert($html);

        $this->assertStringContainsString('href="https://example.com"', $result);
        $this->assertStringContainsString('Link', $result);
        $this->assertSame(2, \substr_count($result, '<!-- wp:list -->'));
    }

    public function testAListItemKeepsItsAttributesWhenItHoldsANestedList(): void
    {
        // The container path emitted a bare <li>, and losing value on an
        // ordered list renumbers everything after it.
        $result = $this->converter->convert('<ol><li value="5">Cinq<ul><li>Imbriqué</li></ul></li></ol>');

        $this->assertStringContainsString('value="5"', $result);
    }
}
