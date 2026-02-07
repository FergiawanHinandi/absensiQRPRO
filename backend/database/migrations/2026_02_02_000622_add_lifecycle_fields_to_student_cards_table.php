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
        Schema::table('student_cards', function (Blueprint $table) {
            $table->timestamp('distributed_at')->nullable()->after('issued_by');
            $table->foreignId('distributed_by')->nullable()->constrained('users')->after('distributed_at');
            $table->timestamp('revoked_at')->nullable()->after('distributed_by');
            $table->string('revoked_reason')->nullable()->after('revoked_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_cards', function (Blueprint $table) {
            $table->dropForeign(['distributed_by']);
            $table->dropColumn(['distributed_at', 'distributed_by', 'revoked_at', 'revoked_reason']);
        });
    }
};
