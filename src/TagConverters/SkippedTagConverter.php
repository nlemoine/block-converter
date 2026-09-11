<?php

declare(strict_types=1);

namespace n5s\BlockConverter\TagConverters;

use n5s\BlockConverter\Block;
use voku\helper\SimpleHtmlDomInterface;
use WP_Post;

class SkippedTagConverter implements TagConverterInterface
{
    public static function tags(): array
    {
        return ['br', 'cite', 'source'];
    }

    public function convert(
        SimpleHtmlDomInterface $element,
        ?WP_Post $post = null,
    ): Block|array|null {

        return null;
    }
}
