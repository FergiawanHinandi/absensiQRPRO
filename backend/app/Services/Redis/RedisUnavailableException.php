<?php

namespace App\Services\Redis;

use RuntimeException;

/**
 * Exception thrown when Redis is unavailable after all retry attempts
 */
class RedisUnavailableException extends RuntimeException
{
    //
}
