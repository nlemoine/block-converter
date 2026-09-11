<?php

declare(strict_types=1);

namespace n5s\BlockConverter\TagConverters;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\Support\HtmlUtils;
use voku\helper\SimpleHtmlDomInterface;
use WP_Post;

class ParagraphConverter implements TagConverterInterface
{
    public static function tags(): array
    {
        return ['p'];
    }

    public function convert(
        SimpleHtmlDomInterface $element,
        ?WP_Post $post = null,
    ): Block|array|null {
        // Skip empty paragraphs
        if ($this->isEmpty($element)) {
            return null;
        }

        $attributes = [];
        $align = HtmlUtils::extractTextAlign($element->getAttribute('class'));

        if ($align !== null && $align !== 'left') {
            $attributes['align'] = $align;
        }

        return new Block('paragraph', $attributes, innerContent: [$element->html()]);
    }

    private function isEmpty(SimpleHtmlDomInterface $element): bool
    {
        return is_countable($element->childNodes())
            && \count($element->childNodes()) === 0
            && HtmlUtils::trim($element->plaintext) === '';
    }
}
