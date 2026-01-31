<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DevelopmentSeeder extends Seeder
{
    /**
     * Run comprehensive seeders for development environment.
     */
    public function run(): void
    {
        $this->command->info('Starting Development Seeding...');

        // Seed roles and permissions
        $this->call(RolePermissionSeeder::class);

        // Seed schools
        $this->call(SchoolSeeder::class);

        // Seed demo data (Users, Classes, Schedules)
        $this->call(DemoDataSeeder::class);

        $this->command->info('Development seeding completed!');
    }
}
