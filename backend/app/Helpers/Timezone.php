<?php

namespace App\Helpers;

use App\Models\School;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;

class Timezone
{
    /**
     * Get current time based on School's timezone.
     * 
     * @param School $school
     * @return Carbon
     */
    public static function now(School $school): Carbon
    {
        $timezone = $school->timezone ?? Config::get('app.timezone', 'UTC');
        return Carbon::now($timezone);
    }

    /**
     * Get today's date string (Y-m-d) based on School's timezone.
     * Critical for attendance_date consistency.
     * 
     * @param School $school
     * @return string
     */
    public static function today(School $school): string
    {
        return self::now($school)->toDateString();
    }
}
