<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Integration\ShortcodeConverters;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\ShortcodeConverters\CaptionConverter;
use n5s\BlockConverter\Tests\WpTestCase;

use function Mantle\Testing\html_string;

final class CaptionConverterTest extends WpTestCase
{
    private CaptionConverter $converter;
    private int $attachmentId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new CaptionConverter();

        $this->attachmentId = self::factory()->attachment->create([
            'post_title' => 'Test Image',
            'post_mime_type' => 'image/jpeg',
            'guid' => 'https://example.com/wp-content/uploads/photo.jpg',
        ]);
        update_post_meta($this->attachmentId, '_wp_attached_file', 'photo.jpg');
    }

    public function testShortcodeName(): void
    {
        $this->assertSame(['caption', 'wp_caption'], CaptionConverter::shortcodes());
    }

    public function testConvertsCaptionToImageBlock(): void
    {
        $result = $this->convertWithImg('A nice photo');

        $this->assertInstanceOf(Block::class, $result);
        $this->assertSame('image', $result->blockName);
    }

    public function testFallbackWithoutImage(): void
    {
        $result = $this->converter->convert(
            [],
            'Just some text without image',
            'caption',
        );

        $this->assertSame('shortcode', $result->blockName);
    }

    public function testEmptyContentIsPreservedAsAShortcode(): void
    {
        foreach ([null, '', '   '] as $content) {
            $block = $this->converter->convert(['align' => 'left'], $content, 'caption');

            $this->assertInstanceOf(Block::class, $block);
            $this->assertSame('shortcode', $block->blockName);
            $this->assertStringStartsWith('[caption align="left"]', $block->innerHTML());
        }
    }

    public function testImageBlockHasFigureWrapper(): void
    {
        $result = $this->convertWithImg('Caption text');

        html_string($result->innerHTML())->first_by_tag('figure')->assertNodeHasClass('wp-block-image');
        html_string($result->innerHTML())->assertQuerySelectorExists('figure');
    }

    public function testCaptionInFigcaption(): void
    {
        $result = $this->convertWithImg('A nice photo');

        $this->assertStringContainsString(
            '<figcaption class="wp-element-caption">A nice photo</figcaption>',
            $result->innerHTML(),
        );
    }

    public function testCaptionFromExplicitAttribute(): void
    {
        $content = \sprintf(
            '<img src="https://example.com/photo.jpg" class="wp-image-%d" />',
            $this->attachmentId,
        );

        $result = $this->converter->convert(
            ['id' => "attachment_{$this->attachmentId}", 'caption' => 'Explicit caption'],
            $content,
            'caption',
        );
        $this->assertInstanceOf(Block::class, $result);

        $this->assertStringContainsString('Explicit caption', $result->innerHTML());
    }

    public function testNoCaptionProducesNoFigcaption(): void
    {
        $content = \sprintf(
            '<img src="https://example.com/photo.jpg" class="wp-image-%d" />',
            $this->attachmentId,
        );

        $result = $this->converter->convert(
            ['id' => "attachment_{$this->attachmentId}"],
            $content,
            'caption',
        );
        $this->assertInstanceOf(Block::class, $result);

        $this->assertStringNotContainsString('figcaption', $result->innerHTML());
    }

    public function testExtractsAttachmentIdFromShortcodeAttr(): void
    {
        $result = $this->convertWithImg('Caption', [
            'id' => "attachment_{$this->attachmentId}",
        ]);

        $this->assertSame($this->attachmentId, $result->attributes['id']);
    }

    public function testExtractsAttachmentIdFromWpImageClass(): void
    {
        $content = \sprintf(
            '<img src="https://example.com/photo.jpg" class="wp-image-%d" /> Caption',
            $this->attachmentId,
        );

        $result = $this->converter->convert([], $content, 'caption');

        $this->assertSame($this->attachmentId, $result->attributes['id']);
    }

    public function testAddsWpImageClassToImg(): void
    {
        $result = $this->convertWithImg('Caption', [
            'id' => "attachment_{$this->attachmentId}",
        ]);

        html_string($result->innerHTML())->first_by_tag('img')->assertNodeHasClass("wp-image-{$this->attachmentId}");
    }

    public function testAlignmentFromShortcodeAttr(): void
    {
        $result = $this->convertWithImg('Caption', ['align' => 'aligncenter']);

        $this->assertSame('center', $result->attributes['align']);
        html_string($result->innerHTML())->first_by_tag('figure')->assertNodeHasClass('aligncenter');
    }

    public function testAlignLeftFromShortcodeAttr(): void
    {
        $result = $this->convertWithImg('Caption', ['align' => 'alignleft']);

        $this->assertSame('left', $result->attributes['align']);
    }

    public function testAlignNoneNotIncludedInAttributes(): void
    {
        $result = $this->convertWithImg('Caption', ['align' => 'alignnone']);

        $this->assertArrayNotHasKey('align', $result->attributes);
    }

    public function testNoAlignByDefault(): void
    {
        $result = $this->convertWithImg('Caption');

        $this->assertArrayNotHasKey('align', $result->attributes);
    }

    public function testStripsWidthAndHeightFromImg(): void
    {
        $content = \sprintf(
            '<img src="https://example.com/photo.jpg" width="300" height="200" class="wp-image-%d" /> Caption',
            $this->attachmentId,
        );

        $result = $this->converter->convert(
            ['id' => "attachment_{$this->attachmentId}"],
            $content,
            'caption',
        );
        $this->assertInstanceOf(Block::class, $result);

        $img = html_string($result->innerHTML())->first_by_tag('img');
        $this->assertNull($img->get_attribute('width'));
        $this->assertNull($img->get_attribute('height'));
    }

    public function testPreservesLinkWrapperAroundImg(): void
    {
        $content = \sprintf(
            '<a href="https://example.com/photo.jpg"><img src="https://example.com/photo.jpg" class="wp-image-%d" /></a> Caption',
            $this->attachmentId,
        );

        $result = $this->converter->convert(
            ['id' => "attachment_{$this->attachmentId}"],
            $content,
            'caption',
        );
        $this->assertInstanceOf(Block::class, $result);

        html_string($result->innerHTML())->assertQuerySelectorExists('a');
        $this->assertStringContainsString('</a>', $result->innerHTML());
    }

    private function convertWithImg(string $captionText, array $atts = []): Block
    {
        $content = \sprintf(
            '<img src="https://example.com/photo.jpg" class="wp-image-%d" /> %s',
            $this->attachmentId,
            $captionText,
        );

        return $this->converter->convert(
            array_merge(['id' => "attachment_{$this->attachmentId}"], $atts),
            $content,
            'caption',
        );
    }

    public function testAcceptsAnUnprefixedAlignValue(): void
    {
        $block = $this->converter->convert(
            ['align' => 'right'],
            '<img src="/a.jpg" /> Légende',
            'caption',
        );

        $this->assertInstanceOf(Block::class, $block);
        $this->assertSame('right', $block->attributes['align']);
        $this->assertStringContainsString('alignright', $block->innerHTML());
    }

    public function testIgnoresAnUnknownAlignValue(): void
    {
        $block = $this->converter->convert(
            ['align' => 'sideways'],
            '<img src="/a.jpg" /> Légende',
            'caption',
        );

        $this->assertInstanceOf(Block::class, $block);
        $this->assertArrayNotHasKey('align', $block->attributes);
    }

    public function testFallbackRebuildsTheShortcodeWithItsAttributes(): void
    {
        // No <img> in the content, so the converter hands back a wp:shortcode
        // block carrying the original attributes.
        $block = $this->converter->convert(
            ['id' => 'attachment_7', 'align' => 'alignleft'],
            'Juste du texte',
            'caption',
        );

        $this->assertInstanceOf(Block::class, $block);
        $this->assertSame('shortcode', $block->blockName);
        $this->assertStringContainsString('id="attachment_7"', $block->innerHTML());
        $this->assertStringContainsString('align="alignleft"', $block->innerHTML());
        $this->assertStringContainsString('Juste du texte[/caption]', $block->innerHTML());
    }
}
