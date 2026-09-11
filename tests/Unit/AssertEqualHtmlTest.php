<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Unit;

use n5s\BlockConverter\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;

final class AssertEqualHtmlTest extends TestCase
{
    public function testToleratesAttributeReordering(): void
    {
        $this->assertEqualHTML(
            '<img src="photo.jpg" alt="A photo">',
            '<img alt="A photo" src="photo.jpg">',
        );
    }

    public function testToleratesClassNameReordering(): void
    {
        $this->assertEqualHTML(
            '<div class="foo bar baz">content</div>',
            '<div class="baz foo bar">content</div>',
        );
    }

    public function testNormalizesStyleWhitespace(): void
    {
        $this->assertEqualHTML(
            '<div style="margin-top: 10px; padding: 5px;">content</div>',
            '<div style="margin-top:10px;padding:5px;">content</div>',
        );
    }

    public function testToleratesBlockCommentAttributeReordering(): void
    {
        $this->assertEqualHTML(
            '<!-- wp:image {"id":123,"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="photo.jpg" class="wp-image-123"/></figure><!-- /wp:image -->',
            '<!-- wp:image {"sizeSlug":"large","id":123} --><figure class="wp-block-image size-large"><img class="wp-image-123" src="photo.jpg"/></figure><!-- /wp:image -->',
        );
    }

    public function testDetectsDifferentContent(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertEqualHTML(
            '<p>Hello</p>',
            '<p>World</p>',
        );
    }

    public function testDetectsMissingAttributes(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertEqualHTML(
            '<img src="photo.jpg" alt="A photo">',
            '<img src="photo.jpg">',
        );
    }
}
