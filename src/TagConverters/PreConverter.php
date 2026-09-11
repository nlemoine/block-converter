<?php

declare(strict_types=1);

namespace n5s\BlockConverter\TagConverters;

use n5s\BlockConverter\Block;
use voku\helper\SimpleHtmlDomInterface;
use WP_Post;

class PreConverter implements TagConverterInterface
{
    public static function tags(): array
    {
        return ['pre'];
    }

    public function convert(
        SimpleHtmlDomInterface $element,
        ?WP_Post $post = null,
    ): Block|array|null {
        // Real <pre> tag
        // Bare name, like every other converter and Block::fromParsed(): a
        // post-processor filtering on blockName must see one spelling.
        return new Block('preformatted', innerContent: [(string) $element]);
    }
}
