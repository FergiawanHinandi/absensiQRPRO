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
        Schema::table('audit_logs', function (Blueprint $table) {
            // Check if column exists before adding (defensive coding)
            if (! Schema::hasColumn('audit_logs', 'module')) {
                $table->string('module')->nullable()->after('school_id')->index();
            }

            if (! Schema::hasColumn('audit_logs', 'severity')) {
                $table->string('severity')->default('info')->after('action')->index()->comment('info, warning, critical');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn(['module', 'severity']);
        });
    }
};
