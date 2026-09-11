<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Integration\ShortcodeConverters;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\ShortcodeConverters\EmbedConverter;
use n5s\BlockConverter\Tests\WpTestCase;

use function Mantle\Testing\html_string;

final class EmbedConverterTest extends WpTestCase
{
    private EmbedConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new EmbedConverter();
    }

    public function testShortcodeName(): void
    {
        $this->assertSame(['embed'], EmbedConverter::shortcodes());
    }

    public function testConvertsEmbedUrlFromContent(): void
    {
        $this->fakeYoutubeOembed();

        $result = $this->converter->convert(
            [],
            'https://www.youtube.com/watch?v=xY1zA_bCdEf',
            'embed',
        );

        $this->assertSame('embed', $result->blockName);
        $this->assertSame('https://www.youtube.com/watch?v=xY1zA_bCdEf', $result->attributes['url']);
    }

    public function testConvertsEmbedUrlFromSrcAttribute(): void
    {
        $this->fake_request('https://vimeo.com/*')
            ->with_json([
                'type' => 'video',
                'provider_name' => 'Vimeo',
                'width' => 640,
                'height' => 360,
            ]);

        $result = $this->converter->convert(
            ['src' => 'https://vimeo.com/123456'],
            null,
            'embed',
        );

        $this->assertSame('embed', $result->blockName);
        $this->assertSame('https://vimeo.com/123456', $result->attributes['url']);
    }

    public function testWithoutAUrlTheShortcodeIsPreserved(): void
    {
        foreach ([[[], null], [[], ''], [['src' => 'not a url'], null]] as [$atts, $content]) {
            $block = $this->converter->convert($atts, $content, 'embed');

            $this->assertInstanceOf(Block::class, $block);
            $this->assertSame('shortcode', $block->blockName);
        }

        $this->assertSame('[embed src="not a url"]', $this->converter->convert(['src' => 'not a url'], null, 'embed')->innerHTML());
    }

    /**
     * FILTER_VALIDATE_URL accepts these; nothing WordPress embeds lives
     * behind them.
     */
    public function testAScriptSchemeIsNotAnEmbed(): void
    {
        foreach (['javascript://a.test/%0aalert(1)', 'vbscript://x/a', 'data:text/html,<script>alert(1)</script>'] as $url) {
            $block = $this->converter->convert(['src' => $url], null, 'embed');

            $this->assertInstanceOf(Block::class, $block);
            $this->assertSame('shortcode', $block->blockName, $url);
        }
    }

    public function testBlockHasFigureWrapper(): void
    {
        $this->fakeYoutubeOembed();

        $result = $this->converter->convert(
            [],
            'https://www.youtube.com/watch?v=xY1zA_bCdEf',
            'embed',
        );
        $this->assertInstanceOf(Block::class, $result);

        $html = html_string($result->innerHTML());
        $html->assertQuerySelectorExists('figure.wp-block-embed');
        $html->assertQuerySelectorExists('figure .wp-block-embed__wrapper');
    }

    public function testBlockContentContainsUrl(): void
    {
        $this->fakeYoutubeOembed();

        $url = 'https://www.youtube.com/watch?v=xY1zA_bCdEf';

        $result = $this->converter->convert([], $url, 'embed');
        $this->assertInstanceOf(Block::class, $result);

        $wrapper = html_string($result->innerHTML())->first_by_selector('.wp-block-embed__wrapper');
        $this->assertStringContainsString($url, $wrapper->text());
    }

    public function testYoutubeProviderDetected(): void
    {
        $this->fakeYoutubeOembed();

        $result = $this->converter->convert(
            [],
            'https://www.youtube.com/watch?v=xY1zA_bCdEf',
            'embed',
        );

        $this->assertSame('youtube', $result->attributes['providerNameSlug']);
        $this->assertSame('video', $result->attributes['type']);
        $this->assertInstanceOf(Block::class, $result);

        $figure = html_string($result->innerHTML())->first_by_tag('figure');
        $figure->assertNodeHasClass('is-provider-youtube');
        $figure->assertNodeHasClass('wp-block-embed-youtube');
        $figure->assertNodeHasClass('is-type-video');
    }

    public function testUnknownProviderStillConverts(): void
    {
        $this->fake_request('https://unknown-site.example.com/*')
            ->with_status(404);

        $result = $this->converter->convert(
            [],
            'https://unknown-site.example.com/embed/123',
            'embed',
        );

        $this->assertSame('embed', $result->blockName);
        $this->assertInstanceOf(Block::class, $result);
        html_string($result->innerHTML())->assertQuerySelectorExists('figure.wp-block-embed');
    }

    public function testResponsiveAttributeSet(): void
    {
        $this->fakeYoutubeOembed();

        $result = $this->converter->convert(
            [],
            'https://www.youtube.com/watch?v=xY1zA_bCdEf',
            'embed',
        );

        $this->assertTrue($result->attributes['responsive']);
    }

    public function testAspectRatio169ForVideoEmbed(): void
    {
        $this->fakeYoutubeOembed(width: 640, height: 360);

        $result = $this->converter->convert([], 'https://www.youtube.com/watch?v=test169', 'embed');
        $this->assertInstanceOf(Block::class, $result);

        $figure = html_string($result->innerHTML())->first_by_tag('figure');
        $figure->assertNodeHasClass('wp-embed-aspect-16-9');
        $figure->assertNodeHasClass('wp-has-aspect-ratio');
    }

    public function testAspectRatio43ForVideoEmbed(): void
    {
        $this->fakeYoutubeOembed(width: 640, height: 480);

        $result = $this->converter->convert([], 'https://www.youtube.com/watch?v=test43', 'embed');
        $this->assertInstanceOf(Block::class, $result);

        $figure = html_string($result->innerHTML())->first_by_tag('figure');
        $figure->assertNodeHasClass('wp-embed-aspect-4-3');
        $figure->assertNodeHasClass('wp-has-aspect-ratio');
    }

    public function testAspectRatio11ForSquareEmbed(): void
    {
        $this->fakeYoutubeOembed(width: 500, height: 500);

        $result = $this->converter->convert([], 'https://www.youtube.com/watch?v=test11', 'embed');
        $this->assertInstanceOf(Block::class, $result);

        $figure = html_string($result->innerHTML())->first_by_tag('figure');
        $figure->assertNodeHasClass('wp-embed-aspect-1-1');
        $figure->assertNodeHasClass('wp-has-aspect-ratio');
    }

    public function testNoAspectRatioWithoutDimensions(): void
    {
        $this->fake_request('https://unknown-site.example.com/*')
            ->with_status(404);

        $result = $this->converter->convert(
            [],
            'https://unknown-site.example.com/embed/no-dims',
            'embed',
        );
        $this->assertInstanceOf(Block::class, $result);

        $html = html_string($result->innerHTML());
        $html->assertQuerySelectorMissing('figure.wp-has-aspect-ratio');
    }

    public function testNoAspectRatioWhenDiffExceedsTolerance(): void
    {
        // Ratio = 800/300 = 2.67 — exceeds 2.33 (21:9) by more than 0.1
        $this->fakeYoutubeOembed(width: 800, height: 300);

        $result = $this->converter->convert([], 'https://www.youtube.com/watch?v=testwide', 'embed');
        $this->assertInstanceOf(Block::class, $result);

        $html = html_string($result->innerHTML());
        $html->assertQuerySelectorMissing('figure.wp-has-aspect-ratio');
    }

    public function testAspectRatio219ForUltraWide(): void
    {
        // Ratio = 2100/900 = 2.33 — exactly 21:9
        $this->fakeYoutubeOembed(width: 2100, height: 900);

        $result = $this->converter->convert([], 'https://www.youtube.com/watch?v=test219', 'embed');
        $this->assertInstanceOf(Block::class, $result);

        $figure = html_string($result->innerHTML())->first_by_tag('figure');
        $figure->assertNodeHasClass('wp-embed-aspect-21-9');
        $figure->assertNodeHasClass('wp-has-aspect-ratio');
    }

    public function testAspectRatio916ForVerticalVideo(): void
    {
        // Ratio = 360/640 = 0.5625 ≈ 0.56 (9:16)
        $this->fakeYoutubeOembed(width: 360, height: 640);

        $result = $this->converter->convert([], 'https://www.youtube.com/watch?v=test916', 'embed');
        $this->assertInstanceOf(Block::class, $result);

        $figure = html_string($result->innerHTML())->first_by_tag('figure');
        $figure->assertNodeHasClass('wp-embed-aspect-9-16');
        $figure->assertNodeHasClass('wp-has-aspect-ratio');
    }

    private function fakeYoutubeOembed(int $width = 200, int $height = 113): void
    {
        $this->fake_request('https://www.youtube.com/*')
            ->with_json([
                'type' => 'video',
                'provider_name' => 'YouTube',
                'width' => $width,
                'height' => $height,
            ]);
    }
}
