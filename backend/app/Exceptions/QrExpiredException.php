<?php

namespace App\Exceptions;

use Exception;

class QrExpiredException extends Exception
{
    protected $message = 'QR code has expired';
}
