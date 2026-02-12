<?php

namespace App\Models;

/**
 * TeachingSession — Alias for Schedule
 *
 * Some controllers and tests reference App\Models\TeachingSession,
 * but the actual model uses the schedules table via the Schedule model.
 * This alias prevents "Class not found" runtime errors.
 *
 * @see \App\Models\Schedule
 */
class TeachingSession extends Schedule
{
    // Pure alias — all logic lives in Schedule
}
