<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Unit;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\BlockConverter;
use n5s\BlockConverter\ConverterRegistry;
use n5s\BlockConverter\PostProcessors\PostProcessorInterface;
use n5s\BlockConverter\PreProcessors\PreProcessorInterface;
use n5s\BlockConverter\ShortcodeConverters\ShortcodeConverterInterface;
use n5s\BlockConverter\TagConverters\TagConverterInterface;
use n5s\BlockConverter\Tests\Support\StubPostProcessor;
use n5s\BlockConverter\Tests\Support\StubPreProcessor;
use n5s\BlockConverter\Tests\TestCase;
use voku\helper\SimpleHtmlDomInterface;
use WP_Post;

final class ConverterRegistryTest extends TestCase
{
    public function testRegisterAndRetrieveTagConverter(): void
    {
        $registry = new ConverterRegistry();

        $converter = new class implements TagConverterInterface {
            public static function tags(): array
            {
                return ['p', 'span'];
            }

            public function convert(SimpleHtmlDomInterface $element, ?WP_Post $post = null): Block|array|null
            {
                return null;
            }
        };

        $registry->registerTagConverter($converter);

        $this->assertSame($converter, $registry->getTagConverter('p'));
        $this->assertSame($converter, $registry->getTagConverter('span'));
        $this->assertNull($registry->getTagConverter('div'));
    }

    public function testTagLookupIsCaseInsensitive(): void
    {
        $registry = new ConverterRegistry();

        $converter = new class implements TagConverterInterface {
            public static function tags(): array
            {
                return ['P'];
            }

            public function convert(SimpleHtmlDomInterface $element, ?WP_Post $post = null): Block|array|null
            {
                return null;
            }
        };

        $registry->registerTagConverter($converter);

        $this->assertSame($converter, $registry->getTagConverter('p'));
        $this->assertSame($converter, $registry->getTagConverter('P'));
    }

    public function testLaterRegistrationOverridesEarlier(): void
    {
        $registry = new ConverterRegistry();

        $first = new class implements TagConverterInterface {
            public static function tags(): array
            {
                return ['p'];
            }

            public function convert(SimpleHtmlDomInterface $element, ?WP_Post $post = null): Block|array|null
            {
                return null;
            }
        };

        $second = new class implements TagConverterInterface {
            public static function tags(): array
            {
                return ['p'];
            }

            public function convert(SimpleHtmlDomInterface $element, ?WP_Post $post = null): Block|array|null
            {
                return null;
            }
        };

        $registry->registerTagConverter($first);
        $registry->registerTagConverter($second);

        $this->assertSame($second, $registry->getTagConverter('p'));
    }

    public function testRegisterAndRetrieveShortcodeConverter(): void
    {
        $registry = new ConverterRegistry();

        $converter = new class implements ShortcodeConverterInterface {
            public static function shortcodes(): array
            {
                return ['gallery'];
            }

            public function convert(array $atts, ?string $content, string $tag, ?WP_Post $post = null): Block
            {
                return new Block('shortcode', innerContent: ['']);
            }
        };

        $registry->registerShortcodeConverter($converter);

        $converters = $registry->getShortcodeConverters();

        $this->assertArrayHasKey('gallery', $converters);
        $this->assertSame($converter, $converters['gallery']);
    }

    public function testRegisterAndRetrievePreProcessors(): void
    {
        $registry = new ConverterRegistry();

        $late = new class implements PreProcessorInterface {
            public function priority(): int
            {
                return 10;
            }

            public function runsBeforeBlockParsing(): bool
            {
                return false;
            }

            public function process(string $html, ?WP_Post $post = null): string
            {
                return $html;
            }
        };

        $early = new class implements PreProcessorInterface {
            public function priority(): int
            {
                return 5;
            }

            public function runsBeforeBlockParsing(): bool
            {
                return true;
            }

            public function process(string $html, ?WP_Post $post = null): string
            {
                return $html;
            }
        };

        $registry->registerPreProcessor($late);
        $registry->registerPreProcessor($early);

        $this->assertCount(1, $registry->getPreProcessors());
        $this->assertSame($late, $registry->getPreProcessors()[0]);

        $this->assertCount(1, $registry->getEarlyPreProcessors());
        $this->assertSame($early, $registry->getEarlyPreProcessors()[0]);
    }

    public function testRegisterAndRetrievePostProcessors(): void
    {
        $registry = new ConverterRegistry();

        $processor = new class implements PostProcessorInterface {
            public function priority(): int
            {
                return 10;
            }

            /**
             * @param  list<Block> $blocks
             * @return list<Block>
             */
            public function process(array $blocks, ?WP_Post $post = null): array
            {
                return $blocks;
            }
        };

        $registry->registerPostProcessor($processor);

        $processors = $registry->getPostProcessors();

        $this->assertCount(1, $processors);
        $this->assertSame($processor, $processors[0]);
    }

