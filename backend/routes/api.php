<?php

use App\Http\Controllers\Admin\SecurityPolicyController;
use App\Http\Controllers\Api\V1\AdminDashboardController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\QrCodeController;
use App\Http\Controllers\Api\V1\ScheduleController;
use App\Http\Controllers\Api\V1\SchoolAdmin\ClassController;
use App\Http\Controllers\Api\V1\SchoolAdmin\ReportController;
use App\Http\Controllers\Api\V1\SchoolAdmin\ScheduleController as AdminScheduleController;
use App\Http\Controllers\Api\V1\SchoolAdmin\SchoolController;
use App\Http\Controllers\Api\V1\SchoolAdmin\StudentController;
use App\Http\Controllers\Api\V1\SchoolAdmin\TeacherController;
use App\Http\Controllers\Api\V1\SchoolAdmin\SubjectController;
use App\Http\Controllers\Api\V1\SchoolAdmin\TeacherSubjectController;
use App\Http\Controllers\Api\V1\SchoolAdmin\AttendanceSettingsController;
use App\Http\Controllers\Api\V1\Student\StudentDashboardController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - MVP MINIMAL
|--------------------------------------------------------------------------
|
| Middleware Stack:
| - auth:sanctum (authentication)
| - role:xxx (authorization)
| - throttle:x,y (rate limiting)
|
*/

// ========================================
// BROADCASTING ROUTES (Sanctum Auth)
// ========================================
Broadcast::routes(["middleware" => ["auth:sanctum"]]);
require base_path("routes/channels.php");

// ========================================
// PUBLIC ROUTES
// ========================================
Route::prefix("v1")
    ->middleware("rate.limit:global")
    ->group(function () {
        // Health check endpoint - CRITICAL for production monitoring
        Route::get("/health", [
            App\Http\Controllers\Api\V1\HealthController::class,
            "check",
        ]);

        // Test route
        Route::get("/test", function () {
            return response()->json([
                "message" => "API is working!",
                "timestamp" => now(),
            ]);
        });

        // Test routes for rate limiting (only in testing/local environment)
        // These routes have NO business logic, policies, or validation - pure rate limit testing
        if (app()->environment(["testing", "local"])) {
            // Test scan rate limit (10 per minute per school)
            Route::middleware(["auth:sanctum", "school.rate.limit:10,1"])->get(
                "/test/rate-limit/scan",
                function () {
                    return response()->json(["ok" => true]);
                },
            );

            // Test API rate limit (60 per minute per school)
            Route::middleware(["auth:sanctum", "school.rate.limit:60,1"])->get(
                "/test/rate-limit/api",
                function () {
                    return response()->json(["ok" => true]);
                },
            );

            // Test custom rate limit (5 per minute for specific testing)
            Route::middleware(["auth:sanctum", "school.rate.limit:5,1"])->post(
                "/test/rate-limit",
                function () {
                    return response()->json([
                        "success" => true,
                        "message" => "Request processed",
                    ]);
                },
            );
        }

        // Login dengan brute force protection
        Route::post("/auth/login", [
            AuthController::class,
            "login",
        ]); // ->middleware("rate.limit:login"); // 5 attempts per 5 minutes per IP

        // Test login tanpa middleware
        Route::post("/auth/login-test", [
            AuthController::class,
            "login",
        ]);

        // Webhook with scan rate limit
        Route::post("/webhooks/payment", [
            App\Http\Controllers\Api\V1\WebhookController::class,
            "handlePayment",
        ])->middleware("rate.limit:api");
    });

