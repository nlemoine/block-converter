<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Unit\TagConverters;

use n5s\BlockConverter\TagConverters\IframeConverter;
use n5s\BlockConverter\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class IframeConverterTest extends TestCase
{
    public function testTagNames(): void
    {
        $this->assertSame(['iframe'], IframeConverter::tags());
    }

    #[DataProvider('urlNormalizationProvider')]
    public function testNormalizesEmbedUrls(string $input, string $expected): void
    {
        $this->assertSame($expected, IframeConverter::normalizeEmbedUrl($input));
    }

    /** @return \Generator<string, array{string, string}> */
    public static function urlNormalizationProvider(): \Generator
    {
        yield 'YouTube embed URL' => [
            'https://www.youtube.com/embed/xY1zA_bCdEf',
            'https://www.youtube.com/watch?v=xY1zA_bCdEf',
        ];

        yield 'YouTube embed URL with query params' => [
            'https://www.youtube.com/embed/xY1zA_bCdEf?autoplay=1&rel=0',
            'https://www.youtube.com/watch?v=xY1zA_bCdEf',
        ];

        yield 'Vimeo player URL' => [
            'https://player.vimeo.com/video/123456789',
            'https://vimeo.com/123456789',
        ];

        yield 'Dailymotion embed URL' => [
            'https://www.dailymotion.com/embed/video/x8abc12',
            'https://www.dailymotion.com/video/x8abc12',
        ];

        yield 'Spotify embed track URL' => [
            'https://open.spotify.com/embed/track/6rqhFgbbKwnb9MLmUQDhG6',
            'https://open.spotify.com/track/6rqhFgbbKwnb9MLmUQDhG6',
        ];

        yield 'Spotify embed playlist URL' => [
            'https://open.spotify.com/embed/playlist/37i9dQZF1DXcBWIGoYBM5M',
            'https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M',
        ];

        yield 'SoundCloud player with public URL' => [
            'https://w.soundcloud.com/player/?url=https%3A//soundcloud.com/artist/track-name&color=%23ff5500',
            'https://soundcloud.com/artist/track-name',
        ];

        yield 'SoundCloud player with API URL' => [
            'https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/tracks/123456789&color=%23ff5500',
            'https://api.soundcloud.com/tracks/123456789',
        ];

        yield 'TED embed URL' => [
            'https://embed.ted.com/talks/speaker_name_talk_title',
            'https://www.ted.com/talks/speaker_name_talk_title',
        ];

        yield 'non-embeddable URL unchanged' => [
            'https://example.com/some-page',
            'https://example.com/some-page',
        ];

        yield 'standard YouTube watch URL unchanged' => [
            'https://www.youtube.com/watch?v=xY1zA_bCdEf',
            'https://www.youtube.com/watch?v=xY1zA_bCdEf',
        ];
    }
}
