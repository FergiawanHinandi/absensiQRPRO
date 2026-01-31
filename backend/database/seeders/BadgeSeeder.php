<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class BadgeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $badges = [
            [
                'name' => '7-Day Warrior',
                'slug' => '7-day-warrior',
                'description' => 'Achieved a daily attendance streak of 7 days.',
                'icon' => 'sword',
            ],
            [
                'name' => 'Perfect Week',
                'slug' => 'perfect-week',
                'description' => 'No lateness or absence for an entire school week.',
                'icon' => 'star',
            ],
            [
                'name' => 'Perfect Month',
                'slug' => 'perfect-month',
                'description' => 'Outstanding attendance for an entire month.',
                'icon' => 'trophy',
            ],
            [
                'name' => 'On-Time Hero',
                'slug' => 'on-time-hero',
                'description' => '30 consecutive days without being late.',
                'icon' => 'clock',
            ],
        ];

        foreach ($badges as $badge) {
            \App\Models\Badge::firstOrCreate(
                ['slug' => $badge['slug']],
                $badge
            );
        }
    }
}
