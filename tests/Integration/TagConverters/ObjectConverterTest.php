<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Integration\TagConverters;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\BlockConverter;
use n5s\BlockConverter\TagConverters\ObjectConverter;
use n5s\BlockConverter\Tests\WpTestCase;
use voku\helper\HtmlDomParser;
use voku\helper\SimpleHtmlDomInterface;

use function Mantle\Testing\html_string;

final class ObjectConverterTest extends WpTestCase
{
    private ObjectConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new ObjectConverter();
    }

    public function testAnObjectWithoutAUrlIsKeptAsHtml(): void
    {
        $element = $this->parseElement('<object classid="clsid:D27CDB6E"></object>');
        $result = $this->converter->convert($element);

        $this->assertInstanceOf(Block::class, $result);
        $this->assertSame('html', $result->blockName);
        $this->assertSame('<object classid="clsid:D27CDB6E"></object>', $result->innerHTML());
    }

    public function testAnObjectNoProviderClaimsIsKeptAsHtml(): void
    {
        $this->prevent_stray_requests();
        $element = $this->parseElement('<object data="https://example.com/doc.pdf" type="application/pdf"></object>');
        $result = $this->converter->convert($element);

        $this->assertInstanceOf(Block::class, $result);
        $this->assertSame('html', $result->blockName);
        $this->assertStringContainsString('doc.pdf', $result->innerHTML());
    }

    public function testReadsTheDataAttribute(): void
    {
        $this->fakeYoutubeOembed();

        $result = $this->converter->convert($this->parseElement('<object data="https://www.youtube.com/v/xY1zA_bCdEf" type="application/x-shockwave-flash"></object>'));

        $this->assertInstanceOf(Block::class, $result);
        $this->assertSame('embed', $result->blockName);
        $this->assertSame('https://www.youtube.com/watch?v=xY1zA_bCdEf', $result->attributes['url']);
    }

    /**
     * The whole pipeline: the default sanitizer strips <embed> but keeps
     * <object data> and <param>, so both legacy shapes must still reach the
     * converter with a URL.
     */
    public function testLegacyObjectMarkupConvertsThroughTheDefaultPipeline(): void
    {
        $this->fakeYoutubeOembed();
        $converter = BlockConverter::createDefault();

        foreach (
            [
            '<object width="425" height="350"><param name="movie" value="http://www.youtube.com/v/xY1zA_bCdEf"></param><embed src="http://www.youtube.com/v/xY1zA_bCdEf" type="application/x-shockwave-flash"></embed></object>',
            '<object data="https://www.youtube.com/v/xY1zA_bCdEf" type="application/x-shockwave-flash"></object>',
            ] as $html
        ) {
            $result = $converter->convert($html);

            $this->assertStringContainsString('<!-- wp:embed', $result, $html);
            $this->assertStringContainsString('"providerNameSlug":"youtube"', $result, $html);
        }

        $pdf = $converter->convert('<p>before</p><object data="https://example.com/doc.pdf" type="application/pdf"></object><p>after</p>');

        $this->assertStringContainsString('<!-- wp:html -->', $pdf);
        $this->assertStringContainsString('<object data="https://example.com/doc.pdf"', $pdf);
    }

    public function testExtractsUrlFromEmbedSrc(): void
    {
        $this->fakeYoutubeOembed();

        $element = $this->parseElement(
            '<embed src="https://www.youtube.com/watch?v=xY1zA_bCdEf" type="text/html">'
        );
        $result = $this->converter->convert($element);

        $this->assertInstanceOf(Block::class, $result);
        $this->assertSame('embed', $result->blockName);
        $this->assertSame('https://www.youtube.com/watch?v=xY1zA_bCdEf', $result->attributes['url']);
    }

    public function testExtractsUrlFromObjectParam(): void
    {
        $this->fakeYoutubeOembed();

        $html = '<object><param name="movie" value="https://www.youtube.com/v/xY1zA_bCdEf"><embed src="https://www.youtube.com/v/xY1zA_bCdEf"></object>';
        $element = $this->parseElement($html);
        $result = $this->converter->convert($element);

        $this->assertInstanceOf(Block::class, $result);
        // Flash URL should be normalized
        $this->assertSame('https://www.youtube.com/watch?v=xY1zA_bCdEf', $result->attributes['url']);
    }

    public function testExtractsUrlFromObjectParamSrc(): void
    {
        $this->fakeYoutubeOembed();

        $html = '<object><param name="src" value="https://www.youtube.com/v/abc123"></object>';
        $element = $this->parseElement($html);
        $result = $this->converter->convert($element);

        $this->assertInstanceOf(Block::class, $result);
        $this->assertSame('https://www.youtube.com/watch?v=abc123', $result->attributes['url']);
    }

    public function testFallsBackToNestedEmbedWhenNoParam(): void
    {
        $this->fakeYoutubeOembed();

        $html = '<object><embed src="https://www.youtube.com/v/xyz789"></object>';
        $element = $this->parseElement($html);
        $result = $this->converter->convert($element);

        $this->assertInstanceOf(Block::class, $result);
        $this->assertSame('https://www.youtube.com/watch?v=xyz789', $result->attributes['url']);
    }

    public function testProducesValidEmbedBlockMarkup(): void
    {
        $this->fakeYoutubeOembed();

        $element = $this->parseElement(
            '<embed src="https://www.youtube.com/watch?v=xY1zA_bCdEf">'
        );
        $result = $this->converter->convert($element);

        $this->assertInstanceOf(Block::class, $result);
        html_string($result->innerHTML())->first_by_tag('figure')->assertNodeHasClass('wp-block-embed');
        html_string($result->innerHTML())->assertQuerySelectorExists('.wp-block-embed__wrapper');
        $this->assertTrue($result->attributes['responsive']);
    }

    public function testOembedProviderDetected(): void
    {
        $this->fakeYoutubeOembed();

        $element = $this->parseElement(
            '<embed src="https://www.youtube.com/watch?v=xY1zA_bCdEf">'
        );
        $result = $this->converter->convert($element);

        $this->assertSame('youtube', $result->attributes['providerNameSlug']);
        $this->assertSame('video', $result->attributes['type']);
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
