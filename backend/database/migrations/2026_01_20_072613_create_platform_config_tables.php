<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Feature Flags Table
        Schema::create('feature_flags', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // e.g., 'enable_face_recognition'
            $table->string('label');         // e.g., 'Face Recognition Attendance'
            $table->boolean('is_enabled')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Seed initial feature flags
        DB::table('feature_flags')->insert([
            ['key' => 'module_billing', 'label' => 'Billing & Payment Module', 'is_enabled' => true, 'description' => 'Enable billing management features'],
            ['key' => 'module_qr_attendance', 'label' => 'QR Code Attendance', 'is_enabled' => true, 'description' => 'Enable QR Code generation and scanning'],
            ['key' => 'beta_ai_analytics', 'label' => 'AI Analytics (Beta)', 'is_enabled' => false, 'description' => 'Experimental AI insights for attendance'],
            ['key' => 'maintenance_mode', 'label' => 'Maintenance Mode', 'is_enabled' => false, 'description' => 'Put system in maintenance mode for schools'],
        ]);

        // Schedule Templates Table
        Schema::create('schedule_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');         // e.g., 'Full Day School - SD'
            $table->text('description')->nullable();
            $table->json('schedule_structure'); // JSON containing periods, breaks, times
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_templates');
        Schema::dropIfExists('feature_flags');
    }
};
