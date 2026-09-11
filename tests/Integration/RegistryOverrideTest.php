<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Integration;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\BlockConverter;
use n5s\BlockConverter\RegistryAwareInterface;
use n5s\BlockConverter\TagConverters\FigureConverter;
use n5s\BlockConverter\TagConverters\LinkConverter;
use n5s\BlockConverter\Tests\Support\MarkingImageConverter;
use n5s\BlockConverter\Tests\WpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use voku\helper\HtmlDomParser;
use voku\helper\SimpleHtmlDomInterface;

/**
 * The README promises that registering a converter for a handled tag replaces
 * the existing one. That was only true for a bare <img>: FigureConverter and
 * LinkConverter held an ImageConverter of their own and never consulted the
 * registry, so two of the three paths ignored the override without a word.
 */
final class RegistryOverrideTest extends WpTestCase
{
    #[DataProvider('imageMarkup')]
    public function testAnOverriddenImageConverterReachesEveryPath(string $input): void
    {
        $converter = BlockConverter::createDefault();
        $converter->getRegistry()->registerTagConverter(new MarkingImageConverter());

        $this->assertStringContainsString('"marked":true', $converter->convert($input));
    }

    #[DataProvider('imageMarkup')]
    public function testRemovingTheImageConverterIsHonouredEverywhere(string $input): void
    {
        // Removing img means "do not convert images". Falling back to a private
        // instance would quietly override that decision.
        $converter = BlockConverter::createDefault();
        $converter->getRegistry()->removeTagConverter('img');

        $result = $converter->convert($input);

        $this->assertStringContainsString('wp:html', $result);
        $this->assertStringNotContainsString('wp:image', $result);
    }

    /** @return \Iterator<string, array{0: string}> */
    public static function imageMarkup(): \Iterator
    {
        yield 'bare image' => ['<p><img src="/a.jpg"></p>'];
        yield 'image in a figure' => ['<figure><img src="/a.jpg"><figcaption>L</figcaption></figure>'];
        yield 'image in a link' => ['<a href="/full.jpg"><img src="/a.jpg"></a>'];
    }

    public function testBothDelegatingConvertersAskForTheRegistry(): void
    {
        $this->assertInstanceOf(RegistryAwareInterface::class, new FigureConverter());
        $this->assertInstanceOf(RegistryAwareInterface::class, new LinkConverter());
    }

    public function testADelegatingConverterStillWorksOnItsOwn(): void
    {
        // Never registered, so no registry was ever handed over: the converter
        // falls back to the collaborator it was constructed with.
        $block = (new FigureConverter())->convert(
            $this->parseElement('<figure><img src="/a.jpg"></figure>'),
        );

        $this->assertInstanceOf(Block::class, $block);
        $this->assertSame('image', $block->blockName);
    }

    public function testAConverterRegisteredForImgDoesNotRecurseIntoItself(): void
    {
        // FigureConverter resolving img to itself would loop forever.
        $registry = BlockConverter::createDefault()->getRegistry();
        $figureConverter = new FigureConverter();

        $registry->registerTagConverter($figureConverter);
        $registry->registerShortcodeTag('noop');

        // Registering it for img is nonsense, but it must not hang.
        $block = $figureConverter->convert($this->parseElement('<figure><img src="/a.jpg"></figure>'));

        $this->assertInstanceOf(Block::class, $block);
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
