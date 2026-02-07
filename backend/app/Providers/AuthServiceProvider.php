<?php

namespace App\Providers;

use App\Models\Attendance;
use App\Models\AttendanceReport;
use App\Models\ClassModel;
use App\Models\QrCode;
use App\Models\Schedule;
use App\Models\School;
use App\Models\StudentPermission;
use App\Models\User;
use App\Policies\AttendancePolicy;
use App\Policies\ClassPolicy;
use App\Policies\QrCodePolicy;
use App\Policies\ReportPolicy;
use App\Policies\SchedulePolicy;
use App\Policies\SchoolPolicy;
use App\Policies\StudentCardPolicy;
use App\Policies\StudentPermissionPolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

/**
 * AuthServiceProvider - Authorization Configuration
 *
 * MULTI-TENANT SECURITY MODEL:
 * - Every model policy enforces school_id isolation
 * - Super admin can bypass all restrictions
 * - Role hierarchy determines access levels
 *
 * POLICY METHODS AVAILABLE:
 * - viewAny: Can list resources
 * - view: Can view specific resource
 * - create: Can create new resource
 * - update: Can modify existing resource
 * - delete: Can soft-delete resource
 * - restore: Can restore soft-deleted resource
 * - forceDelete: Can permanently delete (usually denied)
 *
 * ATTENDANCE-SPECIFIC METHODS:
 * - scan: Student QR scan
 * - scanStudent: Teacher scanning student
 * - createManual: Manual attendance input
 * - viewReports: Access to reports
 * - export: Data export permission
 * - viewBySchedule: View attendance for a schedule
 */
class AuthServiceProvider extends ServiceProvider
{
    /**
     * Policy mappings for multi-tenant protection
     *
     * These policies act as the LAST LINE OF DEFENSE against cross-school access,
     * even if global scopes are bypassed or raw queries are used.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        User::class => UserPolicy::class,
        Attendance::class => AttendancePolicy::class,
        ClassModel::class => ClassPolicy::class,
        Schedule::class => SchedulePolicy::class,
        AttendanceReport::class => ReportPolicy::class,
        QrCode::class => QrCodePolicy::class,
        \App\Models\StudentCard::class => StudentCardPolicy::class,
        // CRITICAL: School Policy - Controls Unit Admin vs Global Admin
        School::class => SchoolPolicy::class,
        // CRITICAL: Student Permission Policy - Leave/Izin Management
        StudentPermission::class => StudentPermissionPolicy::class,
        // CRITICAL: Student Card Policy - Only School Admin Access
        'student-card' => StudentCardPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        // Register policies
        $this->registerPolicies();

        // Define additional gates
        $this->defineGates();

        // Register super admin bypass
        $this->registerSuperAdminBypass();
    }

    /**
     * Define custom gates for non-model abilities
     */
    private function defineGates(): void
    {
        // Access school dashboard
        Gate::define('access-dashboard', function (User $user) {
            return in_array($user->role_type, [
                'teacher',
                'homeroom_teacher',
                'admin',
                'school_admin',
                'principal',
                'super_admin',
            ]);
        });

        // Access real-time monitoring
        Gate::define('access-realtime-monitor', function (User $user) {
            return in_array($user->role_type, [
                'teacher',
                'homeroom_teacher',
                'admin',
                'school_admin',
                'principal',
                'super_admin',
            ]);
        });

        // Access school settings
        Gate::define('manage-school-settings', function (User $user) {
            return in_array($user->role_type, [
                'admin',
                'school_admin',
                'super_admin',
            ]);
        });

        // Access security logs
        Gate::define('view-security-logs', function (User $user) {
            return in_array($user->role_type, [
                'admin',
                'school_admin',
                'super_admin',
            ]);
        });

        // Generate QR codes
        Gate::define('generate-qr-codes', function (User $user) {
            return in_array($user->role_type, [
                'teacher',
                'homeroom_teacher',
                'admin',
                'school_admin',
            ]);
        });

        // Bulk operations
        Gate::define('perform-bulk-operations', function (User $user) {
            return in_array($user->role_type, [
                'admin',
                'school_admin',
                'super_admin',
            ]);
        });
    }

    /**
     * Register super admin gate bypass
     *
     * Super admins can do everything (before all checks)
     */
    private function registerSuperAdminBypass(): void
    {
        Gate::before(function (User $user, string $ability) {
            // Super admin bypass
            if ($user->role_type === 'super_admin') {
                return true;
            }

            // Check via Spatie roles if available
            if (method_exists($user, 'hasRole') && $user->hasRole('super_admin')) {
                return true;
            }

            return null; // Fall through to specific check
        });
    }
}
