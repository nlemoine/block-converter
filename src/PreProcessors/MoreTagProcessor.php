<?php

declare(strict_types=1);

namespace n5s\BlockConverter\PreProcessors;

use n5s\BlockConverter\Block;
use WP_Post;

/**
 * Turns the legacy <!--more--> and <!--nextpage--> markers into their blocks.
 *
 * Without this the markers are lost outright: the sanitizer strips HTML
 * comments, so the excerpt split point and the pagination breaks would
 * disappear silently rather than fall back to anything.
 *
 * Runs early so parse_blocks() sees real core/more and core/nextpage blocks.
 */
class MoreTagProcessor implements PreProcessorInterface
{
    /** Matches <!--more-->, <!--more Custom teaser-->, optionally followed by <!--noteaser-->. */
    private const string MORE_PATTERN = '/<!--more(?<text>[^>]*?)-->(?<noteaser>\s*<!--noteaser-->)?/i';

    private const string NEXTPAGE_PATTERN = '/<!--nextpage-->/i';

    /** A paragraph wrapping nothing but those markers — wpautop leaves these behind. */
    private const string WRAPPED_PATTERN = '/<p[^>]*>\s*((?:<!--(?:more[^>]*?|noteaser|nextpage)-->\s*)+)<\/p>/i';

    public function priority(): int
    {
        return 5;
    }

    public function runsBeforeBlockParsing(): bool
    {
        return true;
    }

    public function process(string $html, ?WP_Post $post = null): string
    {
        if (\stripos($html, '<!--more') === false && \stripos($html, '<!--nextpage-->') === false) {
            return $html;
        }

        // Lift the markers out of any paragraph that holds nothing else, so the
        // block markup does not end up nested inside a <p>.
        $html = (string) \preg_replace(self::WRAPPED_PATTERN, '$1', $html);

        $html = (string) \preg_replace_callback(
            self::MORE_PATTERN,
            static function (array $matches): string {
                $customText = \trim($matches['text']);
                $noTeaser = \trim($matches['noteaser'] ?? '') !== '';

                $attributes = [];

                if ($customText !== '') {
                    $attributes['customText'] = $customText;
                }

                if ($noTeaser) {
                    $attributes['noTeaser'] = true;
                }

                $marker = $customText === '' ? '<!--more-->' : \sprintf('<!--more %s-->', $customText);

                if ($noTeaser) {
                    $marker .= "\n<!--noteaser-->";
                }

                return (new Block('more', $attributes, innerContent: [$marker]))->render();
            },
            $html,
        );

        return (string) \preg_replace_callback(
            self::NEXTPAGE_PATTERN,
            static fn (): string => (new Block('nextpage', innerContent: ['<!--nextpage-->']))->render(),
            $html,
        );
    }
}
