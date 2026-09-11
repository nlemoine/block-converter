<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Integration\TagConverters;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\BlockConverter;
use n5s\BlockConverter\TagConverters\AudioConverter;
use n5s\BlockConverter\Tests\WpTestCase;
use voku\helper\HtmlDomParser;
use voku\helper\SimpleHtmlDomInterface;

use function Mantle\Testing\html_string;

final class AudioConverterTest extends WpTestCase
{
    private AudioConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new AudioConverter();
    }

    public function testTagNames(): void
    {
        $this->assertSame(['audio'], AudioConverter::tags());
    }

    public function testReturnsNullWithoutSrc(): void
    {
        $element = $this->parseElement('<audio></audio>');
        $this->assertNull($this->converter->convert($element));
    }

    public function testReturnsNullWithEmptySrc(): void
    {
        $element = $this->parseElement('<audio src=""></audio>');
        $this->assertNull($this->converter->convert($element));
    }

    public function testConvertsAudioWithSrcAttribute(): void
    {
        $element = $this->parseElement('<audio src="https://example.com/audio.mp3" controls></audio>');
        $result = $this->converter->convert($element);

        $this->assertInstanceOf(Block::class, $result);
        $this->assertSame('audio', $result->blockName);
        $this->assertSame(
            'https://example.com/audio.mp3',
            html_string($result->innerHTML())->first_by_tag('audio')->get_attribute('src'),
        );
    }

    public function testConvertsAudioWithSourceChild(): void
    {
        $element = $this->parseElement(
            '<audio controls><source src="https://example.com/song.mp3" type="audio/mpeg"></audio>'
        );
        $result = $this->converter->convert($element);

        $this->assertInstanceOf(Block::class, $result);
        $this->assertSame('audio', $result->blockName);
        $this->assertSame(
            'https://example.com/song.mp3',
            html_string($result->innerHTML())->first_by_tag('audio')->get_attribute('src'),
        );
    }

    public function testPicksFirstSourceWhenMultiple(): void
    {
        $element = $this->parseElement(
            '<audio controls>'
            . '<source src="https://example.com/song.mp3" type="audio/mpeg">'
            . '<source src="https://example.com/song.ogg" type="audio/ogg">'
            . '</audio>'
        );
        $result = $this->converter->convert($element);

        $this->assertSame(
            'https://example.com/song.mp3',
            html_string($result->innerHTML())->first_by_tag('audio')->get_attribute('src'),
        );
    }

    public function testSrcAttributeTakesPriorityOverSourceChild(): void
    {
        $element = $this->parseElement(
            '<audio src="https://example.com/main.mp3" controls>'
            . '<source src="https://example.com/fallback.mp3">'
            . '</audio>'
        );
        $result = $this->converter->convert($element);

        $this->assertSame(
            'https://example.com/main.mp3',
            html_string($result->innerHTML())->first_by_tag('audio')->get_attribute('src'),
        );
    }

    public function testAudioBlockHasFigureWrapper(): void
    {
        $element = $this->parseElement('<audio src="https://example.com/audio.mp3" controls></audio>');
        $result = $this->converter->convert($element);

        html_string($result->innerHTML())->first_by_tag('figure')->assertNodeHasClass('wp-block-audio');
    }

    public function testAudioAlwaysHasControls(): void
    {
        $element = $this->parseElement('<audio src="https://example.com/audio.mp3"></audio>');
        $result = $this->converter->convert($element);

        $this->assertNotNull(
            html_string($result->innerHTML())->first_by_tag('audio')->get_attribute('controls'),
        );
    }

    public function testAutoplayAttribute(): void
    {
        $element = $this->parseElement('<audio src="https://example.com/a.mp3" autoplay controls></audio>');
        $result = $this->converter->convert($element);

        $this->assertTrue($result->attributes['autoplay']);
        $this->assertNotNull(
            html_string($result->innerHTML())->first_by_tag('audio')->get_attribute('autoplay'),
        );
    }

    public function testLoopAttribute(): void
    {
        $element = $this->parseElement('<audio src="https://example.com/a.mp3" loop controls></audio>');
        $result = $this->converter->convert($element);

        $this->assertTrue($result->attributes['loop']);
        $this->assertNotNull(
            html_string($result->innerHTML())->first_by_tag('audio')->get_attribute('loop'),
        );
    }

    public function testBooleanAttributesOmittedWhenAbsent(): void
    {
        $element = $this->parseElement('<audio src="https://example.com/a.mp3" controls></audio>');
        $result = $this->converter->convert($element);

        $this->assertArrayNotHasKey('autoplay', $result->attributes);
        $this->assertArrayNotHasKey('loop', $result->attributes);
    }

    public function testPreloadDefaultNoneNotIncludedInAttributes(): void
    {
        $element = $this->parseElement('<audio src="https://example.com/a.mp3" controls></audio>');
        $result = $this->converter->convert($element);

        $this->assertArrayNotHasKey('preload', $result->attributes);
        $this->assertSame(
            'none',
            html_string($result->innerHTML())->first_by_tag('audio')->get_attribute('preload'),
        );
    }

    public function testPreloadMetadataIncludedInAttributes(): void
    {
        $element = $this->parseElement('<audio src="https://example.com/a.mp3" preload="metadata" controls></audio>');
        $result = $this->converter->convert($element);

        $this->assertSame('metadata', $result->attributes['preload']);
    }

    public function testPreloadAutoIncludedInAttributes(): void
    {
        $element = $this->parseElement('<audio src="https://example.com/a.mp3" preload="auto" controls></audio>');
        $result = $this->converter->convert($element);

        $this->assertSame('auto', $result->attributes['preload']);
    }

    public function testInvalidPreloadFallsBackToNone(): void
    {
        $element = $this->parseElement('<audio src="https://example.com/a.mp3" preload="garbage" controls></audio>');
        $result = $this->converter->convert($element);

        $this->assertArrayNotHasKey('preload', $result->attributes);
        $this->assertSame(
            'none',
            html_string($result->innerHTML())->first_by_tag('audio')->get_attribute('preload'),
        );
    }

    public function testResolvesAttachmentIdFromUrl(): void
    {
        $attachmentId = self::factory()->attachment->create(['file' => 'song.mp3']);
        $url = wp_get_attachment_url($attachmentId);

        $element = $this->parseElement(\sprintf('<audio src="%s" controls></audio>', $url));
        $result = $this->converter->convert($element);

        $this->assertSame($attachmentId, $result->attributes['id']);
    }

    public function testNoIdForUnknownUrl(): void
    {
        $element = $this->parseElement('<audio src="https://example.com/unknown.mp3" controls></audio>');
        $result = $this->converter->convert($element);

        $this->assertArrayNotHasKey('id', $result->attributes);
    }

    public function testReturnsNullWhenSourceChildHasNoSrc(): void
    {
        $element = $this->parseElement('<audio controls><source type="audio/mpeg"></audio>');
        $this->assertNull($this->converter->convert($element));
    }

    public function testIntegrationWithBlockConverter(): void
    {
        $converter = BlockConverter::createDefault();
        $result = $converter->convert('<audio src="https://example.com/audio.mp3" controls></audio>');

        $this->assertStringContainsString('<!-- wp:audio', $result);
        $this->assertStringContainsString('wp-block-audio', $result);
    }

    private function parseElement(string $html): SimpleHtmlDomInterface
    {
        $dom = HtmlDomParser::str_get_html('<body>' . $html . '</body>');
        $elements = $dom->findMultiOrFalse('//body/*');

        if ($elements === false) {
            throw new \RuntimeException("No element found in: {$html}");
        }

        return $elements[0];
    }
}
