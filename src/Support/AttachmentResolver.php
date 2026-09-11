<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Support;

class AttachmentResolver
{
    /**
     * Resolved URLs, misses included.
     *
     * attachment_url_to_postid() queries wp_postmeta on meta_value, which is
     * unindexed, so every call is a full scan. Legacy content repeats the same
     * images across posts and is full of images that are not attachments at
     * all, so caching the misses matters as much as caching the hits.
     *
     * Only this lookup is cached: fromId() goes through get_post_type(), which
     * WordPress already serves from its object cache.
     *
     * @var array<string, int> 0 for a miss, as attachment_url_to_postid() reports it
     */
    private array $urlCache = [];

    /**
     * Resolve from an explicit attachment ID.
     * Validates the ID is positive and corresponds to an actual attachment post.
     */
    public function fromId(int $id): ?int
    {
        if ($id <= 0) {
            return null;
        }

        // 'inherit' is what an attachment carries in normal use; a trashed one
        // reads 'trash' and would otherwise yield an id pointing at media the
        // editor cannot show.
        if (get_post_type($id) === 'attachment' && get_post_status($id) !== 'trash') {
            return $id;
        }

        return null;
    }

    /**
     * Resolve from CSS classes containing wp-image-{id} pattern.
     *
     * @param string[] $classes
     */
    public function fromCssClass(array $classes): ?int
    {
        foreach ($classes as $class) {
            if (\preg_match('/^wp-image-(\d+)$/', $class, $matches)) {
                return $this->fromId((int) $matches[1]);
            }
        }

        return null;
    }

    /**
     * Resolve from a URL via WordPress attachment_url_to_postid().
     *
     * The lookup is a bare meta_value match with no status filter, so its
     * result goes through the same validation as an explicit id: every caller
     * chains fromCssClass() ?? fromUrl(), and without this a trashed
     * attachment refused by the first was handed back by the second. Only the
     * lookup is cached; the status is checked on each call, from the object
     * cache, so media trashed mid-run is not resolved from stale state.
     */
    public function fromUrl(string $url): ?int
    {
        if (!\array_key_exists($url, $this->urlCache)) {
            $this->urlCache[$url] = attachment_url_to_postid($url);
        }

        return $this->fromId($this->urlCache[$url]);
    }

    /**
     * How many URLs the cache is holding.
     *
     * A migration is a long-lived process and this map only grows, so a caller
     * working through a very large corpus can watch it and call forget().
     */
    public function cachedUrlCount(): int
    {
        return \count($this->urlCache);
    }

    public function forget(): void
    {
        $this->urlCache = [];
    }
}
