<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // qr_replay_spike, login_attack, cross_school_access
            $table->string('severity')->default('medium'); // low, medium, high, critical
            $table->text('description');
            $table->json('details')->nullable(); // Count, source IPs, affected users
            $table->timestamp('detected_at')->useCurrent();
            $table->boolean('is_resolved')->default(false);
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_alerts');
    }
};
