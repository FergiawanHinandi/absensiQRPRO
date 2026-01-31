Route::middleware(['auth:sanctum', 'role:school_admin'])->group(function () {
    Route::get('students/photos/pending', [
        \App\Http\Controllers\Api\V1\Admin\StudentPhotoReviewController::class,
        'pending',
    ])->name('admin.students.photos.pending');
    Route::post('students/{student}/photos/approve', [
        \App\Http\Controllers\Api\V1\Admin\StudentPhotoReviewController::class,
        'approve',
    ])->name('admin.students.photos.approve');
    Route::post('students/{student}/photos/reject', [
        \App\Http\Controllers\Api\V1\Admin\StudentPhotoReviewController::class,
        'reject',
    ])->name('admin.students.photos.reject');
    Route::post('students/{student}/photos/reupload', [
        \App\Http\Controllers\Api\V1\Admin\StudentPhotoReviewController::class,
        'reupload',
    ])->name('admin.students.photos.reupload');
    Route::post('students/photos/bulk-approve', [
        \App\Http\Controllers\Api\V1\Admin\StudentPhotoReviewController::class,
        'bulkApprove',
    ])->name('admin.students.photos.bulk-approve');
    Route::post('students/photos/bulk-reject', [
        \App\Http\Controllers\Api\V1\Admin\StudentPhotoReviewController::class,
        'bulkReject',
    ])->name('admin.students.photos.bulk-reject');

    // Duplicate Photo Management
    Route::get('students/photo-duplicates', [
        \App\Http\Controllers\Api\V1\Admin\StudentPhotoDuplicateController::class,
        'index',
    ])->name('admin.students.photo-duplicates');

    Route::post('students/{student}/resolve-duplicate', [
        \App\Http\Controllers\Api\V1\Admin\StudentPhotoDuplicateController::class,
        'resolve',
    ])->name('admin.students.resolve-duplicate');

    Route::get('student-cards/progress', [
        \App\Http\Controllers\Api\V1\Admin\StudentCardProgressController::class,
        'progress',
    ])->name('admin.student-cards.progress');
});
Route::post('students/photos/upload', [
    \App\Http\Controllers\Api\V1\Admin\StudentPhotoController::class,
    'bulkUpload',
])->middleware(['auth:sanctum', 'role:school_admin'])->name('admin.students.photos.upload');
// ========================================
// STUDENT CARD MANAGEMENT
// ========================================
Route::prefix('students')->middleware('ability:student:manage,*')->group(function () {
    Route::post('/{student}/generate-card', [
        \App\Http\Controllers\Api\V1\Admin\StudentCardController::class,
        'generate',
    ])->name('admin.students.generate-card');

    Route::post('/{student}/regenerate-card', [
        \App\Http\Controllers\Api\V1\Admin\StudentCardController::class,
        'regenerate',
    ])->name('admin.students.regenerate-card');

    Route::get('/{student}/card-history', [
        \App\Http\Controllers\Api\V1\Admin\StudentCardController::class,
        'history',
    ])->name('admin.students.card-history');
});

Route::post('student-cards/{card}/mark-distributed', [
    \App\Http\Controllers\Api\V1\Admin\StudentCardController::class,
    'markDistributed',
])->name('admin.student-cards.mark-distributed');

Route::post('student-cards/bulk-generate', [
    \App\Http\Controllers\Api\V1\Admin\StudentCardController::class,
    'bulkGenerate',
])->middleware(['auth:sanctum', 'role:school_admin'])->name('admin.student-cards.bulk-generate');
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\SchoolAdmin\StudentCardController;

/*
|--------------------------------------------------------------------------
| School Admin Routes - API v1
|--------------------------------------------------------------------------
|
| Routes for school administration including:
| - Dashboard
| - Teachers management
| - Students management
| - Classes management
| - Schedules management
| - Settings
|
*/

