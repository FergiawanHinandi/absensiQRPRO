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
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('full_name', 255);
            $table->string('nik', 16)->nullable()->comment('NIK untuk guru/staff');
            $table->string('nisn', 10)->nullable()->comment('NISN untuk siswa');
            $table->string('nip', 20)->nullable()->comment('NIP untuk guru PNS');
            $table->enum('gender', ['male', 'female']);
            $table->date('birth_date')->nullable();
            $table->string('birth_place', 100)->nullable();
            $table->string('phone', 20)->nullable();
            $table->text('address')->nullable();
            $table->string('photo_url', 255)->nullable();
            $table->string('emergency_contact', 20)->nullable();
            $table->string('emergency_name', 255)->nullable();
            $table->enum('blood_type', ['A', 'B', 'AB', 'O'])->nullable();
            $table->string('religion', 50)->nullable();
            $table->json('metadata')->nullable()->comment('Additional data');
            $table->timestamps();

            // Indexes
            $table->unique('user_id');
            $table->index('nisn', 'idx_profiles_nisn');
            $table->index('nik', 'idx_profiles_nik');
            $table->index('nip', 'idx_profiles_nip');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
