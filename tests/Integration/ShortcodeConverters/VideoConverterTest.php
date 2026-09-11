<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Integration\ShortcodeConverters;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\ShortcodeConverters\VideoConverter;
use n5s\BlockConverter\Tests\WpTestCase;

use function Mantle\Testing\html_string;

final class VideoConverterTest extends WpTestCase
{
    private VideoConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new VideoConverter();
    }

    public function testShortcodeName(): void
    {
        $this->assertSame(['video'], VideoConverter::shortcodes());
    }

    // --- Source resolution ---

    public function testConvertsVideoWithSrc(): void
    {
        $result = $this->convert(['src' => 'https://example.com/video.mp4']);

        $this->assertSame('video', $result->blockName);
        $this->assertSame('https://example.com/video.mp4', html_string($result->innerHTML())->first_by_tag('video')->get_attribute('src'));
    }

    public function testConvertsVideoWithMp4Attribute(): void
    {
        $result = $this->convert(['mp4' => 'https://example.com/clip.mp4']);

        $this->assertSame('video', $result->blockName);
        $this->assertSame('https://example.com/clip.mp4', html_string($result->innerHTML())->first_by_tag('video')->get_attribute('src'));
    }

    public function testConvertsVideoWithWebmAttribute(): void
    {
        $result = $this->convert(['webm' => 'https://example.com/clip.webm']);

        $this->assertSame('https://example.com/clip.webm', html_string($result->innerHTML())->first_by_tag('video')->get_attribute('src'));
    }

    public function testWithoutASourceTheShortcodeIsPreserved(): void
    {
        $block = $this->converter->convert(['poster' => '/p.jpg'], null, 'video');

        $this->assertInstanceOf(Block::class, $block);
        $this->assertSame('shortcode', $block->blockName);
        $this->assertSame('[video poster="/p.jpg"]', $block->innerHTML());
    }

    // --- Figure wrapper ---

    public function testVideoBlockHasFigureWrapper(): void
    {
        $result = $this->convert(['src' => 'https://example.com/video.mp4']);

        html_string($result->innerHTML())->first_by_tag('figure')->assertNodeHasClass('wp-block-video');
        html_string($result->innerHTML())->assertQuerySelectorExists('figure');
    }

    public function testVideoAlwaysHasControls(): void
    {
        $result = $this->convert(['src' => 'https://example.com/video.mp4']);

        $this->assertNotNull(html_string($result->innerHTML())->first_by_tag('video')->get_attribute('controls'));
    }

    // --- Boolean attributes ---

    public function testAutoplayAttribute(): void
    {
        $result = $this->convert(['src' => 'https://example.com/v.mp4', 'autoplay' => 'on']);

        $this->assertTrue($result->attributes['autoplay']);
        $this->assertNotNull(html_string($result->innerHTML())->first_by_tag('video')->get_attribute('autoplay'));
    }

    public function testLoopAttribute(): void
    {
        $result = $this->convert(['src' => 'https://example.com/v.mp4', 'loop' => 'on']);

        $this->assertTrue($result->attributes['loop']);
        $this->assertNotNull(html_string($result->innerHTML())->first_by_tag('video')->get_attribute('loop'));
    }

    public function testMutedAttribute(): void
    {
        $result = $this->convert(['src' => 'https://example.com/v.mp4', 'muted' => 'true']);

        $this->assertTrue($result->attributes['muted']);
        $this->assertNotNull(html_string($result->innerHTML())->first_by_tag('video')->get_attribute('muted'));
    }

    public function testMutedDefaultFalseNotIncluded(): void
    {
        $result = $this->convert(['src' => 'https://example.com/v.mp4', 'muted' => 'false']);

        $this->assertArrayNotHasKey('muted', $result->attributes);
    }

    public function testPlaysInlineAttribute(): void
    {
        $result = $this->convert(['src' => 'https://example.com/v.mp4', 'playsinline' => 'on']);

        $this->assertTrue($result->attributes['playsInline']);
        $this->assertNotNull(html_string($result->innerHTML())->first_by_tag('video')->get_attribute('playsinline'));
    }

    public function testBooleanAttributesOmittedWhenFalse(): void
    {
        $result = $this->convert(['src' => 'https://example.com/v.mp4']);

        $this->assertArrayNotHasKey('autoplay', $result->attributes);
        $this->assertArrayNotHasKey('loop', $result->attributes);
        $this->assertArrayNotHasKey('muted', $result->attributes);
        $this->assertArrayNotHasKey('playsInline', $result->attributes);
    }

    // --- Poster ---

    public function testPosterAttribute(): void
    {
        $result = $this->convert([
            'src' => 'https://example.com/v.mp4',
            'poster' => 'https://example.com/thumb.jpg',
        ]);

        $this->assertSame('https://example.com/thumb.jpg', $result->attributes['poster']);
        $this->assertSame('https://example.com/thumb.jpg', html_string($result->innerHTML())->first_by_tag('video')->get_attribute('poster'));
    }

    public function testPosterOmittedWhenEmpty(): void
    {
        $result = $this->convert(['src' => 'https://example.com/v.mp4']);
        $this->assertArrayNotHasKey('poster', $result->attributes);
    }

    // --- Preload ---

    public function testPreloadDefaultMetadataNotIncludedInAttributes(): void
    {
        $result = $this->convert(['src' => 'https://example.com/v.mp4']);

        $this->assertArrayNotHasKey('preload', $result->attributes);
        $this->assertSame('metadata', html_string($result->innerHTML())->first_by_tag('video')->get_attribute('preload'));
    }

    public function testPreloadAutoIncludedInAttributes(): void
    {
        $result = $this->convert(['src' => 'https://example.com/v.mp4', 'preload' => 'auto']);

        $this->assertSame('auto', $result->attributes['preload']);
        $this->assertSame('auto', html_string($result->innerHTML())->first_by_tag('video')->get_attribute('preload'));
    }

    public function testPreloadNoneIncludedInAttributes(): void
    {
        $result = $this->convert(['src' => 'https://example.com/v.mp4', 'preload' => 'none']);

        $this->assertSame('none', $result->attributes['preload']);
    }

    public function testInvalidPreloadFallsBackToMetadata(): void
    {
        $result = $this->convert(['src' => 'https://example.com/v.mp4', 'preload' => 'garbage']);

        $this->assertArrayNotHasKey('preload', $result->attributes);
        $this->assertSame('metadata', html_string($result->innerHTML())->first_by_tag('video')->get_attribute('preload'));
    }

    // --- Alignment ---

    public function testAlignAttribute(): void
    {
        $result = $this->convert(['src' => 'https://example.com/v.mp4', 'align' => 'wide']);

        $this->assertSame('wide', $result->attributes['align']);
        html_string($result->innerHTML())->first_by_tag('figure')->assertNodeHasClass('alignwide');
    }

    public function testAlignCenter(): void
    {
        $result = $this->convert(['src' => 'https://example.com/v.mp4', 'align' => 'center']);

        $this->assertSame('center', $result->attributes['align']);
        html_string($result->innerHTML())->first_by_tag('figure')->assertNodeHasClass('aligncenter');
    }

    public function testNoAlignByDefault(): void
    {
        $result = $this->convert(['src' => 'https://example.com/v.mp4']);
        $this->assertArrayNotHasKey('align', $result->attributes);
    }

    // --- Caption ---

    public function testCaptionFromContent(): void
    {
        $result = $this->converter->convert(
            ['src' => 'https://example.com/v.mp4'],
            'My video caption',
            'video',
        );
        $this->assertInstanceOf(Block::class, $result);

        $this->assertStringContainsString('<figcaption class="wp-element-caption">My video caption</figcaption>', $result->innerHTML());
    }

    public function testNoCaptionWhenContentEmpty(): void
    {
        $result = $this->convert(['src' => 'https://example.com/v.mp4']);
        $this->assertStringNotContainsString('figcaption', $result->innerHTML());
    }

    // --- Attachment ID resolution ---

    public function testResolvesAttachmentIdFromAttribute(): void
    {
        $attachmentId = self::factory()->attachment->create();
        $result = $this->convert([
            'src' => 'https://example.com/v.mp4',
            'id' => (string) $attachmentId,
        ]);

        $this->assertSame($attachmentId, $result->attributes['id']);
    }

    public function testResolvesAttachmentIdFromUrl(): void
    {
        $baseUrl = wp_get_upload_dir()['baseurl'];
        $attachmentId = self::factory()->attachment->create([
            'post_mime_type' => 'video/mp4',
            'guid' => "{$baseUrl}/2024/01/clip.mp4",
        ]);
        update_post_meta($attachmentId, '_wp_attached_file', '2024/01/clip.mp4');

        $result = $this->convert(['src' => "{$baseUrl}/2024/01/clip.mp4"]);

        $this->assertSame($attachmentId, $result->attributes['id']);
    }

    public function testInvalidIdAttributeIgnored(): void
    {
        $postId = self::factory()->post->create();
        $result = $this->convert([
            'src' => 'https://example.com/v.mp4',
            'id' => (string) $postId,
        ]);

        $this->assertArrayNotHasKey('id', $result->attributes);
    }

    // --- Full block render ---

    public function testFullBlockRenderMatchesGutenbergFormat(): void
    {
        $attachmentId = self::factory()->attachment->create();

        $result = $this->converter->convert(
            [
                'src' => 'https://example.com/video.mp4',
                'id' => (string) $attachmentId,
                'autoplay' => 'on',
                'loop' => 'on',
                'muted' => 'true',
                'playsinline' => 'on',
            ],
            null,
            'video',
        );
        $this->assertInstanceOf(Block::class, $result);

        $rendered = $result->render();

        $this->assertStringContainsString('<!-- wp:video', $rendered);
        $this->assertStringContainsString('"id":' . $attachmentId, $rendered);
        $this->assertStringContainsString('"autoplay":true', $rendered);
        $this->assertStringContainsString('"loop":true', $rendered);
        $this->assertStringContainsString('"muted":true', $rendered);
        $this->assertStringContainsString('"playsInline":true', $rendered);
        html_string($rendered)->first_by_tag('figure')->assertNodeHasClass('wp-block-video');
        $this->assertStringContainsString('<!-- /wp:video -->', $rendered);
    }

    // --- Helpers ---

    private function convert(array $atts): Block
    {
        return $this->converter->convert($atts, null, 'video');
    }
}
