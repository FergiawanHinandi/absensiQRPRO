<?php

namespace App\Events;

use Throwable;
use App\Services\SelfHealing\FailureType;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AutoRemediationTriggered
{
    use Dispatchable, SerializesModels;

    public FailureType $failureType;
    public string $action;
    public Throwable $exception;
    public array $context;

    public function __construct(
        FailureType $failureType,
        string $action,
        Throwable $exception,
        array $context = []
    ) {
        $this->failureType = $failureType;
        $this->action = $action;
        $this->exception = $exception;
        $this->context = $context;
    }
}
