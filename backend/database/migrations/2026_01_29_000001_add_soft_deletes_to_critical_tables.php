<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SECURITY MIGRATION: Add SoftDeletes to critical tables
 *
 * This migration adds deleted_at column to critical tables for:
 * - Data recovery capability
 * - Audit trail preservation
 * - Accidental deletion protection
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add soft deletes to schools table
        if (! Schema::hasColumn('schools', 'deleted_at')) {
            Schema::table('schools', function (Blueprint $table) {
                $table->softDeletes();
                $table->index('deleted_at');
            });
        }

        // Add soft deletes to users table
        if (! Schema::hasColumn('users', 'deleted_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->softDeletes();
                $table->index('deleted_at');
            });
        }

        // Add soft deletes to schedules table
        if (! Schema::hasColumn('schedules', 'deleted_at')) {
            Schema::table('schedules', function (Blueprint $table) {
                $table->softDeletes();
                $table->index('deleted_at');
            });
        }

        // Add soft deletes to classes table (ClassModel)
        if (! Schema::hasColumn('classes', 'deleted_at')) {
            Schema::table('classes', function (Blueprint $table) {
                $table->softDeletes();
                $table->index('deleted_at');
            });
        }

        // Add soft deletes to subjects table
        if (! Schema::hasColumn('subjects', 'deleted_at')) {
            Schema::table('subjects', function (Blueprint $table) {
                $table->softDeletes();
                $table->index('deleted_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('schedules', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('classes', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('subjects', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
