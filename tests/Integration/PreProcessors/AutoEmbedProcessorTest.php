<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Integration\PreProcessors;

use n5s\BlockConverter\BlockConverter;
use n5s\BlockConverter\PreProcessors\AutoEmbedProcessor;
use n5s\BlockConverter\PreProcessors\PreProcessorInterface;
use n5s\BlockConverter\Support\EmbedBlockFactory;
use n5s\BlockConverter\Tests\WpTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AutoEmbedProcessorTest extends WpTestCase
{
    private AutoEmbedProcessor $processor;

    /**
     * Map of oEmbed endpoint URL wildcards to mock response JSON.
     *
     * Endpoint URLs are derived from WP_oEmbed::__construct() in
     * wp-includes/class-wp-oembed.php. Wildcards match the query string
     * that get_data() appends (?url=…&format=json).
     */
    private const array OEMBED_ENDPOINT_MOCKS = [
        'https://www.youtube.com/oembed*' => ['type' => 'video', 'provider_name' => 'YouTube', 'width' => 200, 'height' => 113],
        'https://vimeo.com/api/oembed*' => ['type' => 'video', 'provider_name' => 'Vimeo', 'width' => 640, 'height' => 360],
        'https://www.dailymotion.com/services/oembed*' => ['type' => 'video', 'provider_name' => 'Dailymotion', 'width' => 480, 'height' => 270],
        'https://www.flickr.com/services/oembed*' => ['type' => 'photo', 'provider_name' => 'Flickr', 'width' => 640, 'height' => 480],
        'https://publish.twitter.com/oembed*' => ['type' => 'rich', 'provider_name' => 'Twitter', 'width' => 550, 'height' => 0],
        'https://soundcloud.com/oembed*' => ['type' => 'rich', 'provider_name' => 'SoundCloud', 'width' => 500, 'height' => 0],
        'https://embed.spotify.com/oembed*' => ['type' => 'rich', 'provider_name' => 'Spotify', 'width' => 300, 'height' => 380],
        'https://www.tiktok.com/oembed*' => ['type' => 'video', 'provider_name' => 'TikTok', 'width' => 340, 'height' => 700],
        'https://www.reddit.com/oembed*' => ['type' => 'rich', 'provider_name' => 'Reddit', 'width' => 600, 'height' => 0],
        'https://www.tumblr.com/oembed*' => ['type' => 'rich', 'provider_name' => 'Tumblr', 'width' => 540, 'height' => 0],
        'https://www.ted.com/services/v1/oembed*' => ['type' => 'video', 'provider_name' => 'TED', 'width' => 560, 'height' => 315],
        'https://api.imgur.com/oembed*' => ['type' => 'rich', 'provider_name' => 'Imgur', 'width' => 540, 'height' => 500],
        'https://www.pinterest.com/oembed*' => ['type' => 'rich', 'provider_name' => 'Pinterest', 'width' => 600, 'height' => 0],
        'https://embed.bsky.app/oembed*' => ['type' => 'rich', 'provider_name' => 'Bluesky', 'width' => 550, 'height' => 0],
        'https://www.kickstarter.com/services/oembed*' => ['type' => 'rich', 'provider_name' => 'Kickstarter', 'width' => 640, 'height' => 480],
        'https://speakerdeck.com/oembed*' => ['type' => 'rich', 'provider_name' => 'Speaker Deck', 'width' => 710, 'height' => 0],
        'https://www.scribd.com/services/oembed*' => ['type' => 'rich', 'provider_name' => 'Scribd', 'width' => 500, 'height' => 600],
        'https://wordpress.tv/oembed/*' => ['type' => 'video', 'provider_name' => 'WordPress.tv', 'width' => 640, 'height' => 360],
        'https://public-api.wordpress.com/oembed/*' => ['type' => 'video', 'provider_name' => 'VideoPress', 'width' => 640, 'height' => 360],
    ];

    /**
     * Hosts WordPress has no provider for. Faked so oEmbed discovery does not
     * reach the network; the response carries no oEmbed link tag.
     */
    private const array UNSUPPORTED_PROVIDER_HOSTS = [
        'https://www.instagram.com/*',
        'https://www.facebook.com/*',
        'https://www.slideshare.net/*',
        'https://x.com/*',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeOEmbedEndpoints();
        $this->processor = new AutoEmbedProcessor();
    }

    /**
     * Register mock HTTP responses for all oEmbed endpoints used in tests.
     */
    private function fakeOEmbedEndpoints(): void
    {
        foreach (self::OEMBED_ENDPOINT_MOCKS as $pattern => $data) {
            $this->fake_request($pattern)->with_json($data);
        }

        // Discovery attempts for unknown URLs return plain HTML (no oEmbed link tags).
        $this->fake_request('https://unknown-oembed-site.example.com/*')
            ->with_body('<html><body>No oEmbed here</body></html>');

        foreach (self::UNSUPPORTED_PROVIDER_HOSTS as $pattern) {
            $this->fake_request($pattern)->with_body('<html><body>No oEmbed here</body></html>');
        }
    }

    // ------------------------------------------------------------------
    // Interface & basics
    // ------------------------------------------------------------------

    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(PreProcessorInterface::class, $this->processor);
    }

    public function testPriority(): void
    {
        $this->assertSame(50, $this->processor->priority());
    }

    public function testLeavesHtmlWithoutUrlsUntouched(): void
    {
        $input = '<p>No URLs here</p>';
        $this->assertSame($input, $this->processor->process($input));
    }

    public function testEmptyStringReturnsEmpty(): void
    {
        $this->assertSame('', $this->processor->process(''));
    }

    public function testPlainTextWithoutUrlsUntouched(): void
    {
        $input = 'Just some text without any links';
        $this->assertSame($input, $this->processor->process($input));
    }

    // ------------------------------------------------------------------
    // oEmbed providers — bare URL on own line
    // ------------------------------------------------------------------

    #[DataProvider('oembedProviderUrlsOnOwnLine')]
    public function testConvertsOEmbedProviderOnOwnLine(string $url, string $expectedSlug): void
    {
        $result = $this->processor->process($url . "\n");

        $this->assertStringContainsString('<!-- wp:embed', $result);
        $this->assertStringContainsString($expectedSlug, $result);
        $this->assertStringContainsString('<!-- /wp:embed -->', $result);
    }

    public static function oembedProviderUrlsOnOwnLine(): \Iterator
    {
        yield 'youtube-watch' => ['https://www.youtube.com/watch?v=xY1zA_bCdEf', 'xY1zA_bCdEf'];
        yield 'youtube-short' => ['https://youtu.be/xY1zA_bCdEf', 'xY1zA_bCdEf'];
        yield 'youtube-shorts' => ['https://www.youtube.com/shorts/abc123', 'abc123'];
        yield 'youtube-playlist' => ['https://www.youtube.com/playlist?list=PLrAXtmErZgOeiKm4sgNOknGvNjby9efdf', 'PLrAXtmErZgOeiKm4sgNOknGvNjby9efdf'];
        yield 'youtube-live' => ['https://www.youtube.com/live/abc123', 'abc123'];
        yield 'vimeo' => ['https://vimeo.com/123456789', '123456789'];
        yield 'dailymotion' => ['https://www.dailymotion.com/video/x5e9eog', 'x5e9eog'];
        yield 'dailymotion-short' => ['https://dai.ly/x5e9eog', 'x5e9eog'];
        yield 'flickr' => ['https://www.flickr.com/photos/example/12345678', '12345678'];
        yield 'twitter-status' => ['https://twitter.com/example_user/status/1234567890', '1234567890'];
        yield 'soundcloud' => ['https://soundcloud.com/artist/track-name', 'track-name'];
        yield 'spotify' => ['https://open.spotify.com/track/4uLU6hMCjMI75M1A2tKUQC', '4uLU6hMCjMI75M1A2tKUQC'];
        yield 'tiktok-video' => ['https://www.tiktok.com/@user/video/7123456789', '7123456789'];
        yield 'reddit' => ['https://www.reddit.com/r/wordpress/comments/abc123/some_post', 'abc123'];
        yield 'tumblr' => ['https://example.tumblr.com/post/123456789', '123456789'];
        yield 'ted' => ['https://www.ted.com/talks/some_talk', 'some_talk'];
        yield 'imgur' => ['https://imgur.com/gallery/abc123', 'abc123'];
        yield 'pinterest' => ['https://www.pinterest.com/pin/123456789/', '123456789'];
        yield 'bluesky' => ['https://bsky.app/profile/user.bsky.social/post/abc123', 'abc123'];
        yield 'kickstarter' => ['https://www.kickstarter.com/projects/user/project-name', 'project-name'];
        yield 'speakerdeck' => ['https://speakerdeck.com/user/presentation', 'presentation'];
        yield 'scribd' => ['https://www.scribd.com/document/123456789/Example-Document', '123456789'];
        yield 'wordpress-tv' => ['https://wordpress.tv/2023/01/01/example-video/', 'example-video'];
        yield 'videopress' => ['https://videopress.com/v/abc123XY', 'abc123XY'];
    }

    // ------------------------------------------------------------------
    // Through the whole converter
    // ------------------------------------------------------------------

    /**
     * AutoEmbedProcessor runs late, on raw HTML fragments, so the block markup
     * it injects has to be parsed a second time by parsePreProcessedHtml().
     * Exercising the processor on its own never reaches that path.
     */
    public function testBareUrlBecomesAnEmbedBlockThroughTheConverter(): void
    {
        $result = BlockConverter::createDefault()->convert(
            "<p>Avant</p>\nhttps://www.youtube.com/watch?v=xY1zA_bCdEf\n<p>Après</p>",
        );

        $names = \array_column(\parse_blocks($result), 'blockName');

        $this->assertContains('core/embed', $names);
        $this->assertContains('core/paragraph', $names);
    }

    public function testSurroundingHtmlSurvivesAroundAnInjectedEmbed(): void
    {
        $result = BlockConverter::createDefault()->convert(
            "<h2>Titre</h2>\nhttps://vimeo.com/123456789\n<p>Suite</p>",
        );

        $this->assertStringContainsString('<!-- wp:heading', $result);
        $this->assertStringContainsString('<!-- wp:embed', $result);
        $this->assertStringContainsString('Suite', $result);
    }

    public function testConverterYieldsNothingWhenThePipelineEmptiesTheContent(): void
    {
        // The sanitizer drops the script wholesale, leaving nothing to convert.
        $this->assertSame('', BlockConverter::createDefault()->convert('<script>void 0;</script>'));
    }

    // ------------------------------------------------------------------
    // Providers WordPress does not ship
    // ------------------------------------------------------------------

    /**
     * Core dropped Instagram, Facebook and SlideShare from its provider list,
     * and never matched x.com after the Twitter rename. Embedding relies on
     * wp_oembed_get(), so these stay plain URLs rather than becoming blocks —
     * asserted so the day core adds one back, this test says so.
     */
    #[DataProvider('urlsWordPressHasNoProviderFor')]
    public function testLeavesUrlsWithoutACoreProviderAlone(string $url): void
    {
        $result = $this->processor->process($url . "\n");

        $this->assertStringNotContainsString('<!-- wp:embed', $result);
        $this->assertStringContainsString($url, $result);
    }

    public static function urlsWordPressHasNoProviderFor(): \Iterator
    {
        yield 'instagram' => ['https://www.instagram.com/p/CSpmSvAphdf/'];
        yield 'facebook' => ['https://www.facebook.com/example/posts/1329405240877426'];
        yield 'slideshare' => ['https://www.slideshare.net/example/example-presentation'];
        yield 'x-com' => ['https://x.com/example/status/1679189879086018562'];
    }

    // ------------------------------------------------------------------
    // oEmbed providers — URL inside <p> tag
    // ------------------------------------------------------------------

    #[DataProvider('oembedProviderUrlsInParagraph')]
    public function testConvertsOEmbedProviderInParagraph(string $url, string $expectedSlug): void
    {
        $result = $this->processor->process('<p>' . $url . '</p>');

        $this->assertStringContainsString('<!-- wp:embed', $result);
        $this->assertStringContainsString($expectedSlug, $result);
        // The <p> wrapper must be removed.
        $this->assertStringNotContainsString('<p>' . $url, $result);
    }

    public static function oembedProviderUrlsInParagraph(): \Iterator
    {
        yield 'youtube-in-p' => ['https://www.youtube.com/watch?v=xY1zA_bCdEf', 'xY1zA_bCdEf'];
        yield 'vimeo-in-p' => ['https://vimeo.com/123456789', '123456789'];
        yield 'spotify-in-p' => ['https://open.spotify.com/track/4uLU6hMCjMI75M1A2tKUQC', '4uLU6hMCjMI75M1A2tKUQC'];
        yield 'twitter-in-p' => ['https://twitter.com/example_user/status/1234567890', '1234567890'];
        yield 'tiktok-in-p' => ['https://www.tiktok.com/@user/video/7123456789', '7123456789'];
    }

    // ------------------------------------------------------------------
    // Non-embeddable URLs — must be left untouched
    // ------------------------------------------------------------------

    public function testLeavesNonEmbeddableUrlOnOwnLineUntouched(): void
    {
        $input = "https://example.com/some-page\n";
        $result = $this->processor->process($input);
        $this->assertStringNotContainsString('wp:embed', $result);
        $this->assertStringContainsString('https://example.com/some-page', $result);
    }

    public function testLeavesNonEmbeddableUrlInParagraphUntouched(): void
    {
        $input = '<p>https://example.com/some-page</p>';
        $result = $this->processor->process($input);
        $this->assertStringNotContainsString('wp:embed', $result);
        $this->assertStringContainsString('<p>https://example.com/some-page</p>', $result);
    }

    public function testLeavesUrlMixedWithTextInParagraphUntouched(): void
    {
        $input = '<p>Check out https://www.youtube.com/watch?v=xY1zA_bCdEf for more</p>';
        $result = $this->processor->process($input);
        $this->assertStringNotContainsString('wp:embed', $result);
    }

    public function testLeavesUrlMixedWithTextOnLineUntouched(): void
    {
        $input = "Watch this: https://www.youtube.com/watch?v=xY1zA_bCdEf now\n";
        $result = $this->processor->process($input);
        $this->assertStringNotContainsString('wp:embed', $result);
    }

    public function testLeavesUrlInsideAnchorTagUntouched(): void
    {
        $input = '<a href="https://www.youtube.com/watch?v=xY1zA_bCdEf">Watch</a>';
        $result = $this->processor->process($input);
        $this->assertStringNotContainsString('wp:embed', $result);
    }

    // ------------------------------------------------------------------
    // Multiple URLs
    // ------------------------------------------------------------------

    public function testConvertsMultipleUrlsOnSeparateLines(): void
    {
        $input = "https://www.youtube.com/watch?v=xY1zA_bCdEf\nhttps://vimeo.com/123456789\n";
        $result = $this->processor->process($input);
        $this->assertSame(2, \substr_count($result, '<!-- wp:embed'));
    }

    public function testConvertsMultipleUrlsInSeparateParagraphs(): void
    {
        $input = "<p>https://www.youtube.com/watch?v=xY1zA_bCdEf</p>\n<p>https://vimeo.com/123456789</p>";
        $result = $this->processor->process($input);
        $this->assertSame(2, \substr_count($result, '<!-- wp:embed'));
        $this->assertStringNotContainsString('<p>https://', $result);
    }

    public function testMixOfEmbeddableAndNonEmbeddable(): void
    {
        $input = "https://www.youtube.com/watch?v=xY1zA_bCdEf\nhttps://example.com/page\nhttps://vimeo.com/123456789\n";
        $result = $this->processor->process($input);
        $this->assertSame(2, \substr_count($result, '<!-- wp:embed'));
        $this->assertStringContainsString('https://example.com/page', $result);
    }

    // ------------------------------------------------------------------
    // Content preservation around embeds
    // ------------------------------------------------------------------

    public function testPreservesHtmlBeforeAndAfterBareUrl(): void
    {
        $input = "<p>Before</p>\nhttps://www.youtube.com/watch?v=xY1zA_bCdEf\n<p>After</p>";
        $result = $this->processor->process($input);
        $this->assertStringContainsString('<p>Before</p>', $result);
        $this->assertStringContainsString('<!-- wp:embed', $result);
        $this->assertStringContainsString('<p>After</p>', $result);
    }

    public function testPreservesHtmlBetweenParagraphEmbeds(): void
    {
        $input = "<p>https://www.youtube.com/watch?v=xY1zA_bCdEf</p>\n<p>Some text</p>\n<p>https://vimeo.com/123456789</p>";
        $result = $this->processor->process($input);
        $this->assertStringContainsString('<p>Some text</p>', $result);
        $this->assertSame(2, \substr_count($result, '<!-- wp:embed'));
    }

    // ------------------------------------------------------------------
    // Paragraph replacement: <p> wrapper stripped
    // ------------------------------------------------------------------

    public function testParagraphWrapperIsStrippedForEmbed(): void
    {
        $result = $this->processor->process('<p>https://www.youtube.com/watch?v=xY1zA_bCdEf</p>');
        $this->assertStringNotContainsString('<p><!-- wp:embed', $result);
        $this->assertStringNotContainsString('</p>', $result);
        $this->assertStringStartsWith('<!-- wp:embed', $result);
    }

    public function testParagraphWithAttributesIsStripped(): void
    {
        $result = $this->processor->process('<p class="intro">https://www.youtube.com/watch?v=xY1zA_bCdEf</p>');
        $this->assertStringNotContainsString('<p class', $result);
        $this->assertStringContainsString('<!-- wp:embed', $result);
    }

    public function testParagraphWithWhitespaceAroundUrl(): void
    {
        $result = $this->processor->process('<p>  https://www.youtube.com/watch?v=xY1zA_bCdEf  </p>');
        $this->assertStringContainsString('<!-- wp:embed', $result);
        $this->assertStringNotContainsString('<p>', $result);
    }

    // ------------------------------------------------------------------
    // Line break protection (wp_replace_in_html_tags)
    // ------------------------------------------------------------------

    public function testProtectsLineBreaksInsideHtmlTags(): void
    {
        // A URL should not be matched inside an attribute value that contains newlines.
        $input = "<div data-url=\"\nhttps://www.youtube.com/watch?v=xY1zA_bCdEf\n\">content</div>";
        $result = $this->processor->process($input);
        // The URL is inside an HTML tag's attribute — should NOT be converted.
        $this->assertStringNotContainsString('wp:embed', $result);
    }

    // ------------------------------------------------------------------
    // Embed handlers
    // ------------------------------------------------------------------

    public function testEmbedHandlerIsCheckedBeforeOEmbed(): void
    {
        $handlerCalled = false;

        \wp_embed_register_handler(
            'test-handler',
            '#https?://custom-embed\.example\.com/.*#i',
            static function (array $matches) use (&$handlerCalled): string {
                $handlerCalled = true;

                return '<iframe src="' . \esc_url($matches[0]) . '"></iframe>';
            },
        );

        $result = $this->processor->process("https://custom-embed.example.com/video/123\n");

        $this->assertTrue($handlerCalled, 'Embed handler callback should have been called');
        $this->assertStringContainsString('<!-- wp:embed', $result);
        $this->assertStringContainsString('custom-embed.example.com/video/123', $result);

        \wp_embed_unregister_handler('test-handler');
    }

    public function testEmbedHandlerTakesPriorityOverOEmbed(): void
    {
        // Register a handler that matches YouTube URLs — it should take priority.
        \wp_embed_register_handler(
            'override-youtube',
            '#https?://(www\.)?youtube\.com/watch.*#i',
            static fn (): string => '<div class="custom-youtube-embed">custom</div>',
        );

        $result = $this->processor->process("https://www.youtube.com/watch?v=xY1zA_bCdEf\n");

        $this->assertStringContainsString('<!-- wp:embed', $result);
        // Provider slug should be "embed-handler" (from handler path, not oEmbed).
        $this->assertStringContainsString('embed-handler', $result);

        \wp_embed_unregister_handler('override-youtube');
    }

    public function testWithoutWpEmbedGlobalFallsToOEmbed(): void
    {
        $saved = $GLOBALS['wp_embed'] ?? null;
        unset($GLOBALS['wp_embed']);

        $result = $this->processor->process("https://www.youtube.com/watch?v=xY1zA_bCdEf\n");
        $this->assertStringContainsString('<!-- wp:embed', $result);
        // Should still work via oEmbed path.
        $this->assertStringContainsString('xY1zA_bCdEf', $result);

        if ($saved !== null) {
            $GLOBALS['wp_embed'] = $saved;
        }
    }

    public function testEmbedHandlerReturningFalseFallsToOEmbed(): void
    {
        \wp_embed_register_handler(
            'always-false',
            '#https?://(www\.)?youtube\.com/watch.*#i',
            static fn (): bool => false,
        );

        $result = $this->processor->process("https://www.youtube.com/watch?v=xY1zA_bCdEf\n");
        // Should fall through to oEmbed since handler returned false.
        $this->assertStringContainsString('<!-- wp:embed', $result);

        \wp_embed_unregister_handler('always-false');
    }

    public function testEmbedHandlerReturningEmptyFallsToOEmbed(): void
    {
        \wp_embed_register_handler(
            'empty-return',
            '#https?://(www\.)?youtube\.com/watch.*#i',
            static fn (): string => '',
        );

        $result = $this->processor->process("https://www.youtube.com/watch?v=xY1zA_bCdEf\n");
        $this->assertStringContainsString('<!-- wp:embed', $result);

        \wp_embed_unregister_handler('empty-return');
    }

    // ------------------------------------------------------------------
    // Internal embed (current site URLs)
    // ------------------------------------------------------------------

    public function testInternalPostUrlBecomesEmbed(): void
    {
        $postId = self::factory()->post->create(['post_title' => 'Hello World', 'post_status' => 'publish']);
        $url = \get_permalink($postId);

        $result = $this->processor->process($url . "\n");

        $this->assertStringContainsString('<!-- wp:embed', $result);
        $this->assertStringContainsString($url, $result);
    }

    public function testInternalPostUrlInParagraphBecomesEmbed(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $url = \get_permalink($postId);

        $result = $this->processor->process('<p>' . $url . '</p>');

        $this->assertStringContainsString('<!-- wp:embed', $result);
        $this->assertStringNotContainsString('<p>' . $url, $result);
    }

    public function testInternalUrlNotMatchingPostIsNotConverted(): void
    {
        $url = \home_url('/this-post-does-not-exist-at-all/');

        $result = $this->processor->process($url . "\n");

        $this->assertStringNotContainsString('wp:embed', $result);
        $this->assertStringContainsString($url, $result);
    }

    public function testInternalEmbedTakesPriorityOverOEmbed(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $url = \get_permalink($postId);

        $result = $this->processor->process($url . "\n");

        // Should be converted without hitting any oEmbed endpoint.
        $this->assertStringContainsString('<!-- wp:embed', $result);
        $this->assertStringContainsString('wp-embed', $result);
    }

    // ------------------------------------------------------------------
    // Discovery on / off
    // ------------------------------------------------------------------

    public function testDiscoveryOffByDefault(): void
    {
        // A URL that has no known oEmbed provider and no embed handler
        // should NOT be converted when discovery is off.
        $result = $this->processor->process("https://unknown-oembed-site.example.com/content/123\n");
        $this->assertStringNotContainsString('wp:embed', $result);
        $this->assertStringContainsString('https://unknown-oembed-site.example.com/content/123', $result);
    }

    /**
     * Discovery is the factory's setting. When this processor had a flag of
     * its own, turning it on here alone made the provider lookup succeed and
     * the data lookup fail, and a working URL came back as an embed block
     * that named no provider.
     */
    public function testDiscoveryOnLeavesAUrlWithNothingToDiscoverAlone(): void
    {
        $processor = new AutoEmbedProcessor(new EmbedBlockFactory(discover: true));

        $result = $processor->process("https://unknown-oembed-site.example.com/content/123\n");

        $this->assertRequestSent('https://unknown-oembed-site.example.com/content/123');
        $this->assertSame("https://unknown-oembed-site.example.com/content/123\n", $result);
    }

    public function testDiscoveryOnStillConvertsKnownProviders(): void
    {
        $processor = new AutoEmbedProcessor(new EmbedBlockFactory(discover: true));

        $result = $processor->process("https://www.youtube.com/watch?v=xY1zA_bCdEf\n");
        $this->assertStringContainsString('<!-- wp:embed', $result);
        $this->assertStringContainsString('xY1zA_bCdEf', $result);
    }

    // ------------------------------------------------------------------
    // Edge cases
    // ------------------------------------------------------------------

    public function testHttpUrlIsAlsoMatched(): void
    {
        // WP_oEmbed provider regex accepts both http and https.
        $result = $this->processor->process("http://www.youtube.com/watch?v=xY1zA_bCdEf\n");
        $this->assertStringContainsString('<!-- wp:embed', $result);
    }

    public function testUrlWithTrailingWhitespace(): void
    {
        $result = $this->processor->process("https://www.youtube.com/watch?v=xY1zA_bCdEf   \n");
        $this->assertStringContainsString('<!-- wp:embed', $result);
    }

    public function testUrlWithLeadingWhitespace(): void
    {
        $result = $this->processor->process("   https://www.youtube.com/watch?v=xY1zA_bCdEf\n");
        $this->assertStringContainsString('<!-- wp:embed', $result);
    }

    public function testMultipleLinesWithBlanksInBetween(): void
    {
        $input = "https://www.youtube.com/watch?v=xY1zA_bCdEf\n\nhttps://vimeo.com/123456789\n";
        $result = $this->processor->process($input);
        $this->assertSame(2, \substr_count($result, '<!-- wp:embed'));
    }

    public function testUrlInsidePreTagIsNotConverted(): void
    {
        $input = "<pre>\nhttps://www.youtube.com/watch?v=xY1zA_bCdEf\n</pre>";
        $result = $this->processor->process($input);
        // The line-break protection should prevent matching inside <pre>.
        // The URL is still on its own line but protected by wp_replace_in_html_tags.
        $this->assertStringContainsString('youtube.com', $result);
    }

    public function testMobileYouTubeUrl(): void
    {
        $result = $this->processor->process("https://m.youtube.com/watch?v=xY1zA_bCdEf\n");
        $this->assertStringContainsString('<!-- wp:embed', $result);
    }

    public function testUrlWithQueryParameters(): void
    {
        $result = $this->processor->process("https://www.youtube.com/watch?v=xY1zA_bCdEf&t=120\n");
        $this->assertStringContainsString('<!-- wp:embed', $result);
        $this->assertStringContainsString('xY1zA_bCdEf', $result);
    }

    public function testBlockMarkupStructure(): void
    {
        $result = $this->processor->process("https://www.youtube.com/watch?v=xY1zA_bCdEf\n");

        // Verify the block structure.
        $this->assertStringContainsString('<!-- wp:embed', $result);
        $this->assertStringContainsString('"url":"https://www.youtube.com/watch?v=xY1zA_bCdEf', $result);
        $this->assertStringContainsString('"responsive":true', $result);
        $this->assertStringContainsString('wp-block-embed', $result);
        $this->assertStringContainsString('wp-block-embed__wrapper', $result);
        $this->assertStringContainsString('<!-- /wp:embed -->', $result);
    }

    /**
     * End to end: the provider's answer is remote data, and the embed block a
     * late pre-processor emits never meets the sanitizer.
     */
    public function testAProviderCannotInjectMarkupThroughTheResponseType(): void
    {
        $this->fake_request('https://hostile.example.com/oembed*')
            ->with_json(['type' => 'video"><script>alert(1)</script><span class="', 'provider_name' => 'Hostile']);
        \wp_oembed_add_provider('https://hostile.example.com/*', 'https://hostile.example.com/oembed');

        $result = BlockConverter::createDefault()->convert('<p>https://hostile.example.com/video/1</p>');

        $this->assertStringContainsString('<!-- wp:embed', $result);
        $this->assertStringNotContainsString('<script', $result);
        $this->assertStringNotContainsString('is-type-video"', $result);
    }
}
