<?php

namespace App\Helpers;

class SecurityHelper
{
    /**
     * Mask sensitive data except last 4 characters.
     *
     * @param string $value
     * @param int $visibleCount
     * @return string
     */
    public static function maskSecret(string $value, int $visibleCount = 4): string
    {
        $len = strlen($value);
        if ($len <= $visibleCount) {
            return $value; // Too short to mask
        }
        
        return str_repeat('*', $len - $visibleCount) . substr($value, -$visibleCount);
    }
}
