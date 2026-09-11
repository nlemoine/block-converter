<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Unit;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\Tests\TestCase;

final class BlockTest extends TestCase
{
    public function testRendersBlockWithContent(): void
    {
        $block = new Block(
            blockName: 'paragraph',
            attributes: [],
            innerContent: ['<p>Hello world</p>'],
        );

        $this->assertSame(
            "<!-- wp:paragraph -->\n<p>Hello world</p>\n<!-- /wp:paragraph -->",
            $block->render(),
        );
    }

    public function testRendersBlockWithAttributes(): void
    {
        $block = new Block(
            blockName: 'heading',
            attributes: ['level' => 2],
            innerContent: ['<h2>Title</h2>'],
        );

        $rendered = $block->render();
        $this->assertStringContainsString('<!-- wp:heading {"level":2} -->', $rendered);
        $this->assertStringContainsString('<h2>Title</h2>', $rendered);
    }

    public function testRendersSelfClosingBlock(): void
    {
        $block = new Block(
            blockName: 'n5s/poll',
            attributes: ['name' => 'n5s/poll', 'data' => ['id' => '42']],
        );

        $rendered = $block->render();
        $this->assertStringContainsString('<!-- wp:n5s/poll', $rendered);
        $this->assertStringContainsString('/-->', $rendered);
    }

    public function testFromParsedPreservesInnerContentInterleaving(): void
    {
        // Simulate what parse_blocks() returns for a wp:list with inner blocks
        $parsed = [
            'blockName' => 'core/list',
            'attrs' => [],
            'innerBlocks' => [
                [
                    'blockName' => 'core/list-item',
                    'attrs' => [],
                    'innerBlocks' => [],
                    'innerHTML' => '<li>Item 1</li>',
                    'innerContent' => ['<li>Item 1</li>'],
                ],
                [
                    'blockName' => 'core/list-item',
                    'attrs' => [],
                    'innerBlocks' => [],
                    'innerHTML' => '<li>Item 2</li>',
                    'innerContent' => ['<li>Item 2</li>'],
                ],
            ],
            'innerHTML' => "<ul class=\"wp-block-list\">\n\n</ul>",
            'innerContent' => [
                "\n<ul class=\"wp-block-list\">",
                null,
                "\n\n",
                null,
                "</ul>\n",
            ],
        ];

        $block = Block::fromParsed($parsed);
        $rendered = $block->render();

        // The <ul> must wrap the inner blocks, not come after them
        $this->assertStringContainsString('<ul class="wp-block-list">', $rendered);
        $ulPos = \strpos($rendered, '<ul class="wp-block-list">');
        $firstItemPos = \strpos($rendered, '<li>Item 1</li>');
        $closingUlPos = \strpos($rendered, '</ul>');
        $this->assertLessThan($firstItemPos, $ulPos);
        $this->assertGreaterThan($firstItemPos, $closingUlPos);
    }

    public function testToStringDelegatesToRender(): void
    {
        $block = new Block('separator', innerContent: ['<hr class="wp-block-separator"/>']);

        $this->assertSame($block->render(), (string) $block);
    }

    public function testFromParsedFallsBackToInnerHtmlWhenInnerContentIsEmpty(): void
    {
        $block = Block::fromParsed([
            'blockName' => 'core/paragraph',
            'attrs' => [],
            'innerBlocks' => [],
            'innerHTML' => '<p>Contenu</p>',
            'innerContent' => [],
        ]);

        $this->assertSame('paragraph', $block->blockName);
        $this->assertSame('<p>Contenu</p>', $block->innerHTML());
    }

    public function testFromParsedKeepsAnEmptyBlockEmpty(): void
    {
        $block = Block::fromParsed([
            'blockName' => 'core/spacer',
            'attrs' => ['height' => '20px'],
            'innerBlocks' => [],
            'innerHTML' => '',
            'innerContent' => [],
        ]);

        $this->assertSame('', $block->innerHTML());
        $this->assertSame('<!-- wp:spacer {"height":"20px"} /-->', $block->render());
    }

    public function testToArrayRoundTripsThroughFromParsed(): void
    {
        $parsed = [
            'blockName' => 'core/quote',
            'attrs' => ['citation' => 'Quelqu\'un'],
            'innerBlocks' => [
                [
                    'blockName' => 'core/paragraph',
                    'attrs' => [],
                    'innerBlocks' => [],
                    'innerHTML' => '<p>Cité</p>',
                    'innerContent' => ['<p>Cité</p>'],
                ],
            ],
            'innerHTML' => '<blockquote></blockquote>',
            'innerContent' => ['<blockquote>', null, '</blockquote>'],
        ];

        $array = Block::fromParsed($parsed)->toArray();

        $this->assertSame('quote', $array['blockName']);
        $this->assertSame(['citation' => 'Quelqu\'un'], $array['attrs']);
        $this->assertSame(['<blockquote>', null, '</blockquote>'], $array['innerContent']);
        $this->assertSame('paragraph', $array['innerBlocks'][0]['blockName']);
        $this->assertSame('<p>Cité</p>', $array['innerBlocks'][0]['innerHTML']);
    }

    public function testToArrayFlattensInnerHtmlFromInnerContent(): void
    {
        $block = new Block('paragraph', innerContent: ['<p>a</p>']);

        $this->assertSame('<p>a</p>', $block->toArray()['innerHTML']);
    }
}
