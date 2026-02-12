<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\ImmutableSecurityLog;
use Carbon\Carbon;

class ImmutableSecurityLogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Only run if table is empty to preserve chain integrity
        if (DB::table('immutable_security_logs')->count() > 1) { // 1 is genesis block
            $this->command->info('Immutable security logs already populated. Skipping.');
            return;
        }

        $service = app(\App\Services\ImmutableSecurityLogService::class);
        $users = \App\Models\User::take(3)->get();
        if ($users->isEmpty()) {
            return;
        }

        $this->command->info('Seeding immutable security logs...');

        // Create a chain of events
        foreach(range(1, 10) as $i) {
            $user = $users->random();
            $service->write(
                'login_success',
                "User {$user->name} logged in successfully",
                $user->id,
                $user->school_id,
                ['ip' => '127.0.0.1', 'device' => 'Seeder']
            );
            
            // Add slight delay to ensure timestamp diffs if needed (though sequence handles order)
        }

        $this->command->info('Immutable security logs seeded with valid hash chain.');
    }
}
