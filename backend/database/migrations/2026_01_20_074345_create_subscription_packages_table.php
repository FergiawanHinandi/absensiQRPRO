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
        Schema::create('subscription_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('price', 12, 2);
            $table->enum('billing_cycle', ['monthly', 'yearly'])->default('monthly');
            $table->json('features')->nullable(); // max_students, max_teachers, storage_gb, etc
            $table->boolean('is_popular')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Insert Default Packages
        DB::table('subscription_packages')->insert([
            [
                'name' => 'Basic',
                'price' => 500000,
                'billing_cycle' => 'monthly',
                'features' => json_encode([
                    'max_students' => 500,
                    'max_teachers' => 50,
                    'max_classes' => 20,
                    'storage_gb' => 10,
                    'support' => 'Email',
                ]),
                'is_popular' => false,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Pro',
                'price' => 1000000,
                'billing_cycle' => 'monthly',
                'features' => json_encode([
                    'max_students' => 1000,
                    'max_teachers' => 100,
                    'max_classes' => 40,
                    'storage_gb' => 50,
                    'support' => 'Priority Email & Phone',
                ]),
                'is_popular' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Premium',
                'price' => 2500000,
                'billing_cycle' => 'monthly',
                'features' => json_encode([
                    'max_students' => -1,
                    'max_teachers' => -1,
                    'max_classes' => -1,
                    'storage_gb' => 200,
                    'support' => '24/7 Dedicated Support',
                ]),
                'is_popular' => false,
                'is_active' => true,
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
        Schema::dropIfExists('subscription_packages');
    }
};
