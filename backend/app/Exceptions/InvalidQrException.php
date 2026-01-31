<?php

namespace App\Exceptions;

use Exception;

class InvalidQrException extends Exception
{
    protected $message = 'Invalid QR code';
}
