<?php

namespace App\Events;

use Throwable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CircuitBreakerOpened
{
    use Dispatchable, SerializesModels;

    public string $name;
    public Throwable $exception;
    public array $context;

    public function __construct(string $name, Throwable $exception, array $context = [])
    {
        $this->name = $name;
        $this->exception = $exception;
        $this->context = $context;
    }
}
