<?php

declare(strict_types=1);

namespace n5s\BlockConverter\TagConverters;

use n5s\BlockConverter\Block;
use n5s\BlockConverter\Support\HtmlUtils;
use voku\helper\SimpleHtmlDomInterface;
use voku\helper\SimpleHtmlDomNodeInterface;
use WP_HTML_Tag_Processor;
use WP_Post;

class TableConverter implements TagConverterInterface
{
    public static function tags(): array
    {
        return ['table'];
    }

    public function convert(
        SimpleHtmlDomInterface $element,
        ?WP_Post $post = null,
    ): Block|array|null {

        // html() is the outer markup, so it is never blank for a real element;
        // emptiness has to be judged on what the element contains.
        if (HtmlUtils::trim($element->innerHtml()) === '') {
            return null;
        }

        $this->stripStructuralWhitespace($element);

        $content = $element->html();

        $content = $this->ensureTbody($content, $element);
        $content = $this->addCellDataAlignAttributes($content);

        return new Block(
            blockName: 'table',
            attributes: ['className' => 'is-style-regular'],
            innerContent: [\sprintf('<figure class="wp-block-table is-style-regular">%s</figure>', $content)],
        );
    }

    /**
     * Drop the whitespace between a table's structural tags.
     *
     * wpautop() puts a newline around every <tr> and <td>, and whether the
     * parser then places that newline before or after the <tbody> it inserts
     * on its own depends on the libxml build: PHP 8.3 and 8.4 disagreed on
     * CI, and one wrapped the rows here instead. Text between table, section
     * and row tags is not content, and Gutenberg's own table block carries
     * none, so it goes, and the output reads the same on every build.
     */
    private function stripStructuralWhitespace(SimpleHtmlDomInterface $element): void
    {
        $node = $element->getNode();
        $document = $node->ownerDocument;

        if (!$document instanceof \DOMDocument) {
            return;
        }

        $xpath = new \DOMXPath($document);
        $blanks = $xpath->query('.//text()[normalize-space(.) = ""]', $node);

        if ($blanks === false) {
            return;
        }

        foreach ($blanks as $blank) {
            $parent = $blank->parentNode;

            if (
                $blank instanceof \DOMNode
                && $parent instanceof \DOMElement
                && \in_array(\strtolower($parent->tagName), ['table', 'thead', 'tbody', 'tfoot', 'tr'], true)
            ) {
                $parent->removeChild($blank);
            }
        }
    }

    /**
     * Wrap bare <tr> elements in <tbody> when no <thead>, <tbody>, or <tfoot> exists.
     *
     * Browsers implicitly insert <tbody>, but the HTML parser may not.
     * Gutenberg expects the explicit tag.
     *
     * The decision is made on the element's own children rather than on its
     * serialised markup: a nested table's <tbody> is in that string too, and
     * matching it left the outer table's rows unwrapped.
     */
    private function ensureTbody(string $html, SimpleHtmlDomInterface $element): string
    {
        if ($this->hasOwnSection($element)) {
            return $html;
        }

        // preg_replace() returns null only on failure, in which case the table
        // is better left as it came in than dropped.
        return \preg_replace(
            '/^(<table[^>]*>)(.*?)(<\/table>)$/is',
            '$1<tbody>$2</tbody>$3',
            $html,
        ) ?? $html;
    }

    /**
     * Add data-align attributes to table cells that have has-text-align-* classes.
     *
     * Gutenberg table blocks use data-align on <td>/<th> to indicate cell alignment.
     */
    private function addCellDataAlignAttributes(string $html): string
    {
        $processor = new WP_HTML_Tag_Processor($html);

        while ($processor->next_tag()) {
            $tag = \strtolower((string) $processor->get_tag());

            if ($tag !== 'td' && $tag !== 'th') {
                continue;
            }

            $class = $processor->get_attribute('class');

            if (!\is_string($class)) {
                continue;
            }

            if (\preg_match('/\bhas-text-align-(left|center|right|justify)\b/', $class, $m)) {
                $processor->set_attribute('data-align', $m[1]);
            }
        }

        return $processor->get_updated_html();
    }

    /**
     * Whether the table already carries a section of its own.
     */
    private function hasOwnSection(SimpleHtmlDomInterface $element): bool
    {
        $children = $element->childNodes();

        // @codeCoverageIgnoreStart
        // The parser always hands back a node list; the check only satisfies
        // the union in its signature.
        if (!$children instanceof SimpleHtmlDomNodeInterface) {
            return false;
        }
        // @codeCoverageIgnoreEnd

        foreach ($children as $child) {
            if (\in_array($child->getTag(), ['thead', 'tbody', 'tfoot'], true)) {
                return true;
            }
        }

        return false;
    }
}
