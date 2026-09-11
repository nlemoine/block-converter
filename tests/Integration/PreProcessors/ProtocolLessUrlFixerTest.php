<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Integration\PreProcessors;

use n5s\BlockConverter\PreProcessors\PreProcessorInterface;
use n5s\BlockConverter\PreProcessors\ProtocolLessUrlFixer;
use n5s\BlockConverter\Tests\WpTestCase;

final class ProtocolLessUrlFixerTest extends WpTestCase
{
    private ProtocolLessUrlFixer $fixer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixer = new ProtocolLessUrlFixer();
    }

    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(PreProcessorInterface::class, $this->fixer);
    }

    public function testPriority(): void
    {
        $this->assertSame(3, $this->fixer->priority());
    }

    public function testRunsBeforeBlockParsing(): void
    {
        $this->assertTrue($this->fixer->runsBeforeBlockParsing());
    }

    public function testReturnsEmptyStringForEmptyInput(): void
    {
        $this->assertSame('', $this->fixer->process(''));
    }

    public function testFixesProtocolLessImgSrc(): void
    {
        $input = '<img src="//www.example.com/content/uploads/2015/01/photo.jpg" alt="photo" />';
        $result = $this->fixer->process($input);
        $this->assertStringContainsString('src="https://www.example.com/content/uploads/2015/01/photo.jpg"', $result);
    }

    public function testFixesProtocolLessHref(): void
    {
        $input = '<a href="//www.example.com/article">lire la suite</a>';
        $result = $this->fixer->process($input);
        $this->assertStringContainsString('href="https://www.example.com/article"', $result);
    }

    public function testFixesProtocolLessPoster(): void
    {
        $input = '<video poster="//cdn.example.com/thumb.jpg"></video>';
        $result = $this->fixer->process($input);
        $this->assertStringContainsString('poster="https://cdn.example.com/thumb.jpg"', $result);
    }

    public function testFixesMultipleAttributesOnDifferentTags(): void
    {
        $input = '<p><a href="//www.example.com"><img src="//cdn.example.com/img.jpg" /></a></p>';
        $result = $this->fixer->process($input);
        $this->assertStringContainsString('href="https://www.example.com"', $result);
        $this->assertStringContainsString('src="https://cdn.example.com/img.jpg"', $result);
    }

    public function testLeavesHttpsUrlsUntouched(): void
    {
        $input = '<img src="https://www.example.com/img.jpg" />';
        $this->assertSame($input, $this->fixer->process($input));
    }

    public function testLeavesHttpUrlsUntouched(): void
    {
        $input = '<img src="http://www.example.com/img.jpg" />';
        $this->assertSame($input, $this->fixer->process($input));
    }

    public function testLeavesRelativeUrlsUntouched(): void
    {
        $input = '<img src="/uploads/photo.jpg" />';
        $this->assertSame($input, $this->fixer->process($input));
    }

    public function testLeavesTextContentUntouched(): void
    {
        $input = '<p>Voir //www.example.com pour plus de détails</p>';
        $this->assertSame($input, $this->fixer->process($input));
    }

    public function testSkipsContentWithoutDoubleSlash(): void
    {
        $input = '<p>Simple text with <strong>no URLs</strong></p>';
        $this->assertSame($input, $this->fixer->process($input));
    }

    public function testFixesProtocolLessUrlInsideExistingBlock(): void
    {
        $input = <<<'HTML'
            <!-- wp:image {"id":42} -->
            <figure><img src="//cdn.example.com/photo.jpg" /></figure>
            <!-- /wp:image -->
            HTML;

        $result = $this->fixer->process($input);

        $this->assertStringContainsString('src="https://cdn.example.com/photo.jpg"', $result);
        $this->assertStringContainsString('<!-- wp:image {"id":42} -->', $result);
        $this->assertStringContainsString('<!-- /wp:image -->', $result);
    }

    public function testFixesInMixedBlockAndRawContent(): void
    {
        $input = <<<'HTML'
            <!-- wp:paragraph -->
            <p><a href="//www.example.com/link">In block</a></p>
            <!-- /wp:paragraph -->

            <p><img src="//cdn.example.com/raw.jpg" /></p>
            HTML;

        $result = $this->fixer->process($input);

        $this->assertStringContainsString('href="https://www.example.com/link"', $result);
        $this->assertStringContainsString('src="https://cdn.example.com/raw.jpg"', $result);
    }

    public function testPreservesBlockCommentDelimiters(): void
    {
        $input = <<<'HTML'
            <!-- wp:paragraph -->
            <p><a href="//example.com">link</a></p>
            <!-- /wp:paragraph -->
            HTML;

        $result = $this->fixer->process($input);

        $this->assertStringContainsString('<!-- wp:paragraph -->', $result);
        $this->assertStringContainsString('<!-- /wp:paragraph -->', $result);
    }

    public function testFixesProtocolLessUrlInNestedBlocks(): void
    {
        $input = <<<'HTML'
            <!-- wp:quote -->
            <blockquote>
            <!-- wp:paragraph -->
            <p><a href="//www.example.com">citation</a></p>
            <!-- /wp:paragraph -->
            </blockquote>
            <!-- /wp:quote -->
            HTML;

        $result = $this->fixer->process($input);

        $this->assertStringContainsString('href="https://www.example.com"', $result);
        $this->assertStringContainsString('<!-- wp:quote -->', $result);
        $this->assertStringContainsString('<!-- /wp:quote -->', $result);
    }

    public function testFixesMultipleProtocolLessUrlsAcrossBlockBoundaries(): void
    {
        $input = <<<'HTML'
            <!-- wp:image -->
            <figure><img src="//cdn.example.com/img1.jpg" /></figure>
            <!-- /wp:image -->

            <!-- wp:image -->
            <figure><img src="//cdn.example.com/img2.jpg" /></figure>
            <!-- /wp:image -->

            <a href="//www.example.com">raw link</a>
            HTML;

        $result = $this->fixer->process($input);

        $this->assertStringContainsString('src="https://cdn.example.com/img1.jpg"', $result);
        $this->assertStringContainsString('src="https://cdn.example.com/img2.jpg"', $result);
        $this->assertStringContainsString('href="https://www.example.com"', $result);
    }

    public function testFixesPosterAttributeInsideBlock(): void
    {
        $input = <<<'HTML'
            <!-- wp:video -->
            <figure><video poster="//cdn.example.com/thumb.jpg" src="video.mp4"></video></figure>
            <!-- /wp:video -->
            HTML;

        $result = $this->fixer->process($input);

        $this->assertStringContainsString('poster="https://cdn.example.com/thumb.jpg"', $result);
    }
}
