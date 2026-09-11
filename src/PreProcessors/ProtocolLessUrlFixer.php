<?php

declare(strict_types=1);

namespace n5s\BlockConverter\PreProcessors;

use WP_HTML_Tag_Processor;
use WP_Post;

/**
 * Fixes protocol-less URLs in HTML attributes using WordPress's set_url_scheme().
 *
 * Legacy content often has URLs like "//www.example.com/image.jpg"
 * which were valid when pages could be served over HTTP or HTTPS.
 * This processor normalizes them to use the site's configured scheme.
 *
 * @see https://developer.wordpress.org/reference/functions/set_url_scheme/
 */
class ProtocolLessUrlFixer implements PreProcessorInterface
{
    /** @var list<string> */
    private const array URL_ATTRIBUTES = ['src', 'href', 'poster', 'action'];

    public function priority(): int
    {
        return 3;
    }

    public function runsBeforeBlockParsing(): bool
    {
        return true;
    }

    public function process(string $html, ?WP_Post $post = null): string
    {
        if ($html === '' || !str_contains($html, '//')) {
            return $html;
        }

        $processor = new WP_HTML_Tag_Processor($html);
        $changed = false;

        while ($processor->next_tag()) {
            foreach (self::URL_ATTRIBUTES as $attr) {
                $value = $processor->get_attribute($attr);

                if (!\is_string($value) || !str_starts_with($value, '//')) {
                    continue;
                }

                $processor->set_attribute($attr, \set_url_scheme($value));
                $changed = true;
            }
        }

        return $changed ? $processor->get_updated_html() : $html;
    }
}
