<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Memperbaiki ukuran kolom yang di-encrypt di tabel user_profiles.
     * Laravel encrypted cast menghasilkan string base64 panjang (~200-300 chars),
     * sehingga varchar(10) atau varchar(20) tidak cukup.
     */
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            // nisn: encrypted, butuh > varchar(10)
            $table->text('nisn')->nullable()->change();
            
            // phone: encrypted, butuh > varchar(20)
            $table->text('phone')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->string('nisn', 10)->nullable()->change();
            $table->string('phone', 20)->nullable()->change();
        });
    }
};
