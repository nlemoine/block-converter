<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Integration;

use n5s\BlockConverter\BlockConverter;
use n5s\BlockConverter\PreProcessors\ShortcodeProcessor;
use n5s\BlockConverter\Tests\WpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_HTML_Tag_Processor;

/**
 * Shortcode attributes and content are attacker-controlled.
 *
 * The library's input is a legacy dump — content that never passed through
 * KSES — so nothing upstream has filtered it. Everything the shortcode pipeline
 * emits is block markup, which parse_blocks() then hands straight through, so
 * the late sanitizer never sees it. ShortcodeProcessor therefore sanitizes
 * every block a converter returns before rendering it; these tests hold that
 * boundary, whatever the converters interpolate.
 */
final class ShortcodeInjectionTest extends WpTestCase
{
    private BlockConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = BlockConverter::createDefault();
    }

    #[DataProvider('breakoutPayloads')]
    public function testNoPayloadYieldsAnExecutableTagOrHandler(string $shortcode): void
    {
        $result = $this->converter->convert($shortcode);

        $this->assertSame([], $this->executableTags($result), 'An executable tag reached the output.');
        $this->assertSame([], $this->eventHandlers($result), 'An event handler reached the output.');
    }

    /** @return \Iterator<string, array{0: string}> */
    public static function breakoutPayloads(): \Iterator
    {
        yield 'video align out of its class' => ['[video src="https://x.test/a.mp4" align=\'x" onmouseover="alert(1)\']'];
        yield 'caption attribute carrying markup' => ['[caption caption=\'<img src=x onerror=alert(1)>\']<img src="/x.jpg">[/caption]'];
        yield 'audio content carrying a script' => ['[audio src="https://x.test/a.mp3"]<script>alert(1)</script>[/audio]'];
        yield 'video content carrying a script' => ['[video src="https://x.test/a.mp4"]<script>alert(1)</script>[/video]'];
        yield 'embed src carrying markup behind a valid scheme' => ['[embed src=\'http://example.com/"><script>alert(1)</script>\']'];
        yield 'caption image carrying a handler' => ['[caption id="attachment_9"]<img src="/x.jpg" onerror="alert(1)"> Légende[/caption]'];
        yield 'caption link carrying a script scheme' => ['[caption]<a href="javascript:alert(1)"><img src="/x.jpg"></a> Légende[/caption]'];
    }

    /**
     * kses keeps HTML comments, so a caption run through wp_kses_post() can
     * still close the block that carries it and open another.
     */
    #[DataProvider('captionForgeries')]
    public function testACaptionCannotCloseTheBlockThatCarriesIt(string $shortcode, string $expectedBlock): void
    {
        $forgery = '<!-- /wp:' . $expectedBlock . ' --><!-- wp:html --><iframe src="//evil.test"></iframe><!-- /wp:html -->';
        $result = $this->shortcodeOutput(\str_replace('{forgery}', $forgery, $shortcode));

        $names = \array_map(
            static fn (array $block): string => $block['blockName'] ?? 'raw',
            \parse_blocks($result),
        );

        $this->assertSame(['core/' . $expectedBlock], $names, 'The caption split the block it was carried in.');
        $this->assertStringNotContainsString('<iframe', $result);
    }

    /** @return \Iterator<string, array{0: string, 1: string}> */
    public static function captionForgeries(): \Iterator
    {
        yield 'audio content' => ['[audio src="https://x.test/a.mp3"]c{forgery}[/audio]', 'audio'];
        yield 'video content' => ['[video src="https://x.test/a.mp4"]c{forgery}[/video]', 'video'];
        yield 'caption attribute' => ['[caption caption="c{forgery}"]<img src="/x.jpg">[/caption]', 'image'];
        yield 'caption trailing text' => ['[caption]<img src="/x.jpg"> c{forgery}[/caption]', 'image'];
    }

    /**
     * The reconstructed shortcode text sits inside a block delimiter, which is
     * an HTML comment: a terminator in the text closes the block early and
     * everything after it is parsed as markup.
     */
    #[DataProvider('delimiterPayloads')]
    public function testShortcodeTextCannotCloseItsOwnBlock(string $shortcode): void
    {
        $result = $this->shortcodeOutput($shortcode);

        $names = \array_map(
            static fn (array $block): string => $block['blockName'] ?? 'raw',
            \parse_blocks($result),
        );

        $this->assertSame(['core/shortcode'], $names, 'The payload split the block it was carried in.');
    }

    /** @return \Iterator<string, array{0: string}> */
    public static function delimiterPayloads(): \Iterator
    {
        yield 'closing comment in an attribute' => [
            '[playlist x=\'<!-- /wp:shortcode --><!-- wp:html --><script>alert(1)</script><!-- /wp:html --><!-- wp:shortcode -->\']',
        ];
        yield 'closing comment in the content' => [
            '[playlist]<!-- /wp:shortcode --><!-- wp:html --><script>alert(1)</script><!-- /wp:html --><!-- wp:shortcode -->[/playlist]',
        ];
        yield 'bang terminator, which a browser accepts' => [
            '[playlist x=\'--!><script>alert(1)</script>\']',
        ];
    }

    /**
     * A gallery is a container: its own markup is split around the inner
     * image blocks, so the sanitizer has to keep both halves and the images
     * between them while still filtering the excerpt-sourced caption.
     */
    public function testAGalleryCaptionIsFilteredWithoutLosingTheImages(): void
    {
        $id = self::factory()->attachment->create([
            'post_mime_type' => 'image/jpeg',
            'post_excerpt' => 'Légende<script>alert(1)</script><!-- /wp:image --><!-- wp:html --><iframe src="//evil.test"></iframe><!-- /wp:html -->',
        ]);
        \update_post_meta($id, '_wp_attached_file', "image-{$id}.jpg");

        $result = $this->converter->convert(\sprintf('[gallery ids="%d,%d"]', $id, $id));
        $blocks = \parse_blocks($result);

        $this->assertSame(['core/gallery'], \array_column($blocks, 'blockName'));
        $this->assertSame(['core/image', 'core/image'], \array_column($blocks[0]['innerBlocks'], 'blockName'));
        $this->assertSame([], $this->executableTags($result));
        $this->assertStringContainsString('<figcaption class="wp-element-caption">Légende', $result);
        $this->assertStringNotContainsString('evil.test', $result);
    }

    // ------------------------------------------------------------------
    // The filtering must not eat legitimate content
    // ------------------------------------------------------------------

    public function testCaptionsKeepInlineFormatting(): void
    {
        $result = $this->converter->convert('[audio src="https://x.test/a.mp3"]Un <strong>titre</strong>[/audio]');

        $this->assertStringContainsString('<strong>titre</strong>', $result);
    }

    public function testKnownAlignmentsStillApply(): void
    {
        $result = $this->converter->convert('[video src="https://x.test/a.mp4" align="center"]');

        $this->assertStringContainsString('"align":"center"', $result);
        $this->assertStringContainsString('wp-block-video aligncenter', $result);
    }

    public function testUnknownAlignmentIsDroppedRatherThanInterpolated(): void
    {
        $result = $this->converter->convert('[video src="https://x.test/a.mp4" align="sideways"]');

        $this->assertStringNotContainsString('sideways', $result);
        $this->assertStringNotContainsString('"align"', $result);
    }

    public function testAValidEmbedSrcIsStillAccepted(): void
    {
        $result = $this->converter->convert('[embed src="https://vimeo.com/123"]');

        $this->assertStringContainsString('wp:embed', $result);
        $this->assertStringContainsString('https://vimeo.com/123', $result);
    }

    public function testAnUnknownShortcodeIsStillPreservedVerbatim(): void
    {
        $result = $this->converter->convert('[playlist ids="1,2" style="light"]');

        // Exact: a contains-check passed while a closing tag was being
        // appended to every self-closing shortcode.
        $this->assertSame("<!-- wp:shortcode -->\n[playlist ids=\"1,2\" style=\"light\"]\n<!-- /wp:shortcode -->", $result);
    }

    /**
     * The remaining exposure, stated rather than left implicit.
     *
     * A core/shortcode block exists to hand the original text back to
     * do_shortcode(), so it is preserved byte for byte — markup included. It
     * cannot be filtered here: wp_kses_post() corrupts shortcode syntax, eating
     * the closing quote of `[foo bar="a < b"]` and rewriting & inside URLs.
     *
     * What the conversion does guarantee is that such text stays inside its
     * block instead of becoming markup of its own. Callers migrating untrusted
     * content have to filter upstream.
     */
    #[DataProvider('unconvertibleShortcodes')]
    public function testShortcodeFallbackPreservesItsTextVerbatim(string $shortcode): void
    {
        $result = $this->converter->convert($shortcode);

        $names = \array_map(
            static fn (array $block): string => $block['blockName'] ?? 'raw',
            \parse_blocks($result),
        );

        $this->assertSame(['core/shortcode'], $names);
        $this->assertStringContainsString('<script>alert(1)</script>', $result);
    }

    /** @return \Iterator<string, array{0: string}> */
    public static function unconvertibleShortcodes(): \Iterator
    {
        yield 'orphaned tag' => ['[playlist x=\'<script>alert(1)</script>\']'];
        // A converter that cannot build its block preserves the shortcode
        // the same way, so the same exposure applies.
        yield 'embed with no URL to speak of' => ['[embed src=\'"><script>alert(1)</script>\']'];
    }

    /**
     * What the shortcode pipeline emits for the input, before block parsing.
     *
     * convert() parses blocks first and hands any balanced block markup it
     * finds in the input back untouched, as the README says it does — so a
     * forgery placed in the raw input is read as existing blocks before any
     * shortcode runs. The property under test is that a converter's output
     * cannot be split by the text it was given, which is only observable on
     * that output.
     */
    private function shortcodeOutput(string $shortcode): string
    {
        return (new ShortcodeProcessor($this->converter->getRegistry()))->process($shortcode);
    }

    /** @return list<string> */
    private function executableTags(string $html): array
    {
        $found = [];
        $processor = new WP_HTML_Tag_Processor($html);

        while ($processor->next_tag()) {
            $tag = \strtolower((string) $processor->get_tag());

            if (\in_array($tag, ['script', 'iframe', 'object', 'embed'], true)) {
                $found[] = $tag;
            }
        }

        return $found;
    }

    /** @return list<string> */
    private function eventHandlers(string $html): array
    {
        $found = [];
        $processor = new WP_HTML_Tag_Processor($html);

        while ($processor->next_tag()) {
            foreach ($processor->get_attribute_names_with_prefix('') ?? [] as $name) {
                if (\str_starts_with($name, 'on')) {
                    $found[] = \strtolower((string) $processor->get_tag()) . '@' . $name;
                }
            }
        }

        return $found;
    }
}
