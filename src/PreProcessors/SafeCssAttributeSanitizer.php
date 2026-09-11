<?php

declare(strict_types=1);

namespace n5s\BlockConverter\PreProcessors;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Runs inline styles through WordPress' own CSS filter.
 *
 * $allowedposttags lists style as a global attribute, but WordPress never
 * honours that on its own: kses pairs it with safecss_filter_attr(), which
 * drops properties outside its allowlist and any value carrying a url() with a
 * script scheme. Mirroring the element and attribute lists without that filter
 * leaves the configuration strictly more permissive than the kses it is built
 * from — `background:url(javascript:alert(1))` and a full-viewport
 * `position:fixed;inset:0;z-index:2147483647` overlay both walk straight
 * through.
 *
 * That matters because the content this library converts is not assumed to come
 * from a WordPress database: ingesting arbitrary external HTML is a supported
 * use, and such input is untrusted by definition.
 */
final class SafeCssAttributeSanitizer implements AttributeSanitizerInterface
{
    /**
     * @return list<string>|null
     */
    public function getSupportedElements(): ?array
    {
        // style is a global attribute, so every element.
        return null;
    }

    /**
     * @return list<string>
     */
    public function getSupportedAttributes(): array
    {
        return ['style'];
    }

    public function sanitizeAttribute(
        string $element,
        string $attribute,
        string $value,
        HtmlSanitizerConfig $config,
    ): ?string {

        $safe = \safecss_filter_attr($value);

        // Nothing survived the filter: drop the attribute rather than leave an
        // empty one behind.
        return $safe === '' ? null : $safe;
    }
}
