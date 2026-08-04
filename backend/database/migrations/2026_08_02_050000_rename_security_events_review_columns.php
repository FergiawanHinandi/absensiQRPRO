<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Upgrade databases that still carry the legacy column names
     * (details / reviewed / reviewed_by / reviewed_at / review_notes)
     * to the new unified names (context / is_resolved / resolved_by /
     * resolved_at / resolution_notes).
     *
     * Fresh installs already get the new names from the create migration,
     * so each rename below is guarded by Schema::hasColumn - it becomes a
     * no-op when the target columns are already in place.
     */
    public function up(): void
    {
        if (! Schema::hasTable('security_events')) {
            return;
        }

        Schema::table('security_events', function (Blueprint $table) {
            if (Schema::hasColumn('security_events', 'details') && ! Schema::hasColumn('security_events', 'context')) {
                $table->renameColumn('details', 'context');
            }

            if (Schema::hasColumn('security_events', 'reviewed') && ! Schema::hasColumn('security_events', 'is_resolved')) {
                $table->renameColumn('reviewed', 'is_resolved');
            }

            if (Schema::hasColumn('security_events', 'reviewed_by') && ! Schema::hasColumn('security_events', 'resolved_by')) {
                $table->renameColumn('reviewed_by', 'resolved_by');
            }

            if (Schema::hasColumn('security_events', 'reviewed_at') && ! Schema::hasColumn('security_events', 'resolved_at')) {
                $table->renameColumn('reviewed_at', 'resolved_at');
            }

            if (Schema::hasColumn('security_events', 'review_notes') && ! Schema::hasColumn('security_events', 'resolution_notes')) {
                $table->renameColumn('review_notes', 'resolution_notes');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('security_events', function (Blueprint $table) {
            if (Schema::hasColumn('security_events', 'context') && ! Schema::hasColumn('security_events', 'details')) {
                $table->renameColumn('context', 'details');
            }

            if (Schema::hasColumn('security_events', 'is_resolved') && ! Schema::hasColumn('security_events', 'reviewed')) {
                $table->renameColumn('is_resolved', 'reviewed');
            }

            if (Schema::hasColumn('security_events', 'resolved_by') && ! Schema::hasColumn('security_events', 'reviewed_by')) {
                $table->renameColumn('resolved_by', 'reviewed_by');
            }

            if (Schema::hasColumn('security_events', 'resolved_at') && ! Schema::hasColumn('security_events', 'reviewed_at')) {
                $table->renameColumn('resolved_at', 'reviewed_at');
            }

            if (Schema::hasColumn('security_events', 'resolution_notes') && ! Schema::hasColumn('security_events', 'review_notes')) {
                $table->renameColumn('resolution_notes', 'review_notes');
            }
        });
    }
};