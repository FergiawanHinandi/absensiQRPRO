<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates admin_activity_logs table for tracking all sensitive admin actions.
     * This table is protected against UPDATE and DELETE at the database level.
     */
    public function up(): void
    {
        Schema::create('admin_activity_logs', function (Blueprint $table) {
            $table->id();
            
            // Admin who performed the action
            $table->foreignId('admin_user_id')
                ->constrained('users')
                ->onDelete('cascade');
            
            // Role at time of action (super_admin | school_admin | admin)
            $table->string('role', 50);
            
            // School context (null for super_admin system-wide actions)
            $table->foreignId('school_id')
                ->nullable()
                ->constrained('schools')
                ->onDelete('set null');
            
            // Type of action performed
            $table->string('action_type', 100)->index();
            
            // Target entity type (e.g., "Teacher", "Schedule", "Student", "School")
            $table->string('target_type', 100)->nullable();
            
            // Target entity ID
            $table->unsignedBigInteger('target_id')->nullable();
            
            // Human-readable description of the action
            $table->text('description');
            
            // Additional structured data about the action
            $table->jsonb('metadata')->nullable();
            
            // Request context
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('route_name', 200)->nullable();
            $table->string('http_method', 10)->nullable();
            
            // Timestamp (no updated_at - records are immutable)
            $table->timestamp('created_at')->useCurrent();
            
            // Composite index for efficient queries
            $table->index(['admin_user_id', 'created_at']);
            $table->index(['school_id', 'created_at']);
            $table->index(['action_type', 'created_at']);
            $table->index(['target_type', 'target_id']);
        });

        // Create database triggers to prevent UPDATE and DELETE
        $this->createDatabaseTriggers();
    }

    /**
     * Create database triggers to prevent modification of audit logs.
     * This provides database-level enforcement even if application code is compromised.
     */
    protected function createDatabaseTriggers(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // PostgreSQL triggers
            DB::unprepared("
                CREATE OR REPLACE FUNCTION prevent_admin_activity_log_modification()
                RETURNS TRIGGER AS \$\$
                BEGIN
                    RAISE EXCEPTION 'admin_activity_logs table does not allow UPDATE or DELETE operations. This is an audit table.';
                    RETURN NULL;
                END;
                \$\$ LANGUAGE plpgsql;

                CREATE TRIGGER prevent_update_admin_activity_logs
                BEFORE UPDATE ON admin_activity_logs
                FOR EACH ROW
                EXECUTE FUNCTION prevent_admin_activity_log_modification();

                CREATE TRIGGER prevent_delete_admin_activity_logs
                BEFORE DELETE ON admin_activity_logs
                FOR EACH ROW
                EXECUTE FUNCTION prevent_admin_activity_log_modification();
            ");
        } elseif ($driver === 'mysql') {
            // MySQL triggers
            DB::unprepared("
                CREATE TRIGGER prevent_update_admin_activity_logs
                BEFORE UPDATE ON admin_activity_logs
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'admin_activity_logs table does not allow UPDATE operations';
                END;
            ");

            DB::unprepared("
                CREATE TRIGGER prevent_delete_admin_activity_logs
                BEFORE DELETE ON admin_activity_logs
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'admin_activity_logs table does not allow DELETE operations';
                END;
            ");
        }
        // SQLite doesn't support BEFORE triggers that can prevent operations
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        // Drop triggers first
        if ($driver === 'pgsql') {
            DB::unprepared("
                DROP TRIGGER IF EXISTS prevent_update_admin_activity_logs ON admin_activity_logs;
                DROP TRIGGER IF EXISTS prevent_delete_admin_activity_logs ON admin_activity_logs;
                DROP FUNCTION IF EXISTS prevent_admin_activity_log_modification();
            ");
        } elseif ($driver === 'mysql') {
            DB::unprepared("DROP TRIGGER IF EXISTS prevent_update_admin_activity_logs");
            DB::unprepared("DROP TRIGGER IF EXISTS prevent_delete_admin_activity_logs");
        }

        Schema::dropIfExists('admin_activity_logs');
    }
};
