<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Tabel untuk menyimpan perangkat Solution X606-S Fingerprint & RFID Reader
     * yang terhubung via ADMS Push Server Protocol.
     */
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->onDelete('cascade');
            $table->string('sn', 50)->unique()->comment('Serial Number perangkat Solution X606-S');
            $table->string('name', 100)->comment('Nama perangkat, e.g. Mesin Absensi Kelas 1A');
            $table->string('type', 50)->default('fingerprint_rfid')
                ->comment('Tipe: fingerprint_rfid, qr_scanner, dll');
            $table->string('model', 100)->nullable()->comment('Model perangkat, e.g. Solution X606-S');
            $table->string('ip_address', 45)->nullable()->comment('IP Address perangkat di jaringan lokal');
            $table->integer('port')->nullable()->comment('Port koneksi ADMS Push Server');
            $table->string('location', 255)->nullable()->comment('Lokasi perangkat dipasang');
            $table->timestamp('last_ping_at')->nullable()->comment('Waktu terakhir perangkat mengirim sinyal');
            $table->boolean('is_online')->default(false)->comment('Status koneksi perangkat');
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable()->comment('Konfigurasi tambahan perangkat');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['school_id', 'is_online'], 'idx_devices_school_online');
            $table->index(['school_id', 'is_active'], 'idx_devices_school_active');
        });

        // Pivot table: devices <-> teachers (assign device to teacher)
        Schema::create('device_teacher', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->onDelete('cascade');
            $table->foreignId('teacher_id')->constrained('users')->onDelete('cascade');
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('unassigned_at')->nullable();
            $table->boolean('is_active')->default(true);

            $table->unique(['device_id', 'teacher_id', 'is_active']);
            $table->index('teacher_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_teacher');
        Schema::dropIfExists('devices');
    }
};