    public function testPreProcessorsReturnedInPriorityOrder(): void
    {
        $registry = new ConverterRegistry();

        $low = new class implements PreProcessorInterface {
            public function priority(): int
            {
                return 20;
            }

            public function runsBeforeBlockParsing(): bool
            {
                return false;
            }

            public function process(string $html, ?WP_Post $post = null): string
            {
                return $html;
            }
        };

        $high = new class implements PreProcessorInterface {
            public function priority(): int
            {
                return 1;
            }

            public function runsBeforeBlockParsing(): bool
            {
                return false;
            }

            public function process(string $html, ?WP_Post $post = null): string
            {
                return $html;
            }
        };

        // Register low priority first, high priority second
        $registry->registerPreProcessor($low);
        $registry->registerPreProcessor($high);

        $processors = $registry->getPreProcessors();
        $this->assertSame($high, $processors[0]);
        $this->assertSame($low, $processors[1]);
    }

    public function testGetTagConverterReturnsNullForUnknownTag(): void
    {
        $registry = new ConverterRegistry();

        $this->assertNull($registry->getTagConverter('nonexistent'));
    }

    public function testRegisterShortcodeTagAsFallback(): void
    {
        $registry = new ConverterRegistry();

        $registry->registerShortcodeTag('newsletter');
        $registry->registerShortcodeTag('legacy_widget');

        $converters = $registry->getShortcodeConverters();

        $this->assertArrayHasKey('newsletter', $converters);
        $this->assertNull($converters['newsletter']);
        $this->assertArrayHasKey('legacy_widget', $converters);
        $this->assertNull($converters['legacy_widget']);
    }

    public function testConverterTakesPrecedenceOverFallbackTag(): void
    {
        $registry = new ConverterRegistry();

        // Register as fallback first.
        $registry->registerShortcodeTag('gallery');

        // Then register a real converter — should override.
        $converter = new class implements ShortcodeConverterInterface {
            public static function shortcodes(): array
            {
                return ['gallery'];
            }

            public function convert(array $atts, ?string $content, string $tag, ?WP_Post $post = null): Block
            {
                return new Block('gallery', innerContent: ['']);
            }
        };

        $registry->registerShortcodeConverter($converter);

        $this->assertSame($converter, $registry->getShortcodeConverters()['gallery']);
    }

    public function testFallbackTagDoesNotOverrideConverter(): void
    {
        $registry = new ConverterRegistry();

        $converter = new class implements ShortcodeConverterInterface {
            public static function shortcodes(): array
            {
                return ['gallery'];
            }

            public function convert(array $atts, ?string $content, string $tag, ?WP_Post $post = null): Block
            {
                return new Block('gallery', innerContent: ['']);
            }
        };

        // Register converter first, then fallback tag — converter should survive.
        $registry->registerShortcodeConverter($converter);
        $registry->registerShortcodeTag('gallery');

        $this->assertSame($converter, $registry->getShortcodeConverters()['gallery']);
    }

    public function testRemoveTagConverter(): void
    {
        $registry = new ConverterRegistry();

        $converter = new class implements TagConverterInterface {
            public static function tags(): array
            {

                return ['p', 'span'];
            }

            public function convert(SimpleHtmlDomInterface $element, ?WP_Post $post = null): Block|array|null
            {

                return null;
            }
        };

        $registry->registerTagConverter($converter);
        $registry->removeTagConverter('p');

        $this->assertNull($registry->getTagConverter('p'));
        $this->assertSame($converter, $registry->getTagConverter('span'));
    }

    public function testRemoveTagConverterThrowsForUnknownTag(): void
    {
        $registry = new ConverterRegistry();

        $this->expectException(\InvalidArgumentException::class);
        $registry->removeTagConverter('nonexistent');
    }

    public function testRemoveTagConverterIsCaseInsensitive(): void
    {
        $registry = new ConverterRegistry();

        $converter = new class implements TagConverterInterface {
            public static function tags(): array
            {

                return ['p'];
            }

            public function convert(SimpleHtmlDomInterface $element, ?WP_Post $post = null): Block|array|null
            {

                return null;
            }
        };

        $registry->registerTagConverter($converter);
        $registry->removeTagConverter('P');

        $this->assertNull($registry->getTagConverter('p'));
    }

