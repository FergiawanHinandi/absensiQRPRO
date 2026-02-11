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
        // CONFLICT RESOLUTION:
        // This migration was creating 'schools' table which conflicts with 2026_01_19_045132_create_schools_table.php
        // We disable this part to allow the main migration to handle the schema.

        /*
        if (!Schema::hasTable('schools')) {
            Schema::create('schools', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('code')->unique();
                $table->text('address')->nullable();
                $table->string('phone')->nullable();
                $table->string('email')->nullable();
                $table->string('timezone')->default('Asia/Jakarta');
                $table->decimal('latitude', 10, 8)->nullable();
                $table->decimal('longitude', 11, 8)->nullable();
                $table->integer('radius_meters')->default(100);
                $table->string('logo_url')->nullable();
                $table->json('settings')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['is_active']);
                $table->index(['code']);
            });
        }
        */

        /*
        // Add school_id to users table if not exists
        if (!Schema::hasColumn('users', 'school_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('school_id')->nullable()->after('id')->constrained('schools')->onDelete('cascade');
                $table->index(['school_id']);
            });
        }
        */
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('users', 'school_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign(['school_id']);
                $table->dropColumn('school_id');
            });
        }

        // Schema::dropIfExists('schools');
    }
};
