<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refresh_tokens', function (Blueprint $table) {
            $table->timestamp('session_started_at')->nullable()->after('rotation_count');
        });

        // Backfill existing tokens: set session_started_at to created_at
        DB::table('refresh_tokens')
            ->whereNull('session_started_at')
            ->update(['session_started_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('refresh_tokens', function (Blueprint $table) {
            $table->dropColumn('session_started_at');
        });
    }
};
