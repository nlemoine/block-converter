<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Unit\PreProcessors;

use n5s\BlockConverter\PreProcessors\InlineStyleNormalizer;
use n5s\BlockConverter\PreProcessors\PreProcessorInterface;
use n5s\BlockConverter\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class InlineStyleNormalizerTest extends TestCase
{
    private InlineStyleNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new InlineStyleNormalizer();
    }

    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(PreProcessorInterface::class, $this->normalizer);
    }

    public function testPriority(): void
    {
        $this->assertSame(6, $this->normalizer->priority());
    }

    public function testRunsBeforeBlockParsing(): void
    {
        $this->assertTrue($this->normalizer->runsBeforeBlockParsing());
    }

    public function testReturnsEmptyStringForEmptyInput(): void
    {
        $this->assertSame('', $this->normalizer->process(''));
    }

    public function testConvertsTextAlignCenterOnParagraph(): void
    {
        $input = '<p style="text-align: center">Centered text</p>';
        $result = $this->normalizer->process($input);

        $this->assertStringContainsString('has-text-align-center', $result);
        $this->assertStringNotContainsString('style', $result);
    }

    public function testConvertsTextAlignRightOnParagraph(): void
    {
        $input = '<p style="text-align: right">Right text</p>';
        $result = $this->normalizer->process($input);

        $this->assertStringContainsString('has-text-align-right', $result);
        $this->assertStringNotContainsString('style', $result);
    }

    public function testConvertsTextAlignJustifyOnParagraph(): void
    {
        $input = '<p style="text-align: justify">Justified text</p>';
        $result = $this->normalizer->process($input);

        $this->assertStringContainsString('has-text-align-justify', $result);
        $this->assertStringNotContainsString('style', $result);
    }

    public function testConvertsTextAlignLeftOnParagraph(): void
    {
        $input = '<p style="text-align: left">Left text</p>';
        $result = $this->normalizer->process($input);

        $this->assertStringContainsString('has-text-align-left', $result);
        $this->assertStringNotContainsString('style', $result);
    }

    public function testConvertsTextAlignOnHeading(): void
    {
        $input = '<h2 style="text-align: center">Centered Heading</h2>';
        $result = $this->normalizer->process($input);

        $this->assertStringContainsString('has-text-align-center', $result);
        $this->assertStringNotContainsString('style', $result);
    }

    public function testConvertsTextAlignOnTableCell(): void
    {
        $input = '<td style="text-align: center">Cell</td>';
        $result = $this->normalizer->process($input);

        $this->assertStringContainsString('has-text-align-center', $result);
        $this->assertStringNotContainsString('style', $result);
    }

    public function testConvertsTextAlignOnTableHeader(): void
    {
        $input = '<th style="text-align: right">Header</th>';
        $result = $this->normalizer->process($input);

        $this->assertStringContainsString('has-text-align-right', $result);
        $this->assertStringNotContainsString('style', $result);
    }

    public function testConvertsTextAlignOnBlockquote(): void
    {
        $input = '<blockquote style="text-align: center">A quote</blockquote>';
        $result = $this->normalizer->process($input);

        $this->assertStringContainsString('has-text-align-center', $result);
        $this->assertStringNotContainsString('style', $result);
    }

    public function testConvertsFloatLeftOnImg(): void
    {
        $input = '<img src="photo.jpg" style="float: left">';
        $result = $this->normalizer->process($input);

        $this->assertStringContainsString('alignleft', $result);
        $this->assertStringNotContainsString('style', $result);
    }

    public function testConvertsFloatRightOnImg(): void
    {
        $input = '<img src="photo.jpg" style="float: right">';
        $result = $this->normalizer->process($input);

        $this->assertStringContainsString('alignright', $result);
        $this->assertStringNotContainsString('style', $result);
    }

    public function testDoesNotConvertFloatOnNonImgTags(): void
    {
        $input = '<div style="float: left">Content</div>';
        $result = $this->normalizer->process($input);

        $this->assertStringNotContainsString('alignleft', $result);
        $this->assertStringContainsString('float: left', $result);
    }

    public function testPreservesOtherStyleProperties(): void
    {
        $input = '<p style="font-weight: 400; text-align: center">Text</p>';
        $result = $this->normalizer->process($input);

        $this->assertStringContainsString('has-text-align-center', $result);
        $this->assertStringContainsString('font-weight: 400', $result);
        // The style attribute should no longer contain text-align (only the class does)
        $this->assertStringNotContainsString('text-align:', $result);
    }

    public function testPreservesOtherStylePropertiesOnImg(): void
    {
        $input = '<img src="photo.jpg" style="float: left; width: 300px">';
        $result = $this->normalizer->process($input);

        $this->assertStringContainsString('alignleft', $result);
        $this->assertStringContainsString('width: 300px', $result);
        $this->assertStringNotContainsString('float', $result);
    }

    public function testRemovesStyleAttributeWhenEmpty(): void
    {
        $input = '<p style="text-align: center">Text</p>';
        $result = $this->normalizer->process($input);

        $this->assertStringNotContainsString('style=', $result);
    }

    public function testHandlesTextAlignWithoutSpaceAroundColon(): void
    {
        $input = '<p style="text-align:center">Text</p>';
        $result = $this->normalizer->process($input);

        $this->assertStringContainsString('has-text-align-center', $result);
    }

    public function testHandlesTextAlignWithExtraSpaces(): void
    {
        $input = '<p style="text-align :  center">Text</p>';
        $result = $this->normalizer->process($input);

        $this->assertStringContainsString('has-text-align-center', $result);
    }

    public function testNoOpWhenNoRelevantStyles(): void
    {
        $input = '<p style="color: red; font-size: 14px">Text</p>';
        $result = $this->normalizer->process($input);

        $this->assertStringContainsString('color: red', $result);
        $this->assertStringContainsString('font-size: 14px', $result);
        $this->assertStringNotContainsString('has-text-align', $result);
    }

    public function testNoOpWhenNoStyleAttribute(): void
    {
        $input = '<p>Plain text</p>';
        $result = $this->normalizer->process($input);

        $this->assertSame($input, $result);
    }

    public function testPreservesExistingClasses(): void
    {
        $input = '<p class="custom-class" style="color:red; text-align: center">Text</p>';
        $result = $this->normalizer->process($input);

        $this->assertStringContainsString('custom-class', $result);
        $this->assertStringContainsString('has-text-align-center', $result);
    }

    public function testHandlesMultipleElementsInContent(): void
    {
        $input = '<p style="text-align: center">Centered</p><p style="text-align: right">Right</p>';
        $result = $this->normalizer->process($input);

        $this->assertStringContainsString('has-text-align-center', $result);
        $this->assertStringContainsString('has-text-align-right', $result);
    }

    public function testHandlesImgWithBothFloatAndTextAlign(): void
    {
        $input = '<img src="photo.jpg" style="float: left; text-align: center">';
        $result = $this->normalizer->process($input);

        $this->assertStringContainsString('alignleft', $result);
        $this->assertStringContainsString('has-text-align-center', $result);
        $this->assertStringNotContainsString('style', $result);
    }

    public function testIgnoresUnknownTextAlignValues(): void
    {
        $input = '<p style="text-align: invalid">Text</p>';
        $result = $this->normalizer->process($input);

        $this->assertStringNotContainsString('has-text-align', $result);
        $this->assertStringContainsString('text-align: invalid', $result);
    }

    public function testNormalizesInlineStyleInsideExistingBlock(): void
    {
        $input = <<<'HTML'
            <!-- wp:paragraph -->
            <p style="text-align: center">Centered in block</p>
            <!-- /wp:paragraph -->
            HTML;

        $result = $this->normalizer->process($input);

        $this->assertStringContainsString('has-text-align-center', $result);
        $this->assertStringNotContainsString('text-align:', $result);
        $this->assertStringContainsString('<!-- wp:paragraph -->', $result);
        $this->assertStringContainsString('<!-- /wp:paragraph -->', $result);
    }

    public function testNormalizesInMixedBlockAndRawContent(): void
    {
        $input = <<<'HTML'
            <!-- wp:heading -->
            <h2 style="text-align: right">Block heading</h2>
            <!-- /wp:heading -->

            <p style="text-align: center">Raw paragraph</p>
            HTML;

        $result = $this->normalizer->process($input);

        $this->assertStringContainsString('has-text-align-right', $result);
        $this->assertStringContainsString('has-text-align-center', $result);
    }

    public function testPreservesBlockCommentDelimiters(): void
    {
        $input = <<<'HTML'
            <!-- wp:image {"id":42} -->
            <figure><img src="photo.jpg" style="float: left" /></figure>
            <!-- /wp:image -->
            HTML;

        $result = $this->normalizer->process($input);

        $this->assertStringContainsString('<!-- wp:image {"id":42} -->', $result);
        $this->assertStringContainsString('<!-- /wp:image -->', $result);
        $this->assertStringContainsString('alignleft', $result);
    }

    public function testNormalizesInNestedBlocks(): void
    {
        $input = <<<'HTML'
            <!-- wp:quote -->
            <blockquote>
            <!-- wp:paragraph -->
            <p style="text-align: center">Centered quote</p>
            <!-- /wp:paragraph -->
            </blockquote>
            <!-- /wp:quote -->
            HTML;

        $result = $this->normalizer->process($input);

        $this->assertStringContainsString('has-text-align-center', $result);
        $this->assertStringContainsString('<!-- wp:quote -->', $result);
        $this->assertStringContainsString('<!-- /wp:quote -->', $result);
    }

    /**
     * The pattern this replaced consumed the separator on both sides of the
     * declaration it removed, so a property in the middle of a rule took its
     * neighbours' semicolon with it and glued them together. Every existing
     * test happened to put the property first or last, where that is invisible.
     *
     * @param list<string> $expectedDeclarations
     */
    #[DataProvider('stylesWithSurvivingDeclarations')]
    public function testNeighbouringDeclarationsSurviveTheRemoval(string $input, array $expectedDeclarations): void
    {
        $result = $this->normalizer->process($input);

        $this->assertStringContainsString('has-text-align-center', $result);

        $style = $this->styleOf($result);

        foreach ($expectedDeclarations as $declaration) {
            $this->assertStringContainsString($declaration, $style);
        }

        // On the class, not in the rule it was lifted out of.
        $this->assertStringNotContainsString('text-align', $style);
    }

    public function testTheOtherDeclarationsAreKeptAsWritten(): void
    {
        // Legacy rules are often half-typed. A fragment with no colon sets
        // nothing, but re-serialising the rule from parsed parts dropped it,
        // and dropped anything a naive split had cut in two along with it.
        $result = $this->normalizer->process('<p style="color: red; garbage; text-align: center">x</p>');

        $this->assertStringContainsString('has-text-align-center', $result);
        $this->assertSame('color: red; garbage', self::styleOf($result));
    }

    /**
     * A semicolon inside a url() or a quoted string is part of the value.
     * Splitting on it truncated the value and dropped the remainder.
     */
    #[DataProvider('valuesHoldingASemicolon')]
    public function testASemicolonInsideAValueDoesNotSplitIt(string $style, string $expected): void
    {
        $result = $this->normalizer->process(\sprintf('<p style="%s">x</p>', $style));

        $this->assertStringContainsString('has-text-align-center', $result);
        $this->assertSame($expected, self::styleOf($result));
    }

    /** @return \Iterator<string, array{0: string, 1: string}> */
    public static function valuesHoldingASemicolon(): \Iterator
    {
        yield 'data URI' => [
            'background: url(data:image/png;base64,AAAA); text-align: center',
            'background: url(data:image/png;base64,AAAA)',
        ];
        yield 'quoted font name' => [
            "font-family: 'a;b'; text-align: center",
            "font-family: 'a;b'",
        ];
        yield 'quoted url with a parenthesis' => [
            "background: url('x;y).png'); text-align: center; color: red",
            "background: url('x;y).png'); color: red",
        ];
    }

    /**
     * An unclosed parenthesis or quote would fold everything after it into
     * one declaration; the rule is then split on every semicolon instead,
     * which is what safecss_filter_attr() does and what recovers the
     * alignment here.
     */
    #[DataProvider('unbalancedStyles')]
    public function testAnUnbalancedRuleFallsBackToThePlainSplit(string $style, string $expected): void
    {
        $result = $this->normalizer->process(\sprintf('<p style="%s">y</p>', $style));

        $this->assertStringContainsString('has-text-align-center', $result);
        $this->assertSame($expected, self::styleOf($result));
    }

    /** @return \Iterator<string, array{0: string, 1: string}> */
    public static function unbalancedStyles(): \Iterator
    {
        yield 'unclosed parenthesis' => ['background:url(x;text-align:center', 'background:url(x'];
        yield 'unclosed quote' => ["font-family:'a;text-align:center", "font-family:'a"];
    }

    public function testTheLastDeclarationOfAPropertyWins(): void
    {
        // As in the cascade: the browser centres this text.
        $result = $this->normalizer->process('<p style="text-align:left;text-align:center">x</p>');

        $this->assertStringContainsString('has-text-align-center', $result);
        $this->assertStringNotContainsString('has-text-align-left', $result);
        $this->assertSame('', self::styleOf($result));
    }

    /** The style attribute of the first tag, or an empty string once removed. */
    private function styleOf(string $html): string
    {
        $processor = new \WP_HTML_Tag_Processor($html);
        $processor->next_tag();
        $style = $processor->get_attribute('style');

        return \is_string($style) ? $style : '';
    }

    /** @return \Iterator<string, array{0: string, 1: list<string>}> */
    public static function stylesWithSurvivingDeclarations(): \Iterator
    {
        yield 'in the middle' => [
            '<p style="color: red; text-align: center; font-size: 14px">x</p>',
            ['color: red', 'font-size: 14px'],
        ];
        yield 'first' => ['<p style="text-align: center; color: red">x</p>', ['color: red']];
        yield 'last' => ['<p style="color: red; text-align: center">x</p>', ['color: red']];
        yield 'no spaces' => [
            '<p style="color:red;text-align:center;font-size:14px">x</p>',
            ['color:red', 'font-size:14px'],
        ];
        yield 'trailing semicolon' => ['<p style="color: red; text-align: center;">x</p>', ['color: red']];
        yield 'uppercase property' => [
            '<p style="COLOR: red; TEXT-ALIGN: CENTER; font-size: 14px">x</p>',
            ['COLOR: red', 'font-size: 14px'],
        ];
    }

    public function testAValueHoldingAColonIsNotSplitOnIt(): void
    {
        $result = $this->normalizer->process('<p style="background: url(http://x/a.png); text-align: center">x</p>');

        $this->assertStringContainsString('background: url(http://x/a.png)', $result);
    }

    public function testFloatRemovalAlsoKeepsItsNeighbours(): void
    {
        $result = $this->normalizer->process('<img style="border: 1px; float: left; margin: 4px" src="/a.jpg">');
        $style = $this->styleOf($result);

        $this->assertStringContainsString('alignleft', $result);
        $this->assertStringContainsString('border: 1px', $style);
        $this->assertStringContainsString('margin: 4px', $style);
        $this->assertStringNotContainsString('float', $style);
    }

    public function testBothPropertiesCanBeLiftedFromTheSameRule(): void
    {
        $result = $this->normalizer->process('<img style="text-align: center; float: right; padding: 2px" src="/a.jpg">');

        $this->assertStringContainsString('has-text-align-center', $result);
        $this->assertStringContainsString('alignright', $result);
        $this->assertStringContainsString('padding: 2px', $result);
    }
}
