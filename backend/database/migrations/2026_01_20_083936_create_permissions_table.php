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
        if (! Schema::hasTable('student_permissions')) {
            Schema::create('student_permissions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('student_id')->constrained('users')->onDelete('cascade');
                $table->foreignId('school_id')->constrained('schools')->onDelete('cascade');
                $table->foreignId('class_id')->nullable()->constrained('classes')->onDelete('set null');

                $table->enum('type', ['sick', 'permit']);
                $table->string('reason'); // Judul/Alasan singkat
                $table->text('description')->nullable(); // Detail
                $table->string('attachment_path')->nullable(); // Bukti foto/surat

                $table->date('start_date');
                $table->date('end_date');

                $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
                $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
                $table->timestamp('approved_at')->nullable();

                $table->timestamps();

                $table->index(['school_id', 'status']);
                $table->index(['student_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_permissions');
    }
};
