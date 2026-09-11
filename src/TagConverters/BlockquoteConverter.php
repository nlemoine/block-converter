<?php

declare(strict_types=1);

namespace n5s\BlockConverter\TagConverters;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\Support\HtmlUtils;
use voku\helper\SimpleHtmlDomInterface;
use WP_Post;

class BlockquoteConverter implements TagConverterInterface
{
    public static function tags(): array
    {
        return ['blockquote'];
    }

    public function convert(
        SimpleHtmlDomInterface $element,
        ?WP_Post $post = null,
    ): Block|array|null {

        $attributes = [];
        $classes = ['wp-block-quote'];

        $textAlign = HtmlUtils::extractTextAlign($element->getAttribute('class'));

        if ($textAlign !== null && $textAlign !== 'left') {
            $classes[] = 'has-text-align-' . $textAlign;
            $attributes['textAlign'] = $textAlign;
        }

        $block = new Block('quote', $attributes, container: true);
        $block->appendContent(\sprintf('<blockquote class="%s">', \implode(' ', $classes)));

        return $block;
    }
}
