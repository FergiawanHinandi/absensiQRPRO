<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CircuitBreakerClosed
{
    use Dispatchable, SerializesModels;

    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}
