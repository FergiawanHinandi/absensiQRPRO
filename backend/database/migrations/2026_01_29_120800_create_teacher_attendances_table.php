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
        Schema::create('teacher_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->onDelete('cascade');
            $table->foreignId('teacher_id')->constrained('users')->onDelete('cascade');
            $table->date('attendance_date');
            $table->enum('status', ['present', 'late', 'absent', 'sick', 'permit', 'excused'])->default('present');
            $table->timestamp('check_in_time')->nullable();
            $table->timestamp('check_out_time')->nullable();
            $table->decimal('lat_in', 10, 8)->nullable(); // Check-in latitude
            $table->decimal('lng_in', 11, 8)->nullable(); // Check-in longitude
            $table->decimal('lat_out', 10, 8)->nullable(); // Check-out latitude
            $table->decimal('lng_out', 11, 8)->nullable(); // Check-out longitude
            $table->float('accuracy_in')->nullable(); // GPS accuracy on check-in (meters)
            $table->float('accuracy_out')->nullable(); // GPS accuracy on check-out (meters)
            $table->string('device_id_in')->nullable(); // Device used for check-in
            $table->string('device_id_out')->nullable(); // Device used for check-out
            $table->float('distance_in')->nullable(); // Distance from school on check-in (meters)
            $table->float('distance_out')->nullable(); // Distance from school on check-out (meters)
            $table->boolean('is_manual')->default(false);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->onDelete('set null');
            $table->text('notes')->nullable();
            $table->string('request_id')->nullable(); // For idempotency
            $table->timestamps();
            $table->softDeletes();

            // Unique: One attendance per teacher per day
            $table->unique(['teacher_id', 'attendance_date']);

            // Indexes for querying
            $table->index(['school_id', 'attendance_date']);
            $table->index(['teacher_id', 'attendance_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teacher_attendances');
    }
};
