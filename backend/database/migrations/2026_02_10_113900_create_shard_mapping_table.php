<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Skip if mysql_global connection is not configured (e.g., in tests)
        if (!config('database.connections.mysql_global')) {
            return;
        }

        Schema::connection('mysql_global')->create('shard_mapping', function (Blueprint $table) {
            $table->id('shard_id');
            $table->string('shard_name', 50)->unique()->comment('Laravel connection name');
            $table->unsignedInteger('school_id_min')->comment('Minimum school_id in this shard');
            $table->unsignedInteger('school_id_max')->comment('Maximum school_id in this shard');
            $table->enum('status', ['active', 'readonly', 'migrating', 'inactive'])->default('active');
            $table->string('host')->nullable()->comment('Database host');
            $table->unsignedInteger('port')->default(3306)->comment('Database port');
            $table->string('database')->nullable()->comment('Database name');
            $table->text('notes')->nullable()->comment('Admin notes');
            $table->timestamps();

            // Indexes
            $table->index(['school_id_min', 'school_id_max'], 'idx_school_range');
            $table->index('status');
        });

        // Insert initial shard configuration
        DB::connection('mysql_global')->table('shard_mapping')->insert([
            [
                'shard_name' => 'mysql_shard_1',
                'school_id_min' => 1,
                'school_id_max' => 1000,
                'status' => 'active',
                'host' => env('DB_SHARD_1_HOST', '127.0.0.1'),
                'port' => env('DB_SHARD_1_PORT', 3306),
                'database' => env('DB_SHARD_1_DATABASE', 'attendance_shard_1'),
                'notes' => 'Initial shard for schools 1-1000',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'shard_name' => 'mysql_shard_2',
                'school_id_min' => 1001,
                'school_id_max' => 2000,
                'status' => 'active',
                'host' => env('DB_SHARD_2_HOST', '127.0.0.1'),
                'port' => env('DB_SHARD_2_PORT', 3306),
                'database' => env('DB_SHARD_2_DATABASE', 'attendance_shard_2'),
                'notes' => 'Second shard for schools 1001-2000',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'shard_name' => 'mysql_shard_3',
                'school_id_min' => 2001,
                'school_id_max' => 3000,
                'status' => 'active',
                'host' => env('DB_SHARD_3_HOST', '127.0.0.1'),
                'port' => env('DB_SHARD_3_PORT', 3306),
                'database' => env('DB_SHARD_3_DATABASE', 'attendance_shard_3'),
                'notes' => 'Third shard for schools 2001-3000',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Skip if mysql_global connection is not configured (e.g., in tests)
        if (!config('database.connections.mysql_global')) {
            return;
        }

        Schema::connection('mysql_global')->dropIfExists('shard_mapping');
    }
};
