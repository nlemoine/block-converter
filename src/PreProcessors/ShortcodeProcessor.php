<?php

declare(strict_types=1);

namespace n5s\BlockConverter\PreProcessors;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\ConverterRegistry;
use n5s\BlockConverter\Support\BlockSanitizer;
use n5s\BlockConverter\Support\ShortcodeBlockBuilder;
use WP_Post;

/**
 * Converts shortcodes to block markup inline.
 *
 * Temporarily replaces WordPress shortcode handlers with our converters,
 * then delegates to do_shortcode() for full WordPress-compatible processing
 * (nested shortcodes, escaping, hooks, etc.).
 *
 * Shortcodes with a registered ShortcodeConverterInterface are converted to
 * their target block type. Shortcode tags registered via registerShortcodeTag()
 * (orphaned shortcodes from deactivated plugins) are wrapped in wp:shortcode
 * fallback blocks so they are preserved in Gutenberg.
 *
 * This runs before block parsing, so the markup it emits is handed back by
 * parse_blocks() as finished blocks and never reaches the late pre-processors.
 * Shortcode attributes and content are attacker-controlled — the input is a
 * legacy dump nothing upstream has filtered — so every block a converter
 * returns is sanitized here, at the one point all of them pass through,
 * rather than at each place a converter interpolates a value.
 */
class ShortcodeProcessor implements PreProcessorInterface
{
    private readonly BlockSanitizer $blockSanitizer;

    /**
     * @param PreProcessorInterface|null $sanitizer Applied to the HTML of every
     *                                              block a converter returns.
     *                                              Pass the same instance the
     *                                              registry runs late, so both
     *                                              paths enforce one policy.
     *                                              Defaults to HtmlSanitizer.
     */
    public function __construct(
        private readonly ConverterRegistry $registry,
        ?PreProcessorInterface $sanitizer = null,
    ) {

        $this->blockSanitizer = new BlockSanitizer($sanitizer ?? new HtmlSanitizer());
    }

    public function priority(): int
    {
        return 20;
    }

    public function runsBeforeBlockParsing(): bool
    {
        return true;
    }

    public function process(string $html, ?WP_Post $post = null): string
    {
        if (!\str_contains($html, '[')) {
            return $html;
        }

        $shortcodes = $this->registry->getShortcodeConverters();

        if ($shortcodes === []) {
            return $html;
        }

        /** @var array<string, callable> $shortcode_tags */
        global $shortcode_tags;

        $savedTags = $shortcode_tags;

        $fallback = static function (array|string $atts, ?string $content, string $tag): string {
            /** @var array<int|string, string> $atts */
            $atts = \is_array($atts) ? $atts : [];

            return ShortcodeBlockBuilder::build($tag, $atts, $content)->render();
        };

        // Override ALL WP-registered shortcodes with the fallback handler
        // to prevent their original handlers from executing during migration
        // (they may produce HTML that no tag converter handles).
        foreach (\array_keys($shortcode_tags) as $tag) {
            // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
            $shortcode_tags[$tag] = $fallback;
        }

        // Layer our converters on top, and add orphaned tags not in WP.
        foreach ($shortcodes as $tag => $converter) {
            if ($converter === null) {
                // Orphaned tag — may or may not already be in $shortcode_tags.
                // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
                $shortcode_tags[$tag] = $fallback;

                continue;
            }

            $blockSanitizer = $this->blockSanitizer;

            // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
            $shortcode_tags[$tag] = static function (array|string $atts, ?string $content, string $tag) use ($converter, $post, $blockSanitizer): string {
                /** @var array<string, string> $atts */
                $atts = \is_array($atts) ? $atts : [];
                $content = \is_string($content) && $content !== '' ? $content : null;
                $block = $converter->convert($atts, $content, $tag, $post);

                if (!$block instanceof Block) {
                    return '';
                }

                $blockSanitizer->sanitize($block, $post);

                return $block->render();
            };
        }

        try {
            return \do_shortcode($html);
        } finally {
            // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
            $shortcode_tags = $savedTags;
        }
    }
}
