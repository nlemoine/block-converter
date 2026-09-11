<?php

declare(strict_types=1);

namespace n5s\BlockConverter\TagConverters;

use n5s\BlockConverter\Block;
use voku\helper\SimpleHtmlDomInterface;
use WP_Post;

class ListItemConverter implements TagConverterInterface
{
    public static function tags(): array
    {
        return ['li'];
    }

    public function convert(
        SimpleHtmlDomInterface $element,
        ?WP_Post $post = null,
    ): Block|array|null {

        // An empty <li> is kept on purpose: it is a deliberate blank row in the
        // author's list, and dropping it would renumber an ordered list.
        $content = $element->html();

        // Nested lists inside <li> need container mode so the inner <ul>/<ol>
        // becomes a proper wp:list inner block instead of raw HTML.
        if ($element->findOneOrFalse('ul') || $element->findOneOrFalse('ol')) {
            $block = new Block('list-item', container: true);

            // Reopen the <li> with the attributes it came in with. Emitting a
            // bare <li> here dropped value/class/id, and losing value on an
            // ordered list renumbers everything after it.
            $block->appendContent($this->openingTag($element));

            return $block;
        }

        return new Block(
            blockName: 'list-item',
            attributes: [],
            innerContent: [$content],
        );
    }

    /**
     * The element's own opening tag, attributes included.
     *
     * Rebuilt from the parsed attributes rather than cut from the markup at
     * the first `>`: the default sanitizer encodes that character inside a
     * value, but the sanitizer is replaceable and this converter is usable
     * on its own, and `<li title="a>b">` cut mid-attribute is invalid HTML.
     */
    private function openingTag(SimpleHtmlDomInterface $element): string
    {
        $tag = '<li';

        foreach ($element->getAllAttributes() ?? [] as $name => $value) {
            $tag .= ' ' . $name . '="' . \esc_attr((string) $value) . '"';
        }

        return $tag . '>';
    }
}
