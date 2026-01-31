<?php

namespace App\Providers;

use App\Listeners\LogSuccessfulLogin;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        Login::class => [
            LogSuccessfulLogin::class,
        ],
        \App\Events\BadgeAwarded::class => [
            \App\Listeners\SendGamificationNotification::class,
        ],
        \App\Events\RewardEligibilityLost::class => [
            \App\Listeners\SendGamificationNotification::class,
        ],
        \App\Events\AcademicYearActivated::class => [
            \App\Listeners\ClearSchoolCache::class,
        ],
        \App\Events\StudentAttended::class => [
            \App\Listeners\InvalidateDashboardCache::class,
            \App\Listeners\AwardAttendancePoints::class,
        ],
        \App\Events\StudentUpdated::class => [
            \App\Listeners\InvalidateDashboardCache::class,
        ],
        \App\Events\ScheduleChanged::class => [
            \App\Listeners\InvalidateDashboardCache::class,
        ],
        \App\Events\AttendanceRecorded::class => [
            \App\Listeners\SendAttendanceNotification::class,
        ],
        \App\Events\AttendanceLate::class => [
            \App\Listeners\SendAttendanceNotification::class,
        ],
        \App\Events\AttendanceAbsent::class => [
            \App\Listeners\SendAttendanceNotification::class,
        ],
        \App\Events\RiskLevelUpdated::class => [
            \App\Listeners\SendRiskNotification::class,
        ],
        \App\Events\StreakAchieved::class => [
            \App\Listeners\SendStreakNotification::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
