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
        // BUGFIX A3-C3: Tabel slow_queries mungkin sudah ada karena
        // migration duplikat (115524) sempat berjalan sebelumnya.
        if (Schema::hasTable('slow_queries')) {
            return;
        }

        Schema::create('slow_queries', function (Blueprint $table) {
            $table->id();
            $table->text('sql');
            $table->json('bindings')->nullable();
            $table->decimal('execution_time', 10, 2); // milliseconds
            $table->string('connection')->default('mysql');
            $table->text('execution_plan')->nullable();
            $table->string('request_id')->nullable();
            $table->string('user_id')->nullable();
            $table->string('school_id')->nullable();
            $table->string('route')->nullable();
            $table->string('method')->nullable();
            $table->integer('query_count')->default(1); // For aggregation
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            // Indexes for dashboard queries
            $table->index('execution_time');
            $table->index('last_seen_at');
            $table->index(['school_id', 'execution_time']);
            $table->index('created_at');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slow_queries');
    }
};
