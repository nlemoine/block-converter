<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Unit\Support;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\PreProcessors\HtmlSanitizer;
use n5s\BlockConverter\PreProcessors\PreProcessorInterface;
use n5s\BlockConverter\Support\BlockSanitizer;
use n5s\BlockConverter\Tests\TestCase;
use WP_Post;

final class BlockSanitizerTest extends TestCase
{
    private BlockSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new BlockSanitizer(new HtmlSanitizer());
    }

    public function testStripsExecutableMarkupFromALeafBlock(): void
    {
        $block = new Block('image', ['id' => 7], innerContent: [
            '<figure class="wp-block-image"><img src="/x.jpg" onerror="alert(1)"><script>alert(2)</script></figure>',
        ]);

        $this->sanitizer->sanitize($block);

        $this->assertSame(
            ['<figure class="wp-block-image"><img src="/x.jpg" /></figure>'],
            $block->innerContent,
        );
        $this->assertSame(['id' => 7], $block->attributes, 'Attributes are data, not markup.');
    }

    public function testKeepsInnerBlocksWhereTheyWere(): void
    {
        $gallery = new Block('gallery', container: true);
        $gallery->appendContent('<figure class="wp-block-gallery">');
        $gallery->appendInnerBlock(new Block('image', innerContent: ['<figure><img src="/a.jpg" onload="alert(1)"></figure>']));
        $gallery->appendInnerBlock(new Block('image', innerContent: ['<figure><img src="/b.jpg"></figure>']));
        $gallery->appendContent('<figcaption>Une <b>légende</b><script>x</script></figcaption></figure>');

        $this->sanitizer->sanitize($gallery);

        $this->assertSame(
            ['<figure class="wp-block-gallery">', null, null, '<figcaption>Une <b>légende</b></figcaption></figure>'],
            $gallery->innerContent,
            'The opening tag in one chunk and its closing tag in another must survive as unbalanced fragments.',
        );
        $this->assertCount(2, $gallery->innerBlocks);
        $this->assertSame(['<figure><img src="/a.jpg" /></figure>'], $gallery->innerBlocks[0]->innerContent, 'Inner blocks are sanitized too.');
    }

    public function testLeavesShortcodeTextAlone(): void
    {
        $text = '[playlist ids="1,2" x=\'a<b\']';

        foreach (['shortcode', 'core/shortcode'] as $name) {
            $block = new Block($name, innerContent: [$text]);

            $this->sanitizer->sanitize($block);

            $this->assertSame([$text], $block->innerContent, 'A double quote must not come back as &#34;.');
        }
    }

    public function testAPlaceholderCharacterInTheInputCannotAddAChunk(): void
    {
        $block = new Block('paragraph', innerContent: ["<p>a\u{E000}b</p>"]);

        $this->sanitizer->sanitize($block);

        $this->assertSame(['<p>ab</p>'], $block->innerContent);
    }

    /**
     * When the sanitizer cannot keep a placeholder where it stands, the inner
     * blocks are folded into their parent rather than misplaced or dropped.
     */
    public function testFoldsInnerBlocksIntoTheParentWhenTheStructureCannotBeKept(): void
    {
        $dropsPlaceholders = new class () implements PreProcessorInterface {
            public function priority(): int
            {
                return 0;
            }

            public function runsBeforeBlockParsing(): bool
            {
                return false;
            }

            public function process(string $html, ?WP_Post $post = null): string
            {
                return \str_replace(["\u{E000}", '<!-- wp:image -->', '<!-- /wp:image -->'], '', $html);
            }
        };

        $gallery = new Block('gallery', container: true);
        $gallery->appendContent('<figure>');
        $gallery->appendInnerBlock(new Block('image', innerContent: ['<img src="/a.jpg">']));
        $gallery->appendContent('</figure>');

        (new BlockSanitizer($dropsPlaceholders))->sanitize($gallery);

        $this->assertSame([], $gallery->innerBlocks);
        $this->assertSame(["<figure>\n<img src=\"/a.jpg\">\n</figure>"], $gallery->innerContent, 'The inner markup is kept, only its delimiters are lost.');
    }
}
