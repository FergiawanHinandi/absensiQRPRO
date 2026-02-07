<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Backup executions tracking
        Schema::create('backup_executions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('backup_type', 50); // full, incremental, wal_archive, cleanup
            $table->integer('school_id')->nullable(); // null for global backups
            $table->string('status', 20)->default('started'); // started, running, completed, failed
            $table->timestamp('executed_at');
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->bigInteger('backup_size_bytes')->nullable();
            $table->decimal('compression_ratio', 5, 2)->nullable();
            $table->text('backup_path')->nullable();
            $table->json('metadata')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            
            $table->index(['backup_type', 'executed_at']);
            $table->index(['school_id', 'backup_type']);
            $table->index(['status', 'executed_at']);
            
            $table->foreign('school_id')->references('id')->on('schools')->onDelete('cascade');
        });
        
        // Backup chains for incremental backups
        Schema::create('backup_chains', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->integer('school_id')->nullable();
            $table->uuid('base_backup_id'); // Reference to full backup
            $table->string('backup_type', 20); // full, incremental, differential
            $table->integer('sequence_number')->default(0);
            $table->timestamp('backup_timestamp');
            $table->text('backup_path');
            $table->bigInteger('backup_size_bytes');
            $table->json('change_summary')->nullable(); // Summary of changes
            $table->string('checksum', 64);
            $table->timestamps();
            
            $table->index(['school_id', 'backup_timestamp']);
            $table->index(['base_backup_id', 'sequence_number']);
            $table->index(['backup_type', 'backup_timestamp']);
            
            $table->foreign('school_id')->references('id')->on('schools')->onDelete('cascade');
            $table->foreign('base_backup_id')->references('id')->on('backup_executions')->onDelete('cascade');
        });
        
        // WAL/Binlog archive tracking
        Schema::create('transaction_log_archives', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('log_type', 20); // wal, binlog
            $table->string('log_file_name');
            $table->text('archive_path');
            $table->timestamp('log_timestamp');
            $table->timestamp('archived_at');
            $table->bigInteger('file_size_bytes');
            $table->string('checksum', 64);
            $table->string('lsn_position')->nullable(); // For PostgreSQL WAL
            $table->string('binlog_position')->nullable(); // For MySQL binlog
            $table->timestamps();
            
            $table->index(['log_type', 'log_timestamp']);
            $table->index(['archived_at']);
            $table->unique(['log_type', 'log_file_name']);
        });
        
        // Point-in-time recovery tracking
        Schema::create('pitr_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->integer('school_id');
            $table->timestamp('target_timestamp');
            $table->timestamp('recovery_started_at');
            $table->timestamp('recovery_completed_at')->nullable();
            $table->string('status', 20); // started, in_progress, completed, failed, rolled_back
            $table->uuid('base_backup_id');
            $table->json('incremental_backups')->nullable(); // Array of backup IDs
            $table->json('transaction_logs')->nullable(); // Array of log file names
            $table->text('recovery_path');
            $table->integer('duration_seconds')->nullable();
            $table->text('verification_results')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('created_by'); // User who initiated recovery
            $table->timestamps();
            
            $table->index(['school_id', 'target_timestamp']);
            $table->index(['status', 'recovery_started_at']);
            
            $table->foreign('school_id')->references('id')->on('schools')->onDelete('cascade');
            $table->foreign('base_backup_id')->references('id')->on('backup_executions')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
        });
        
        // Backup verification results
        Schema::create('backup_verifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('backup_execution_id');
            $table->string('verification_type', 50); // integrity, consistency, restore_test
            $table->string('status', 20); // passed, failed, warning
            $table->timestamp('verified_at');
            $table->integer('duration_seconds');
            $table->json('test_results');
            $table->text('error_details')->nullable();
            $table->timestamps();
            
            $table->index(['backup_execution_id', 'verification_type']);
            $table->index(['status', 'verified_at']);
            
            $table->foreign('backup_execution_id')->references('id')->on('backup_executions')->onDelete('cascade');
        });
        
        // Backup metrics for monitoring
        Schema::create('backup_metrics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('metric_name', 100);
            $table->string('metric_type', 50); // counter, gauge, histogram
            $table->decimal('metric_value', 15, 4);
            $table->json('labels')->nullable(); // Additional metric labels
            $table->timestamp('recorded_at');
            $table->timestamps();
            
            $table->index(['metric_name', 'recorded_at']);
            $table->index(['metric_type', 'recorded_at']);
        });
        
        // Backup alerts and notifications
        Schema::create('backup_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('alert_type', 50); // backup_failure, storage_threshold, long_running
            $table->string('severity', 20); // info, warning, critical
            $table->string('title');
            $table->text('message');
            $table->json('context')->nullable(); // Additional alert context
            $table->timestamp('triggered_at');
            $table->timestamp('resolved_at')->nullable();
            $table->string('status', 20)->default('active'); // active, resolved, suppressed
            $table->json('notification_channels')->nullable(); // email, slack, sms
            $table->timestamps();
            
            $table->index(['alert_type', 'severity']);
            $table->index(['status', 'triggered_at']);
        });
    }
    
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backup_alerts');
        Schema::dropIfExists('backup_metrics');
        Schema::dropIfExists('backup_verifications');
        Schema::dropIfExists('pitr_operations');
        Schema::dropIfExists('transaction_log_archives');
        Schema::dropIfExists('backup_chains');
        Schema::dropIfExists('backup_executions');
    }
};