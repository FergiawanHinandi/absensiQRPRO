<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEC-4: DB-backed ownership for secure file uploads.
 *
 * Previously files were addressable purely by storage path, so any
 * authenticated user who knew/guessed a path could read or delete
 * another user's files (IDOR). This table records per-file ownership
 * so every access can be scoped to user_id + school_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secure_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->string('storage_path')->unique();
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->string('category')->default('general');
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'school_id'], 'idx_secure_files_owner');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secure_files');
    }
};
