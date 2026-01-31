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
        Schema::table('schools', function (Blueprint $table) {
            $table->string('package_type')->default('basic')->after('settings');
            $table->integer('max_students')->default(500)->after('package_type');
            $table->integer('max_teachers')->default(50)->after('max_students');
            $table->integer('max_classes')->default(20)->after('max_teachers');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['package_type', 'max_students', 'max_teachers', 'max_classes']);
        });
    }
};
