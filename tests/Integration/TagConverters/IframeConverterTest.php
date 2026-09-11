<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Integration\TagConverters;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\TagConverters\IframeConverter;
use n5s\BlockConverter\Tests\WpTestCase;
use voku\helper\HtmlDomParser;
use voku\helper\SimpleHtmlDomInterface;

use function Mantle\Testing\html_string;

final class IframeConverterTest extends WpTestCase
{
    private IframeConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new IframeConverter();
    }

    public function testReturnsNullWithoutSrc(): void
    {
        $element = $this->parseElement('<iframe></iframe>');
        $this->assertNull($this->converter->convert($element));
    }

    public function testReturnsNullWithEmptySrc(): void
    {
        $element = $this->parseElement('<iframe src=""></iframe>');
        $this->assertNull($this->converter->convert($element));
    }

    public function testConvertsYouTubeEmbedToEmbedBlock(): void
    {
        $this->fakeYoutubeOembed();

        $element = $this->parseElement(
            '<iframe src="https://www.youtube.com/embed/xY1zA_bCdEf" width="560" height="315"></iframe>'
        );
        $result = $this->converter->convert($element);

        $this->assertInstanceOf(Block::class, $result);
        $this->assertSame('embed', $result->blockName);
        $this->assertSame('https://www.youtube.com/watch?v=xY1zA_bCdEf', $result->attributes['url']);
        $this->assertSame('youtube', $result->attributes['providerNameSlug']);
    }

    public function testConvertsVimeoPlayerToEmbedBlock(): void
    {
        $this->fake_request('https://vimeo.com/*')
            ->with_json([
                'type' => 'video',
                'provider_name' => 'Vimeo',
                'width' => 426,
                'height' => 240,
            ]);

        $element = $this->parseElement(
            '<iframe src="https://player.vimeo.com/video/123456789" width="640" height="360"></iframe>'
        );
        $result = $this->converter->convert($element);

        $this->assertInstanceOf(Block::class, $result);
        $this->assertSame('embed', $result->blockName);
        $this->assertSame('https://vimeo.com/123456789', $result->attributes['url']);
        $this->assertSame('vimeo', $result->attributes['providerNameSlug']);
    }

    public function testConvertsSoundCloudPlayerToEmbedBlock(): void
    {
        $this->fake_request('https://soundcloud.com/*')
            ->with_json([
                'type' => 'rich',
                'provider_name' => 'SoundCloud',
                'width' => 500,
                'height' => 400,
            ]);

        // Public SoundCloud URLs in the player's url= param match WP's oEmbed provider.
        // API URLs (api.soundcloud.com/tracks/ID) don't match and fall back to core/html.
        $element = $this->parseElement(
            '<iframe src="https://w.soundcloud.com/player/?url=https%3A//soundcloud.com/artist/track-name&color=%23ff5500" width="100%" height="166"></iframe>'
        );
        $result = $this->converter->convert($element);

        $this->assertInstanceOf(Block::class, $result);
        $this->assertSame('embed', $result->blockName);
        $this->assertSame('https://soundcloud.com/artist/track-name', $result->attributes['url']);
        $this->assertSame('soundcloud', $result->attributes['providerNameSlug']);
    }

    public function testFallsBackToHtmlBlockForUnknownUrl(): void
    {
        $this->fake_request('https://example.com/*')
            ->with_json([]);

        $element = $this->parseElement(
            '<iframe src="https://example.com/widget" width="400" height="300"></iframe>'
        );
        $result = $this->converter->convert($element);

        $this->assertInstanceOf(Block::class, $result);
        $this->assertSame('html', $result->blockName);
        $this->assertStringContainsString('https://example.com/widget', $result->innerHTML());
    }

    public function testPreservesIframeAttributesInHtmlFallback(): void
    {
        $this->fake_request('https://example.com/*')
            ->with_json([]);

        $element = $this->parseElement(
            '<iframe src="https://example.com/widget" width="400" height="300" allowfullscreen></iframe>'
        );
        $result = $this->converter->convert($element);

        $this->assertInstanceOf(Block::class, $result);
        $this->assertSame('html', $result->blockName);
        $this->assertStringContainsString('width="400"', $result->innerHTML());
        $this->assertStringContainsString('height="300"', $result->innerHTML());
        $this->assertStringContainsString('allowfullscreen', $result->innerHTML());
    }

    public function testProducesValidEmbedBlockMarkup(): void
    {
        $this->fakeYoutubeOembed();

        $element = $this->parseElement(
            '<iframe src="https://www.youtube.com/embed/xY1zA_bCdEf"></iframe>'
        );
        $result = $this->converter->convert($element);

        $this->assertInstanceOf(Block::class, $result);
        html_string($result->innerHTML())->first_by_tag('figure')->assertNodeHasClass('wp-block-embed');
        html_string($result->innerHTML())->assertQuerySelectorExists('.wp-block-embed__wrapper');
        $this->assertTrue($result->attributes['responsive']);
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    private function fakeYoutubeOembed(): void
    {
        $this->fake_request('https://www.youtube.com/*')
            ->with_json([
                'type' => 'video',
                'provider_name' => 'YouTube',
                'width' => 200,
                'height' => 113,
            ]);
    }

    private function parseElement(string $html): SimpleHtmlDomInterface
    {
        $dom = HtmlDomParser::str_get_html('<body>' . $html . '</body>');
        $elements = $dom->findMultiOrFalse('//body/*');

        if ($elements === false) {
            throw new \RuntimeException("No element found in: {$html}");
        }

        foreach ($elements as $element) {
            return $element;
        }

        throw new \RuntimeException("No element found in: {$html}");
    }
}
