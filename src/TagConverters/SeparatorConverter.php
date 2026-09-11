<?php

declare(strict_types=1);

namespace n5s\BlockConverter\TagConverters;

use n5s\BlockConverter\Block;
use voku\helper\SimpleHtmlDomInterface;
use WP_Post;

class SeparatorConverter implements TagConverterInterface
{
    public static function tags(): array
    {
        return ['hr'];
    }

    public function convert(
        SimpleHtmlDomInterface $element,
        ?WP_Post $post = null,
    ): Block|array|null {

        return new Block(
            blockName: 'separator',
            innerContent: ['<hr class="wp-block-separator has-alpha-channel-opacity"/>'],
        );
    }
}
