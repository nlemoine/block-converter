<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Integration\TagConverters;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\TagConverters\ImageConverter;
use n5s\BlockConverter\Tests\WpTestCase;
use voku\helper\HtmlDomParser;
use voku\helper\SimpleHtmlDomInterface;

use function Mantle\Testing\html_string;

final class ImageConverterTest extends WpTestCase
{
    private ImageConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new ImageConverter();
    }

    public function testTagNames(): void
    {
        $this->assertSame(['img'], ImageConverter::tags());
    }

    public function testReturnsNullWithoutSrc(): void
    {
        $element = $this->parseElement('<img alt="no source">');
        $this->assertNull($this->converter->convert($element));
    }

    public function testReturnsNullWithoutImgTag(): void
    {
        $element = $this->parseElement('<span>no image here</span>');
        $this->assertNull($this->converter->convert($element));
    }

    public function testBasicImageProducesImageBlock(): void
    {
        $element = $this->parseElement('<img src="https://example.com/photo.jpg" alt="A photo">');
        $result = $this->converter->convert($element);

        $this->assertInstanceOf(Block::class, $result);
        $this->assertSame('image', $result->blockName);
        html_string($result->innerHTML())->first_by_tag('figure')->assertNodeHasClass('wp-block-image');
        html_string($result->innerHTML())->assertQuerySelectorExists('figure');
        $this->assertStringContainsString('photo.jpg', $result->innerHTML());
    }

    public function testRemovesWidthAndHeight(): void
    {
        $element = $this->parseElement('<img src="https://example.com/photo.jpg" width="800" height="600">');
        $result = $this->converter->convert($element);

        $img = html_string($result->innerHTML())->first_by_tag('img');
        $this->assertNull($img->get_attribute('width'));
        $this->assertNull($img->get_attribute('height'));
    }

    public function testResolvesIdFromWpImageClass(): void
    {
        $attachmentId = self::factory()->attachment->create([
            'post_mime_type' => 'image/jpeg',
            'guid' => 'https://example.com/wp-content/uploads/photo.jpg',
        ]);

        $element = $this->parseElement(\sprintf(
            '<img src="https://example.com/wp-content/uploads/photo.jpg" class="wp-image-%d">',
            $attachmentId,
        ));
        $result = $this->converter->convert($element);

        $this->assertSame($attachmentId, $result->attributes['id']);
    }

    public function testResolvesIdFromUrlViaAttachedFile(): void
    {
        $uploadDir = wp_get_upload_dir();
        $baseUrl = $uploadDir['baseurl'];

        $attachmentId = self::factory()->attachment->create([
            'post_mime_type' => 'image/jpeg',
            'guid' => "{$baseUrl}/2024/01/landscape.jpg",
        ]);
        update_post_meta($attachmentId, '_wp_attached_file', '2024/01/landscape.jpg');

        $element = $this->parseElement("<img src=\"{$baseUrl}/2024/01/landscape.jpg\">");
        $result = $this->converter->convert($element);

        $this->assertSame($attachmentId, $result->attributes['id']);
    }

    public function testAddsWpImageClassWhenIdResolved(): void
    {
        $attachmentId = self::factory()->attachment->create([
            'post_mime_type' => 'image/jpeg',
            'guid' => 'https://example.com/wp-content/uploads/photo.jpg',
        ]);

        $element = $this->parseElement(\sprintf(
            '<img src="https://example.com/wp-content/uploads/photo.jpg" class="wp-image-%d">',
            $attachmentId,
        ));
        $result = $this->converter->convert($element);

        html_string($result->innerHTML())->first_by_tag('img')->assertNodeHasClass("wp-image-{$attachmentId}");
    }

    public function testAddsWpImageClassWhenIdResolvedFromUrl(): void
    {
        $uploadDir = wp_get_upload_dir();
        $baseUrl = $uploadDir['baseurl'];

        $attachmentId = self::factory()->attachment->create([
            'post_mime_type' => 'image/jpeg',
            'guid' => "{$baseUrl}/2024/02/sunset.jpg",
        ]);
        update_post_meta($attachmentId, '_wp_attached_file', '2024/02/sunset.jpg');

        $element = $this->parseElement("<img src=\"{$baseUrl}/2024/02/sunset.jpg\">");
        $result = $this->converter->convert($element);

        $this->assertSame($attachmentId, $result->attributes['id']);
        html_string($result->innerHTML())->first_by_tag('img')->assertNodeHasClass("wp-image-{$attachmentId}");
    }

    public function testExtractsAlignmentFromImgClasses(): void
    {
        $element = $this->parseElement('<img src="https://example.com/photo.jpg" class="aligncenter">');
        $result = $this->converter->convert($element);

        $this->assertSame('center', $result->attributes['align']);
        // Alignment class should be on figure, not on img
        html_string($result->innerHTML())->first_by_tag('figure')->assertNodeHasClass('aligncenter');
    }

    public function testRemovesAlignmentClassFromImg(): void
    {
        $element = $this->parseElement('<img src="https://example.com/photo.jpg" class="alignleft custom-class">');
        $result = $this->converter->convert($element);

        // The img tag should not have alignleft anymore
        $this->assertFalse(html_string($result->innerHTML())->first_by_tag('img')->has_class('alignleft'), "Img should NOT have class 'alignleft'");
        // But should keep other classes
        html_string($result->innerHTML())->first_by_tag('img')->assertNodeHasClass('custom-class');
    }

    public function testAlignNoneIsRemovedButNotAddedToAttributes(): void
    {
        $element = $this->parseElement('<img src="https://example.com/photo.jpg" class="alignnone">');
        $result = $this->converter->convert($element);

        $this->assertArrayNotHasKey('align', $result->attributes);
        $this->assertFalse(html_string($result->innerHTML())->first_by_tag('img')->has_class('alignnone'), "Img should NOT have class 'alignnone'");
    }

    public function testExtractsSizeFromImgClasses(): void
    {
        $element = $this->parseElement('<img src="https://example.com/photo.jpg" class="size-large">');
        $result = $this->converter->convert($element);

        $this->assertSame('large', $result->attributes['sizeSlug']);
        html_string($result->innerHTML())->first_by_tag('figure')->assertNodeHasClass('size-large');
    }

    public function testRemovesSizeClassFromImg(): void
    {
        $element = $this->parseElement('<img src="https://example.com/photo.jpg" class="size-medium other-class">');
        $result = $this->converter->convert($element);

        $this->assertFalse(html_string($result->innerHTML())->first_by_tag('img')->has_class('size-medium'), "Img should NOT have class 'size-medium'");
        html_string($result->innerHTML())->first_by_tag('img')->assertNodeHasClass('other-class');
    }

    public function testDetectsMediaLinkDestination(): void
    {
        $siteUrl = home_url();
        $element = $this->parseElement(\sprintf(
            '<a href="%s/wp-content/uploads/photo.jpg"><img src="%s/wp-content/uploads/photo.jpg"></a>',
            $siteUrl,
            $siteUrl,
        ));
        $result = $this->converter->convert($element);

        $this->assertSame('media', $result->attributes['linkDestination']);
    }

    public function testRemovesTitleFromLink(): void
    {
        $siteUrl = home_url();
        $element = $this->parseElement(\sprintf(
            '<a href="%s/wp-content/uploads/photo.jpg" title="Some title"><img src="%s/wp-content/uploads/photo.jpg"></a>',
            $siteUrl,
            $siteUrl,
        ));
        $result = $this->converter->convert($element);

        $a = html_string($result->innerHTML())->first_by_tag('a');
        $this->assertNull($a->get_attribute('title'));
    }

    public function testDoesNotDetectLinkToExternalUrl(): void
    {
        $element = $this->parseElement(
            '<a href="https://external.com/page"><img src="https://example.com/photo.jpg"></a>',
        );
        $result = $this->converter->convert($element);

        $this->assertArrayNotHasKey('linkDestination', $result->attributes);
    }

    public function testFullScenarioWithAllFeatures(): void
    {
        $attachmentId = self::factory()->attachment->create([
            'post_mime_type' => 'image/jpeg',
            'guid' => 'https://example.com/wp-content/uploads/hero.jpg',
        ]);

        $element = $this->parseElement(\sprintf(
            '<img src="https://example.com/wp-content/uploads/hero.jpg" class="wp-image-%d aligncenter size-large" width="1024" height="768">',
            $attachmentId,
        ));
        $result = $this->converter->convert($element);

        $this->assertSame('image', $result->blockName);
        $this->assertSame($attachmentId, $result->attributes['id']);
        $this->assertSame('center', $result->attributes['align']);
        $this->assertSame('large', $result->attributes['sizeSlug']);

        $img = html_string($result->innerHTML())->first_by_tag('img');
        $this->assertNull($img->get_attribute('width'));
        $this->assertNull($img->get_attribute('height'));

        $figure = html_string($result->innerHTML())->first_by_tag('figure');
        $figure->assertNodeHasClass('wp-block-image');
        $figure->assertNodeHasClass('aligncenter');
        $figure->assertNodeHasClass('size-large');

        $img->assertNodeHasClass("wp-image-{$attachmentId}");
        $this->assertFalse($img->has_class('aligncenter'), "Img should NOT have class 'aligncenter'");
        $this->assertFalse($img->has_class('size-large'), "Img should NOT have class 'size-large'");
    }

    public function testAddsIsResizedClassWhenDimensionsPresent(): void
    {
        $element = $this->parseElement('<img src="https://example.com/photo.jpg" width="800" height="600">');
        $result = $this->converter->convert($element);

        html_string($result->innerHTML())->first_by_tag('figure')->assertNodeHasClass('is-resized');
    }

    public function testNoIsResizedClassWithoutDimensions(): void
    {
        $element = $this->parseElement('<img src="https://example.com/photo.jpg">');
        $result = $this->converter->convert($element);

        $this->assertFalse(html_string($result->innerHTML())->first_by_tag('figure')->has_class('is-resized'), "Figure should NOT have class 'is-resized'");
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------


    public function testExternalLinkIsNotAMediaDestination(): void
    {
        $block = $this->converter->convert(
            $this->parseElement('<a href="https://ailleurs.example.com/photo.jpg"><img src="/a.jpg"></a>'),
        );

        $this->assertInstanceOf(Block::class, $block);
        $this->assertArrayNotHasKey('linkDestination', $block->attributes);
    }

    public function testExtensionlessInternalLinkIsNotAMediaDestination(): void
    {
        $block = $this->converter->convert(
            $this->parseElement(\sprintf('<a href="%s/un-article"><img src="/a.jpg"></a>', \home_url())),
        );

        $this->assertInstanceOf(Block::class, $block);
        $this->assertArrayNotHasKey('linkDestination', $block->attributes);
    }

    public function testLinkWithoutAnHrefIsNotAMediaDestination(): void
    {
        $block = $this->converter->convert($this->parseElement('<a><img src="/a.jpg"></a>'));

        $this->assertInstanceOf(Block::class, $block);
        $this->assertArrayNotHasKey('linkDestination', $block->attributes);
    }

    public function testInternalLinkWithoutAPathIsNotAMediaDestination(): void
    {
        $block = $this->converter->convert(
            $this->parseElement(\sprintf('<a href="%s"><img src="/a.jpg"></a>', \home_url())),
        );

        $this->assertInstanceOf(Block::class, $block);
        $this->assertArrayNotHasKey('linkDestination', $block->attributes);
    }

    public function testTheSizeSlugIsASanitizedClass(): void
    {
        // The sanitizer keeps the quote encoded, so this is hygiene rather than
        // a breakout, but the slug reaches both the attributes and a class.
        $result = (new ImageConverter())->convert($this->parseElement("<img src='/a.jpg' class='size-x&#34;y'>"));

        $this->assertInstanceOf(Block::class, $result);
        $this->assertSame('x34y', $result->attributes['sizeSlug']);
        $this->assertStringContainsString('size-x34y', $result->innerHTML());
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