    public function testRemoveShortcodeConverter(): void
    {
        $registry = new ConverterRegistry();

        $converter = new class implements ShortcodeConverterInterface {
            public static function shortcodes(): array
            {

                return ['gallery'];
            }

            public function convert(array $atts, ?string $content, string $tag, ?WP_Post $post = null): Block
            {
                return new Block('gallery', innerContent: ['']);
            }
        };

        $registry->registerShortcodeConverter($converter);
        $registry->removeShortcodeConverter('gallery');

        $this->assertArrayNotHasKey('gallery', $registry->getShortcodeConverters());
    }

    public function testRemoveShortcodeConverterThrowsForUnknownShortcode(): void
    {
        $registry = new ConverterRegistry();

        $this->expectException(\InvalidArgumentException::class);
        $registry->removeShortcodeConverter('nonexistent');
    }

    public function testRemovePreProcessor(): void
    {
        $registry = new ConverterRegistry();

        $processor = new class implements PreProcessorInterface {
            public function priority(): int
            {

                return 1;
            }

            public function runsBeforeBlockParsing(): bool
            {

                return false;
            }

            public function process(string $html, ?WP_Post $post = null): string
            {

                return $html;
            }
        };

        $registry->registerPreProcessor($processor);
        $registry->removePreProcessor($processor::class);

        $this->assertCount(0, $registry->getPreProcessors());
    }

    public function testRemovePreProcessorThrowsWhenNotFound(): void
    {
        $registry = new ConverterRegistry();

        $this->expectException(\InvalidArgumentException::class);
        $registry->removePreProcessor('NonExistentProcessor');
    }

    public function testReplacePreProcessor(): void
    {
        $registry = new ConverterRegistry();

        $original = new class implements PreProcessorInterface {
            public function priority(): int
            {

                return 1;
            }

            public function runsBeforeBlockParsing(): bool
            {

                return false;
            }

            public function process(string $html, ?WP_Post $post = null): string
            {

                return 'original';
            }
        };

        $replacement = new class implements PreProcessorInterface {
            public function priority(): int
            {

                return 1;
            }

            public function runsBeforeBlockParsing(): bool
            {

                return false;
            }

            public function process(string $html, ?WP_Post $post = null): string
            {

                return 'replaced';
            }
        };

        $registry->registerPreProcessor($original);
        $registry->replacePreProcessor($original::class, $replacement);

        $processors = $registry->getPreProcessors();
        $this->assertCount(1, $processors);
        $this->assertSame($replacement, $processors[0]);
    }

