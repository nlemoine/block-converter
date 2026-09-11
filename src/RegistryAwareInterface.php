<?php

declare(strict_types=1);

namespace n5s\BlockConverter;

/**
 * Implemented by converters and processors that need to reach other converters.
 *
 * The registry wires itself in at registration, so nothing has to be threaded
 * through constructors. Shaped after PSR-3's LoggerAwareInterface, which this
 * library already uses for the logger.
 *
 * Declaring this is opt-in on purpose: a converter that does not ask for the
 * registry cannot reach sideways into the pipeline, and stays usable on its own.
 */
interface RegistryAwareInterface
{
    // phpcs:ignore Syde.Classes.DisallowSetter.SetterFound -- the registry is wired at registration, not construction; same shape as PSR-3 LoggerAwareInterface.
    public function setRegistry(ConverterRegistry $registry): void;
}
