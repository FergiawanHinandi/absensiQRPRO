<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('photo_review_status')->nullable()->default('pending');
            $table->timestamp('photo_reviewed_at')->nullable();
            $table->unsignedBigInteger('photo_reviewed_by')->nullable();
            $table->foreign('photo_reviewed_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['photo_reviewed_by']);
            $table->dropColumn(['photo_review_status', 'photo_reviewed_at', 'photo_reviewed_by']);
        });
    }
};
