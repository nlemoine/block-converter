<?php

declare(strict_types=1);

namespace n5s\BlockConverter;

use n5s\BlockConverter\PostProcessors\PostProcessorInterface;
use n5s\BlockConverter\PreProcessors\PreProcessorInterface;
use n5s\BlockConverter\ShortcodeConverters\ShortcodeConverterInterface;
use n5s\BlockConverter\TagConverters\TagConverterInterface;

class ConverterRegistry
{
    /** @var array<string, TagConverterInterface> */
    private array $tagConverters = [];

    /** @var array<string, ?ShortcodeConverterInterface> */
    private array $shortcodeConverters = [];

    /** @var PreProcessorInterface[] */
    private array $preProcessors = [];

    /**
     * The two lists getEarlyPreProcessors() and getPreProcessors() hand out, keyed by (int) $early.
     *
     * @var array<int, PreProcessorInterface[]>
     */
    private array $sortedPreProcessors = [];

    /** @var PostProcessorInterface[] */
    private array $postProcessors = [];

    public function registerTagConverter(TagConverterInterface $converter): void
    {
        $this->shareSelfWith($converter);

        foreach ($converter::tags() as $tag) {
            $this->tagConverters[\strtolower($tag)] = $converter;
        }
    }

    public function getTagConverter(string $tag): ?TagConverterInterface
    {
        return $this->tagConverters[\strtolower($tag)] ?? null;
    }

    public function removeTagConverter(string $tag): void
    {
        $tag = \strtolower($tag);

        if (!isset($this->tagConverters[$tag])) {
            throw new \InvalidArgumentException(
                \sprintf('No tag converter registered for "%s".', $tag),
            );
        }

        unset($this->tagConverters[$tag]);
    }

    public function registerShortcodeConverter(ShortcodeConverterInterface $converter): void
    {
        $this->shareSelfWith($converter);

        foreach ($converter::shortcodes() as $shortcode) {
            $this->shortcodeConverters[$shortcode] = $converter;
        }
    }

    /**
     * Register a shortcode tag to be wrapped in a wp:shortcode fallback block.
     *
     * Use this for orphaned shortcodes (from deactivated plugins) that should
     * be preserved as Gutenberg shortcode blocks during migration.
     */
    public function registerShortcodeTag(string $tag): void
    {
        // Don't override an existing converter.
        $this->shortcodeConverters[$tag] ??= null;
    }

    public function removeShortcodeConverter(string $shortcode): void
    {
        if (!\array_key_exists($shortcode, $this->shortcodeConverters)) {
            throw new \InvalidArgumentException(
                \sprintf('No shortcode converter registered for "%s".', $shortcode),
            );
        }

        unset($this->shortcodeConverters[$shortcode]);
    }

    /**
     * @return array<string, ?ShortcodeConverterInterface>
     */
    public function getShortcodeConverters(): array
    {
        return $this->shortcodeConverters;
    }

    public function registerPreProcessor(PreProcessorInterface $processor): void
    {
        $this->shareSelfWith($processor);

        $this->preProcessors[] = $processor;
        $this->sortedPreProcessors = [];
    }

    /**
     * @param class-string<PreProcessorInterface> $className
     */
    public function removePreProcessor(string $className): void
    {
        $found = false;
        $this->sortedPreProcessors = [];

        $this->preProcessors = \array_values(\array_filter(
            $this->preProcessors,
            static function (PreProcessorInterface $p) use ($className, &$found): bool {
                if ($p instanceof $className) {
                    $found = true;
                    return false;
                }
                return true;
            },
        ));

        if (!$found) {
            throw new \InvalidArgumentException(
                \sprintf('No pre-processor of type "%s" is registered.', $className),
            );
        }
    }

    /**
     * @param class-string<PreProcessorInterface> $className
     */
    public function replacePreProcessor(string $className, PreProcessorInterface $replacement): void
    {
        $found = false;
        $this->sortedPreProcessors = [];

        $this->preProcessors = \array_map(
            static function (PreProcessorInterface $p) use ($className, $replacement, &$found): PreProcessorInterface {
                if ($p instanceof $className) {
                    $found = true;
                    return $replacement;
                }
                return $p;
            },
            $this->preProcessors,
        );

        if (!$found) {
            throw new \InvalidArgumentException(
                \sprintf('No pre-processor of type "%s" is registered.', $className),
            );
        }
    }

    /**
     * Pre-processors that run on full post content before parse_blocks().
     *
     * @return PreProcessorInterface[]
     */
    public function getEarlyPreProcessors(): array
    {
        return $this->sortedPreProcessors(true);
    }

    /**
     * Pre-processors that run on raw HTML fragments after parse_blocks().
     *
     * @return PreProcessorInterface[]
     */
    public function getPreProcessors(): array
    {
        return $this->sortedPreProcessors(false);
    }

    /**
     * Filtered and sorted once per registration change rather than per call:
     * the converter asks for both lists once per segment of every post.
     *
     * @return PreProcessorInterface[]
     */
    private function sortedPreProcessors(bool $early): array
    {
        if (isset($this->sortedPreProcessors[(int) $early])) {
            return $this->sortedPreProcessors[(int) $early];
        }

        $processors = \array_filter(
            $this->preProcessors,
            static fn (PreProcessorInterface $p): bool => $p->runsBeforeBlockParsing() === $early,
        );
        \usort(
            $processors,
            static fn (PreProcessorInterface $a, PreProcessorInterface $b): int => $a->priority() <=> $b->priority(),
        );

        return $this->sortedPreProcessors[(int) $early] = $processors;
    }

    public function registerPostProcessor(PostProcessorInterface $processor): void
    {
        $this->shareSelfWith($processor);

        $this->postProcessors[] = $processor;
    }

    /**
     * @param class-string<PostProcessorInterface> $className
     */
    public function removePostProcessor(string $className): void
    {
        $found = false;

        $this->postProcessors = \array_values(\array_filter(
            $this->postProcessors,
            static function (PostProcessorInterface $p) use ($className, &$found): bool {
                if ($p instanceof $className) {
                    $found = true;
                    return false;
                }
                return true;
            },
        ));

        if (!$found) {
            throw new \InvalidArgumentException(
                \sprintf('No post-processor of type "%s" is registered.', $className),
            );
        }
    }

    /**
     * @param class-string<PostProcessorInterface> $className
     */
    public function replacePostProcessor(string $className, PostProcessorInterface $replacement): void
    {
        $found = false;

        $this->postProcessors = \array_map(
            static function (PostProcessorInterface $p) use ($className, $replacement, &$found): PostProcessorInterface {
                if ($p instanceof $className) {
                    $found = true;
                    return $replacement;
                }
                return $p;
            },
            $this->postProcessors,
        );

        if (!$found) {
            throw new \InvalidArgumentException(
                \sprintf('No post-processor of type "%s" is registered.', $className),
            );
        }
    }

    /**
     * @return PostProcessorInterface[]
     */
    public function getPostProcessors(): array
    {
        $processors = $this->postProcessors;
        \usort(
            $processors,
            static fn (PostProcessorInterface $a, PostProcessorInterface $b): int => $a->priority() <=> $b->priority(),
        );

        return $processors;
    }

    /**
     * Hand the registry to anything that asked for it.
     *
     * Converters that need to reach other converters — FigureConverter and
     * LinkConverter both delegate images — get it here rather than through a
     * constructor, so registering an override is enough for them to see it.
     */
    private function shareSelfWith(object $candidate): void
    {
        if ($candidate instanceof RegistryAwareInterface) {
            $candidate->setRegistry($this);
        }
    }
}
