<?php

namespace Database\Seeders;

use App\Models\School;
use App\Models\Subscription;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class SubscriptionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get all schools
        $schools = School::all();

        if ($schools->isEmpty()) {
            $this->command->warn('No schools found. Please run SchoolSeeder first.');
            return;
        }

        foreach ($schools as $school) {
            // Skip if subscription already exists
            if ($school->subscription()->exists()) {
                $this->command->info("Subscription already exists for school: {$school->name}");
                continue;
            }

            // Create subscription based on school ID (for demo purposes)
            $subscriptionData = $this->getSubscriptionData($school->id);

            Subscription::create([
                'school_id' => $school->id,
                'plan_type' => $subscriptionData['plan_type'],
                'is_active' => $subscriptionData['is_active'],
                'starts_at' => $subscriptionData['starts_at'],
                'expires_at' => $subscriptionData['expires_at'],
                'max_students' => $subscriptionData['max_students'],
                'max_teachers' => $subscriptionData['max_teachers'],
                'features' => $subscriptionData['features'],
            ]);

            $this->command->info("Created {$subscriptionData['plan_type']} subscription for: {$school->name}");
        }
    }

    /**
     * Get subscription data based on school ID (for demo)
     */
    private function getSubscriptionData(int $schoolId): array
    {
        // School ID 1: Active Premium (1 year)
        if ($schoolId === 1) {
            return [
                'plan_type' => 'premium',
                'is_active' => true,
                'starts_at' => now()->subMonths(2),
                'expires_at' => now()->addMonths(10),
                'max_students' => 1000,
                'max_teachers' => 50,
                'features' => ['reports', 'analytics', 'api_access', 'custom_branding', 'priority_support'],
            ];
        }

        // School ID 2: Active Basic (6 months)
        if ($schoolId === 2) {
            return [
                'plan_type' => 'basic',
                'is_active' => true,
                'starts_at' => now()->subMonth(),
                'expires_at' => now()->addMonths(5),
                'max_students' => 300,
                'max_teachers' => 15,
                'features' => ['reports', 'basic_analytics'],
            ];
        }

        // School ID 3: Expired (for testing)
        if ($schoolId === 3) {
            return [
                'plan_type' => 'basic',
                'is_active' => false,
                'starts_at' => now()->subYear(),
                'expires_at' => now()->subMonths(2), // Expired 2 months ago
                'max_students' => 300,
                'max_teachers' => 15,
                'features' => ['reports'],
            ];
        }

        // School ID 4: Expiring Soon (3 days)
        if ($schoolId === 4) {
            return [
                'plan_type' => 'premium',
                'is_active' => true,
                'starts_at' => now()->subMonths(11),
                'expires_at' => now()->addDays(3), // Expiring in 3 days
                'max_students' => 1000,
                'max_teachers' => 50,
                'features' => ['reports', 'analytics', 'api_access'],
            ];
        }

        // School ID 5: In Grace Period (expired 2 days ago)
        if ($schoolId === 5) {
            return [
                'plan_type' => 'basic',
                'is_active' => false,
                'starts_at' => now()->subYear(),
                'expires_at' => now()->subDays(2), // Expired 2 days ago, still in 7-day grace
                'max_students' => 300,
                'max_teachers' => 15,
                'features' => ['reports'],
            ];
        }

        // Default: Free tier (30 days trial)
        return [
            'plan_type' => 'free',
            'is_active' => true,
            'starts_at' => now(),
            'expires_at' => now()->addDays(30),
            'max_students' => 50,
            'max_teachers' => 5,
            'features' => ['basic_reports'],
        ];
    }
}
