<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Unit\PreProcessors;

use n5s\BlockConverter\PreProcessors\DeprecatedTagReplacer;
use n5s\BlockConverter\PreProcessors\PreProcessorInterface;
use n5s\BlockConverter\Tests\TestCase;

final class DeprecatedTagReplacerTest extends TestCase
{
    private DeprecatedTagReplacer $replacer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->replacer = new DeprecatedTagReplacer();
    }

    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(PreProcessorInterface::class, $this->replacer);
    }

    public function testPriority(): void
    {
        $this->assertSame(4, $this->replacer->priority());
    }

    public function testRunsBeforeBlockParsing(): void
    {
        $this->assertTrue($this->replacer->runsBeforeBlockParsing());
    }

    public function testReturnsEmptyStringForEmptyInput(): void
    {
        $this->assertSame('', $this->replacer->process(''));
    }

    public function testReplacesBoldTag(): void
    {
        $input = '<p>Le croissant est <b>croustillant</b></p>';
        $expected = '<p>Le croissant est <strong>croustillant</strong></p>';
        $this->assertSame($expected, $this->replacer->process($input));
    }

    public function testReplacesItalicTag(): void
    {
        $input = '<p>La baguette est <i>tradition</i></p>';
        $expected = '<p>La baguette est <em>tradition</em></p>';
        $this->assertSame($expected, $this->replacer->process($input));
    }

    public function testReplacesNestedTags(): void
    {
        $input = '<p><b>Pain au chocolat</b> ou <i>chocolatine</i> ?</p>';
        $expected = '<p><strong>Pain au chocolat</strong> ou <em>chocolatine</em> ?</p>';
        $this->assertSame($expected, $this->replacer->process($input));
    }

    public function testReplacesUppercaseTags(): void
    {
        $input = '<p><B>Gras</B> et <I>italique</I></p>';
        $expected = '<p><strong>Gras</strong> et <em>italique</em></p>';
        $this->assertSame($expected, $this->replacer->process($input));
    }

    public function testReplacesTagsWithAttributes(): void
    {
        $input = '<p><b class="legacy">gras</b> et <i style="color:red">rouge</i></p>';
        $expected = '<p><strong class="legacy">gras</strong> et <em style="color:red">rouge</em></p>';
        $this->assertSame($expected, $this->replacer->process($input));
    }

    public function testLeavesStrongAndEmUntouched(): void
    {
        $input = '<p><strong>déjà correct</strong> et <em>aussi</em></p>';
        $this->assertSame($input, $this->replacer->process($input));
    }

    public function testDoesNotAffectOtherTags(): void
    {
        $input = '<p><br /><blockquote>citation</blockquote><img src="photo.jpg" /></p>';
        $this->assertSame($input, $this->replacer->process($input));
    }

    public function testSkipsContentWithoutDeprecatedTags(): void
    {
        $input = '<p>Texte simple sans formatage</p>';
        $this->assertSame($input, $this->replacer->process($input));
    }

    public function testReplacesDeprecatedTagsInsideExistingBlocks(): void
    {
        $input = <<<'HTML'
            <!-- wp:paragraph -->
            <p>Le texte est <b>gras</b> et <i>italique</i></p>
            <!-- /wp:paragraph -->
            HTML;

        $expected = <<<'HTML'
            <!-- wp:paragraph -->
            <p>Le texte est <strong>gras</strong> et <em>italique</em></p>
            <!-- /wp:paragraph -->
            HTML;

        $this->assertSame($expected, $this->replacer->process($input));
    }

    public function testReplacesInMixedBlockAndRawContent(): void
    {
        $input = <<<'HTML'
            <!-- wp:heading -->
            <h2><b>Block heading</b></h2>
            <!-- /wp:heading -->

            <p><i>Raw paragraph</i></p>
            HTML;

        $result = $this->replacer->process($input);

        $this->assertStringContainsString('<strong>Block heading</strong>', $result);
        $this->assertStringContainsString('<em>Raw paragraph</em>', $result);
        $this->assertStringNotContainsString('<b>', $result);
        $this->assertStringNotContainsString('<i>', $result);
    }

    public function testPreservesBlockCommentDelimiters(): void
    {
        $input = <<<'HTML'
            <!-- wp:paragraph -->
            <p><b>bold</b></p>
            <!-- /wp:paragraph -->
            HTML;

        $result = $this->replacer->process($input);

        $this->assertStringContainsString('<!-- wp:paragraph -->', $result);
        $this->assertStringContainsString('<!-- /wp:paragraph -->', $result);
    }

    public function testDoesNotMatchBrOrBlockquoteInsideBlocks(): void
    {
        $input = <<<'HTML'
            <!-- wp:paragraph -->
            <p>Line one<br />Line two</p>
            <!-- /wp:paragraph -->

            <blockquote><b>Bold quote</b></blockquote>
            HTML;

        $result = $this->replacer->process($input);

        $this->assertStringContainsString('<br />', $result);
        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('<strong>Bold quote</strong>', $result);
    }

    public function testCustomTagMap(): void
    {
        $replacer = new DeprecatedTagReplacer(['strike' => 'del', 'tt' => 'code']);

        $input = '<p><strike>removed</strike> and <tt>monospace</tt></p>';
        $expected = '<p><del>removed</del> and <code>monospace</code></p>';
        $this->assertSame($expected, $replacer->process($input));
    }

    public function testATagNameIsMatchedLiterally(): void
    {
        // Custom element names may hold a dot; unquoted, it matched any character.
        $replacer = new DeprecatedTagReplacer(['x.y' => 'span']);

        $this->assertSame('<span>a</span> <xzy>b</xzy>', $replacer->process('<x.y>a</x.y> <xzy>b</xzy>'));
    }

    public function testCustomTagMapDoesNotReplaceDefaultTags(): void
    {
        $replacer = new DeprecatedTagReplacer(['strike' => 'del']);

        $input = '<p><b>bold</b> and <strike>removed</strike></p>';
        $expected = '<p><b>bold</b> and <del>removed</del></p>';
        $this->assertSame($expected, $replacer->process($input));
    }

    public function testReplacesInNestedBlocks(): void
    {
        $input = <<<'HTML'
            <!-- wp:quote -->
            <blockquote class="wp-block-quote">
            <!-- wp:paragraph -->
            <p><b>Bold</b> and <i>italic</i></p>
            <!-- /wp:paragraph -->
            </blockquote>
            <!-- /wp:quote -->
            HTML;

        $result = $this->replacer->process($input);

        $this->assertStringContainsString('<strong>Bold</strong>', $result);
        $this->assertStringContainsString('<em>italic</em>', $result);
        $this->assertStringContainsString('<!-- wp:quote -->', $result);
        $this->assertStringContainsString('<!-- wp:paragraph -->', $result);
    }
}
