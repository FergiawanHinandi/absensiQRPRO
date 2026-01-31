<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSecurityPolicyHistoryTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('security_policy_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('policy_id');
            $table->json('old_value');
            $table->json('new_value');
            $table->unsignedBigInteger('changed_by');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('policy_id')->references('id')->on('security_policies')->onDelete('cascade');
            $table->index('policy_id');
            $table->index('changed_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::dropIfExists('security_policy_history');
    }
}
