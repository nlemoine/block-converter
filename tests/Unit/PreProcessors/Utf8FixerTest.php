<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Unit\PreProcessors;

use n5s\BlockConverter\BlockConverter;
use n5s\BlockConverter\PreProcessors\PreProcessorInterface;
use n5s\BlockConverter\PreProcessors\Utf8Fixer;
use n5s\BlockConverter\Tests\TestCase;

final class Utf8FixerTest extends TestCase
{
    private Utf8Fixer $fixer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixer = new Utf8Fixer();
    }

    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(PreProcessorInterface::class, $this->fixer);
    }

    public function testCreateDefaultDoesNotRegisterTheFixer(): void
    {
        $preProcessors = BlockConverter::createDefault()->getRegistry()->getEarlyPreProcessors();

        $this->assertNotEmpty($preProcessors);
        $this->assertEmpty(\array_filter(
            $preProcessors,
            static fn (PreProcessorInterface $p): bool => $p instanceof Utf8Fixer,
        ));
    }

    public function testCanBeRegisteredExplicitly(): void
    {
        $registry = BlockConverter::createDefault()->getRegistry();
        $registry->registerPreProcessor(new Utf8Fixer());

        $this->assertNotEmpty(\array_filter(
            $registry->getEarlyPreProcessors(),
            static fn (PreProcessorInterface $p): bool => $p instanceof Utf8Fixer,
        ));
    }

    public function testPriorityIsLowest(): void
    {
        $this->assertSame(1, $this->fixer->priority());
    }

    public function testRunsBeforeBlockParsing(): void
    {
        $this->assertTrue($this->fixer->runsBeforeBlockParsing());
    }

    public function testEmptyContentShortCircuits(): void
    {
        $this->assertSame('', $this->fixer->process(''));
    }

    public function testLeavesValidUtf8Untouched(): void
    {
        $input = '<p>Hello world! Héllo wörld! 日本語</p>';
        $this->assertSame($input, $this->fixer->process($input));
    }

    public function testFixesInvalidUtf8Sequences(): void
    {
        // \x80 is an invalid continuation byte on its own
        $input = "Hello \x80 world";
        $result = $this->fixer->process($input);

        // Should not contain the raw invalid byte
        $this->assertTrue(\mb_check_encoding($result, 'UTF-8'));
        $this->assertStringContainsString('Hello', $result);
        $this->assertStringContainsString('world', $result);
    }

    public function testPreservesEmoji(): void
    {
        $input = '<p>Hello 🌍🎉</p>';
        $this->assertSame($input, $this->fixer->process($input));
    }

    public function testFixesMojibakeInBlockAttributes(): void
    {
        $input = <<<'HTML'
            <!-- wp:image {"alt":"DÃ¼sseldorf"} -->
            <figure><img src="photo.jpg" alt="DÃ¼sseldorf" /></figure>
            <!-- /wp:image -->
            HTML;

        $result = $this->fixer->process($input);

        $this->assertStringContainsString('"alt":"Düsseldorf"', $result);
        $this->assertStringContainsString('alt="Düsseldorf"', $result);
    }

    public function testFixesMojibakeInMixedContent(): void
    {
        $input = <<<'HTML'
            <!-- wp:paragraph -->
            <p>CafÃ© in DÃ¼sseldorf</p>
            <!-- /wp:paragraph -->

            <p>CrÃ¨me brÃ»lÃ©e</p>
            HTML;

        $result = $this->fixer->process($input);

        // Both existing block content and raw HTML are fixed
        $this->assertStringContainsString('Café in Düsseldorf', $result);
        $this->assertStringContainsString('Crème brûlée', $result);
    }

    public function testPreservesBlockCommentStructure(): void
    {
        $input = <<<'HTML'
            <!-- wp:heading {"level":2} -->
            <h2>Valid UTF-8 heading</h2>
            <!-- /wp:heading -->
            HTML;

        $this->assertSame($input, $this->fixer->process($input));
    }

    public function testFixesMojibakeInNestedBlockAttributes(): void
    {
        $input = <<<'HTML'
            <!-- wp:gallery {"columns":3} -->
            <!-- wp:image {"alt":"Ã©tÃ©"} -->
            <figure><img src="summer.jpg" alt="Ã©tÃ©" /></figure>
            <!-- /wp:image -->
            <!-- /wp:gallery -->
            HTML;

        $result = $this->fixer->process($input);

        $this->assertStringContainsString('"alt":"été"', $result);
        $this->assertStringContainsString('alt="été"', $result);
        $this->assertStringContainsString('<!-- wp:gallery {"columns":3} -->', $result);
        $this->assertStringContainsString('<!-- /wp:gallery -->', $result);
    }

    public function testHandlesInvalidBytesInBlockContent(): void
    {
        $input = "<!-- wp:paragraph -->\n<p>Text with \x80 invalid bytes</p>\n<!-- /wp:paragraph -->";

        $result = $this->fixer->process($input);

        $this->assertTrue(\mb_check_encoding($result, 'UTF-8'));
        $this->assertStringContainsString('<!-- wp:paragraph -->', $result);
        $this->assertStringContainsString('<!-- /wp:paragraph -->', $result);
    }
}
