<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Integration\ShortcodeConverters;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\ShortcodeConverters\GalleryConverter;
use n5s\BlockConverter\Tests\WpTestCase;

use function Mantle\Testing\html_string;

final class GalleryConverterTest extends WpTestCase
{
    private GalleryConverter $converter;

    /** @var int[] */
    private array $attachmentIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new GalleryConverter();
        $this->attachmentIds = $this->createTestAttachments();
    }

    public function testShortcodeName(): void
    {
        $this->assertSame(['gallery'], GalleryConverter::shortcodes());
    }

    public function testConvertsGalleryToBlock(): void
    {
        $result = $this->convertWithIds();

        $this->assertInstanceOf(Block::class, $result);
        $this->assertSame('gallery', $result->blockName);
    }

    public function testGalleryHasInnerImageBlocks(): void
    {
        $result = $this->convertWithIds();

        $this->assertCount(3, $result->innerBlocks);
        foreach ($result->innerBlocks as $inner) {
            $this->assertSame('image', $inner->blockName);
        }
    }

    public function testInnerImageBlocksHaveIdAndSrc(): void
    {
        $result = $this->convertWithIds();

        foreach ($result->innerBlocks as $i => $imageBlock) {
            $expectedId = $this->attachmentIds[$i];

            $this->assertSame($expectedId, $imageBlock->attributes['id']);
            html_string($imageBlock->innerHTML())->first_by_tag('img')->assertNodeHasClass("wp-image-{$expectedId}");
            html_string($imageBlock->innerHTML())->first_by_tag('figure')->assertNodeHasClass('wp-block-image');
        }
    }

    public function testDefaultLinkIsAttachmentPage(): void
    {
        $result = $this->convertWithIds();

        $this->assertSame('attachment', $result->attributes['linkTo']);
    }

    public function testLinkToFile(): void
    {
        $result = $this->convertWithIds(['link' => 'file']);

        $this->assertSame('media', $result->attributes['linkTo']);
    }

    public function testLinkToPost(): void
    {
        $result = $this->convertWithIds(['link' => 'post']);

        $this->assertSame('attachment', $result->attributes['linkTo']);
    }

    public function testLinkNone(): void
    {
        $result = $this->convertWithIds(['link' => 'none']);

        $this->assertSame('none', $result->attributes['linkTo']);
    }

    public function testColumnsAttribute(): void
    {
        $result = $this->convertWithIds(['columns' => '2']);

        $this->assertSame(2, $result->attributes['columns']);
    }

    public function testDefaultColumnsNotInAttributes(): void
    {
        $result = $this->convertWithIds();

        $this->assertArrayNotHasKey('columns', $result->attributes);
    }

    public function testColumnsRenderedInFigureClass(): void
    {
        $result = $this->convertWithIds(['columns' => '4']);

        $rendered = $result->render();
        html_string($rendered)->first_by_tag('figure')->assertNodeHasClass('columns-4');
    }

    public function testDefaultColumnsRenderedAs3(): void
    {
        $result = $this->convertWithIds();

        $rendered = $result->render();
        html_string($rendered)->first_by_tag('figure')->assertNodeHasClass('columns-3');
    }

    public function testDefaultSizeIsThumbnail(): void
    {
        $result = $this->convertWithIds();

        foreach ($result->innerBlocks as $imageBlock) {
            $this->assertSame('thumbnail', $imageBlock->attributes['sizeSlug']);
        }
    }

    public function testCustomSize(): void
    {
        $result = $this->convertWithIds(['size' => 'large']);

        foreach ($result->innerBlocks as $imageBlock) {
            $this->assertSame('large', $imageBlock->attributes['sizeSlug']);
        }
    }

    public function testInnerImageBlocksHaveCaptionsFromExcerpt(): void
    {
        wp_update_post([
            'ID' => $this->attachmentIds[0],
            'post_excerpt' => 'First image caption',
        ]);

        $result = $this->convertWithIds();

        $this->assertStringContainsString(
            '<figcaption class="wp-element-caption">First image caption</figcaption>',
            $result->innerBlocks[0]->innerHTML(),
        );
    }

    public function testInnerImageBlocksOmitEmptyCaptions(): void
    {
        $result = $this->convertWithIds();

        foreach ($result->innerBlocks as $imageBlock) {
            $this->assertStringNotContainsString('figcaption', $imageBlock->innerHTML());
        }
    }

    public function testGalleryRendersWithCorrectFigureClasses(): void
    {
        $rendered = $this->convertWithIds()->render();

        $this->assertStringContainsString('wp:gallery', $rendered);
        $figure = html_string($rendered)->first_by_tag('figure');
        $figure->assertNodeHasClass('has-nested-images');
        $figure->assertNodeHasClass('is-cropped');
    }

    public function testSkipsNonexistentAttachmentIds(): void
    {
        $ids = $this->attachmentIds[0] . ',999999';
        $result = $this->converter->convert(['ids' => $ids], null, 'gallery');

        $this->assertCount(1, $result->innerBlocks);
    }

    /**
     * [gallery] without ids means every attachment of the post, resolved at
     * render time; ids that no longer exist are the author's to see. Both
     * used to vanish from the output.
     */
    public function testAGalleryItCannotBuildIsPreservedAsAShortcode(): void
    {
        foreach ([[], ['ids' => '999998,999999']] as $atts) {
            $block = $this->converter->convert($atts, null, 'gallery');

            $this->assertInstanceOf(Block::class, $block);
            $this->assertSame('shortcode', $block->blockName);
        }

        $this->assertSame('[gallery ids="999998,999999"]', $this->converter->convert(['ids' => '999998,999999'], null, 'gallery')->innerHTML());
    }

    private function convertWithIds(array $extraAtts = []): Block
    {
        return $this->converter->convert(
            array_merge(['ids' => \implode(',', $this->attachmentIds)], $extraAtts),
            null,
            'gallery',
        );
    }

    /**
     * @return int[]
     */
    private function createTestAttachments(): array
    {
        $ids = [];

        for ($i = 1; $i <= 3; $i++) {
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
}
