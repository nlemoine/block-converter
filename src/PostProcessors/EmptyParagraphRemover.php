<?php

declare(strict_types=1);

namespace n5s\BlockConverter\PostProcessors;

use n5s\BlockConverter\Block;
use WP_Post;

/**
 * Removes empty paragraph blocks (including whitespace-only and &nbsp;-only).
 *
 * Operates recursively on container blocks (e.g. blockquotes).
 */
class EmptyParagraphRemover implements PostProcessorInterface
{
    public function priority(): int
    {
        return 10;
    }

    /**
     * @param  list<Block> $blocks
     * @return list<Block>
     */
    public function process(array $blocks, ?WP_Post $post = null): array
    {
        foreach ($blocks as $block) {
            if ($block->innerBlocks !== []) {
                $this->processContainer($block, $post);
            }
        }

        return \array_values(\array_filter($blocks, fn (Block $block): bool => !$this->isEmpty($block)));
    }

    /**
     * Remove empty inner blocks and their corresponding null placeholders in innerContent.
     */
    private function processContainer(Block $block, ?WP_Post $post): void
    {
        // First recurse into nested containers
        foreach ($block->innerBlocks as $inner) {
            if ($inner->innerBlocks !== []) {
                $this->processContainer($inner, $post);
            }
        }

        // Find which inner block indices are empty
        $removedIndices = [];
        foreach ($block->innerBlocks as $i => $inner) {
            if ($this->isEmpty($inner)) {
                $removedIndices[$i] = true;
            }
        }

        if ($removedIndices === []) {
            return;
        }

        // Remove the corresponding null entries from innerContent
        $blockIndex = 0;
        $newContent = [];
        foreach ($block->innerContent as $chunk) {
            if ($chunk !== null) {
                $newContent[] = $chunk;

                continue;
            }

            if (!isset($removedIndices[$blockIndex])) {
                $newContent[] = null;
            }

            $blockIndex++;
        }

        $block->innerContent = $newContent;
        $block->innerBlocks = \array_values(\array_filter(
            $block->innerBlocks,
            static fn (int $i): bool => !isset($removedIndices[$i]),
            \ARRAY_FILTER_USE_KEY,
        ));
    }

    private function isEmpty(Block $block): bool
    {
        if ($block->blockName !== 'paragraph') {
            return false;
        }

        $content = $block->innerHTML();

        // An anchor target carries no text but is the destination of every
        // "#section-2" link in the document. Removing the paragraph around it
        // breaks them all, so text alone cannot decide emptiness.
        if ($this->holdsAnAnchorTarget($content)) {
            return false;
        }

        // wp_strip_all_tags() also drops <script>/<style> bodies, which the
        // sanitizer normally removed long before this — it only matters when
        // HtmlSanitizer has been replaced.
        $inner = \wp_strip_all_tags($content);

        // Decode HTML entities (&#160;, &shy;, &#8203;, etc.) to UTF-8.
        $inner = \html_entity_decode($inner, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        // Strip all Unicode whitespace (\p{Z}) and invisible formatting (\p{Cf})
        // covers: nbsp, thin/hair/en/em spaces, ZWSP, ZWJ, ZWNJ, soft hyphen,
        // word joiner, ideographic space, narrow nbsp, etc.
        $inner = \preg_replace('/[\s\p{Z}\p{Cf}]/u', '', $inner) ?? '';

        return $inner === '';
    }

    /**
     * Whether the markup holds an <a> that exists to be linked to.
     */
    private function holdsAnAnchorTarget(string $html): bool
    {
        $processor = new \WP_HTML_Tag_Processor($html);

        while ($processor->next_tag(['tag_name' => 'a'])) {
            if (\is_string($processor->get_attribute('name')) || \is_string($processor->get_attribute('id'))) {
                return true;
            }
        }

        return false;
    }
}
