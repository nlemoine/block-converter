<?php

declare(strict_types=1);

namespace n5s\BlockConverter\TagConverters;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\Support\HtmlUtils;
use voku\helper\SimpleHtmlDomInterface;
use WP_Post;

class HeadingConverter implements TagConverterInterface
{
    private const int DEFAULT_LEVEL = 2;

    public static function tags(): array
    {
        return ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
    }

    public function convert(
        SimpleHtmlDomInterface $element,
        ?WP_Post $post = null,
    ): Block|array|null {

        $innerHtml = $element->innerHtml();

        if ($innerHtml === '') {
            return null;
        }

        $tag = $element->getTag();
        $level = (int) \str_replace('h', '', $tag);

        $classes = ['wp-block-heading'];
        $attributes = $level !== self::DEFAULT_LEVEL ? ['level' => $level] : [];

        $textAlign = HtmlUtils::extractTextAlign($element->getAttribute('class'));

        if ($textAlign !== null && $textAlign !== 'left') {
            $classes[] = 'has-text-align-' . $textAlign;
            $attributes['textAlign'] = $textAlign;
        }

        // The heading is rebuilt from scratch, so its id has to be carried
        // over deliberately: it is the target of every "#section" link in
        // the document, and Gutenberg keeps it as the block's anchor.
        $id = \trim($element->getAttribute('id'));
        $anchor = '';

        if ($id !== '') {
            $attributes['anchor'] = $id;
            $anchor = \sprintf(' id="%s"', \esc_attr($id));
        }

        return new Block(
            blockName: 'heading',
            attributes: $attributes,
            innerContent: [\sprintf('<%s class="%s"%s>%s</%s>', $tag, \implode(' ', $classes), $anchor, $innerHtml, $tag)],
        );
    }
}
