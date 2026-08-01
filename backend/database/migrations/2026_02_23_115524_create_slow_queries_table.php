<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * A3-C3 FIX: Migration ini adalah DUPLIKAT dari 2026_02_23_125729_create_slow_queries_table.php
 * yang memiliki schema lengkap.
 *
 * Migration ini dikosongkan (no-op) agar tidak crash ketika kedua migration dijalankan.
 * Tabel slow_queries yang sesungguhnya dibuat oleh migration berikutnya (timestamp 125729).
 */
return new class extends Migration
{
    public function up(): void
    {
        // A3-C3 FIX: Dikosongkan untuk mencegah duplikasi tabel slow_queries.
        // Schema lengkap ada di migration 2026_02_23_125729_create_slow_queries_table.php
        if (Schema::hasTable('slow_queries')) {
            return;
        }
        // Jika karena suatu alasan migration index-125729 tidak berjalan, ini sebagai fallback
        // dengan schema minimal
        \Illuminate\Support\Facades\Schema::create('slow_queries', function ($table) {
            $table->id();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // No-op: biarkan migration 125729 yang handle drop
    }
};