// ========================================
// PROTECTED ROUTES (auth:sanctum required)
// ========================================
Route::prefix("v1")
    ->middleware([
        "auth:sanctum",
        \App\Http\Middleware\CheckApiMaintenance::class,
        "rate.limit:api", // 60 requests per minute per user
        "session.anomaly",
    ])
    ->group(function () {
        // ========================================
        // SCHOOL ADMIN MANAGEMENT ROUTES
        // ========================================
        Route::prefix("admin")
            ->middleware(["role:school_admin"])
            ->group(function () {
                // 1. Subject Management
                Route::apiResource('subjects', SubjectController::class);

                // 2. Teacher-Subject Assignment
                Route::get('teacher-subjects', [TeacherSubjectController::class, 'index']);
                Route::post('teacher-subjects', [TeacherSubjectController::class, 'store']);
                Route::delete('teacher-subjects/{id}', [TeacherSubjectController::class, 'destroy']);

                // 3. Class Schedule Management
                Route::apiResource('schedules', AdminScheduleController::class);
                Route::post('schedules/import', [AdminScheduleController::class, 'import']);
                
                // 5. Schedule View Per Class
                Route::get('classes/{classId}/weekly-schedule', [AdminScheduleController::class, 'getWeeklyScheduleByClass']);
                
                // 6. Schedule View Per Teacher
                Route::get('teachers/{teacherId}/weekly-schedule', [AdminScheduleController::class, 'getWeeklyScheduleByTeacher']);

                // 7. Attendance Window Settings
                Route::get('attendance-settings', [AttendanceSettingsController::class, 'index']);
                Route::post('attendance-settings', [AttendanceSettingsController::class, 'update']);
            });

        // ========================================
        // AUTH ENDPOINTS (All authenticated users)
        // ========================================
        Route::get("/auth/me", [AuthController::class, "me"]);
        Route::post("/auth/logout", [AuthController::class, "logout"]);
        Route::post("/auth/refresh", [AuthController::class, "refresh"]);

        // ========================================
        // NOTIFICATIONS
        // ========================================
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/{id}/mark-read', [NotificationController::class, 'markRead']);

        // Session Management (All authenticated users)
        Route::get("/auth/sessions", [
            App\Http\Controllers\Api\V1\Admin\ActiveSessionsController::class,
            "index",
        ]);
        Route::delete("/auth/sessions/{sessionId}", [
            App\Http\Controllers\Api\V1\Admin\ActiveSessionsController::class,
            "destroy",
        ]);
        Route::post("/auth/sessions/revoke-others", [
            App\Http\Controllers\Api\V1\Admin\ActiveSessionsController::class,
            "revokeOthers",
        ]);

        // ========================================
        // MOBILE SECURITY (Event reporting from mobile apps)
        // ========================================
        Route::prefix("security")->group(function () {
            Route::post("/mobile-event", [
                App\Http\Controllers\Api\V1\MobileSecurityController::class,
                "reportEvent",
            ]);
            Route::post("/mobile-events/batch", [
                App\Http\Controllers\Api\V1\MobileSecurityController::class,
                "reportBatch",
            ]);
            Route::post("/device-integrity", [
                App\Http\Controllers\Api\V1\MobileSecurityController::class,
                "reportDeviceIntegrity",
            ]);
        });

        // System monitoring endpoints (All authenticated users)
        Route::get("/system/queue-status", [
            App\Http\Controllers\Api\V1\QueueHealthController::class,
            "status",
        ]);

        // Broadcasts (Announcements for all users)
        Route::get("/broadcasts", [
            \App\Http\Controllers\Api\V1\SuperAdmin\AnnouncementController::class,
            "getActive",
        ]);

        // ========================================
        // STUDENT ROUTES
        // ========================================
        Route::middleware("role:student")
            ->prefix("student")
            ->group(function () {
                // 1. Main Dashboard Overview
                Route::get("/dashboard", [
                    StudentDashboardController::class,
                    "index",
                ]);

                // 2. Attendance History
                Route::get("/attendance-history", [
                    StudentDashboardController::class,
                    "history",
                ]);

                // 3. My Schedule Today
                Route::get("/today-schedule", [
                    StudentDashboardController::class,
                    "getTodayTimeline",
                ]);

                // 4. Monthly Summary
                Route::get("/monthly-summary", [
                    StudentDashboardController::class,
                    "getMonthlySummary",
                ]);

                // 7. Profile Summary
                Route::get("/profile", [
                    StudentDashboardController::class,
                    "getProfileSummary",
                ]);

                // 8. Notification Feed
                Route::get("/notifications", [
                    StudentDashboardController::class,
                    "notifications",
                ]);

                // Extra: Gamification
                Route::get("/gamification", [
                    StudentDashboardController::class,
                    "getGamificationStats",
                ]);

                // Extra: Claim Reward
                Route::post("/claim-reward", [
                    StudentDashboardController::class,
                    "claimRewardCertificate",
                ]);
            });

        // ========================================
        // PARENT ROUTES
        // ========================================
        Route::middleware("role:parent")
            ->prefix("parent")
            ->group(function () {
                Route::get("/my-children", [
                    App\Http\Controllers\Api\V1\Parent\ParentDashboardController::class,
                    "index",
                ])->middleware("ability:parent:view_children");
                Route::get("/children/{id}/dashboard", [
                    App\Http\Controllers\Api\V1\Parent\ParentDashboardController::class,
                    "getDashboardStats",
                ])->middleware("ability:parent:view_attendance");

                Route::get("/children/{id}/attendance", [
                    App\Http\Controllers\Api\V1\Parent\ParentDashboardController::class,
                    "childAttendance",
                ])->middleware("ability:parent:view_attendance");

                Route::get("/children/{id}/late-analysis", [
                    App\Http\Controllers\Api\V1\Parent\ParentDashboardController::class,
                    "getLateAnalysis",
                ])->middleware("ability:parent:view_attendance");

                Route::get("/children/{id}/alerts", [
                    App\Http\Controllers\Api\V1\Parent\ParentDashboardController::class,
                    "getAbsenceAlerts",
                ])->middleware("ability:parent:view_attendance");

                Route::get("/children/{id}/timeline", [
                    App\Http\Controllers\Api\V1\Parent\ParentDashboardController::class,
                    "getTodayTimeline",
                ])->middleware("ability:parent:view_attendance");

                Route::get("/children/{id}/reports/monthly", [
                    App\Http\Controllers\Api\V1\Parent\ParentDashboardController::class,
                    "getMonthlyReport",
                ])->middleware("ability:parent:view_attendance");

                Route::get("/children/{id}/notifications", [
                    App\Http\Controllers\Api\V1\Parent\ParentDashboardController::class,
                    "getNotificationFeed",
                ])->middleware("ability:parent:view_attendance");

                Route::get("/children/{id}/profile", [
                    App\Http\Controllers\Api\V1\Parent\ParentDashboardController::class,
                    "getStudentProfile",
                ])->middleware("ability:parent:view_attendance");
            });

        // ========================================
        // TEACHER ROUTES
        // ========================================
        Route::middleware("role:teacher")
            ->prefix("teacher")
            ->group(function () {
                // Dashboard Stats
                Route::get("/dashboard", [
                    App\Http\Controllers\Api\V1\Teacher\TeacherDashboardController::class,
                    "index",
                ])->middleware("ability:teacher:view_dashboard");
                Route::get("/dashboard", [
                    App\Http\Controllers\Api\V1\Teacher\TeacherDashboardController::class,
                    "index",
                ])->middleware("ability:teacher:view_dashboard");

                Route::get("/teaching/dashboard", [
                    App\Http\Controllers\Api\V1\Teacher\TeacherDashboardController::class,
                    "getSubjectDashboard",
                ])->middleware("ability:teacher:view_dashboard");

                Route::get("/teaching/monitoring", [
                    App\Http\Controllers\Api\V1\Teacher\TeacherDashboardController::class,
                    "getSessionMonitoring",
                ])->middleware("ability:teacher:view_dashboard");

                Route::get("/teaching/schedules/{id}/students", [
                    App\Http\Controllers\Api\V1\Teacher\TeacherDashboardController::class,
                    "getSessionStudents",
                ])->middleware("ability:teacher:view_dashboard");

                Route::get("/teaching/classes/{id}/behavior", [
                    App\Http\Controllers\Api\V1\Teacher\TeacherDashboardController::class,
                    "getClassBehaviorAnalysis",
                ])->middleware("ability:teacher:view_dashboard");

                Route::get("/teaching/classes/{id}/trend", [
                    App\Http\Controllers\Api\V1\Teacher\TeacherDashboardController::class,
                    "getClassAttendanceTrend",
                ])->middleware("ability:teacher:view_dashboard");

                Route::get("/teaching/activity-feed", [
                    App\Http\Controllers\Api\V1\Teacher\TeacherDashboardController::class,
                    "getSubjectActivityFeed",
                ])->middleware("ability:teacher:view_dashboard");

                Route::post("/teaching/attendance/manual", [
                    App\Http\Controllers\Api\V1\Teacher\TeacherDashboardController::class,
                    "storeManualAttendance",
                ])->middleware("ability:teacher:view_dashboard");

                Route::get("/teaching/performance", [
                    App\Http\Controllers\Api\V1\Teacher\TeacherDashboardController::class,
                    "getTeachingPerformance",
                ])->middleware("ability:teacher:view_dashboard");

                Route::get("/teaching/timeline", [
                    App\Http\Controllers\Api\V1\Teacher\TeacherDashboardController::class,
                    "getTeachingTimeline",
                ])->middleware("ability:teacher:view_dashboard");

                Route::get("/teaching/students/{studentId}", [
                    App\Http\Controllers\Api\V1\Teacher\TeacherDashboardController::class,
                    "getStudentSubjectDetail",
                ])->middleware("ability:teacher:view_dashboard");

                Route::get("/homeroom/summary", [
                    App\Http\Controllers\Api\V1\Teacher\TeacherDashboardController::class,
                    "homeroomSummary",
                ])->middleware(
                    "ability:homeroom:view_summary,teacher:view_dashboard",
                );
                Route::get("/homeroom/alerts", [
                    App\Http\Controllers\Api\V1\Teacher\TeacherDashboardController::class,
                    "getAlerts",
                ])->middleware("ability:homeroom:view_summary,teacher:view_dashboard");
                Route::get("/homeroom/students/{studentId}/risk", [
                    App\Http\Controllers\Api\V1\Teacher\TeacherDashboardController::class,
                    "getStudentRiskDetail",
                ])->middleware("ability:homeroom:view_summary,teacher:view_dashboard");

                Route::get("/risk-dashboard/student/{studentId}/arrival", [
                    App\Http\Controllers\Api\V1\RiskDashboardController::class,
                    "getStudentArrivalAnalytics",
                ])->middleware("ability:homeroom:view_summary,teacher:view_dashboard,school:view_dashboard");

                Route::get("/risk-dashboard/student/{studentId}/time-patterns", [
                    App\Http\Controllers\Api\V1\RiskDashboardController::class,
                    "getTimeBasedRiskAnalytics",
                ])->middleware("ability:homeroom:view_summary,teacher:view_dashboard,school:view_dashboard");

                Route::get("/risk-dashboard/student/{studentId}/behavior-changes", [
                    App\Http\Controllers\Api\V1\RiskDashboardController::class,
                    "getBehaviorChangeAnalytics",
                ])->middleware("ability:homeroom:view_summary,teacher:view_dashboard,school:view_dashboard");

                Route::get("/risk-dashboard/student/{studentId}/late-prediction", [
                    App\Http\Controllers\Api\V1\RiskDashboardController::class,
                    "getLateArrivalPrediction",
                ])->middleware("ability:homeroom:view_summary,teacher:view_dashboard,school:view_dashboard");

                Route::get("/risk-dashboard/student/{studentId}/behavior-summary", [
                    App\Http\Controllers\Api\V1\RiskDashboardController::class,
                    "getStudentBehaviorSummary",
                ])->middleware("ability:homeroom:view_summary,teacher:view_dashboard,school:view_dashboard");

                Route::get("/risk-dashboard/school-analytics", [
                    App\Http\Controllers\Api\V1\RiskDashboardController::class,
                    "getSchoolWideAnalytics",
                ])->middleware("ability:school:view_dashboard,report:view");

                Route::get("/homeroom/reward-candidates", [
                    App\Http\Controllers\Api\V1\Teacher\TeacherDashboardController::class,
                    "getRewardCandidates",
                ])->middleware("ability:homeroom:view_summary,teacher:view_dashboard");

                Route::get("/homeroom/timeline", [
                    App\Http\Controllers\Api\V1\Teacher\TeacherDashboardController::class,
                    "getClassTimeline",
                ])->middleware("ability:homeroom:view_summary,teacher:view_dashboard");

                Route::get("/homeroom/analytics", [
                    App\Http\Controllers\Api\V1\Teacher\TeacherDashboardController::class,
                    "getMonthlyAnalytics",
                ])->middleware("ability:homeroom:view_summary,teacher:view_dashboard");
                
                Route::get("/homeroom/parents", [
                    App\Http\Controllers\Api\V1\Teacher\TeacherDashboardController::class,
                    "getParentContactList",
                ])->middleware("ability:homeroom:view_summary,teacher:view_dashboard");

                Route::get("/homeroom/corrections", [
                    App\Http\Controllers\Api\V1\Teacher\TeacherDashboardController::class,
                    "getCorrectionList",
                ])->middleware("ability:homeroom:view_summary,teacher:view_dashboard");

                Route::get("/homeroom/health-score", [
                    App\Http\Controllers\Api\V1\Teacher\TeacherDashboardController::class,
                    "getClassHealth",
                ])->middleware("ability:homeroom:view_summary,teacher:view_dashboard");

                Route::get("/homeroom/students/{studentId}", [
                    App\Http\Controllers\Api\V1\Teacher\TeacherDashboardController::class,
                    "getStudentDetail",
                ])->middleware("ability:homeroom:view_summary,teacher:view_dashboard");

                Route::get("/homeroom/activity-feed", [
                    App\Http\Controllers\Api\V1\Teacher\TeacherDashboardController::class,
                    "getActivityFeed",
                ])->middleware("ability:homeroom:view_summary,teacher:view_dashboard");
                
                Route::get("/my-students", [
                    App\Http\Controllers\Api\V1\Teacher\TeacherDashboardController::class,
                    "myStudents",
                ])->middleware("ability:teacher:view_students");

                // Teacher Profile (with role info)
                Route::get("/profile", [
                    App\Http\Controllers\Api\V1\TeacherProfileController::class,
                    "show",
                ])->middleware("ability:teacher:view_profile");

                // Schedules
                Route::get("/schedules/today", [
                    ScheduleController::class,
                    "today",
                ])->middleware("ability:teacher:view_schedules");

                // Reports Export (RATE LIMITED - 20/minute per school)
                Route::get("/reports/export-excel", [
                    App\Http\Controllers\Api\V1\ReportExportController::class,
                    "exportExcel",
                ])->middleware([
                    "ability:report:export",
                    "school.rate.limit:20,1",
                ]);
                Route::get("/reports/export-pdf", [
                    App\Http\Controllers\Api\V1\ReportExportController::class,
                    "exportPdf",
                ])->middleware([
                    "ability:report:export",
                    "school.rate.limit:20,1",
                ]);
                Route::get("/reports/monthly-summary", [
                    App\Http\Controllers\Api\V1\ReportExportController::class,
                    "monthlySummary",
                ])->middleware([
                    "ability:report:export",
                    "school.rate.limit:20,1",
                ]);

                // Teacher Attendance (Self Check-in/Check-out)
                Route::prefix("attendance")->group(function () {
                    Route::post("/check-in", [
                        App\Http\Controllers\Api\V1\Teacher\TeacherAttendanceController::class,
                        "checkIn",
                    ])->middleware("school.rate.limit:10,1");
                    Route::post("/check-out", [
                        App\Http\Controllers\Api\V1\Teacher\TeacherAttendanceController::class,
                        "checkOut",
                    ])->middleware("school.rate.limit:10,1");
                    Route::get("/today", [
                        App\Http\Controllers\Api\V1\Teacher\TeacherAttendanceController::class,
                        "today",
                    ]);
                    Route::get("/history", [
                        App\Http\Controllers\Api\V1\Teacher\TeacherAttendanceController::class,
                        "history",
                    ]);
                    Route::get("/summary", [
                        App\Http\Controllers\Api\V1\Teacher\TeacherAttendanceController::class,
                        "summary",
                    ]);
                    Route::get("/devices", [
                        App\Http\Controllers\Api\V1\Teacher\TeacherAttendanceController::class,
                        "devices",
                    ]);
                });
            });

        // ========================================
        // QR CODE ROUTES (Teacher only)
        // ========================================
        Route::middleware("role:teacher,homeroom_teacher")
            ->prefix("qr")
            ->group(function () {
                Route::post("/generate", [
                    QrCodeController::class,
                    "generate",
                ])->middleware(["ability:qr:generate", "throttle:scan"]);
                Route::post("/close", [
                    QrCodeController::class,
                    "close",
                ])->middleware("ability:qr:close");
            });

        // ========================================
        // PERMISSION ROUTES (Digital Izin)
        // ========================================
        Route::middleware("role:student")
            ->prefix("student")
            ->group(function () {
                Route::get("/my-qr-card", [
                    App\Http\Controllers\Api\V1\StudentQrController::class,
                    "myQrCard",
                ])->middleware("ability:student:view_qr_card");
                
                Route::get("/dashboard", [
                    App\Http\Controllers\Api\V1\Student\StudentDashboardController::class,
                    "index",
                ])->middleware("ability:student:view_dashboard");

                Route::get("/history", [
                    App\Http\Controllers\Api\V1\Student\StudentDashboardController::class,
                    "history",
                ])->middleware("ability:student:view_dashboard");

                Route::get("/insights/punctuality", [
                    App\Http\Controllers\Api\V1\Student\StudentDashboardController::class,
                    "getPunctualityInsights",
                ])->middleware("ability:student:view_dashboard");

                Route::get("/insights/risk", [
                    App\Http\Controllers\Api\V1\Student\StudentDashboardController::class,
                    "getAbsenceRisk",
                ])->middleware("ability:student:view_dashboard");

                Route::get("/timeline", [
                    App\Http\Controllers\Api\V1\Student\StudentDashboardController::class,
                    "getTodayTimeline",
                ])->middleware("ability:student:view_dashboard");

                Route::get("/summary/monthly", [
                    App\Http\Controllers\Api\V1\Student\StudentDashboardController::class,
                    "getMonthlySummary",
                ])->middleware("ability:student:view_dashboard");

                Route::get("/insights/streaks", [
                    App\Http\Controllers\Api\V1\Student\StudentDashboardController::class,
                    "getStreakInfo",
                ])->middleware("ability:student:view_dashboard");

                Route::get("/insights/motivation", [
                    App\Http\Controllers\Api\V1\Student\StudentDashboardController::class,
                    "getMotivationalInsight",
                ])->middleware("ability:student:view_dashboard");

                Route::get("/profile/gamification", [
                    App\Http\Controllers\Api\V1\Student\StudentDashboardController::class,
                    "getGamificationStats",
                ])->middleware("ability:student:view_dashboard");

                Route::get("/profile/summary", [
                    App\Http\Controllers\Api\V1\Student\StudentDashboardController::class,
                    "getProfileSummary",
                ])->middleware("ability:student:view_dashboard");

                Route::post("/profile/claim-certificate", [
                    App\Http\Controllers\Api\V1\Student\StudentDashboardController::class,
                    "claimRewardCertificate",
                ])->middleware("ability:student:view_dashboard");

                Route::post("/permissions", [
                    App\Http\Controllers\Api\V1\PermissionController::class,
                    "store",
                ])->middleware("ability:permission:create");
                // Route::get('/permissions', ...); // History
                Route::get("/leaderboard", [
                    App\Http\Controllers\Api\V1\LeaderboardController::class,
                    "index",
                ])->middleware("ability:student:view_dashboard");

                Route::get("/leaderboard/classes", [
                    App\Http\Controllers\Api\V1\LeaderboardController::class,
                    "classCompetition",
                ])->middleware("ability:student:view_dashboard");

                Route::get("/leaderboard/official", [
                    App\Http\Controllers\Api\V1\LeaderboardController::class,
                    "officialLeaderboard",
                ])->middleware("ability:student:view_dashboard");

                Route::get("/leaderboard/hall-of-fame", [
                    App\Http\Controllers\Api\V1\LeaderboardController::class,
                    "hallOfFame",
                ])->middleware("ability:student:view_dashboard");
            });

        Route::middleware("role:teacher,homeroom_teacher")
            ->prefix("teacher")
            ->group(function () {
                Route::get("/permissions", [
                    App\Http\Controllers\Api\V1\PermissionController::class,
                    "index",
                ])->middleware("ability:permission:view");
                Route::post("/permissions", [
                    App\Http\Controllers\Api\V1\PermissionController::class,
                    "store",
                ])->middleware("ability:permission:view"); // Create new
                Route::patch("/permissions/{id}/status", [
                    App\Http\Controllers\Api\V1\PermissionController::class,
                    "updateStatus",
                ])->middleware("ability:permission:approve");
            });

        /*
    |--------------------------------------------------------------------------
    | Security Policy Management Routes
    |--------------------------------------------------------------------------
    */
        Route::prefix("admin/security-policies")
            ->middleware(["role:admin,school_admin,super_admin"])
            ->group(function () {
                Route::get("/", [SecurityPolicyController::class, "index"]);
                Route::get("/keys", [SecurityPolicyController::class, "keys"]);
                Route::post("/", [SecurityPolicyController::class, "store"]);
                Route::get("/{id}", [SecurityPolicyController::class, "show"]);
                Route::put("/{id}", [
                    SecurityPolicyController::class,
                    "update",
                ]);
                Route::delete("/{id}", [
                    SecurityPolicyController::class,
                    "destroy",
                ]);
                Route::get("/{id}/history", [
                    SecurityPolicyController::class,
                    "history",
                ]);
                Route::post("/bulk", [
                    SecurityPolicyController::class,
                    "bulkUpdate",
                ]);
            });

        // ========================================
        // ATTENDANCE ROUTES
        // ========================================

        // Student: Scan QR (RATE LIMITED - 60/minute per school)
        Route::middleware([
            "role:student",
            "ability:attendance:scan",
            "school.rate.limit:60,1",
        ])->group(function () {
            Route::post("/attendance/scan", [
                AttendanceController::class,
                "scan",
            ]);
        });

        // Teacher: Manual attendance (with device binding security)
        Route::middleware([
            "role:teacher,homeroom_teacher,admin,school_admin",
        ])->group(function () {
            Route::post("/attendance/scan", [
                App\Http\Controllers\Api\V1\TeacherScanController::class,
                "scan",
            ])->middleware([
                "teacher.device",
                "ability:attendance:scan",
                "school.rate.limit:60,1",
            ]);
            Route::post("/attendance/manual", [
                AttendanceController::class,
                "manual",
            ])->middleware([
                "teacher.device",
                "ability:attendance:manual,*",
                "school.rate.limit:60,1",
            ]);
        });

        // ========================================
        // SECURE ATTENDANCE (HMAC Signature Validation)
        // ========================================
        Route::middleware([
            "role:teacher,homeroom_teacher",
            "teacher.device",
            "school.rate.limit:60,1",
        ])->prefix("attendance/secure")->group(function () {
            // Scan with JSON payload (signature verification)
            Route::post("/scan", [
                App\Http\Controllers\Api\V1\SecureAttendanceScanController::class,
                "scan",
            ])->middleware("ability:attendance:scan");

            // Scan with encoded QR string (base64)
            Route::post("/scan-encoded", [
                App\Http\Controllers\Api\V1\SecureAttendanceScanController::class,
                "scanEncoded",
            ])->middleware("ability:attendance:scan");

            // Generate signed QR for student
            Route::post("/generate-qr", [
                App\Http\Controllers\Api\V1\SecureAttendanceScanController::class,
                "generateQR",
            ])->middleware("ability:qr:generate");
        });

        // Student: History
        Route::middleware("role:student")->group(function () {
            Route::get("/attendance/history", [
                AttendanceController::class,
                "history",
            ])->middleware("ability:attendance:view_own");
        });

        // ========================================
        // REPORTS (Admin only)
        // ========================================
        Route::middleware("role:admin,school_admin,super_admin")
            ->prefix("reports")
            ->group(function () {
                Route::get("/daily", [
                    AttendanceController::class,
                    "dailyReport",
                ])->middleware([
                    "ability:report:daily,*",
                    "school.rate.limit:20,1",
                ]);

                // Async Report Export (Rate limited: 5 per hour per user)
                Route::post("/export", [
                    \App\Http\Controllers\Api\V1\AsyncReportExportController::class,
                    "create",
                ])
                    ->middleware("ability:report:export,*")
                    ->name("reports.export.create");
                Route::get("/export/history", [
                    \App\Http\Controllers\Api\V1\AsyncReportExportController::class,
                    "history",
                ])
                    ->middleware("ability:report:export,*")
                    ->name("reports.export.history");
                Route::get("/export/{jobId}/status", [
                    \App\Http\Controllers\Api\V1\AsyncReportExportController::class,
                    "status",
                ])
                    ->middleware("ability:report:export,*")
                    ->name("reports.export.status");
                Route::get("/export/{jobId}/download", [
                    \App\Http\Controllers\Api\V1\AsyncReportExportController::class,
                    "download",
                ])
                    ->middleware("ability:report:export,*")
                    ->name("reports.export.download");
                Route::delete("/export/{jobId}", [
                    \App\Http\Controllers\Api\V1\AsyncReportExportController::class,
                    "cancel",
                ])
                    ->middleware("ability:report:export,*")
                    ->name("reports.export.cancel");

                // Teacher Recognition (Admin)
                Route::get("/teacher-recognition", [
                    App\Http\Controllers\Api\V1\SchoolAdmin\ReportController::class,
                    "teacherRecognition",
                ])->middleware("ability:report:view,*");

                // Student Semester Summary (Admin)
                Route::get("/student-semester-summary/{studentId}", [
                    App\Http\Controllers\Api\V1\SchoolAdmin\ReportController::class,
                    "studentSemesterSummary",
                ])->middleware("ability:report:view,*");

                // Risk Dashboard (Admin)
                Route::get("/risk-dashboard", [
                    App\Http\Controllers\Api\V1\RiskDashboardController::class,
                    "index",
                ])->middleware("ability:report:view,school:view_dashboard,*");

                // Reward Management (Certificates/Badges)
                Route::prefix("rewards")->group(function () {
                    Route::get("/", [
                        App\Http\Controllers\Api\V1\SchoolAdmin\RewardController::class,
                        "index",
                    ])->middleware("ability:student:view_profile,*"); // Reuse permission or add new
                    
                    Route::get("/students/{id}", [
                        App\Http\Controllers\Api\V1\SchoolAdmin\RewardController::class,
                        "studentHistory",
                    ])->middleware("ability:student:view_profile,*");

                    Route::post("/{id}/redeem", [
                        App\Http\Controllers\Api\V1\SchoolAdmin\RewardController::class,
                        "redeem",
                    ])->middleware("ability:student:update,*"); // Treat as updating student record

                    Route::delete("/{id}", [
                        App\Http\Controllers\Api\V1\SchoolAdmin\RewardController::class,
                        "destroy",
                    ])->middleware("ability:student:update,*");
                });
            });

        // ========================================
        // ADMIN DASHBOARD (School Admin)
        // ========================================
        Route::middleware("role:admin,school_admin,super_admin")
            ->prefix("admin/dashboard")
            ->group(function () {
                Route::get("/class-attendance", [
                    AdminDashboardController::class,
                    "classAttendance",
                ])->middleware("ability:dashboard:admin,*");
                Route::get("/teacher-absent", [
                    AdminDashboardController::class,
                    "teacherAbsent",
                ])->middleware("ability:dashboard:admin,*");
                Route::get("/late-alpha", [
                    AdminDashboardController::class,
                    "lateAlpha",
                ])->middleware("ability:dashboard:admin,*");
                Route::get("/anomalies", [
                    AdminDashboardController::class,
                    "anomalies",
                ])->middleware("ability:dashboard:admin,*");
            });

        // ========================================
        // ADMIN MANAGEMENT (School Admin)
        // ========================================
        Route::middleware("role:admin,school_admin,super_admin")
            ->prefix("admin")
            ->group(function () {
                // Risk Overview Routes
                Route::prefix('risk-overview')->name('risk-overview.')->group(function () {
                    Route::get('/', [\App\Http\Controllers\Api\V1\SchoolAdmin\RiskOverviewController::class, 'index'])->name('index');
                    Route::get('/students', [\App\Http\Controllers\Api\V1\SchoolAdmin\RiskOverviewController::class, 'studentDetails'])->name('students');
                    Route::get('/trend', [\App\Http\Controllers\Api\V1\SchoolAdmin\RiskOverviewController::class, 'trendData'])->name('trend');
                    Route::post('/export', [\App\Http\Controllers\Api\V1\SchoolAdmin\RiskOverviewController::class, 'export'])->name('export');
                });

                // Student Card Management (Strictly Admin)
                Route::prefix('student-cards')->group(function () {
                    Route::post('/{studentId}/generate', [\App\Http\Controllers\Api\V1\SchoolAdmin\StudentCardController::class, 'generateCard']);
                    Route::post('/{studentId}/regenerate', [\App\Http\Controllers\Api\V1\SchoolAdmin\StudentCardController::class, 'regenerateCard']);
                    Route::delete('/{studentId}', [\App\Http\Controllers\Api\V1\SchoolAdmin\StudentCardController::class, 'deactivateCard']);
                    Route::get('/{studentId}/status', [\App\Http\Controllers\Api\V1\SchoolAdmin\StudentCardController::class, 'getCardStatus']);
                });

                // Student Card PDF Generation
                Route::post('/students/{student}/card-pdf', [
                    \App\Http\Controllers\Api\V1\SchoolAdmin\StudentCardPdfController::class,
                    'generateSingle'
                ])->middleware('role:school_admin');

                Route::post('/classes/{class}/card-pdf-bulk', [
                    \App\Http\Controllers\Api\V1\SchoolAdmin\StudentCardPdfController::class,
                    'generateBulk'
                ])->middleware('role:school_admin');
            });

        // ========================================
        // ADMIN MANAGEMENT (School Admin)
        // Token binding middleware validates device/IP for admin roles
        // ========================================
        Route::middleware([
            "role:admin,school_admin,super_admin",
            "validate.token.binding",
        ])
            ->prefix("admin")
            ->group(function () {
                // System Health Monitoring (Admin only)
                // SECURITY: Requires EXPLICIT system:monitor permission (no wildcard fallback)
                Route::group(["prefix" => "system"], function () {
                    Route::get("/health", [
                        App\Http\Controllers\Api\V1\Admin\SystemHealthController::class,
                        "health",
                    ])->middleware("ability:system:monitor");
                    Route::get("/health/queue", [
                        App\Http\Controllers\Api\V1\Admin\SystemHealthController::class,
                        "queueHealth",
                    ])->middleware("ability:system:monitor");
                    Route::get("/health/security", [
                        App\Http\Controllers\Api\V1\Admin\SystemHealthController::class,
                        "securityHealth",
                    ])->middleware("ability:system:monitor");

                    // Backup Status (Super Admin only - sensitive infrastructure info)
                    Route::get("/backup-status", [
                        App\Http\Controllers\Api\V1\Admin\SystemHealthController::class,
                        "backupStatus",
                    ])->middleware("role:super_admin");

                    // Security Alerts Aggregation (Legacy)
                    Route::get("/alerts", [
                        App\Http\Controllers\Api\V1\SecurityAlertController::class,
                        "index",
                    ])->middleware("ability:system:monitor");
                    Route::patch("/alerts/{id}/resolve", [
                        App\Http\Controllers\Api\V1\SecurityAlertController::class,
                        "resolve",
                    ])->middleware("ability:system:monitor");
                });

                // Security Alerts (Aggregated - Attendance, Location, Device violations)
                Route::prefix("security-alerts")->group(function () {
                    Route::get("/", [
                        App\Http\Controllers\Api\V1\Admin\AdminSecurityAlertController::class,
                        "index",
                    ])->middleware("ability:system:monitor,*");
                    Route::post("/{id}/resolve", [
                        App\Http\Controllers\Api\V1\Admin\AdminSecurityAlertController::class,
                        "resolve",
                    ])->middleware("ability:system:monitor,*");

                    // Acknowledge alerts
                    Route::patch("/{id}/ack", [
                        App\Http\Controllers\Api\V1\Admin\SecurityDashboardController::class,
                        "acknowledge",
                    ])->middleware("ability:system:monitor,*");
                    Route::post("/bulk-ack", [
                        App\Http\Controllers\Api\V1\Admin\SecurityDashboardController::class,
                        "bulkAcknowledge",
                    ])->middleware("ability:system:monitor,*");
                });

                // Security Dashboard (Analytics & Monitoring)
                Route::prefix("security-dashboard")
                    ->middleware("ability:system:monitor,*")
                    ->group(function () {
                        Route::get("/summary", [
                            App\Http\Controllers\Api\V1\Admin\SecurityDashboardController::class,
                            "summary",
                        ]);
                        Route::get("/trend", [
                            App\Http\Controllers\Api\V1\Admin\SecurityDashboardController::class,
                            "trend",
                        ]);
                        Route::get("/by-type", [
                            App\Http\Controllers\Api\V1\Admin\SecurityDashboardController::class,
                            "byType",
                        ]);
                        Route::get("/by-school", [
                            App\Http\Controllers\Api\V1\Admin\SecurityDashboardController::class,
                            "bySchool",
                        ]);
                        Route::get("/by-severity", [
                            App\Http\Controllers\Api\V1\Admin\SecurityDashboardController::class,
                            "bySeverity",
                        ]);
                        Route::get("/critical-recent", [
                            App\Http\Controllers\Api\V1\Admin\SecurityDashboardController::class,
                            "criticalRecent",
                        ]);

                        // Active Sessions Management (super_admin only)
                        Route::get("/sessions/overview", [
                            App\Http\Controllers\Api\V1\Admin\ActiveSessionsController::class,
                            "overview",
                        ]);
                        Route::get("/users/{userId}/sessions", [
                            App\Http\Controllers\Api\V1\Admin\ActiveSessionsController::class,
                            "indexForUser",
                        ]);
                        Route::delete("/sessions/{sessionId}", [
                            App\Http\Controllers\Api\V1\Admin\ActiveSessionsController::class,
                            "adminDestroy",
                        ]);
                        Route::post("/users/{userId}/sessions/revoke-all", [
                            App\Http\Controllers\Api\V1\Admin\ActiveSessionsController::class,
                            "adminRevokeAll",
                        ]);

                        // Behavior Risk Analysis
                        Route::get("/behavior-risk", [
                            App\Http\Controllers\Api\V1\Admin\SecurityDashboardBehaviorController::class,
                            "behaviorRisk",
                        ]);
                        Route::get("/behavior-risk/{userId}", [
                            App\Http\Controllers\Api\V1\Admin\SecurityDashboardBehaviorController::class,
                            "teacherDetail",
                        ]);
                        Route::post("/behavior-risk/{userId}/clear-flag", [
                            App\Http\Controllers\Api\V1\Admin\SecurityDashboardBehaviorController::class,
                            "clearFlag",
                        ]);
                        Route::post(
                            "/behavior-risk/{userId}/clear-reverification",
                            [
                                App\Http\Controllers\Api\V1\Admin\SecurityDashboardBehaviorController::class,
                                "clearReverification",
                            ],
                        );
                        Route::post("/behavior-risk/{userId}/reanalyze", [
                            App\Http\Controllers\Api\V1\Admin\SecurityDashboardBehaviorController::class,
                            "reanalyze",
                        ]);
                        Route::get("/behavior-trends", [
                            App\Http\Controllers\Api\V1\Admin\SecurityDashboardBehaviorController::class,
                            "behaviorTrends",
                        ]);

                        // Teacher Location Heatmap
                        Route::get("/teacher-heatmap", [
                            App\Http\Controllers\Api\V1\Admin\TeacherHeatmapController::class,
                            "index",
                        ]);
                        Route::get("/teacher-heatmap/cluster-details", [
                            App\Http\Controllers\Api\V1\Admin\TeacherHeatmapController::class,
                            "clusterDetails",
                        ]);
                        Route::get("/teacher-heatmap/teachers", [
                            App\Http\Controllers\Api\V1\Admin\TeacherHeatmapController::class,
                            "teacherSummary",
                        ]);
                        Route::get("/teacher-heatmap/anomalies", [
                            App\Http\Controllers\Api\V1\Admin\TeacherHeatmapController::class,
                            "anomalies",
                        ]);
                    });

                // Security Investigation Reports
                Route::prefix("security-reports")
                    ->middleware("ability:system:monitor,*")
                    ->group(function () {
                        Route::get("/", [
                            App\Http\Controllers\Api\V1\Admin\SecurityReportController::class,
                            "index",
                        ]);
                        Route::post("/teacher", [
                            App\Http\Controllers\Api\V1\Admin\SecurityReportController::class,
                            "generateTeacherReport",
                        ]);
                        Route::get("/{id}", [
                            App\Http\Controllers\Api\V1\Admin\SecurityReportController::class,
                            "show",
                        ]);
                        Route::get("/{id}/download", [
                            App\Http\Controllers\Api\V1\Admin\SecurityReportController::class,
                            "download",
                        ])->name("admin.security-reports.download");
                        Route::delete("/{id}", [
                            App\Http\Controllers\Api\V1\Admin\SecurityReportController::class,
                            "destroy",
                        ]);
                    });

                // Admin Activity Audit Logs
                Route::prefix("system/admin-activity")
                    ->middleware([
                        "ability:system:monitor,*",
                        "log.admin.action",
                    ])
                    ->group(function () {
                        Route::get("/", [
                            App\Http\Controllers\Api\V1\Admin\AdminActivityController::class,
                            "index",
                        ])->name("admin.activity.index");
                        Route::get("/summary", [
                            App\Http\Controllers\Api\V1\Admin\AdminActivityController::class,
                            "summary",
                        ])->name("admin.activity.summary");
                        Route::get("/action-types", [
                            App\Http\Controllers\Api\V1\Admin\AdminActivityController::class,
                            "actionTypes",
                        ])->name("admin.activity.action-types");
                        Route::get("/my-activity", [
                            App\Http\Controllers\Api\V1\Admin\AdminActivityController::class,
                            "myActivity",
                        ])->name("admin.activity.my-activity");
                        Route::get("/target/{type}/{id}", [
                            App\Http\Controllers\Api\V1\Admin\AdminActivityController::class,
                            "forTarget",
                        ])->name("admin.activity.target");
                        Route::get("/{id}", [
                            App\Http\Controllers\Api\V1\Admin\AdminActivityController::class,
                            "show",
                        ])->name("admin.activity.show");
                    });

                // Subscription / Billing
                Route::get("/subscription/packages", [
                    App\Http\Controllers\Api\V1\School\SubscriptionController::class,
                    "packages",
                ]);
                Route::post("/subscription/purchase", [
                    App\Http\Controllers\Api\V1\School\SubscriptionController::class,
                    "purchase",
                ]);

                // Teachers
                Route::get("/teachers", [
                    TeacherController::class,
                    "index",
                ])->middleware("ability:teacher:manage,*");
                Route::post("/teachers/import", [
                    TeacherController::class,
                    "import",
                ])->middleware("ability:teacher:manage,*");
                Route::post("/teachers", [
                    TeacherController::class,
                    "store",
                ])->middleware("ability:teacher:manage,*");
                Route::put("/teachers/{teacherId}", [
                    TeacherController::class,
                    "update",
                ])->middleware("ability:teacher:manage,*");
                Route::patch("/teachers/{teacherId}/status", [
                    TeacherController::class,
                    "updateStatus",
                ])->middleware("ability:teacher:manage,*");
                Route::get("/teachers/assignments", [
                    TeacherController::class,
                    "assignments",
                ])->middleware("ability:teacher:manage,*");
                Route::post("/teachers/assignments", [
                    TeacherController::class,
                    "storeAssignment",
                ])->middleware("ability:teacher:manage,*");
                Route::delete("/teachers/assignments/{assignmentId}", [
                    TeacherController::class,
                    "destroyAssignment",
                ])->middleware("ability:teacher:manage,*");
                Route::post("/teachers/homeroom", [
                    TeacherController::class,
                    "setHomeroom",
                ])->middleware("ability:teacher:manage,*");

                // Students
                Route::post("/students/verify-qr-card", [
                    App\Http\Controllers\Api\V1\StudentQrController::class,
                    "verify",
                ])->middleware("ability:student:manage,*");
                Route::get("/students/{id}/qr-card", [
                    App\Http\Controllers\Api\V1\StudentQrController::class,
                    "show",
                ])->middleware("ability:student:manage,*");
                Route::get("/students", [
                    StudentController::class,
                    "index",
                ])->middleware("ability:student:manage,*");
                Route::get("/students/placement", [
                    StudentController::class,
                    "placements",
                ])->middleware("ability:student:manage,*");
                Route::patch("/students/{studentId}/placement", [
                    StudentController::class,
                    "updatePlacement",
                ])->middleware("ability:student:manage,*");
                Route::get("/students/mutations", [
                    StudentController::class,
                    "mutations",
                ])->middleware("ability:student:manage,*");
                Route::patch("/students/{studentId}/mutation", [
                    StudentController::class,
                    "updateMutation",
                ])->middleware("ability:student:manage,*");
                Route::post("/students/import", [
                    StudentController::class,
                    "import",
                ])->middleware("ability:student:manage,*");
                Route::post("/students", [
                    StudentController::class,
                    "store",
                ])->middleware("ability:student:manage,*");
                Route::put("/students/{studentId}", [
                    StudentController::class,
                    "update",
                ])->middleware("ability:student:manage,*");

                // Classes
                Route::get("/classes", [
                    ClassController::class,
                    "index",
                ])->middleware("ability:class:manage,*");
                Route::post("/classes", [
                    ClassController::class,
                    "store",
                ])->middleware("ability:class:manage,*");
                Route::put("/classes/{classId}", [
                    ClassController::class,
                    "update",
                ])->middleware("ability:class:manage,*");
                Route::patch("/classes/{classId}/status", [
                    ClassController::class,
                    "updateStatus",
                ])->middleware("ability:class:manage,*");
                Route::delete("/classes/{classId}", [
                    ClassController::class,
                    "destroy",
                ])->middleware("ability:class:manage,*");

                // Schedules & Subjects
                Route::get("/subjects", [SchoolController::class, "subjects"]);
                Route::get("/schedules", [
                    AdminScheduleController::class,
                    "index",
                ]);
                Route::post("/schedules", [
                    AdminScheduleController::class,
                    "store",
                ]);
                Route::put("/schedules/{scheduleId}", [
                    AdminScheduleController::class,
                    "update",
                ]);

                // Parents & Reports
                Route::get("/parents", [StudentController::class, "parents"]);
                Route::get("/reports", [ReportController::class, "index"]);

                // Settings (Profile & Config)
                Route::get("/settings/profile", [
                    SchoolController::class,
                    "profile",
                ]);
                Route::put("/settings/profile", [
                    SchoolController::class,
                    "updateProfile",
                ])->middleware("permission:manage school settings");

                Route::get("/settings/config", [
                    SchoolController::class,
                    "getSettings",
                ]);
                Route::put("/settings/config", [
                    SchoolController::class,
                    "updateSettings",
                ])->middleware("permission:manage school settings");

                // Academic Year Management
                Route::get("/settings/academic-year", [
                    SchoolController::class,
                    "academicYears",
                ]);
                Route::group(
                    ["middleware" => ["permission:manage school settings"]],
                    function () {
                        Route::post("/settings/academic-year", [
                            SchoolController::class,
                            "storeAcademicYear",
                        ]);
                        Route::put("/settings/academic-year/{id}", [
                            SchoolController::class,
                            "updateAcademicYear",
                        ]);
                        Route::patch("/settings/academic-year/{id}/activate", [
                            SchoolController::class,
                            "activateAcademicYear",
                        ]);
                        Route::delete("/settings/academic-year/{id}", [
                            SchoolController::class,
                            "deleteAcademicYear",
                        ]);
                    },
                );

                // Teacher Device Management
                Route::prefix("teacher-devices")
                    ->middleware("log.admin.action")
                    ->group(function () {
                        Route::get("/", [
                            App\Http\Controllers\Api\V1\Admin\TeacherDeviceController::class,
                            "index",
                        ])->middleware("ability:teacher:manage,*");
                        Route::get("/pending", [
                            App\Http\Controllers\Api\V1\Admin\TeacherDeviceController::class,
                            "pending",
                        ])->middleware("ability:teacher:manage,*");
                        Route::post("/{id}/approve", [
                            App\Http\Controllers\Api\V1\Admin\TeacherDeviceController::class,
                            "approve",
                        ])->middleware("ability:teacher:manage,*");
                        Route::post("/{id}/revoke", [
                            App\Http\Controllers\Api\V1\Admin\TeacherDeviceController::class,
                            "revoke",
                        ])->middleware("ability:teacher:manage,*");
                        Route::delete("/{id}", [
                            App\Http\Controllers\Api\V1\Admin\TeacherDeviceController::class,
                            "destroy",
                        ])->middleware("ability:teacher:manage,*");
                    });

                // Teacher Attendance Management
                Route::prefix("teacher-attendance")->group(function () {
                    Route::get("/", [
                        App\Http\Controllers\Api\V1\Admin\TeacherDeviceController::class,
                        "attendances",
                    ])->middleware("ability:teacher:manage,*");
                    Route::get("/anomalies", [
                        App\Http\Controllers\Api\V1\Admin\TeacherDeviceController::class,
                        "anomalies",
                    ])->middleware("ability:teacher:manage,*");
                    Route::post("/anomalies/{id}/review", [
                        App\Http\Controllers\Api\V1\Admin\TeacherDeviceController::class,
                        "reviewAnomaly",
                    ])->middleware("ability:teacher:manage,*");
                });
            });

        // ========================================
        // SUPER ADMIN ROUTES
        // Token binding middleware validates device/IP for super_admin
        // ========================================
        Route::middleware(["role:super_admin", "validate.token.binding", "log.superadmin"])
            ->prefix("super-admin")
            ->group(function () {
                // Dashboard
                Route::get("/dashboard/stats", [
                    App\Http\Controllers\Api\V1\SuperAdmin\DashboardController::class,
                    "index",
                ]);

                // Schools Management
                Route::get("/schools", [
                    \App\Http\Controllers\Api\V1\SuperAdmin\SchoolController::class,
                    "index",
                ]);
                Route::get("/schools/{id}/usage", [
                    \App\Http\Controllers\Api\V1\SuperAdmin\SchoolController::class,
                    "usage",
                ]);
                Route::patch("/schools/{id}/activate", [
                    \App\Http\Controllers\Api\V1\SuperAdmin\SchoolController::class,
                    "activate",
                ]);
                Route::patch("/schools/{id}/deactivate", [
                    \App\Http\Controllers\Api\V1\SuperAdmin\SchoolController::class,
                    "deactivate",
                ]);
                Route::post("/schools", [
                    \App\Http\Controllers\Api\V1\SuperAdmin\SchoolController::class,
                    "store",
                ]);
                Route::put("/schools/{id}", [
                    \App\Http\Controllers\Api\V1\SuperAdmin\SchoolController::class,
                    "update",
                ]);
                Route::post("/schools/{id}/impersonate", [
                    \App\Http\Controllers\Api\V1\SuperAdmin\SchoolController::class,
                    "impersonate",
                ]);
                Route::delete("/schools/{id}", [
                    \App\Http\Controllers\Api\V1\SuperAdmin\SchoolController::class,
                    "destroy",
                ]);

                // User Management
                Route::group(["prefix" => "users"], function () {
                    Route::get("/admins", [
                        \App\Http\Controllers\Api\V1\SuperAdmin\UserManagementController::class,
                        "getAdmins",
                    ]);
                    Route::post("/admins", [
                        \App\Http\Controllers\Api\V1\SuperAdmin\UserManagementController::class,
                        "store",
                    ]); // Add Store
                    Route::post("/reset-access", [
                        \App\Http\Controllers\Api\V1\SuperAdmin\UserManagementController::class,
                        "resetAccess",
                    ]);
                    Route::patch("/{id}/status", [
                        \App\Http\Controllers\Api\V1\SuperAdmin\UserManagementController::class,
                        "toggleStatus",
                    ]);
                    Route::get("/activity-logs", [
                        \App\Http\Controllers\Api\V1\SuperAdmin\UserManagementController::class,
                        "activityLogs",
                    ]);
                });

                // Security & System
                Route::group(["prefix" => "security"], function () {
                    Route::get("/roles", [
                        \App\Http\Controllers\Api\V1\SuperAdmin\SecurityController::class,
                        "roles",
                    ]);
                    Route::get("/audit", [
                        \App\Http\Controllers\Api\V1\SuperAdmin\SecurityController::class,
                        "auditLogs",
                    ]);
                    Route::get("/rate-limit", [
                        \App\Http\Controllers\Api\V1\SuperAdmin\SecurityController::class,
                        "rateLimitStats",
                    ]);
                });

                // Announcements (Broadcast)
                Route::apiResource(
                    "announcements",
                    \App\Http\Controllers\Api\V1\SuperAdmin\AnnouncementController::class,
                );

                // System Management
                Route::group(["prefix" => "system"], function () {
                    Route::get("/backup", [
                        \App\Http\Controllers\Api\V1\SuperAdmin\SystemController::class,
                        "backupDatabase",
                    ]);
                    Route::post("/maintenance", [
                        \App\Http\Controllers\Api\V1\SuperAdmin\SystemController::class,
                        "toggleMaintenanceMode",
                    ]);
                    Route::get("/maintenance/status", [
                        \App\Http\Controllers\Api\V1\SuperAdmin\SystemController::class,
                        "getMaintenanceStatus",
                    ]);
                });

                // Platform Config
                Route::group(["prefix" => "config"], function () {
                    Route::get("/features", [
                        \App\Http\Controllers\Api\V1\SuperAdmin\PlatformConfigController::class,
                        "getFeatureFlags",
                    ]);
                    Route::patch("/features/{id}", [
                        \App\Http\Controllers\Api\V1\SuperAdmin\PlatformConfigController::class,
                        "updateFeatureFlag",
                    ]);

                    Route::get("/templates", [
                        \App\Http\Controllers\Api\V1\SuperAdmin\PlatformConfigController::class,
                        "getScheduleTemplates",
                    ]);
                    Route::post("/templates", [
                        \App\Http\Controllers\Api\V1\SuperAdmin\PlatformConfigController::class,
                        "storeScheduleTemplate",
                    ]);
                    Route::delete("/templates/{id}", [
                        \App\Http\Controllers\Api\V1\SuperAdmin\PlatformConfigController::class,
                        "deleteScheduleTemplate",
                    ]);

                    Route::get("/academic-years", [
                        \App\Http\Controllers\Api\V1\SuperAdmin\PlatformConfigController::class,
                        "getAcademicYearsStats",
                    ]);
                    Route::post("/academic-years/deploy", [
                        \App\Http\Controllers\Api\V1\SuperAdmin\PlatformConfigController::class,
                        "deployAcademicYear",
                    ]);
                });

                // Billing & Payments
                Route::group(["prefix" => "billing"], function () {
                    Route::get("/payments", [
                        \App\Http\Controllers\Api\V1\SuperAdmin\PaymentController::class,
                        "index",
                    ]);
                    Route::post("/payments", [
                        \App\Http\Controllers\Api\V1\SuperAdmin\PaymentController::class,
                        "store",
                    ]);
                    Route::patch("/payments/{id}/status", [
                        \App\Http\Controllers\Api\V1\SuperAdmin\PaymentController::class,
                        "updateStatus",
                    ]);
                    Route::delete("/payments/{id}", [
                        \App\Http\Controllers\Api\V1\SuperAdmin\PaymentController::class,
                        "destroy",
                    ]);
                    Route::get("/stats", [
                        \App\Http\Controllers\Api\V1\SuperAdmin\PaymentController::class,
                        "stats",
                    ]);

                    // Subscription Packages
                    Route::get("/packages", [
                        \App\Http\Controllers\Api\V1\SuperAdmin\SubscriptionPackageController::class,
                        "index",
                    ]);
                    Route::post("/packages", [
                        \App\Http\Controllers\Api\V1\SuperAdmin\SubscriptionPackageController::class,
                        "store",
                    ]);
                    Route::put("/packages/{id}", [
                        \App\Http\Controllers\Api\V1\SuperAdmin\SubscriptionPackageController::class,
                        "update",
                    ]);
                    Route::delete("/packages/{id}", [
                        \App\Http\Controllers\Api\V1\SuperAdmin\SubscriptionPackageController::class,
                        "destroy",
                    ]);
                });

                // Global Reports
                Route::group(["prefix" => "reports"], function () {
                    Route::get("/attendance-recap", [
                        \App\Http\Controllers\Api\V1\SuperAdmin\GlobalReportController::class,
                        "attendanceRecap",
                    ]);
                    Route::get("/platform-stats", [
                        \App\Http\Controllers\Api\V1\SuperAdmin\GlobalReportController::class,
                        "platformStats",
                    ]);
                    Route::get("/export-options", [
                        \App\Http\Controllers\Api\V1\SuperAdmin\GlobalReportController::class,
                        "getExportOptions",
                    ]);
                    Route::post("/export/trigger", [
                        \App\Http\Controllers\Api\V1\SuperAdmin\GlobalReportController::class,
                        "triggerExport",
                    ]);
                });

                // Billing & Packages
                Route::get("/billing/packages", [
                    \App\Http\Controllers\Api\V1\SuperAdmin\SubscriptionPackageController::class,
                    "index",
                ]);
                Route::post("/billing/packages", [
                    \App\Http\Controllers\Api\V1\SuperAdmin\SubscriptionPackageController::class,
                    "store",
                ]);
                Route::put("/billing/packages/{id}", [
                    \App\Http\Controllers\Api\V1\SuperAdmin\SubscriptionPackageController::class,
                    "update",
                ]);
                Route::delete("/billing/packages/{id}", [
                    \App\Http\Controllers\Api\V1\SuperAdmin\SubscriptionPackageController::class,
                    "destroy",
                ]);

                Route::get("/billing/payments", [
                    App\Http\Controllers\Api\V1\SuperAdmin\BillingController::class,
                    "paymentHistory",
                ]);
                Route::get("/billing/invoices", [
                    App\Http\Controllers\Api\V1\SuperAdmin\BillingController::class,
                    "invoices",
                ]);
                Route::get("/billing/stats", [
                    App\Http\Controllers\Api\V1\SuperAdmin\BillingController::class,
                    "statistics",
                ]);
            });


        // ========================================
        // HARDENED SUPER ADMIN MODULE (New Features)
        // ========================================
        Route::prefix("superadmin")
            ->middleware([
                "auth:sanctum",
                "role:super_admin",
                "log.superadmin", 
                "validate.token.binding"
            ])
            ->group(function () {
                
                // 2. Impersonation
                Route::post("/impersonate/{userId}", [
                    \App\Http\Controllers\Api\V1\Admin\ImpersonationController::class, 
                    "start"
                ]);

                // 3. Bulk Actions
                Route::post("/schools/bulk-delete", [
                    \App\Http\Controllers\Api\V1\Admin\SuperAdminValidationController::class, 
                    "bulkDeleteSchools"
                ])->middleware("throttle:3,1"); 
                
                // 9. School Suspension
                Route::post("/schools/suspend", [
                    \App\Http\Controllers\Api\V1\Admin\SchoolSuspensionController::class, 
                    "suspend"
                ]);
                Route::post("/schools/unsuspend", [
                    \App\Http\Controllers\Api\V1\Admin\SchoolSuspensionController::class, 
                    "unsuspend"
                ]);

                // 8. Global Announcements
                Route::post("/announcements", [
                    \App\Http\Controllers\Api\V1\SuperAdmin\AnnouncementController::class, 
                    "store"
                ]);

                // 7. Monitoring
                Route::get("/monitor/stats", [
                    \App\Http\Controllers\Api\V1\SuperAdmin\MonitoringController::class, 
                    "index"
                ]);
                
                // 10. Security Alerts
                Route::get("/monitor/security-events", [
                    \App\Http\Controllers\Api\V1\SuperAdmin\SecurityEventController::class, 
                    "index"
                ]);
            });

        // Impersonation Stop (Accessible by authorized users)
        Route::post("/auth/stop-impersonate", [
            \App\Http\Controllers\Api\V1\Admin\ImpersonationController::class, 
            "stop"
        ])->middleware("auth:sanctum");

    });
