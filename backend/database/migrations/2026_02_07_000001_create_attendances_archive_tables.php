<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archive Migration Template for Attendances
 * 
 * Creates yearly archive tables for attendance data
 * Pattern: attendances_{year}
 * 
 * Usage:
 * - Run this migration to create archive tables
 * - Use artisan command: php artisan attendance:archive {year}
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create archive tables for past years
        $this->createArchiveTable(2024);
        $this->createArchiveTable(2025);
        
        // Current year will remain in main table
        // Future years can be added as needed
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances_2024');
        Schema::dropIfExists('attendances_2025');
    }

    /**
     * Create archive table for specific year
     * 
     * @param int $year
     */
    protected function createArchiveTable(int $year): void
    {
        $tableName = "attendances_{$year}";
        
        if (Schema::hasTable($tableName)) {
            return; // Skip if already exists
        }

        Schema::create($tableName, function (Blueprint $table) use ($year) {
            // Primary key
            $table->id();
            
            // Foreign keys
            $table->foreignId('school_id')->constrained('schools')->onDelete('cascade');
            $table->foreignId('student_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('class_id')->constrained('classes')->onDelete('cascade');
            $table->foreignId('session_id')->nullable(); // No FK constraint if table doesn't exist
            
            // Attendance data
            $table->date('attendance_date');
            $table->time('check_in_time')->nullable();
            $table->time('check_out_time')->nullable();
            $table->enum('status', ['present', 'late', 'absent', 'sick', 'permit', 'excused'])->default('absent');
            
            // Location data
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('location_address')->nullable();
            
            // Metadata
            $table->boolean('is_manual')->default(false);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->onDelete('set null');
            $table->text('notes')->nullable();
            
            // Device tracking
            $table->string('device_id')->nullable();
            $table->string('device_name')->nullable();
            $table->string('ip_address', 45)->nullable();
            
            // Timestamps
            $table->timestamps();
            $table->softDeletes();
            
            // ✅ OPTIMIZED INDEXES for archive queries
            // Most common query: by school + date range
            $table->index(['school_id', 'attendance_date'], "idx_{$year}_school_date");
            
            // Query by class
            $table->index(['class_id', 'attendance_date'], "idx_{$year}_class_date");
            
            // Query by student (for student reports)
            $table->index(['student_id', 'attendance_date'], "idx_{$year}_student_date");
            
            // Query by status (for statistics)
            $table->index(['school_id', 'status', 'attendance_date'], "idx_{$year}_school_status_date");
            
            // Session tracking
            $table->index('session_id', "idx_{$year}_session");
            
            // Soft delete queries
            $table->index('deleted_at', "idx_{$year}_deleted");
            
            // Composite index for common report queries
            $table->index(['school_id', 'class_id', 'attendance_date', 'status'], "idx_{$year}_report_query");
        });

        // Add comment to table (PostgreSQL only)
        // SQLite doesn't support COMMENT ON TABLE syntax
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("COMMENT ON TABLE {$tableName} IS 'Archive table for attendance records from year " . $year . "'");
        }
    }
};
