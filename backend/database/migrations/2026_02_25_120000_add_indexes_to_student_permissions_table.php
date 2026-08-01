<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A3-M4 FIX: Tambahkan index pada kolom created_at di tabel student_permissions.
 *
 * PrincipalMonitoringController::pendingApprovals() mengurutkan dengan
 * orderByDesc('created_at') — tanpa index ini query akan melakukan full sequential scan
 * terutama jika data permissions sudah ribuan baris.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_permissions', function (Blueprint $table) {
            // Index untuk query orderByDesc('created_at') di PrincipalMonitoringController
            $table->index('created_at', 'idx_student_permissions_created_at');

            // Index tambahan untuk filter status='pending' yang sering digunakan
            if (! Schema::hasIndex('student_permissions', 'idx_student_permissions_status')) {
                $table->index('status', 'idx_student_permissions_status');
            }

            // Composite index untuk query umum: WHERE school_id + status + created_at
            // Mengoptimalkan query: StudentPermission::pending()->orderByDesc('created_at')
            // yang menggunakan BelongsToSchool scope (auto-adds WHERE school_id = ?)
            $table->index(['school_id', 'status', 'created_at'], 'idx_student_permissions_school_status_date');
        });
    }

    public function down(): void
    {
        Schema::table('student_permissions', function (Blueprint $table) {
            $table->dropIndex('idx_student_permissions_created_at');
            $table->dropIndex('idx_student_permissions_status');
            $table->dropIndex('idx_student_permissions_school_status_date');
        });
    }
};
