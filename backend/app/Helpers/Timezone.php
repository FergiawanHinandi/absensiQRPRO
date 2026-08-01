<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Models\School;
use Carbon\Carbon;

/**
 * Timezone class - Backward compatibility alias
 *
 * @deprecated Use TimezoneHelper instead
 */
class Timezone
{
    /**
     * Get current time based on School's timezone.
     */
    public static function now(School $school): Carbon
    {
        return TimezoneHelper::schoolNow($school);
    }

    /**
     * Get today's date string (Y-m-d) based on School's timezone.
     * Critical for attendance_date consistency.
     */
    public static function today(School $school): string
    {
        return TimezoneHelper::todayString($school);
    }
}
