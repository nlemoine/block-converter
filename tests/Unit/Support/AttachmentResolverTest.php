<?php

declare(strict_types=1);

namespace n5s\BlockConverter\Tests\Unit\Support;

use n5s\BlockConverter\Support\AttachmentResolver;
use n5s\BlockConverter\Tests\TestCase;

final class AttachmentResolverTest extends TestCase
{
    private AttachmentResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new AttachmentResolver();
    }

    public function testFromIdReturnsNullForZero(): void
    {
        $this->assertNull($this->resolver->fromId(0));
    }

    public function testFromIdReturnsNullForNegative(): void
    {
        $this->assertNull($this->resolver->fromId(-5));
    }

    public function testFromCssClassReturnsNullForNonExistentId(): void
    {
        $this->assertNull($this->resolver->fromCssClass(['wp-image-42', 'size-full']));
    }

    public function testFromCssClassReturnsNullWithoutMatch(): void
    {
        $this->assertNull($this->resolver->fromCssClass(['aligncenter', 'size-large']));
    }

    public function testFromCssClassReturnsNullForEmptyArray(): void
    {
        $this->assertNull($this->resolver->fromCssClass([]));
    }

    public function testFromUrlReturnsNullForUnknownUrl(): void
    {
        $this->assertNull($this->resolver->fromUrl('https://example.com/image.jpg'));
    }
}
