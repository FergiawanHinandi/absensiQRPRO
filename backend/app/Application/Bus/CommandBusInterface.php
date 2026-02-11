<?php

declare(strict_types=1);

namespace App\Application\Bus;

interface CommandBusInterface
{
    /**
     * Dispatch a command to its handler.
     *
     * @param  object  $command  The command DTO to dispatch
     * @return mixed The result from the handler
     */
    public function dispatch(object $command): mixed;
}
