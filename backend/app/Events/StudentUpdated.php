<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StudentUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public $student,
        public $schoolId
    ) {}
}
