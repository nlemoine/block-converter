<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Integration\TagConverters;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\BlockConverter;
use n5s\BlockConverter\TagConverters\VideoConverter;
use n5s\BlockConverter\Tests\WpTestCase;
use voku\helper\HtmlDomParser;
use voku\helper\SimpleHtmlDomInterface;

use function Mantle\Testing\html_string;

final class VideoConverterTest extends WpTestCase
{
    private VideoConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new VideoConverter();
    }

    public function testTagNames(): void
    {
        $this->assertSame(['video'], VideoConverter::tags());
    }

    public function testReturnsNullWithoutSrc(): void
    {
        $this->assertNull($this->converter->convert($this->parseElement('<video></video>')));
    }

    public function testReturnsNullWithEmptySrc(): void
    {
        $this->assertNull($this->converter->convert($this->parseElement('<video src=""></video>')));
    }

    public function testConvertsSimpleVideo(): void
    {
        $block = $this->converter->convert(
            $this->parseElement('<video src="https://example.org/clip.mp4" controls></video>'),
        );

        $this->assertInstanceOf(Block::class, $block);
        $this->assertSame('video', $block->blockName);
        $this->assertSame([], $block->attributes);

        $html = html_string($block->innerHTML());
        $html->assertQuerySelectorExists('figure.wp-block-video');
        $html->assertQuerySelectorExists('figure video');
    }

    public function testAlwaysAddsControls(): void
    {
        $block = $this->converter->convert($this->parseElement('<video src="https://example.org/clip.mp4"></video>'));

        $this->assertInstanceOf(Block::class, $block);
        $this->assertStringContainsString('controls', $block->innerHTML());
    }

    public function testCollectsBooleanAttributes(): void
    {
        $block = $this->converter->convert(
            $this->parseElement('<video src="https://example.org/clip.mp4" autoplay loop muted playsinline></video>'),
        );

        $this->assertInstanceOf(Block::class, $block);
        $this->assertTrue($block->attributes['autoplay']);
        $this->assertTrue($block->attributes['loop']);
        $this->assertTrue($block->attributes['muted']);
        $this->assertTrue($block->attributes['playsInline']);

        // The HTML attribute is lowercase even though the block attribute is not.
        $this->assertStringContainsString('playsinline', $block->innerHTML());
    }

    public function testKeepsPoster(): void
    {
        $block = $this->converter->convert(
            $this->parseElement('<video src="https://example.org/clip.mp4" poster="https://example.org/still.jpg"></video>'),
        );

        $this->assertInstanceOf(Block::class, $block);
        $this->assertSame('https://example.org/still.jpg', $block->attributes['poster']);
        $this->assertStringContainsString('poster="https://example.org/still.jpg"', $block->innerHTML());
    }

    public function testOmitsPreloadAttributeAtItsDefault(): void
    {
        $block = $this->converter->convert(
            $this->parseElement('<video src="https://example.org/clip.mp4" preload="metadata"></video>'),
        );

        $this->assertInstanceOf(Block::class, $block);
        $this->assertArrayNotHasKey('preload', $block->attributes);
        $this->assertStringContainsString('preload="metadata"', $block->innerHTML());
    }

    public function testKeepsNonDefaultPreload(): void
    {
        $block = $this->converter->convert(
            $this->parseElement('<video src="https://example.org/clip.mp4" preload="auto"></video>'),
        );

        $this->assertInstanceOf(Block::class, $block);
        $this->assertSame('auto', $block->attributes['preload']);
    }

    public function testFallsBackToMetadataForUnknownPreload(): void
    {
        $block = $this->converter->convert(
            $this->parseElement('<video src="https://example.org/clip.mp4" preload="bogus"></video>'),
        );

        $this->assertInstanceOf(Block::class, $block);
        $this->assertArrayNotHasKey('preload', $block->attributes);
        $this->assertStringContainsString('preload="metadata"', $block->innerHTML());
    }

    public function testFallsBackToFirstSourceChild(): void
    {
        $block = $this->converter->convert($this->parseElement(
            '<video controls><source src="" type="video/webm"><source src="https://example.org/clip.mp4" type="video/mp4"></video>',
        ));

        $this->assertInstanceOf(Block::class, $block);
        $this->assertStringContainsString('src="https://example.org/clip.mp4"', $block->innerHTML());
    }

    public function testSourceChildSurvivesTheDefaultPipeline(): void
    {
        $result = BlockConverter::createDefault()->convert(
            '<video controls><source src="https://example.org/clip.mp4" type="video/mp4"></video>',
        );

        $this->assertStringContainsString('wp:video', $result);
        $this->assertStringContainsString('https://example.org/clip.mp4', $result);
    }

    public function testResolvesAttachmentIdFromUrl(): void
    {
        $attachmentId = self::factory()->attachment->create(['file' => 'clip.mp4']);
        $url = \wp_get_attachment_url($attachmentId);

        $this->assertIsString($url);

        $block = $this->converter->convert($this->parseElement(\sprintf('<video src="%s"></video>', $url)));

        $this->assertInstanceOf(Block::class, $block);
        $this->assertSame($attachmentId, $block->attributes['id']);
    }

    public function testReturnsNullWhenTheSourceChildHasNoSrc(): void
    {
        $this->assertNull($this->converter->convert(
            $this->parseElement('<video controls><source type="video/mp4"></video>'),
        ));
    }

    private function parseElement(string $html): SimpleHtmlDomInterface
    {
        $dom = HtmlDomParser::str_get_html('<body>' . $html . '</body>');
        $elements = $dom->findMultiOrFalse('//body/*');

        if ($elements === false) {
            throw new \RuntimeException('No element found in the test markup.');
        }

        return $elements[0];
    }
}
