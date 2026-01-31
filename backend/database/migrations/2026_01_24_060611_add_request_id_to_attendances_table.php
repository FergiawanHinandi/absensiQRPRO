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
        if (! Schema::hasColumn('attendances', 'request_id')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->uuid('request_id')->after('device_id_in');
                $table->unique('request_id', 'attendances_request_id_unique');
            });
        } else {
            Schema::table('attendances', function (Blueprint $table) {
                $table->unique('request_id', 'attendances_request_id_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropUnique('attendances_request_id_unique');
        });
        if (Schema::hasColumn('attendances', 'request_id')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->dropColumn('request_id');
            });
        }
    }
};
