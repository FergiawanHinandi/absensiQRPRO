<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds missing indexes on foreign key columns identified by slow query analysis.
     * These indexes improve JOIN performance and prevent table locks during DELETE operations.
     * 
     * Analysis Date: 2026-02-11
     * Total Indexes Added: 50
     * 
     * @return void
     */
    public function up(): void
    {
        // ============================================
        // HIGH PRIORITY: Attendance Domain (10 indexes)
        // ============================================
        
        // attendances table (6 indexes)
        Schema::table('attendances', function (Blueprint $table) {
            $table->index('approved_by', 'idx_attendances_approved_by');
            $table->index('qr_code_id', 'idx_attendances_qr_code_id');
            $table->index('subject_id', 'idx_attendances_subject_id');
            $table->index('verified_by', 'idx_attendances_verified_by');
            $table->index('rejected_by', 'idx_attendances_rejected_by');
            $table->index('correction_requested_by', 'idx_attendances_correction_requested_by');
        });
        
        // attendance_logs table (1 index)
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->index('qr_code_id', 'idx_attendance_logs_qr_code_id');
        });
        
        // attendance_summaries table (1 index)
        Schema::table('attendance_summaries', function (Blueprint $table) {
            $table->index('class_id', 'idx_attendance_summaries_class_id');
        });
        
        // attendance_interventions table (2 indexes)
        Schema::table('attendance_interventions', function (Blueprint $table) {
            $table->index('school_id', 'idx_attendance_interventions_school_id');
            $table->index('teacher_id', 'idx_attendance_interventions_teacher_id');
        });
        
        // ============================================
        // HIGH PRIORITY: Security & Audit (4 indexes)
        // ============================================
        
        // security_events table (2 indexes)
        Schema::table('security_events', function (Blueprint $table) {
            $table->index('user_id', 'idx_security_events_user_id');
            $table->index('reviewed_by', 'idx_security_events_reviewed_by');
        });
        
        // security_alerts table (1 index)
        Schema::table('security_alerts', function (Blueprint $table) {
            $table->index('resolved_by', 'idx_security_alerts_resolved_by');
        });
        
        // audit_logs table (1 index)
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index('user_id', 'idx_audit_logs_user_id');
        });
        
        // ============================================
        // HIGH PRIORITY: Payment & Subscription (2 indexes)
        // ============================================
        
        // payments table (2 indexes)
        Schema::table('payments', function (Blueprint $table) {
            $table->index('package_id', 'idx_payments_package_id');
            $table->index('school_id', 'idx_payments_school_id');
        });
        
        // ============================================
        // MEDIUM PRIORITY: User Management (2 indexes)
        // ============================================
        
        // users table (1 index)
        Schema::table('users', function (Blueprint $table) {
            $table->index('photo_reviewed_by', 'idx_users_photo_reviewed_by');
        });
        
        // notifications table (1 index)
        Schema::table('notifications', function (Blueprint $table) {
            $table->index('user_id', 'idx_notifications_user_id');
        });
        
        // ============================================
        // MEDIUM PRIORITY: QR Code System (3 indexes)
        // ============================================
        
        // qr_codes table (1 index)
        Schema::table('qr_codes', function (Blueprint $table) {
            $table->index('generated_by', 'idx_qr_codes_generated_by');
        });
        
        // qr_nonces table (2 indexes)
        Schema::table('qr_nonces', function (Blueprint $table) {
            $table->index('school_id', 'idx_qr_nonces_school_id');
            $table->index('schedule_id', 'idx_qr_nonces_schedule_id');
        });
        
        // ============================================
        // MEDIUM PRIORITY: Student Management (8 indexes)
        // ============================================
        
        // student_cards table (4 indexes)
        Schema::table('student_cards', function (Blueprint $table) {
            $table->index('school_id', 'idx_student_cards_school_id');
            $table->index('issued_by', 'idx_student_cards_issued_by');
            $table->index('distributed_by', 'idx_student_cards_distributed_by');
            $table->index('student_id', 'idx_student_cards_student_id');
        });
        
        // student_notes table (1 index)
        Schema::table('student_notes', function (Blueprint $table) {
            $table->index('teacher_id', 'idx_student_notes_teacher_id');
        });
        
        // student_permissions table (2 indexes)
        Schema::table('student_permissions', function (Blueprint $table) {
            $table->index('approved_by', 'idx_student_permissions_approved_by');
            $table->index('class_id', 'idx_student_permissions_class_id');
        });
        
        // student_attendance_risk table (1 index)
        Schema::table('student_attendance_risk', function (Blueprint $table) {
            $table->index('student_id', 'idx_student_attendance_risk_student_id');
        });
        
        // ============================================
        // MEDIUM PRIORITY: Teacher Management (6 indexes)
        // ============================================
        
        // teacher_attendances table (1 index)
        Schema::table('teacher_attendances', function (Blueprint $table) {
            $table->index('recorded_by', 'idx_teacher_attendances_recorded_by');
        });
        
        // teacher_devices table (2 indexes)
        Schema::table('teacher_devices', function (Blueprint $table) {
            $table->index('revoked_by', 'idx_teacher_devices_revoked_by');
            $table->index('approved_by', 'idx_teacher_devices_approved_by');
        });
        
        // teacher_roles table (1 index)
        Schema::table('teacher_roles', function (Blueprint $table) {
            $table->index('homeroom_class_id', 'idx_teacher_roles_homeroom_class_id');
        });
        
        // teacher_attendance_anomalies table (2 indexes)
        Schema::table('teacher_attendance_anomalies', function (Blueprint $table) {
            $table->index('reviewed_by', 'idx_teacher_attendance_anomalies_reviewed_by');
            $table->index('teacher_attendance_id', 'idx_teacher_attendance_anomalies_teacher_attendance_id');
        });
        
        // ============================================
        // MEDIUM PRIORITY: Schedule Management (2 indexes)
        // ============================================
        
        // schedules table (2 indexes)
        Schema::table('schedules', function (Blueprint $table) {
            $table->index('subject_id', 'idx_schedules_subject_id');
            $table->index('academic_year_id', 'idx_schedules_academic_year_id');
        });
        
        // ============================================
        // LOW PRIORITY: Other Tables (11 indexes)
        // ============================================
        
        // announcements table (1 index)
        Schema::table('announcements', function (Blueprint $table) {
            $table->index('created_by', 'idx_announcements_created_by');
        });
        
        // certificates table (2 indexes)
        Schema::table('certificates', function (Blueprint $table) {
            $table->index('redeemed_by', 'idx_certificates_redeemed_by');
            $table->index('student_id', 'idx_certificates_student_id');
        });
        
        // backup_jobs table (1 index)
        Schema::table('backup_jobs', function (Blueprint $table) {
            $table->index('user_id', 'idx_backup_jobs_user_id');
        });
        
        // behavior_baselines table (1 index)
        Schema::table('behavior_baselines', function (Blueprint $table) {
            $table->index('flagged_by', 'idx_behavior_baselines_flagged_by');
        });
        
        // suspicious_students table (1 index)
        Schema::table('suspicious_students', function (Blueprint $table) {
            $table->index('reviewed_by', 'idx_suspicious_students_reviewed_by');
        });
        
        // refresh_tokens table (1 index)
        Schema::table('refresh_tokens', function (Blueprint $table) {
            $table->index('personal_access_token_id', 'idx_refresh_tokens_personal_access_token_id');
        });
        
        // pitr_operations table (2 indexes)
        Schema::table('pitr_operations', function (Blueprint $table) {
            $table->index('created_by', 'idx_pitr_operations_created_by');
            $table->index('base_backup_id', 'idx_pitr_operations_base_backup_id');
        });
        
        // attendance_reports table (1 index)
        Schema::table('attendance_reports', function (Blueprint $table) {
            $table->index('generated_by', 'idx_attendance_reports_generated_by');
        });
        
        // activity_logs table (1 index)
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->index('school_id', 'idx_activity_logs_school_id');
        });
        
        // ============================================
        // PARTITIONED TABLES (2 indexes)
        // ============================================
        
        // attendances_2024 table (1 index)
        if (Schema::hasTable('attendances_2024')) {
            Schema::table('attendances_2024', function (Blueprint $table) {
                $table->index('recorded_by', 'idx_attendances_2024_recorded_by');
            });
        }
        
        // attendances_2025 table (1 index)
        if (Schema::hasTable('attendances_2025')) {
            Schema::table('attendances_2025', function (Blueprint $table) {
                $table->index('recorded_by', 'idx_attendances_2025_recorded_by');
            });
        }
    }

    /**
     * Reverse the migrations.
     * 
     * Drops all indexes added by this migration.
     * Safe to rollback as these are performance optimizations only.
     * 
     * @return void
     */
    public function down(): void
    {
        // ============================================
        // Attendance Domain
        // ============================================
        
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex('idx_attendances_approved_by');
            $table->dropIndex('idx_attendances_qr_code_id');
            $table->dropIndex('idx_attendances_subject_id');
            $table->dropIndex('idx_attendances_verified_by');
            $table->dropIndex('idx_attendances_rejected_by');
            $table->dropIndex('idx_attendances_correction_requested_by');
        });
        
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropIndex('idx_attendance_logs_qr_code_id');
        });
        
        Schema::table('attendance_summaries', function (Blueprint $table) {
            $table->dropIndex('idx_attendance_summaries_class_id');
        });
        
        Schema::table('attendance_interventions', function (Blueprint $table) {
            $table->dropIndex('idx_attendance_interventions_school_id');
            $table->dropIndex('idx_attendance_interventions_teacher_id');
        });
        
        // ============================================
        // Security & Audit
        // ============================================
        
        Schema::table('security_events', function (Blueprint $table) {
            $table->dropIndex('idx_security_events_user_id');
            $table->dropIndex('idx_security_events_reviewed_by');
        });
        
        Schema::table('security_alerts', function (Blueprint $table) {
            $table->dropIndex('idx_security_alerts_resolved_by');
        });
        
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('idx_audit_logs_user_id');
        });
        
        // ============================================
        // Payment & Subscription
        // ============================================
        
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('idx_payments_package_id');
            $table->dropIndex('idx_payments_school_id');
        });
        
        // ============================================
        // User Management
        // ============================================
        
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_users_photo_reviewed_by');
        });
        
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('idx_notifications_user_id');
        });
        
        // ============================================
        // QR Code System
        // ============================================
        
        Schema::table('qr_codes', function (Blueprint $table) {
            $table->dropIndex('idx_qr_codes_generated_by');
        });
        
        Schema::table('qr_nonces', function (Blueprint $table) {
            $table->dropIndex('idx_qr_nonces_school_id');
            $table->dropIndex('idx_qr_nonces_schedule_id');
        });
        
        // ============================================
        // Student Management
        // ============================================
        
        Schema::table('student_cards', function (Blueprint $table) {
            $table->dropIndex('idx_student_cards_school_id');
            $table->dropIndex('idx_student_cards_issued_by');
            $table->dropIndex('idx_student_cards_distributed_by');
            $table->dropIndex('idx_student_cards_student_id');
        });
        
        Schema::table('student_notes', function (Blueprint $table) {
            $table->dropIndex('idx_student_notes_teacher_id');
        });
        
        Schema::table('student_permissions', function (Blueprint $table) {
            $table->dropIndex('idx_student_permissions_approved_by');
            $table->dropIndex('idx_student_permissions_class_id');
        });
        
        Schema::table('student_attendance_risk', function (Blueprint $table) {
            $table->dropIndex('idx_student_attendance_risk_student_id');
        });
        
        // ============================================
        // Teacher Management
        // ============================================
        
        Schema::table('teacher_attendances', function (Blueprint $table) {
            $table->dropIndex('idx_teacher_attendances_recorded_by');
        });
        
        Schema::table('teacher_devices', function (Blueprint $table) {
            $table->dropIndex('idx_teacher_devices_revoked_by');
            $table->dropIndex('idx_teacher_devices_approved_by');
        });
        
        Schema::table('teacher_roles', function (Blueprint $table) {
            $table->dropIndex('idx_teacher_roles_homeroom_class_id');
        });
        
        Schema::table('teacher_attendance_anomalies', function (Blueprint $table) {
            $table->dropIndex('idx_teacher_attendance_anomalies_reviewed_by');
            $table->dropIndex('idx_teacher_attendance_anomalies_teacher_attendance_id');
        });
        
        // ============================================
        // Schedule Management
        // ============================================
        
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropIndex('idx_schedules_subject_id');
            $table->dropIndex('idx_schedules_academic_year_id');
        });
        
        // ============================================
        // Other Tables
        // ============================================
        
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropIndex('idx_announcements_created_by');
        });
        
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropIndex('idx_certificates_redeemed_by');
            $table->dropIndex('idx_certificates_student_id');
        });
        
        Schema::table('backup_jobs', function (Blueprint $table) {
            $table->dropIndex('idx_backup_jobs_user_id');
        });
        
        Schema::table('behavior_baselines', function (Blueprint $table) {
            $table->dropIndex('idx_behavior_baselines_flagged_by');
        });
        
        Schema::table('suspicious_students', function (Blueprint $table) {
            $table->dropIndex('idx_suspicious_students_reviewed_by');
        });
        
        Schema::table('refresh_tokens', function (Blueprint $table) {
            $table->dropIndex('idx_refresh_tokens_personal_access_token_id');
        });
        
        Schema::table('pitr_operations', function (Blueprint $table) {
            $table->dropIndex('idx_pitr_operations_created_by');
            $table->dropIndex('idx_pitr_operations_base_backup_id');
        });
        
        Schema::table('attendance_reports', function (Blueprint $table) {
            $table->dropIndex('idx_attendance_reports_generated_by');
        });
        
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex('idx_activity_logs_school_id');
        });
        
        // ============================================
        // Partitioned Tables
        // ============================================
        
        if (Schema::hasTable('attendances_2024')) {
            Schema::table('attendances_2024', function (Blueprint $table) {
                $table->dropIndex('idx_attendances_2024_recorded_by');
            });
        }
        
        if (Schema::hasTable('attendances_2025')) {
            Schema::table('attendances_2025', function (Blueprint $table) {
                $table->dropIndex('idx_attendances_2025_recorded_by');
            });
        }
    }
};
