<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create disaster recovery tracking tables.
 *
 * Spec: disaster-recovery-audit-improvements / tasks.md Task 12.1
 * Tables: backup_operations, backup_chains, dr_audit_log
 */
return new class extends Migration
{
    public function up(): void
    {
        // Table 1: backup_operations — tracks each backup/restore operation
        if (!Schema::hasTable('backup_operations')) {
            Schema::create('backup_operations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id')->nullable()->index(); // null = global
                $table->enum('type', ['full', 'incremental', 'restore', 'verify', 'drill']);
                $table->enum('status', ['pending', 'running', 'completed', 'failed', 'cancelled'])
                      ->default('pending');
                $table->string('scenario', 100)->nullable();   // e.g. 'database_failure'
                $table->integer('progress_percent')->default(0);
                $table->text('progress_message')->nullable();
                $table->bigInteger('bytes_processed')->default(0);
                $table->bigInteger('bytes_total')->default(0);
                $table->string('backup_path', 1000)->nullable();
                $table->string('storage_disk', 50)->nullable();
                $table->text('error_message')->nullable();
                $table->unsignedBigInteger('initiated_by')->nullable(); // user_id
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'created_at']);
                $table->index(['school_id', 'type', 'status']);
            });
        }

        // Table 2: backup_chains — links incremental backups
        if (!Schema::hasTable('backup_chains')) {
            Schema::create('backup_chains', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id')->nullable()->index();
                $table->unsignedBigInteger('full_backup_operation_id')->index(); // parent full backup
                $table->unsignedBigInteger('operation_id')->index();             // this incremental
                $table->integer('sequence')->default(0);   // 1, 2, 3... in chain
                $table->timestamp('backup_from')->nullable();
                $table->timestamp('backup_to')->nullable();
                $table->bigInteger('delta_bytes')->default(0);
                $table->boolean('integrity_verified')->default(false);
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();
            });
        }

        // Table 3: dr_audit_log — comprehensive audit trail for all DR operations
        if (!Schema::hasTable('dr_audit_log')) {
            Schema::create('dr_audit_log', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('operation_id')->nullable()->index();
                $table->unsignedBigInteger('school_id')->nullable()->index();
                $table->string('event_type', 100);   // e.g. 'backup_started', 'restore_failed'
                $table->enum('severity', ['info', 'warning', 'error', 'critical'])->default('info');
                $table->string('actor_type', 50)->nullable(); // 'user', 'system', 'scheduler'
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->json('details')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('occurred_at')->useCurrent()->index();

                $table->index(['event_type', 'occurred_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dr_audit_log');
        Schema::dropIfExists('backup_chains');
        Schema::dropIfExists('backup_operations');
    }
};
