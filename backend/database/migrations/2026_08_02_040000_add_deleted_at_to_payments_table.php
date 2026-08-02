<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FIX: Model Payment menggunakan trait SoftDeletes, tetapi kolom
     * `deleted_at` tidak pernah dibuat di migrasi create_payments_table.
     * Akibatnya semua query Payment (mis. dashboard Super Admin) gagal
     * dengan error "column payments.deleted_at does not exist".
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
