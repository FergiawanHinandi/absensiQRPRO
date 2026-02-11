<?php

namespace App\Domain\Shared;

/**
 * Base Command Handler Interface
 * 
 * All command handlers must implement this interface.
 * Handlers contain the business logic for executing commands.
 */
interface CommandHandler
{
    /**
     * Handle the command
     * 
     * @param Command $command
     * @return mixed
     */
    public function handle(Command $command);
}
