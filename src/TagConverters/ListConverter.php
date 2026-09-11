<?php

declare(strict_types=1);

namespace n5s\BlockConverter\TagConverters;

use n5s\BlockConverter\Block;
use voku\helper\SimpleHtmlDomInterface;
use WP_Post;

class ListConverter implements TagConverterInterface
{
    public static function tags(): array
    {
        return ['ul', 'ol'];
    }

    public function convert(
        SimpleHtmlDomInterface $element,
        ?WP_Post $post = null,
    ): Block|array|null {

        $tag = $element->getTag();

        $attributes = [];
        if ($tag === 'ol') {
            $start = $element->getAttribute('start');
            if (\is_numeric($start)) {
                $attributes['start'] = (int) $start;
            }

            $attributes['ordered'] = true;
        }

        $block = new Block('list', $attributes, container: true);
        $block->appendContent("<{$tag} class=\"wp-block-list\">");

        return $block;
    }
}
