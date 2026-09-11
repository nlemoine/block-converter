<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Unit\Support;

use n5s\BlockConverter\Support\EmbedBlockFactory;
use n5s\BlockConverter\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class EmbedBlockFactoryTest extends TestCase
{
    #[DataProvider('aspectRatioProvider')]
    public function testResolveAspectRatioClass(int $width, int $height, ?string $expected): void
    {
        $this->assertSame($expected, EmbedBlockFactory::resolveAspectRatioClass($width, $height));
    }

    /** @return \Generator<string, array{int, int, ?string}> */
    public static function aspectRatioProvider(): \Generator
    {
        yield 'zero width' => [0, 100, null];
        yield 'zero height' => [100, 0, null];
        yield 'negative width' => [-1, 100, null];
        yield '16:9' => [1600, 900, 'wp-embed-aspect-16-9'];
        yield '4:3' => [800, 600, 'wp-embed-aspect-4-3'];
        yield '1:1' => [500, 500, 'wp-embed-aspect-1-1'];
        yield '21:9' => [2100, 900, 'wp-embed-aspect-21-9'];
        yield '18:9' => [1800, 900, 'wp-embed-aspect-18-9'];
        yield '9:16' => [900, 1600, 'wp-embed-aspect-9-16'];
        yield '1:2' => [500, 1000, 'wp-embed-aspect-1-2'];
        yield 'no match — too far from any ratio' => [1000, 300, null];
    }

    /**
     * The response is remote data, and with discovery on it is data the
     * content chose. The type reached the figure's class attribute raw.
     */
    public function testATypeOutsideTheOEmbedSpecIsNotInterpolated(): void
    {
        $data = (object) ['type' => 'video"><script>alert(1)</script><span class="', 'provider_name' => 'YouTube'];

        $block = (new EmbedBlockFactory())->fromData('https://www.youtube.com/watch?v=x', $data);

        $this->assertSame('', $block->attributes['type']);
        $this->assertStringNotContainsString('<script', $block->innerHTML());
        $this->assertStringContainsString('<figure class="wp-block-embed is-provider-youtube wp-block-embed-youtube">', $block->innerHTML());
    }

    public function testTheFourOEmbedTypesAreKept(): void
    {
        foreach (['photo', 'video', 'link', 'rich'] as $type) {
            $block = (new EmbedBlockFactory())->fromData('https://x.test/a', (object) ['type' => $type]);

            $this->assertSame($type, $block->attributes['type']);
            $this->assertStringContainsString('is-type-' . $type, $block->innerHTML());
        }
    }
}
