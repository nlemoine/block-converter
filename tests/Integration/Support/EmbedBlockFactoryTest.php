<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Integration\Support;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\BlockConverter;
use n5s\BlockConverter\Support\AttachmentResolver;
use n5s\BlockConverter\Support\EmbedBlockFactory;
use n5s\BlockConverter\Tests\WpTestCase;

/**
 * Every oEmbed miss is an HTTP request, and a migration walks a whole corpus.
 */
final class EmbedBlockFactoryTest extends WpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Nothing here may reach the network; anything unstubbed fails the test.
        $this->prevent_stray_requests();

        $this->fake_request('https://www.youtube.com/oembed*')
            ->with_json(['type' => 'video', 'provider_name' => 'YouTube', 'width' => 200, 'height' => 113]);

        // The URL discovery would fetch, serving a page with no oEmbed link tag.
        $this->fake_request('https://unknown.example.com/*')
            ->with_body('<html><body>Rien à découvrir</body></html>');
    }

    public function testTheSameUrlIsOnlyFetchedOnce(): void
    {
        $factory = new EmbedBlockFactory();

        $factory->fromUrl('https://www.youtube.com/watch?v=abc123');
        $factory->fromUrl('https://www.youtube.com/watch?v=abc123');
        $factory->fromUrl('https://www.youtube.com/watch?v=abc123');

        $this->assertRequestCount(1);
        $this->assertSame(1, $factory->cachedUrlCount());
    }

    public function testDistinctUrlsAreFetchedSeparately(): void
    {
        $factory = new EmbedBlockFactory();

        $factory->fromUrl('https://www.youtube.com/watch?v=a');
        $factory->fromUrl('https://www.youtube.com/watch?v=b');

        $this->assertRequestCount(2);
        $this->assertSame(2, $factory->cachedUrlCount());
    }

    public function testForgetEmptiesTheCache(): void
    {
        $factory = new EmbedBlockFactory();
        $factory->fromUrl('https://www.youtube.com/watch?v=a');

        $this->assertSame(1, $factory->cachedUrlCount());

        $factory->forget();

        $this->assertSame(0, $factory->cachedUrlCount());
    }

    /**
     * get_provider() turns discovery on whenever the argument is absent, which
     * is what get_data($url, []) used to do. Discovery fetches the URL itself,
     * so every unrecognised address in the corpus became an outbound request to
     * somewhere the content — not the caller — chose.
     */
    public function testAnUnknownUrlIsNotFetchedByDefault(): void
    {
        (new EmbedBlockFactory())->fromUrl('https://unknown.example.com/page');

        $this->assertNoRequestSent();
    }

    public function testDiscoveryCanStillBeAskedFor(): void
    {
        (new EmbedBlockFactory(discover: true))->fromUrl('https://unknown.example.com/page');

        $this->assertRequestSent('https://unknown.example.com/page');
    }

    public function testAnUnknownUrlStillYieldsAnEmbedBlockWithoutAProvider(): void
    {
        $block = (new EmbedBlockFactory())->fromUrl('https://unknown.example.com/page');

        $this->assertSame('embed', $block->blockName);
        $this->assertSame('', $block->attributes['providerNameSlug']);
        $this->assertSame('https://unknown.example.com/page', $block->attributes['url']);
    }

    /**
     * WP_oEmbed reports a timeout, a 503 and a deleted video alike as false,
     * so a failure cannot be told from a permanent one and is not memoised:
     * one flaky minute must not degrade every later occurrence of a provider.
     */
    public function testAProviderThatAnsweredNothingIsAskedAgain(): void
    {
        $this->fake_request('https://vimeo.com/api/oembed.json*')->with_response_code(503);
        $factory = new EmbedBlockFactory();

        $factory->fromUrl('https://vimeo.com/123');
        $factory->fromUrl('https://vimeo.com/123');

        $this->assertRequestCount(2);
        $this->assertSame(0, $factory->cachedUrlCount());
    }

    public function testResolveYieldsABlockOnlyWhenAProviderAnswered(): void
    {
        $this->fake_request('https://vimeo.com/api/oembed.json*')->with_response_code(503);
        $factory = new EmbedBlockFactory();

        $resolved = $factory->resolve('https://www.youtube.com/watch?v=abc123');
        $this->assertInstanceOf(Block::class, $resolved);
        $this->assertSame('youtube', $resolved->attributes['providerNameSlug']);

        $this->assertNull($factory->resolve('https://unknown.example.com/page'), 'No provider.');
        $this->assertNull($factory->resolve('https://vimeo.com/123'), 'A provider that did not answer.');
    }

    public function testTheCachesHandedToCreateDefaultAreTheOnesItUses(): void
    {
        $factory = new EmbedBlockFactory();
        $resolver = new AttachmentResolver();
        $converter = BlockConverter::createDefault($resolver, $factory);

        $converter->convert('[embed]https://www.youtube.com/watch?v=abc123[/embed]<img src="https://example.org/wp-content/uploads/none.jpg">');

        $this->assertSame(1, $factory->cachedUrlCount());
        $this->assertSame(1, $resolver->cachedUrlCount());

        $factory->forget();
        $converter->convert('[embed]https://www.youtube.com/watch?v=abc123[/embed]');

        $this->assertRequestCount(2);
    }

    public function testTheConverterSharesOneFactoryAcrossPosts(): void
    {
        $converter = BlockConverter::createDefault();
        $markup = '[embed]https://www.youtube.com/watch?v=abc123[/embed]';

        foreach (\range(1, 5) as $ignored) {
            $converter->convert($markup);
        }

        $this->assertRequestCount(1);
    }
}
