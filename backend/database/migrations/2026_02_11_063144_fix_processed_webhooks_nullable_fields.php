<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CRITICAL FIX: Make processed_at nullable and rename notes to processing_notes
     * 
     * This fixes the issue where markAsProcessing() cannot create records
     * because processed_at is NOT NULL in the old schema.
     */
    public function up(): void
    {
        Schema::table('processed_webhooks', function (Blueprint $table) {
            // Make processed_at nullable to support "processing" state
            $table->timestamp('processed_at')->nullable()->change();
            
            // Rename 'notes' to 'processing_notes' if it exists
            if (Schema::hasColumn('processed_webhooks', 'notes')) {
                $table->renameColumn('notes', 'processing_notes');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('processed_webhooks', function (Blueprint $table) {
            // Revert processed_at to NOT NULL
            $table->timestamp('processed_at')->nullable(false)->change();
            
            // Rename back to 'notes'
            if (Schema::hasColumn('processed_webhooks', 'processing_notes')) {
                $table->renameColumn('processing_notes', 'notes');
            }
        });
    }
};
