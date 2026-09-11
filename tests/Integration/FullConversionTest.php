<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Integration;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\BlockConverter;
use n5s\BlockConverter\ConverterRegistry;
use n5s\BlockConverter\TagConverters\HeadingConverter;
use n5s\BlockConverter\TagConverters\TagConverterInterface;
use n5s\BlockConverter\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use voku\helper\SimpleHtmlDomInterface;

final class FullConversionTest extends TestCase
{
    private BlockConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = self::createDefaultConverter();
    }

    // ------------------------------------------------------------------
    //  Fixture-driven tests
    // ------------------------------------------------------------------

    #[DataProvider('coreFixtureProvider')]
    public function testCoreConversion(string $input, string $expected): void
    {
        $result = $this->converter->convert($input);
        $this->assertBlocksEqual($expected, $result);
    }

    public static function coreFixtureProvider(): \Generator
    {
        yield from self::fixtureProvider('core');
    }

    // ------------------------------------------------------------------
    //  Edge-case tests that don't need fixtures
    // ------------------------------------------------------------------

    /**
     * Converting an already-converted post must be a no-op.
     *
     * The early pre-processors used to run over the whole post before
     * parse_blocks(), so they rewrote their own output: a second pass wrapped
     * <!-- wp:more --> in another <!-- wp:more -->, one level deeper each time.
     * Driving this off the fixture corpus keeps every shape covered.
     */
    #[DataProvider('coreFixtureProvider')]
    public function testCoreConversionIsIdempotent(string $input, string $expected): void
    {
        $once = $this->converter->convert($input);

        $this->assertSame($once, $this->converter->convert($once));
        $this->assertNotSame('', $expected, 'The fixture pair is what makes this meaningful.');
    }

    public function testExistingBlocksAreReturnedByteForByte(): void
    {
        $existing = <<<'HTML'
        <!-- wp:embed {"url":"https://youtu.be/abc","type":"video","providerNameSlug":"youtube"} -->
        <figure class="wp-block-embed is-type-video is-provider-youtube"><div class="wp-block-embed__wrapper">
        https://youtu.be/abc
        </div></figure>
        <!-- /wp:embed -->

        <!-- wp:acme/pricing-table {"plan":"pro","seats":5} /-->
        HTML;

        $result = $this->converter->convert($existing . "\n\n<p>Legacy</p>");

        $this->assertStringContainsString('<!-- wp:acme/pricing-table {"plan":"pro","seats":5} /-->', $result);
        $this->assertStringContainsString('"providerNameSlug":"youtube"', $result);
        $this->assertStringContainsString('<!-- wp:paragraph -->', $result);
    }

    /**
     * The six converters that resolve attachments used to build a resolver
     * each, so nothing was shared and the same URL was looked up once per
     * converter, per post. attachment_url_to_postid() scans an unindexed
     * column, which makes that the dominant cost of a real migration.
     */
    public function testTheSameImageIsOnlyResolvedOnceAcrossPosts(): void
    {
        $calls = 0;

        \add_filter(
            'attachment_url_to_postid',
            static function (mixed $id) use (&$calls): mixed {
                ++$calls;

                return $id;
            },
        );

        $converter = self::createDefaultConverter();
        $markup = '<p><img src="https://example.org/repeated.jpg"></p>';

        foreach (\range(1, 5) as $ignored) {
            $converter->convert($markup);
        }

        $this->assertSame(1, $calls);
    }

    // ------------------------------------------------------------------
    //  Converters registered for a tag must actually be reached
    // ------------------------------------------------------------------

    /**
     * The unwrap check used to run before the registry lookup, so an <a> around
     * an image never reached LinkConverter: the anchor was discarded and
     * ImageConverter got the bare <img>. LinkConverter's own tests passed
     * throughout, because they call the converter directly — which is why these
     * assertions go through the whole pipeline instead.
     */
    #[DataProvider('linkedImageMarkup')]
    public function testLinkAroundAnImageSurvivesTheWholePipeline(string $input): void
    {
        $result = $this->converter->convert($input);

        $this->assertStringContainsString('wp:image', $result);
        $this->assertStringContainsString('<a href="https://example.org/full.jpg">', $result);
    }

    /** @return \Iterator<string, array{0: string}> */
    public static function linkedImageMarkup(): \Iterator
    {
        yield 'wrapped in a paragraph' => ['<p><a href="https://example.org/full.jpg"><img src="/thumb.jpg"></a></p>'];
        yield 'on its own' => ['<a href="https://example.org/full.jpg"><img src="/thumb.jpg"></a>'];
    }

    public function testLinkedImageRecordsItsLinkDestination(): void
    {
        $result = $this->converter->convert(
            \sprintf('<p><a href="%s/photo.jpg"><img src="/thumb.jpg"></a></p>', \home_url()),
        );

        $this->assertStringContainsString('"linkDestination":"media"', $result);
    }

    public function testAnInlineWrapperWithoutAConverterIsStillUnwrapped(): void
    {
        // Nothing claims <span>, so the block-level child is lifted out of it.
        $result = $this->converter->convert('<span><img src="/a.jpg"></span>');

        $this->assertStringContainsString('wp:image', $result);
        $this->assertStringNotContainsString('<span>', $result);
    }

    public function testParagraphsAreStillUnwrappedAroundBlockContent(): void
    {
        // <p> has a converter too, but it can never carry an image block.
        $result = $this->converter->convert('<p>Avant <img src="/a.jpg"> après</p>');

        $this->assertSame(2, \substr_count($result, '<!-- wp:paragraph -->'));
        $this->assertStringContainsString('wp:image', $result);
    }

    public function testEmptyContent(): void
    {
        $this->assertSame('', $this->converter->convert(''));
        $this->assertSame('', $this->converter->convert('   '));
    }

    public function testBareTextBecomesParagraph(): void
    {
        $result = $this->converter->convert('just text');
        $this->assertStringContainsString('wp:paragraph', $result);
        $this->assertStringContainsString('just text', $result);
    }

    public function testCustomConverterOverridesBuiltin(): void
    {
        $registry = new ConverterRegistry();
        $registry->registerTagConverter(new HeadingConverter());

        $customConverter = new class implements TagConverterInterface {
            public static function tags(): array
            {
                return ['h2'];
            }

            public function convert(SimpleHtmlDomInterface $e, ?\WP_Post $p = null): Block
            {
                return new Block('custom/heading', ['custom' => true], innerContent: [$e->html()]);
            }
        };
        $registry->registerTagConverter($customConverter);

        $converter = new BlockConverter($registry);

        // h2 uses custom converter
        $result = $converter->convert('<h2>Custom</h2>');
        $this->assertStringContainsString('wp:custom/heading', $result);

        // h1 still uses original HeadingConverter
        $result2 = $converter->convert('<h1>Normal</h1>');
        $this->assertStringContainsString('wp:heading', $result2);
        $this->assertStringNotContainsString('wp:custom', $result2);
    }
}
