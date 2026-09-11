<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Integration\PreProcessors;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\ConverterRegistry;
use n5s\BlockConverter\PreProcessors\PreProcessorInterface;
use n5s\BlockConverter\PreProcessors\ShortcodeProcessor;
use n5s\BlockConverter\ShortcodeConverters\CaptionConverter;
use n5s\BlockConverter\ShortcodeConverters\GalleryConverter;
use n5s\BlockConverter\ShortcodeConverters\ShortcodeConverterInterface;
use n5s\BlockConverter\Tests\WpTestCase;
use WP_Post;

final class ShortcodeProcessorTest extends WpTestCase
{
    private ShortcodeProcessor $processor;

    private ConverterRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new ConverterRegistry();
        $this->registry->registerShortcodeConverter(new GalleryConverter());
        $this->registry->registerShortcodeConverter(new CaptionConverter());
        $this->processor = new ShortcodeProcessor($this->registry);
    }

    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(PreProcessorInterface::class, $this->processor);
    }

    public function testPriority(): void
    {
        $this->assertSame(20, $this->processor->priority());
    }

    public function testLeavesHtmlWithoutShortcodesUntouched(): void
    {
        $input = '<p>No shortcodes here</p>';
        $this->assertSame($input, $this->processor->process($input));
    }

    public function testConvertsGalleryShortcode(): void
    {
        $ids = $this->createTestAttachments(2);
        $idsStr = \implode(',', $ids);

        $input = "<p>Before</p>\n[gallery ids=\"{$idsStr}\"]\n<p>After</p>";
        $result = $this->processor->process($input);

        $this->assertStringContainsString('<p>Before</p>', $result);
        $this->assertStringContainsString('<p>After</p>', $result);
        $this->assertStringContainsString('wp:gallery', $result);
        $this->assertStringNotContainsString('[gallery', $result);
    }

    public function testConvertsCaptionShortcode(): void
    {
        $input = '[caption]<img src="https://example.com/photo.jpg">A nice photo[/caption]';
        $result = $this->processor->process($input);

        $this->assertStringContainsString('wp:image', $result);
        $this->assertStringNotContainsString('[caption]', $result);
    }

    public function testLeavesUnknownShortcodesUntouched(): void
    {
        $input = '<p>Before</p>[unknown_shortcode attr="val"]content[/unknown_shortcode]<p>After</p>';
        $result = $this->processor->process($input);

        $this->assertStringContainsString('[unknown_shortcode', $result);
        $this->assertStringNotContainsString('wp:shortcode', $result);
    }

    public function testHandlesMultipleShortcodes(): void
    {
        $ids = $this->createTestAttachments(2);
        $idsStr = \implode(',', $ids);

        $input = "[gallery ids=\"{$idsStr}\"]\n<p>Between</p>\n[gallery ids=\"{$idsStr}\"]";
        $result = $this->processor->process($input);

        $this->assertSame(2, \substr_count($result, '<!-- wp:gallery'));
        $this->assertStringNotContainsString('[gallery', $result);
    }

    // --- Fallback shortcode tags (registerShortcodeTag) ---

    public function testWrapsFallbackShortcodeWithAttributes(): void
    {
        $this->registry->registerShortcodeTag('legacy_plugin');
        $input = '<p>Before</p>[legacy_plugin attr="val"]content[/legacy_plugin]<p>After</p>';
        $result = $this->processor->process($input);

        $this->assertStringContainsString('<!-- wp:shortcode -->', $result);
        $this->assertStringContainsString('[legacy_plugin attr="val"]content[/legacy_plugin]', $result);
        $this->assertStringContainsString('<!-- /wp:shortcode -->', $result);
        $this->assertStringContainsString('<p>Before</p>', $result);
        $this->assertStringContainsString('<p>After</p>', $result);
    }

    public function testWrapsBareShortcodeTag(): void
    {
        $this->registry->registerShortcodeTag('newsletter');
        $input = '<p>Before</p>[newsletter]<p>After</p>';
        $result = $this->processor->process($input);

        $this->assertStringContainsString('<!-- wp:shortcode -->', $result);
        $this->assertStringContainsString('[newsletter]', $result);
    }

    public function testWrapsFallbackShortcodeWithKeyValueAttributes(): void
    {
        $this->registry->registerShortcodeTag('custom_widget');
        $input = '<p>Text</p>[custom_widget id="42"]';
        $result = $this->processor->process($input);

        $this->assertStringContainsString('<!-- wp:shortcode -->', $result);
        $this->assertStringContainsString('[custom_widget id="42"]', $result);
    }

    public function testWrapsMultipleFallbackShortcodes(): void
    {
        $this->registry->registerShortcodeTag('plugin_a');
        $this->registry->registerShortcodeTag('plugin_b');
        $input = '[plugin_a foo="1"]content A[/plugin_a]<p>Between</p>[plugin_b bar="2"]';
        $result = $this->processor->process($input);

        $this->assertSame(2, \substr_count($result, '<!-- wp:shortcode -->'));
    }

    public function testMixesConverterAndFallbackShortcodes(): void
    {
        $ids = $this->createTestAttachments(2);
        $idsStr = \implode(',', $ids);

        $this->registry->registerShortcodeTag('legacy_tag');
        $input = "[gallery ids=\"{$idsStr}\"]\n<p>Between</p>\n[legacy_tag foo=\"bar\"]";
        $result = $this->processor->process($input);

        // Gallery should be converted to a proper block.
        $this->assertStringContainsString('wp:gallery', $result);
        $this->assertStringNotContainsString('[gallery', $result);

        // Legacy tag should be wrapped in wp:shortcode.
        $this->assertStringContainsString('<!-- wp:shortcode -->', $result);
        $this->assertStringContainsString('[legacy_tag foo="bar"]', $result);
    }

    public function testConverterTakesPrecedenceOverFallbackTag(): void
    {
        // Register 'gallery' as both converter (via setUp) and fallback tag.
        $this->registry->registerShortcodeTag('gallery');

        $ids = $this->createTestAttachments(2);
        $idsStr = \implode(',', $ids);

        $input = "[gallery ids=\"{$idsStr}\"]";
        $result = $this->processor->process($input);

        // Converter should win — not wrapped as wp:shortcode.
        $this->assertStringContainsString('wp:gallery', $result);
        $this->assertStringNotContainsString('wp:shortcode', $result);
    }

    public function testFallbackOnlyRegistryWithNoConverters(): void
    {
        $registry = new ConverterRegistry();
        $registry->registerShortcodeTag('orphaned_shortcode');
        $processor = new ShortcodeProcessor($registry);

        $input = '[orphaned_shortcode key="value"]some content[/orphaned_shortcode]';
        $result = $processor->process($input);

        $this->assertStringContainsString('<!-- wp:shortcode -->', $result);
        $this->assertStringContainsString('[orphaned_shortcode key="value"]some content[/orphaned_shortcode]', $result);
    }

    public function testUnregisteredTagsLeftUntouched(): void
    {
        $this->registry->registerShortcodeTag('known_legacy');
        $input = '[known_legacy foo="1"] [totally_unknown bar="2"]';
        $result = $this->processor->process($input);

        // Registered fallback gets wrapped.
        $this->assertStringContainsString('<!-- wp:shortcode -->', $result);
        $this->assertStringContainsString('[known_legacy foo="1"]', $result);

        // Unknown shortcode left as-is.
        $this->assertStringContainsString('[totally_unknown bar="2"]', $result);
    }

    public function testWpRegisteredShortcodeWithoutConverterGetsWrapped(): void
    {
        // Register a shortcode in WP's global $shortcode_tags (simulates an active plugin).
        \add_shortcode('active_plugin_sc', static fn (): string => '<div class="plugin-output">HTML</div>');

        $input = '<p>Before</p>[active_plugin_sc foo="bar"]<p>After</p>';
        $result = $this->processor->process($input);

        // Should be wrapped as wp:shortcode, NOT executed.
        $this->assertStringContainsString('<!-- wp:shortcode -->', $result);
        $this->assertStringContainsString('[active_plugin_sc foo="bar"]', $result);
        $this->assertStringNotContainsString('plugin-output', $result);
    }

    public function testFallbackShortcodeWithPositionalAttributes(): void
    {
        $this->registry->registerShortcodeTag('legacy_tag');
        $input = '[legacy_tag 123 "hello"]';
        $result = $this->processor->process($input);

        $this->assertStringContainsString('<!-- wp:shortcode -->', $result);
        $this->assertStringContainsString('[legacy_tag', $result);
    }

    public function testConverterReturningNullStripsShortcode(): void
    {
        $converter = new class implements ShortcodeConverterInterface {
            public static function shortcodes(): array
            {
                return ['removable'];
            }

            public function convert(array $atts, ?string $content, string $tag, ?WP_Post $post = null): ?Block
            {
                return null;
            }
        };

        $this->registry->registerShortcodeConverter($converter);
        $input = '<p>Before</p>[removable foo="bar"]some content[/removable]<p>After</p>';
        $result = $this->processor->process($input);

        $this->assertStringContainsString('<p>Before</p>', $result);
        $this->assertStringContainsString('<p>After</p>', $result);
        $this->assertStringNotContainsString('[removable', $result);
        $this->assertStringNotContainsString('some content', $result);
        $this->assertStringNotContainsString('wp:shortcode', $result);
    }

    /**
     * @return int[]
     */
    private function createTestAttachments(int $count): array
    {
        $ids = [];

        for ($i = 1; $i <= $count; $i++) {
            $ids[] = self::factory()->attachment->create([
                'post_title' => "Test Image {$i}",
                'post_mime_type' => 'image/jpeg',
                'guid' => "https://example.com/wp-content/uploads/image-{$i}.jpg",
            ]);
        }

        foreach ($ids as $id) {
            update_post_meta($id, '_wp_attached_file', "image-{$id}.jpg");
        }

        return $ids;
    }

    public function testContentIsUntouchedWhenNoShortcodeConverterIsRegistered(): void
    {
        $processor = new ShortcodeProcessor(new ConverterRegistry());
        $input = '<p>[gallery ids="1,2,3"]</p>';

        $this->assertSame($input, $processor->process($input));
    }

    public function testSelfClosingOrphanShortcodeIsPreserved(): void
    {
        $registry = new ConverterRegistry();
        $registry->registerShortcodeTag('some_old_plugin');

        $result = (new ShortcodeProcessor($registry))->process('<p>[some_old_plugin id="7"]</p>');

        // WordPress hands the callback an empty string rather than null for a
        // self-closing shortcode; that must not grow a closing tag.
        $this->assertSame("<p><!-- wp:shortcode -->\n[some_old_plugin id=\"7\"]\n<!-- /wp:shortcode --></p>", $result);
    }
}
