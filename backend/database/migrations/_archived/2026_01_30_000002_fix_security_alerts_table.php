<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CRITICAL: Fix security_alerts table schema
     */
    public function up(): void
    {
        // Check if table exists
        if (! Schema::hasTable('security_alerts')) {
            Schema::create('security_alerts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('school_id')->nullable();
                $table->string('type')->default('login'); // Fix: Add default value
                $table->string('severity')->default('medium');
                $table->text('description');
                $table->ipAddress('ip_address')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->foreign('school_id')->references('id')->on('schools')->onDelete('cascade');
                $table->index(['school_id', 'type', 'created_at']);
            });
        } else {
            // If table exists, modify it
            Schema::table('security_alerts', function (Blueprint $table) {
                // Make type column nullable or add default
                if (Schema::hasColumn('security_alerts', 'type')) {
                    $table->string('type')->default('login')->change();
                }

                // Make severity column nullable or add default
                if (Schema::hasColumn('security_alerts', 'severity')) {
                    $table->string('severity')->default('medium')->change();
                }

                // Ensure description is nullable for now
                if (Schema::hasColumn('security_alerts', 'description')) {
                    $table->text('description')->nullable()->change();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_alerts');
    }
};
