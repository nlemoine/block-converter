<?php

declare(strict_types=1);

namespace n5s\BlockConverter\PreProcessors;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\Support\EmbedBlockFactory;
use WP_Post;

/**
 * Converts bare oEmbed URLs to Gutenberg embed blocks.
 *
 * Mirrors WordPress's WP_Embed::autoembed() regex patterns to detect
 * URLs on their own line or alone inside a <p> tag.  For each match
 * it checks registered embed handlers first, then known oEmbed
 * providers, and replaces the URL with rendered block markup.
 *
 * oEmbed discovery is the factory's setting, off by default: only sanctioned
 * providers are matched. `new EmbedBlockFactory(discover: true)` also resolves
 * unknown URLs via `<link>` tag discovery, which triggers HTTP requests. There
 * used to be a second flag here; the two had to agree, and when they did not
 * the provider lookup succeeded while the data lookup failed, replacing a
 * working URL with an embed block that named no provider.
 *
 * Runs as a late pre-processor on raw HTML fragments (after
 * parse_blocks() has already isolated existing blocks).  This mirrors
 * WordPress's own architecture where autoembed() never encounters
 * pre-existing block markup.
 *
 * One deliberate divergence: WordPress runs autoembed() at priority 8,
 * before wpautop() at 10, while this runs after WpAutop. Running before it
 * is not an option — wpautop() wraps block delimiters in <p> and breaks the
 * figure open — so a URL on its own line inside a paragraph, i.e.
 * `Text\nURL\nMore`, is joined by a <br> before the patterns see it and
 * stays text, where WordPress would embed it inline. A URL separated from
 * its neighbours by a blank line, or alone in a <p>, embeds as expected.
 * Closing the gap means splitting the paragraph around the embed, since an
 * embed block cannot live inside a paragraph block.
 */
class AutoEmbedProcessor implements PreProcessorInterface
{
    public function __construct(
        private readonly EmbedBlockFactory $embedFactory = new EmbedBlockFactory(),
    ) {
    }

    public function priority(): int
    {
        return 50;
    }

    public function runsBeforeBlockParsing(): bool
    {
        return false;
    }

    public function process(string $html, ?WP_Post $post = null): string
    {
        if (!\preg_match('#(^|\s|>)https?://#i', $html)) {
            return $html;
        }

        // Protect line breaks inside HTML tags (same as WP_Embed::autoembed).
        $content = \wp_replace_in_html_tags($html, ["\n" => '<!-- wp-line-break -->']);

        // URLs on their own line.
        $content = \preg_replace_callback(
            '|^(\s*)(https?://[^\s<>"]+)(\s*)$|im',
            $this->handleUrl(...),
            $content,
        ) ?? $content;

        // URLs alone inside a <p> tag.
        $content = \preg_replace_callback(
            '|(<p(?: [^>]*)?>\s*)(https?://[^\s<>"]+)(\s*</p>)|i',
            $this->handleUrl(...),
            $content,
        ) ?? $content;

        return \str_replace('<!-- wp-line-break -->', "\n", $content);
    }

    /**
     * Attempt to convert a matched URL into an embed block.
     *
     * @param string[] $matches Regex match groups: [0]=full, [1]=prefix, [2]=url, [3]=suffix
     */
    private function handleUrl(array $matches): string
    {
        $url = $matches[2];

        // 1. Short-circuit for URLs belonging to the current site (no HTTP).
        $block = $this->fromInternalPost($url);

        // 2. Check registered embed handlers (same order as WP_Embed::shortcode).
        $block ??= $this->fromEmbedHandler($url);

        // 3. Check known oEmbed providers.
        $block ??= $this->fromOEmbed($url);

        if (!$block instanceof Block) {
            return $matches[0];
        }

        $rendered = $block->render();

        // When matched inside <p>, replace the entire paragraph.
        if (\str_starts_with(\ltrim($matches[1]), '<p')) {
            return $rendered;
        }

        return $matches[1] . $rendered . $matches[3];
    }

    /**
     * Short-circuit for URLs belonging to the current site.
     *
     * @see WP_oEmbed_Controller::get_proxy_item()
     */
    private function fromInternalPost(string $url): ?Block
    {
        $data = \get_oembed_response_data_for_url($url, []);

        if ($data === false) {
            return null;
        }

        return $this->embedFactory->fromData($url, $data);
    }

    /**
     * Try registered embed handlers (WP_Embed::$handlers).
     */
    private function fromEmbedHandler(string $url): ?Block
    {
        $wpEmbed = $GLOBALS['wp_embed'] ?? null;

        if (!$wpEmbed instanceof \WP_Embed) {
            return null;
        }

        // Running the handlers is the only way WP_Embed offers to find out
        // whether one matches; the markup itself is regenerated at render
        // time and not stored in the block.
        $html = $wpEmbed->get_embed_handler_html([], $url);

        if ($html === false || \trim($html) === '') {
            return null;
        }

        return $this->embedFactory->fromHandler($url);
    }

    /**
     * Try known oEmbed providers. A URL nothing answers for stays a URL, as
     * it does under WP_Embed::autoembed().
     */
    private function fromOEmbed(string $url): ?Block
    {
        return $this->embedFactory->resolve($url);
    }
}
