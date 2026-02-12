<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('job_id')->unique();
            $table->string('job_type'); // backup, restore, rollback
            $table->string('status'); // running, success, failed, cancelled
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->unsignedBigInteger('backup_size_bytes')->nullable();
            $table->integer('progress_percentage')->default(0);
            $table->string('status_message')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->json('metrics')->nullable();
            $table->unsignedBigInteger('school_id')->nullable();
            $table->foreign('school_id')->references('id')->on('schools')->onDelete('set null');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->timestamps();

            $table->index(['job_type', 'status']);
            $table->index(['created_at']);
            $table->index(['school_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_jobs');
    }
};
