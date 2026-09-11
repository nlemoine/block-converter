<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Unit\TagConverters;

use n5s\BlockConverter\TagConverters\ObjectConverter;
use n5s\BlockConverter\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ObjectConverterTest extends TestCase
{
    public function testTagNames(): void
    {
        $this->assertSame(['object', 'embed'], ObjectConverter::tags());
    }

    #[DataProvider('youTubeNormalizationProvider')]
    public function testNormalizesYouTubeFlashUrls(string $input, string $expected): void
    {
        $this->assertSame($expected, ObjectConverter::normalizeYouTubeUrl($input));
    }

    /** @return \Generator<string, array{string, string}> */
    public static function youTubeNormalizationProvider(): \Generator
    {
        yield 'flash /v/ URL' => [
            'http://www.youtube.com/v/xY1zA_bCdEf',
            'https://www.youtube.com/watch?v=xY1zA_bCdEf',
        ];

        yield 'flash /v/ URL with query params' => [
            'http://www.youtube.com/v/xY1zA_bCdEf&hl=en&fs=1',
            'https://www.youtube.com/watch?v=xY1zA_bCdEf',
        ];

        yield 'https flash URL' => [
            'https://www.youtube.com/v/xY1zA_bCdEf?autoplay=1',
            'https://www.youtube.com/watch?v=xY1zA_bCdEf',
        ];

        yield 'non-youtube URL unchanged' => [
            'https://vimeo.com/123456',
            'https://vimeo.com/123456',
        ];

        yield 'standard youtube watch URL unchanged' => [
            'https://www.youtube.com/watch?v=xY1zA_bCdEf',
            'https://www.youtube.com/watch?v=xY1zA_bCdEf',
        ];
    }
}
