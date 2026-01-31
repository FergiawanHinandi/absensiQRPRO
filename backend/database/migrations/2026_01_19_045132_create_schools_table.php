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
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('npsn', 20)->unique()->comment('Nomor Pokok Sekolah Nasional');
            $table->enum('school_level', ['SD', 'SMP', 'SMA', 'SMK']);
            $table->text('address')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 100)->unique()->nullable();
            $table->decimal('latitude', 10, 8)->nullable()->comment('GPS coordinate');
            $table->decimal('longitude', 11, 8)->nullable()->comment('GPS coordinate');
            $table->integer('radius_meters')->default(100)->comment('Attendance area radius');
            $table->string('timezone', 50)->default('Asia/Jakarta');
            $table->string('logo_url', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable()->comment('School configuration JSON');
            $table->timestamps();

            // Indexes
            $table->index('npsn', 'idx_schools_npsn');
            $table->index('school_level', 'idx_schools_level');
            $table->index('is_active', 'idx_schools_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schools');
    }
};
