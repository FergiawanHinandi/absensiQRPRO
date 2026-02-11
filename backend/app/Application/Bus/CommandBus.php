<?php

declare(strict_types=1);

namespace App\Application\Bus;

use Illuminate\Contracts\Container\Container;
use Illuminate\Pipeline\Pipeline;
use RuntimeException;

class CommandBus implements CommandBusInterface
{
    /** @var array<class-string, class-string> command FQCN → handler FQCN */
    private array $handlers = [];

    /** @var array<class-string> Bus middleware classes */
    private array $middleware = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * Register a command handler.
     */
    public function register(string $commandClass, string $handlerClass): void
    {
        $this->handlers[$commandClass] = $handlerClass;
    }

    /**
     * Register multiple command → handler mappings.
     *
     * @param  array<class-string, class-string>  $map
     */
    public function map(array $map): void
    {
        foreach ($map as $command => $handler) {
            $this->register($command, $handler);
        }
    }

    /**
     * Add a middleware to the bus pipeline.
     */
    public function addMiddleware(string $middlewareClass): void
    {
        $this->middleware[] = $middlewareClass;
    }

    public function dispatch(object $command): mixed
    {
        $middlewareInstances = array_map(
            fn (string $class) => $this->container->make($class),
            $this->middleware,
        );

        return (new Pipeline($this->container))
            ->send($command)
            ->through($middlewareInstances)
            ->then(fn (object $cmd) => $this->executeHandler($cmd));
    }

    private function executeHandler(object $command): mixed
    {
        $commandClass = $command::class;

        if (! isset($this->handlers[$commandClass])) {
            throw new RuntimeException(
                "No handler registered for command [{$commandClass}]."
            );
        }

        $handler = $this->container->make($this->handlers[$commandClass]);

        if (! method_exists($handler, 'handle')) {
            throw new RuntimeException(
                "Handler [{$this->handlers[$commandClass]}] must implement a handle() method."
            );
        }

        return $handler->handle($command);
    }
}
