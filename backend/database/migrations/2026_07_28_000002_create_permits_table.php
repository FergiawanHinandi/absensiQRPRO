<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Tabel untuk pengajuan izin/sakit siswa dengan workflow approval.
     * Guru/Wali Kelas dapat mengajukan, Kepala Sekolah menyetujui.
     */
    public function up(): void
    {
        Schema::create('permits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->onDelete('cascade');
            $table->foreignId('student_id')->constrained('users')->onDelete('cascade');
            $table->date('date')->comment('Tanggal izin/sakit');
            $table->date('end_date')->nullable()->comment('Tanggal selesai izin (jika lebih dari 1 hari)');
            $table->enum('type', ['Sakit', 'Izin', 'Keperluan Keluarga', 'Lainnya'])->default('Izin');
            $table->text('notes')->nullable()->comment('Keterangan tambahan');
            $table->string('proof_file', 255)->nullable()->comment('File bukti (surat dokter, surat izin)');
            $table->enum('status', ['Pending', 'Approved', 'Rejected'])->default('Pending');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->onDelete('set null')
                ->comment('Yang mengajukan (guru/orang tua)');
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null')
                ->comment('Yang menyetujui (kepala sekolah/guru)');
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable()->comment('Alasan penolakan');
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['student_id', 'date'], 'idx_permits_student_date');
            $table->index(['school_id', 'status'], 'idx_permits_school_status');
            $table->index(['school_id', 'date'], 'idx_permits_school_date');
            $table->index('approved_by', 'idx_permits_approved_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permits');
    }
};
