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
        // Only create if not exists
        if (! Schema::hasTable('security_events')) {
            Schema::create('security_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
                // student_id points to users table as there is no 'students' table
                $table->foreignId('student_id')->nullable()->constrained('users')->onDelete('set null');

                $table->string('event_type', 50);
                $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');

                $table->string('user_type')->nullable()->comment('student, teacher, admin');
                $table->string('ip_address', 45)->nullable();
                $table->string('device_id')->nullable();
                $table->text('user_agent')->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->text('message')->nullable();
                $table->json('context')->nullable();

                $table->boolean('is_resolved')->default(false);
                $table->foreignId('resolved_by')->nullable()->constrained('users')->onDelete('set null');
                $table->timestamp('resolved_at')->nullable();
                $table->text('resolution_notes')->nullable();

                $table->timestamps();

                // Indexes for performance
                $table->index(['school_id', 'created_at']);
                $table->index(['event_type', 'severity']);
                $table->index(['student_id', 'created_at']);
                $table->index('device_id');
            });
        }

        if (! Schema::hasTable('suspicious_students')) {
            Schema::create('suspicious_students', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                // student_id points to users table
                $table->foreignId('student_id')->constrained('users')->onDelete('cascade');

                $table->string('flag_reason', 50);
                $table->integer('violation_count')->default(1);
                $table->json('evidence')->nullable();

                $table->enum('status', ['flagged', 'under_review', 'cleared', 'confirmed'])->default('flagged');
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->onDelete('set null');
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_notes')->nullable();

                $table->timestamp('flagged_at');
                $table->timestamp('cleared_at')->nullable();

                $table->timestamps();

                $table->index(['school_id', 'status']);
                $table->index(['student_id', 'status']);
            });
        }

        if (! Schema::hasTable('suspicious_devices')) {
            Schema::create('suspicious_devices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('school_id')->constrained()->onDelete('cascade');
                $table->string('device_id')->unique();

                $table->integer('unique_students_count')->default(1);
                $table->integer('scan_attempt_count')->default(1);
                $table->integer('failed_attempt_count')->default(0);

                $table->json('student_ids')->nullable();
                $table->json('ip_addresses')->nullable();

                $table->enum('risk_level', ['low', 'medium', 'high', 'critical'])->default('low');
                $table->boolean('blocked')->default(false);
                $table->timestamp('blocked_at')->nullable();

                $table->timestamp('first_seen_at');
                $table->timestamp('last_seen_at');

                $table->timestamps();

                $table->index(['school_id', 'risk_level']);
                $table->index('blocked');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suspicious_devices');
        Schema::dropIfExists('suspicious_students');
        Schema::dropIfExists('security_events');
    }
};
