<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Integration\Support;

use n5s\BlockConverter\Support\AttachmentResolver;
use n5s\BlockConverter\Tests\WpTestCase;

final class AttachmentResolverTest extends WpTestCase
{
    private AttachmentResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new AttachmentResolver();
    }

    public function testFromIdReturnsIdForValidAttachment(): void
    {
        $id = self::factory()->attachment->create();
        $this->assertSame($id, $this->resolver->fromId($id));
    }

    public function testFromIdReturnsNullForNonAttachmentPost(): void
    {
        $id = self::factory()->post->create();
        $this->assertNull($this->resolver->fromId($id));
    }

    public function testFromIdReturnsNullForNonExistentId(): void
    {
        $this->assertNull($this->resolver->fromId(999999));
    }

    public function testFromCssClassResolvesWpImageClass(): void
    {
        $id = self::factory()->attachment->create();
        $this->assertSame($id, $this->resolver->fromCssClass(['wp-image-' . $id, 'size-full']));
    }

    public function testFromCssClassIgnoresNonAttachmentIds(): void
    {
        $postId = self::factory()->post->create();
        $this->assertNull($this->resolver->fromCssClass(['wp-image-' . $postId]));
    }

    public function testFromUrlResolvesAttachmentUrl(): void
    {
        $id = self::factory()->attachment->create(['file' => 'test-image.jpg']);
        $url = wp_get_attachment_url($id);
        $this->assertNotFalse($url, 'Attachment URL should not be false');
        $this->assertSame($id, $this->resolver->fromUrl($url));
    }

    public function testFromUrlReturnsNullForUnknownUrl(): void
    {
        $this->assertNull($this->resolver->fromUrl('https://example.com/nonexistent.jpg'));
    }

    // ------------------------------------------------------------------
    //  URL lookups are memoised
    // ------------------------------------------------------------------

    /**
     * attachment_url_to_postid() queries wp_postmeta on meta_value, which is
     * unindexed, so each call is a full scan. Legacy content repeats the same
     * images across posts, and most of its images are not attachments at all.
     */
    public function testTheSameUrlIsOnlyLookedUpOnce(): void
    {
        $calls = $this->countUrlLookups();
        $resolver = new AttachmentResolver();

        $resolver->fromUrl('https://example.org/repeated.jpg');
        $resolver->fromUrl('https://example.org/repeated.jpg');
        $resolver->fromUrl('https://example.org/repeated.jpg');

        $this->assertSame(1, $calls());
    }

    public function testMissesAreCachedToo(): void
    {
        $calls = $this->countUrlLookups();
        $resolver = new AttachmentResolver();

        $this->assertNull($resolver->fromUrl('https://example.org/not-an-attachment.jpg'));
        $this->assertNull($resolver->fromUrl('https://example.org/not-an-attachment.jpg'));

        $this->assertSame(1, $calls());
        $this->assertSame(1, $resolver->cachedUrlCount());
    }

    public function testDistinctUrlsAreLookedUpSeparately(): void
    {
        $calls = $this->countUrlLookups();
        $resolver = new AttachmentResolver();

        $resolver->fromUrl('https://example.org/one.jpg');
        $resolver->fromUrl('https://example.org/two.jpg');

        $this->assertSame(2, $calls());
        $this->assertSame(2, $resolver->cachedUrlCount());
    }

    public function testForgetEmptiesTheCache(): void
    {
        $resolver = new AttachmentResolver();
        $resolver->fromUrl('https://example.org/one.jpg');

        $this->assertSame(1, $resolver->cachedUrlCount());

        $resolver->forget();

        $this->assertSame(0, $resolver->cachedUrlCount());
    }

    public function testACachedHitStillReturnsTheAttachmentId(): void
    {
        $attachmentId = self::factory()->attachment->create(['file' => 'cached.jpg']);
        $url = \wp_get_attachment_url($attachmentId);

        $this->assertIsString($url);

        $resolver = new AttachmentResolver();

        $this->assertSame($attachmentId, $resolver->fromUrl($url));
        $this->assertSame($attachmentId, $resolver->fromUrl($url));
    }

    /** @return callable(): int */
    private function countUrlLookups(): callable
    {
        $calls = 0;

        \add_filter(
            'attachment_url_to_postid',
            // WordPress passes null through this filter when nothing matched.
            static function (mixed $id) use (&$calls): mixed {
                ++$calls;

                return $id;
            },
        );

        // A regular closure, not an arrow function: the latter would capture
        // $calls by value and always report the count it had at creation.
        return static function () use (&$calls): int {
            return $calls;
        };
    }

    public function testATrashedAttachmentIsNotResolved(): void
    {
        $attachmentId = self::factory()->attachment->create(['file' => 'trashed.jpg']);
        $resolver = new AttachmentResolver();

        $this->assertSame($attachmentId, $resolver->fromId($attachmentId));

        \wp_trash_post($attachmentId);

        // An id pointing at trashed media gives the editor a broken image.
        $this->assertNull((new AttachmentResolver())->fromId($attachmentId));
    }

    /**
     * Every caller chains fromCssClass() ?? fromUrl(), so a status check on
     * the first alone was undone by the second.
     */
    public function testATrashedAttachmentIsNotResolvedFromItsUrlEither(): void
    {
        $attachmentId = self::factory()->attachment->create(['file' => 'trashed-by-url.jpg']);
        $url = \wp_get_attachment_url($attachmentId);
        $resolver = new AttachmentResolver();

        $this->assertSame($attachmentId, $resolver->fromUrl($url));

        \wp_trash_post($attachmentId);

        // Same instance: the cached lookup must not outlive the status.
        $this->assertNull($resolver->fromUrl($url));
        $this->assertNull((new AttachmentResolver())->fromUrl($url));
    }
}
