<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SystemDegradedModeEnabled
{
    use Dispatchable, SerializesModels;

    public string $reason;
    public array $context;

    public function __construct(string $reason, array $context = [])
    {
        $this->reason = $reason;
        $this->context = $context;
    }
}
