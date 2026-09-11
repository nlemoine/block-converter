<?php

declare(strict_types=1);

namespace n5s\BlockConverter;

/**
 * Basic implementation of {@see RegistryAwareInterface}.
 *
 * The registry stays null until registration, so a converter using this is
 * still constructible — and testable — on its own.
 */
trait RegistryAwareTrait
{
    protected ?ConverterRegistry $registry = null;

    // phpcs:ignore Syde.Classes.DisallowSetter.SetterFound -- the registry is wired at registration, not construction; same shape as PSR-3 LoggerAwareInterface.
    public function setRegistry(ConverterRegistry $registry): void
    {
        $this->registry = $registry;
    }
}
