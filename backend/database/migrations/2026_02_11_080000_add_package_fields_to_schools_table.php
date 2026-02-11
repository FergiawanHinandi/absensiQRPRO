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
            if (!Schema::hasColumn('schools', 'package_type')) {
                $table->string('package_type', 50)->nullable()->after('is_active');
            }
            if (!Schema::hasColumn('schools', 'max_students')) {
                $table->integer('max_students')->default(100)->after('package_type');
            }
            if (!Schema::hasColumn('schools', 'max_teachers')) {
                $table->integer('max_teachers')->default(10)->after('max_students');
            }
            if (!Schema::hasColumn('schools', 'max_classes')) {
                $table->integer('max_classes')->default(5)->after('max_teachers');
            }
            if (!Schema::hasColumn('schools', 'package_updated_at')) {
                $table->timestamp('package_updated_at')->nullable()->after('max_classes');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn([
                'package_type',
                'max_students',
                'max_teachers',
                'max_classes',
                'package_updated_at',
            ]);
        });
    }
};
