<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Support;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\PreProcessors\PreProcessorInterface;
use WP_Post;

/**
 * Runs the HTML a block tree carries through the sanitizer, in place.
 *
 * Block markup produced before the sanitizer runs is handed back by
 * parse_blocks() as-is: the delimiters mark it "already a block", and the
 * late pre-processors never see its contents. Anything interpolated into such
 * a block therefore reaches post_content exactly as the converter built it.
 * Escaping each interpolation site is the wrong layer for that guarantee — a
 * missed site is invisible until it is exploited — so this sanitizes the
 * assembled block instead, one step before it is serialised.
 *
 * The attributes are left alone: serialize_block_attributes() escapes them
 * on the way into the delimiter, and they are data, not markup.
 *
 * core/shortcode blocks are skipped. They carry shortcode text rather than
 * HTML, and sanitizing it corrupts the syntax (a double quote comes back as
 * &#34;). Their delimiters are neutralised where they are built instead.
 */
final readonly class BlockSanitizer
{
    /**
     * Stands in for an inner block while its parent's chunks are sanitized.
     *
     * A container's innerContent is a sequence of HTML fragments with a null
     * where each inner block goes — an opening tag in one chunk, its closing
     * tag several chunks later. Sanitized one by one, each fragment would be
     * closed or dropped as unbalanced, so the chunks are joined, sanitized as
     * one document, and split again on this marker. A private-use codepoint
     * is never meaningful content, so any copy the input carries is removed
     * first.
     */
    private const string PLACEHOLDER = "\u{E000}";

    public function __construct(
        private PreProcessorInterface $sanitizer,
    ) {
    }

    public function sanitize(Block $block, ?WP_Post $post = null): void
    {
        if ($this->carriesShortcodeText($block)) {
            return;
        }

        foreach ($block->innerBlocks as $inner) {
            $this->sanitize($inner, $post);
        }

        $expected = 0;
        $joined = '';

        foreach ($block->innerContent as $chunk) {
            if ($chunk === null) {
                $expected++;
                $joined .= self::PLACEHOLDER;

                continue;
            }

            $joined .= \str_replace(self::PLACEHOLDER, '', $chunk);
        }

        $parts = \explode(self::PLACEHOLDER, $this->sanitizer->process($joined, $post));

        // A placeholder survives anywhere text does. The one place it does
        // not is inside an element the sanitizer removes whole — a <style>
        // left open across the inner blocks, say — and that only happens to
        // content built from the input. Structure cannot be trusted then, so
        // the inner blocks are folded into their parent's markup and the
        // result sanitized as plain HTML: their delimiters are lost, nothing
        // else is.
        if (\count($parts) !== $expected + 1) {
            $block->innerContent = [$this->sanitizer->process($this->flatten($block), $post)];
            $block->innerBlocks = [];

            return;
        }

        $content = [];

        foreach ($parts as $i => $part) {
            if ($i > 0) {
                $content[] = null;
            }

            if ($part !== '') {
                $content[] = $part;
            }
        }

        $block->innerContent = $content;
    }

    private function carriesShortcodeText(Block $block): bool
    {
        return \strip_core_block_namespace($block->blockName) === 'shortcode';
    }

    /**
     * The block's own markup with its inner blocks rendered into it.
     */
    private function flatten(Block $block): string
    {
        $blockIndex = 0;
        $html = '';

        foreach ($block->innerContent as $chunk) {
            $html .= $chunk ?? $block->innerBlocks[$blockIndex++]->render();
        }

        return $html;
    }
}
