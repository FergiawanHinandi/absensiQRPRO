<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSecurityPoliciesTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('security_policies', function (Blueprint $table) {
            $table->id();
            $table->enum('scope_type', ['global', 'school']);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('key');
            $table->json('value');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('updated_by');
            $table->timestamps();

            $table->unique(['scope_type', 'scope_id', 'key'], 'security_policies_scope_key_unique');
            $table->index(['scope_type', 'scope_id'], 'security_policies_scope_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::dropIfExists('security_policies');
    }
}
