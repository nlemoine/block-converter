<?php

declare(strict_types=1);

namespace n5s\BlockConverter\PreProcessors;

use WP_Post;

/**
 * Replaces deprecated HTML tags with their semantic equivalents.
 *
 * Legacy content often uses presentational tags like <b> and <i>
 * instead of their semantic counterparts <strong> and <em>.
 * Handles tags with or without attributes (e.g. <b class="x">).
 */
class DeprecatedTagReplacer implements PreProcessorInterface
{
    private const array DEFAULT_TAG_MAP = [
        'b' => 'strong',
        'i' => 'em',
    ];

    /**
     * @param array<string, string> $tagMap Tag rename map (deprecated → semantic). Defaults to b→strong, i→em.
     */
    public function __construct(
        private readonly array $tagMap = self::DEFAULT_TAG_MAP,
    ) {
    }

    public function priority(): int
    {
        return 4;
    }

    public function runsBeforeBlockParsing(): bool
    {
        return true;
    }

    public function process(string $html, ?WP_Post $post = null): string
    {
        if ($html === '') {
            return '';
        }

        foreach ($this->tagMap as $old => $new) {
            // Matches <b>, </b>, <b class="x">, </b >, <B>, etc.
            // The (\s|>) boundary prevents matching <br>, <blockquote>, etc.
            $html = (string) \preg_replace(
                '/<(\\/?)' . \preg_quote($old, '/') . '(\s|>)/i',
                '<$1' . $new . '$2',
                $html,
            );
        }

        return $html;
    }
}