Route::middleware([
    'role:admin,school_admin,super_admin',
    'validate.token.binding',
])->group(function () {
    // ========================================
    // DASHBOARD
    // ========================================
    Route::prefix('dashboard')->group(function () {
        Route::get('/class-attendance', [
            \App\Http\Controllers\Api\V1\AdminDashboardController::class,
            'classAttendance',
        ])->middleware('ability:dashboard:admin,*')
          ->name('admin.dashboard.class-attendance');

        Route::get('/teacher-absent', [
            \App\Http\Controllers\Api\V1\AdminDashboardController::class,
            'teacherAbsent',
        ])->middleware('ability:dashboard:admin,*')
          ->name('admin.dashboard.teacher-absent');

        Route::get('/late-alpha', [
            \App\Http\Controllers\Api\V1\AdminDashboardController::class,
            'lateAlpha',
        ])->middleware('ability:dashboard:admin,*')
          ->name('admin.dashboard.late-alpha');

    // Attendance Reporting & Export
    Route::middleware(['auth:sanctum', 'role:school_admin,principal,homeroom_teacher'])->group(function () {
        Route::get('reports/daily', [AttendanceReportController::class, 'daily']);
        Route::get('reports/monthly', [AttendanceReportController::class, 'monthly']);
        Route::get('reports/student-monthly/{student}', [AttendanceReportController::class, 'studentMonthly']);
        Route::get('reports/school-monthly', [AttendanceReportController::class, 'schoolMonthly']);
        Route::post('reports/export-pdf', [AttendanceReportController::class, 'exportPdf']);
        Route::post('reports/export-excel', [AttendanceReportController::class, 'exportExcel']);
    });
        Route::get('/anomalies', [
            \App\Http\Controllers\Api\V1\AdminDashboardController::class,
            'anomalies',
        ])->middleware('ability:dashboard:admin,*')
          ->name('admin.dashboard.anomalies');
    });

    // ========================================
    // TEACHERS MANAGEMENT
    // ========================================
    Route::prefix('teachers')->middleware('ability:teacher:manage,*')->group(function () {
        Route::get('/', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\TeacherController::class,
            'index',
        ])->name('admin.teachers.index');

        Route::post('/', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\TeacherController::class,
            'store',
        ])->name('admin.teachers.store');

        Route::post('/import', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\TeacherController::class,
            'import',
        ])->name('admin.teachers.import');

        Route::put('/{teacherId}', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\TeacherController::class,
            'update',
        ])->name('admin.teachers.update');

        Route::patch('/{teacherId}/status', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\TeacherController::class,
            'updateStatus',
        ])->name('admin.teachers.update-status');

        Route::get('/assignments', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\TeacherController::class,
            'assignments',
        ])->name('admin.teachers.assignments');

        Route::post('/assignments', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\TeacherController::class,
            'storeAssignment',
        ])->name('admin.teachers.store-assignment');

        Route::delete('/assignments/{assignmentId}', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\TeacherController::class,
            'destroyAssignment',
        ])->name('admin.teachers.destroy-assignment');

        Route::post('/homeroom', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\TeacherController::class,
            'setHomeroom',
        ])->name('admin.teachers.set-homeroom');
    });

    // ========================================
    // STUDENTS MANAGEMENT
    // ========================================
    Route::prefix('students')->middleware('ability:student:manage,*')->group(function () {
        Route::get('/', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\StudentController::class,
            'index',
        ])->name('admin.students.index');

        Route::post('/', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\StudentController::class,
            'store',
        ])->name('admin.students.store');

        Route::post('/import', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\StudentController::class,
            'import',
        ])->name('admin.students.import');

        Route::put('/{studentId}', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\StudentController::class,
            'update',
        ])->name('admin.students.update');

        Route::get('/placement', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\StudentController::class,
            'placements',
        ])->name('admin.students.placements');

        Route::patch('/{studentId}/placement', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\StudentController::class,
            'updatePlacement',
        ])->name('admin.students.update-placement');

        Route::get('/mutations', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\StudentController::class,
            'mutations',
        ])->name('admin.students.mutations');

        Route::patch('/{studentId}/mutation', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\StudentController::class,
            'updateMutation',
        ])->name('admin.students.update-mutation');

        // QR Card Management - STRICT AUTHORIZATION (School Admin Only)
        Route::get('/{id}/qr-card', [
            \App\Http\Controllers\Api\V1\StudentQrController::class,
            'show',
        ])->name('admin.students.qr-card');

        Route::post('/verify-qr-card', [
            \App\Http\Controllers\Api\V1\StudentQrController::class,
            'verify',
        ])->name('admin.students.verify-qr-card');

        // CRITICAL: Student Card Generation - Only School Admin Access
        Route::middleware('role:school_admin')->group(function () {
            Route::post('/{id}/generate-card', [
                \App\Http\Controllers\Api\V1\SchoolAdmin\StudentCardController::class,
                'generateCard',
            ])->name('admin.students.generate-card');

            Route::post('/{id}/regenerate-card', [
                \App\Http\Controllers\Api\V1\SchoolAdmin\StudentCardController::class,
                'regenerateCard',
            ])->name('admin.students.regenerate-card');

            Route::post('/{id}/deactivate-card', [
                \App\Http\Controllers\Api\V1\SchoolAdmin\StudentCardController::class,
                'deactivateCard',
            ])->name('admin.students.deactivate-card');

            Route::post('/{student}/card-pdf', [
                \App\Http\Controllers\Api\V1\SchoolAdmin\StudentCardPdfController::class,
                'generateSingle',
            ])->name('admin.students.card-pdf');
        });

        // Card status viewing (School Admin + Principal)
        Route::get('/{id}/card-status', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\StudentCardController::class,
            'getCardStatus',
        ])->middleware('role:school_admin,principal')
          ->name('admin.students.card-status');
    });

    // ========================================
    // CLASSES MANAGEMENT
    // ========================================
    Route::prefix('classes')->middleware('ability:class:manage,*')->group(function () {
        Route::get('/', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\ClassController::class,
            'index',
        ])->name('admin.classes.index');

        Route::post('/', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\ClassController::class,
            'store',
        ])->name('admin.classes.store');

        Route::put('/{classId}', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\ClassController::class,
            'update',
        ])->name('admin.classes.update');

        Route::patch('/{classId}/status', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\ClassController::class,
            'updateStatus',
        ])->name('admin.classes.update-status');

        Route::delete('/{classId}', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\ClassController::class,
            'destroy',
        ])->name('admin.classes.destroy');

        // Bulk Card PDF Generation
        Route::middleware('role:school_admin')->post('/{class}/card-pdf-bulk', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\StudentCardPdfController::class,
            'generateBulk',
        ])->name('admin.classes.card-pdf-bulk');

        // Group truancy detection
        Route::get('/group-truancy', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\GroupTruancyController::class,
            'detect',
        ])->name('admin.classes.group-truancy');
    });

    // ========================================
    // SCHEDULES MANAGEMENT
    // ========================================
    Route::prefix('schedules')->group(function () {
        Route::get('/', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\ScheduleController::class,
            'index',
        ])->name('admin.schedules.index');

        Route::post('/', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\ScheduleController::class,
            'store',
        ])->name('admin.schedules.store');

        Route::put('/{scheduleId}', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\ScheduleController::class,
            'update',
        ])->name('admin.schedules.update');
    });

    // ========================================
    // SCHOOL SETTINGS
    // ========================================
    Route::prefix('settings')->group(function () {
        Route::get('/profile', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\SchoolController::class,
            'profile',
        ])->name('admin.settings.profile');

        Route::put('/profile', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\SchoolController::class,
            'updateProfile',
        ])->middleware('permission:manage school settings')
          ->name('admin.settings.update-profile');

        Route::get('/config', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\SchoolController::class,
            'getSettings',
        ])->name('admin.settings.config');

        Route::put('/config', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\SchoolController::class,
            'updateSettings',
        ])->middleware('permission:manage school settings')
          ->name('admin.settings.update-config');

        // Academic Year
        Route::get('/academic-year', [
            \App\Http\Controllers\Api\V1\SchoolAdmin\SchoolController::class,
            'academicYears',
        ])->name('admin.settings.academic-years');

        Route::middleware('permission:manage school settings')->group(function () {
            Route::post('/academic-year', [
                \App\Http\Controllers\Api\V1\SchoolAdmin\SchoolController::class,
                'storeAcademicYear',
            ])->name('admin.settings.store-academic-year');

            Route::put('/academic-year/{id}', [
                \App\Http\Controllers\Api\V1\SchoolAdmin\SchoolController::class,
                'updateAcademicYear',
            ])->name('admin.settings.update-academic-year');

            Route::patch('/academic-year/{id}/activate', [
                \App\Http\Controllers\Api\V1\SchoolAdmin\SchoolController::class,
                'activateAcademicYear',
            ])->name('admin.settings.activate-academic-year');

            Route::delete('/academic-year/{id}', [
                \App\Http\Controllers\Api\V1\SchoolAdmin\SchoolController::class,
                'deleteAcademicYear',
            ])->name('admin.settings.delete-academic-year');
        });
    });

    // ========================================
    // SUBJECTS & REPORTS
    // ========================================
    Route::get('/subjects', [
        \App\Http\Controllers\Api\V1\SchoolAdmin\SchoolController::class,
        'subjects',
    ])->name('admin.subjects.index');

    Route::get('/parents', [
        \App\Http\Controllers\Api\V1\SchoolAdmin\StudentController::class,
        'parents',
    ])->name('admin.parents.index');

    Route::get('/reports', [
        \App\Http\Controllers\Api\V1\SchoolAdmin\ReportController::class,
        'index',
    ])->name('admin.reports.index');
});
