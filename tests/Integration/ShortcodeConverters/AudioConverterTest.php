<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Integration\ShortcodeConverters;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\ShortcodeConverters\AudioConverter;
use n5s\BlockConverter\Tests\WpTestCase;

use function Mantle\Testing\html_string;

final class AudioConverterTest extends WpTestCase
{
    private AudioConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new AudioConverter();
    }

    public function testShortcodeName(): void
    {
        $this->assertSame(['audio'], AudioConverter::shortcodes());
    }

    public function testConvertsAudioWithSrc(): void
    {
        $result = $this->convert(['src' => 'https://example.com/audio.mp3']);

        $this->assertSame('audio', $result->blockName);
        $this->assertSame('https://example.com/audio.mp3', html_string($result->innerHTML())->first_by_tag('audio')->get_attribute('src'));
    }

    public function testConvertsAudioWithMp3Attribute(): void
    {
        $result = $this->convert(['mp3' => 'https://example.com/song.mp3']);

        $this->assertSame('audio', $result->blockName);
        $this->assertSame('https://example.com/song.mp3', html_string($result->innerHTML())->first_by_tag('audio')->get_attribute('src'));
    }

    public function testConvertsAudioWithOggAttribute(): void
    {
        $result = $this->convert(['ogg' => 'https://example.com/song.ogg']);

        $this->assertSame('https://example.com/song.ogg', html_string($result->innerHTML())->first_by_tag('audio')->get_attribute('src'));
    }

    public function testWithoutASourceTheShortcodeIsPreserved(): void
    {
        $block = $this->converter->convert(['loop' => 'on'], null, 'audio');

        $this->assertInstanceOf(Block::class, $block);
        $this->assertSame('shortcode', $block->blockName);
        $this->assertSame('[audio loop="on"]', $block->innerHTML());
    }

    public function testAudioBlockHasFigureWrapper(): void
    {
        $result = $this->convert(['src' => 'https://example.com/audio.mp3']);

        html_string($result->innerHTML())->first_by_tag('figure')->assertNodeHasClass('wp-block-audio');
        html_string($result->innerHTML())->assertQuerySelectorExists('figure');
    }

    public function testAudioAlwaysHasControls(): void
    {
        $result = $this->convert(['src' => 'https://example.com/audio.mp3']);

        $this->assertNotNull(html_string($result->innerHTML())->first_by_tag('audio')->get_attribute('controls'));
    }

    public function testAutoplayAttribute(): void
    {
        $result = $this->convert(['src' => 'https://example.com/a.mp3', 'autoplay' => 'on']);

        $this->assertTrue($result->attributes['autoplay']);
        $this->assertNotNull(html_string($result->innerHTML())->first_by_tag('audio')->get_attribute('autoplay'));
    }

    public function testLoopAttribute(): void
    {
        $result = $this->convert(['src' => 'https://example.com/a.mp3', 'loop' => 'on']);

        $this->assertTrue($result->attributes['loop']);
        $this->assertNotNull(html_string($result->innerHTML())->first_by_tag('audio')->get_attribute('loop'));
    }

    public function testBooleanAttributesOmittedWhenFalse(): void
    {
        $result = $this->convert(['src' => 'https://example.com/a.mp3']);

        $this->assertArrayNotHasKey('autoplay', $result->attributes);
        $this->assertArrayNotHasKey('loop', $result->attributes);
    }

    public function testPreloadDefaultNoneNotIncludedInAttributes(): void
    {
        $result = $this->convert(['src' => 'https://example.com/a.mp3']);

        $this->assertArrayNotHasKey('preload', $result->attributes);
        $this->assertSame('none', html_string($result->innerHTML())->first_by_tag('audio')->get_attribute('preload'));
    }

    public function testPreloadMetadataIncludedInAttributes(): void
    {
        $result = $this->convert(['src' => 'https://example.com/a.mp3', 'preload' => 'metadata']);

        $this->assertSame('metadata', $result->attributes['preload']);
    }

    public function testPreloadAutoIncludedInAttributes(): void
    {
        $result = $this->convert(['src' => 'https://example.com/a.mp3', 'preload' => 'auto']);

        $this->assertSame('auto', $result->attributes['preload']);
    }

    public function testInvalidPreloadFallsBackToNone(): void
    {
        $result = $this->convert(['src' => 'https://example.com/a.mp3', 'preload' => 'garbage']);

        $this->assertArrayNotHasKey('preload', $result->attributes);
        $this->assertSame('none', html_string($result->innerHTML())->first_by_tag('audio')->get_attribute('preload'));
    }

    public function testCaptionFromContent(): void
    {
        $result = $this->converter->convert(
            ['src' => 'https://example.com/a.mp3'],
            'My audio caption',
            'audio',
        );
        $this->assertInstanceOf(Block::class, $result);

        $this->assertStringContainsString(
            '<figcaption class="wp-element-caption">My audio caption</figcaption>',
            $result->innerHTML(),
        );
    }

    public function testNoCaptionWhenContentEmpty(): void
    {
        $result = $this->convert(['src' => 'https://example.com/a.mp3']);
        $this->assertStringNotContainsString('figcaption', $result->innerHTML());
    }

    public function testResolvesAttachmentIdFromUrl(): void
    {
        $attachmentId = self::factory()->attachment->create(['file' => 'song.mp3']);
        $url = wp_get_attachment_url($attachmentId);

        $result = $this->convert(['src' => $url]);

        $this->assertSame($attachmentId, $result->attributes['id']);
    }

    public function testNoIdForUnknownUrl(): void
    {
        $result = $this->convert(['src' => 'https://example.com/unknown.mp3']);

        $this->assertArrayNotHasKey('id', $result->attributes);
    }

    private function convert(array $atts): Block
    {
        return $this->converter->convert($atts, null, 'audio');
    }
}
