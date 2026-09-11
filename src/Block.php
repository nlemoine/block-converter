<?php

declare(strict_types=1);

namespace n5s\BlockConverter;

use Stringable;

/**
 * @phpstan-type ParsedBlock array{
 *     blockName: string|null,
 *     attrs: array<string, mixed>,
 *     innerBlocks: array<array<string, mixed>>,
 *     innerHTML: string,
 *     innerContent: array<string|null>,
 * }
 */
class Block implements Stringable
{
    /**
     * @param string               $blockName    The block name (e.g. 'paragraph', 'core/image').
     * @param array<string, mixed> $attributes    Block attributes.
     * @param bool                 $container     When true, the engine recurses into children to build innerBlocks.
     * @param Block[]              $innerBlocks   Nested child blocks.
     * @param array<string|null>   $innerContent  Interleaved HTML chunks and null placeholders for innerBlocks.
     */
    public function __construct(
        public readonly string $blockName,
        public array $attributes = [],
        public readonly bool $container = false,
        /** @var Block[] */
        public array $innerBlocks = [],
        /** @var array<string|null> */
        public array $innerContent = [],
    ) {
    }

    /**
     * Build a Block tree from a parse_blocks() result array.
     *
     * @param ParsedBlock $parsed
     */
    public static function fromParsed(array $parsed): self
    {
        $blockName = $parsed['blockName'] ?? '';

        if (\str_starts_with($blockName, 'core/')) {
            $blockName = \substr($blockName, 5);
        }

        $block = new self(
            blockName: $blockName,
            attributes: $parsed['attrs'],
        );

        // Walk innerContent in a single pass to preserve the interleaving of
        // HTML string chunks and null placeholders (one per inner block).
        if ($parsed['innerContent'] !== []) {
            $blockIndex = 0;

            foreach ($parsed['innerContent'] as $chunk) {
                if (\is_string($chunk)) {
                    $block->appendContent($chunk);

                    continue;
                }

                $block->appendInnerBlock(self::fromParsed($parsed['innerBlocks'][$blockIndex++])); // @phpstan-ignore argument.type (recursive shape not supported)
            }
        } elseif ($parsed['innerHTML'] !== '') {
            $block->appendContent($parsed['innerHTML']);
        }

        return $block;
    }

    /**
     * Concatenated string chunks from innerContent (the block's own HTML, excluding inner blocks).
     */
    public function innerHTML(): string
    {
        return \implode('', \array_filter($this->innerContent, \is_string(...)));
    }

    /**
     * Append a child block. Adds a null placeholder to innerContent.
     */
    public function appendInnerBlock(Block $block): self
    {
        $this->innerBlocks[] = $block;
        $this->innerContent[] = null;

        return $this;
    }

    /**
     * Append HTML content. Merges with the last string chunk if possible.
     */
    public function appendContent(string $html): self
    {
        // phpcs:ignore PHPCompatibility.FunctionUse.NewFunctions.array_lastFound -- provided by symfony/polyfill-php85 below PHP 8.5.
        if ($this->innerContent !== [] && \is_string(\array_last($this->innerContent))) {
            $this->innerContent[array_key_last($this->innerContent)] .= $html;

            return $this;
        }

        $this->innerContent[] = $html;

        return $this;
    }

    /**
     * Serialize this block to Gutenberg block markup.
     *
     * Uses the same comment-delimited format as the Gutenberg JS editor
     * (with newlines around content), which differs from WordPress PHP's
     * serialize_block() that omits these newlines.
     *
     * @see https://github.com/alleyinteractive/wp-block-converter/issues/21
     */
    public function render(): string
    {
        $name = strip_core_block_namespace($this->blockName);
        $attrs = $this->attributes === [] ? '' : serialize_block_attributes($this->attributes) . ' ';

        $content = \trim($this->renderContent(), "\n");

        if ($content === '') {
            return \sprintf('<!-- wp:%s %s/-->', $name, $attrs);
        }

        return \sprintf(
            "<!-- wp:%s %s-->\n%s\n<!-- /wp:%s -->",
            $name,
            $attrs,
            $content,
            $name,
        );
    }

    /**
     * Render the block's inner content, recursively serializing inner blocks.
     */
    private function renderContent(): string
    {
        $blockIndex = 0;
        $parts = [];

        foreach ($this->innerContent as $chunk) {
            $parts[] = $chunk ?? $this->innerBlocks[$blockIndex++]->render();
        }

        return \implode('', $parts);
    }

    /** @return ParsedBlock */
    public function toArray(): array
    {
        return [
            'blockName' => $this->blockName,
            'attrs' => $this->attributes,
            'innerBlocks' => array_map(fn (Block $b): array => $b->toArray(), $this->innerBlocks),
            'innerHTML' => $this->innerHTML(),
            'innerContent' => $this->innerContent,
        ];
    }

    public function __toString(): string
    {
        return $this->render();
    }
}