    public function testReplacePreProcessorThrowsWhenNotFound(): void
    {
        $registry = new ConverterRegistry();

        $replacement = new class implements PreProcessorInterface {
            public function priority(): int
            {

                return 1;
            }

            public function runsBeforeBlockParsing(): bool
            {

                return false;
            }

            public function process(string $html, ?WP_Post $post = null): string
            {

                return '';
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        $registry->replacePreProcessor('NonExistentProcessor', $replacement);
    }

    public function testRemovePostProcessor(): void
    {
        $registry = new ConverterRegistry();

        $processor = new class implements PostProcessorInterface {
            public function priority(): int
            {

                return 10;
            }

            /**
             * @param  list<Block> $blocks
             * @return list<Block>
             */
            public function process(array $blocks, ?WP_Post $post = null): array
            {
                return $blocks;
            }
        };

        $registry->registerPostProcessor($processor);
        $registry->removePostProcessor($processor::class);

        $this->assertCount(0, $registry->getPostProcessors());
    }

    public function testRemovePostProcessorThrowsWhenNotFound(): void
    {
        $registry = new ConverterRegistry();

        $this->expectException(\InvalidArgumentException::class);
        $registry->removePostProcessor('NonExistentProcessor');
    }

    public function testReplacePostProcessor(): void
    {
        $registry = new ConverterRegistry();

        $original = new class implements PostProcessorInterface {
            public function priority(): int
            {

                return 10;
            }

            /**
             * @param  list<Block> $blocks
             * @return list<Block>
             */
            public function process(array $blocks, ?WP_Post $post = null): array
            {
                return $blocks;
            }
        };

        $replacement = new class implements PostProcessorInterface {
            public function priority(): int
            {

                return 10;
            }

            /**
             * @param  list<Block> $blocks
             * @return list<Block>
             */
            public function process(array $blocks, ?WP_Post $post = null): array
            {
                return $blocks;
            }
        };

        $registry->registerPostProcessor($original);
        $registry->replacePostProcessor($original::class, $replacement);

        $processors = $registry->getPostProcessors();
        $this->assertCount(1, $processors);
        $this->assertSame($replacement, $processors[0]);
    }

    public function testReplacePostProcessorThrowsWhenNotFound(): void
    {
        $registry = new ConverterRegistry();

        $replacement = new class implements PostProcessorInterface {
            public function priority(): int
            {

                return 10;
            }

            /**
             * @param  list<Block> $blocks
             * @return list<Block>
             */
            public function process(array $blocks, ?WP_Post $post = null): array
            {
                return $blocks;
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        $registry->replacePostProcessor('NonExistentProcessor', $replacement);
    }

    // ------------------------------------------------------------------
    // Removal and replacement leave the other processors alone
    // ------------------------------------------------------------------

    public function testRemovePreProcessorKeepsTheOthers(): void
    {
        $registry = new ConverterRegistry();
        $kept = $this->preProcessor(5);
        $removed = new class extends StubPreProcessor {
        };

        $registry->registerPreProcessor($kept);
        $registry->registerPreProcessor($removed);
        $registry->removePreProcessor($removed::class);

        $this->assertSame([$kept], $registry->getEarlyPreProcessors());
    }

    public function testReplacePreProcessorKeepsTheOthers(): void
    {
        $registry = new ConverterRegistry();
        $kept = $this->preProcessor(5);
        $original = new class extends StubPreProcessor {
        };
        $replacement = new class extends StubPreProcessor {
        };

        $registry->registerPreProcessor($kept);
        $registry->registerPreProcessor($original);
        $registry->replacePreProcessor($original::class, $replacement);

        $processors = $registry->getEarlyPreProcessors();

        $this->assertContains($kept, $processors);
        $this->assertContains($replacement, $processors);
        $this->assertNotContains($original, $processors);
    }

    public function testRemovePostProcessorKeepsTheOthers(): void
    {
        $registry = new ConverterRegistry();
        $kept = $this->postProcessor(5);
        $removed = new class extends StubPostProcessor {
        };

        $registry->registerPostProcessor($kept);
        $registry->registerPostProcessor($removed);
        $registry->removePostProcessor($removed::class);

        $this->assertSame([$kept], $registry->getPostProcessors());
    }

    public function testReplacePostProcessorKeepsTheOthers(): void
    {
        $registry = new ConverterRegistry();
        $kept = $this->postProcessor(5);
        $original = new class extends StubPostProcessor {
        };
        $replacement = new class extends StubPostProcessor {
        };

        $registry->registerPostProcessor($kept);
        $registry->registerPostProcessor($original);
        $registry->replacePostProcessor($original::class, $replacement);

        $processors = $registry->getPostProcessors();

        $this->assertContains($kept, $processors);
        $this->assertContains($replacement, $processors);
        $this->assertNotContains($original, $processors);
    }

    private function preProcessor(int $priority): PreProcessorInterface
    {
        return new class ($priority) extends StubPreProcessor {
        };
    }

    private function postProcessor(int $priority): PostProcessorInterface
    {
        return new class ($priority) extends StubPostProcessor {
        };
    }

    /**
     * A late pre-processor that emits block markup is only safe after the
     * sanitizer, which strips comments: the fragment is split again once
     * all late processors have run. Nothing enforces that but this test.
     */
    public function testTheDefaultProcessorsRunInTheDocumentedOrder(): void
    {
        $registry = BlockConverter::createDefault()->getRegistry();

        $names = static fn (array $processors): array => \array_map(
            static fn (PreProcessorInterface $p): string => \substr($p::class, \strrpos($p::class, '\\') + 1),
            $processors,
        );

        $this->assertSame(
            ['ProtocolLessUrlFixer', 'DeprecatedTagReplacer', 'MoreTagProcessor', 'InlineStyleNormalizer', 'ShortcodeProcessor'],
            $names($registry->getEarlyPreProcessors()),
        );
        $this->assertSame(
            ['HtmlSanitizer', 'WpAutop', 'AutoEmbedProcessor'],
            $names($registry->getPreProcessors()),
        );
    }

    public function testTheSortedListsFollowEveryRegistrationChange(): void
    {
        $registry = new ConverterRegistry();
        $first = new class (20) extends StubPreProcessor {
        };
        $second = new class (10) extends StubPreProcessor {
        };

        $registry->registerPreProcessor($first);
        $this->assertSame([$first], $registry->getEarlyPreProcessors());

        $registry->registerPreProcessor($second);
        $this->assertSame([$second, $first], $registry->getEarlyPreProcessors(), 'Registering must drop the memoised list.');

        $registry->removePreProcessor($second::class);
        $this->assertSame([$first], $registry->getEarlyPreProcessors());

        $replacement = new class (5) extends StubPreProcessor {
        };
        $registry->replacePreProcessor($first::class, $replacement);
        $this->assertSame([$replacement], $registry->getEarlyPreProcessors());
    }
}
