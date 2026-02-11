<?php

namespace App\Domain\Shared;

/**
 * Base Command Interface
 * 
 * All commands in the CQRS architecture must implement this interface.
 * Commands represent intentions to change state in the system.
 */
interface Command
{
    /**
     * Validate the command data
     * 
     * @throws \InvalidArgumentException
     */
    public function validate(): void;
}
