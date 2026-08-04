<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allow security_events without a school.
     *
     * Anonymous security events (rate limit exceeded, unauthorized
     * access attempts before authentication, etc.) have no user and
     * therefore no school — the tenant key cannot be derived.
     */
    public function up(): void
    {
        Schema::table('security_events', function (Blueprint $table) {
            $table->foreignId('school_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('security_events', function (Blueprint $table) {
            $table->foreignId('school_id')->nullable(false)->change();
        });
    }
};
