<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LevelSystemTest extends TestCase
{
    // use RefreshDatabase; // Commented out to avoid wiping DB if not configured correctly for testing

    public function test_level_calculation_bronze()
    {
        $user = new User(['total_points' => 100]);
        $this->assertEquals('Bronze', $user->level);

        $user = new User(['total_points' => 0]);
        $this->assertEquals('Bronze', $user->level);

        $user = new User(['total_points' => 200]);
        $this->assertEquals('Bronze', $user->level);
    }

    public function test_level_calculation_silver()
    {
        $user = new User(['total_points' => 201]);
        $this->assertEquals('Silver', $user->level);

        $user = new User(['total_points' => 500]);
        $this->assertEquals('Silver', $user->level);
    }

    public function test_level_calculation_gold()
    {
        $user = new User(['total_points' => 501]);
        $this->assertEquals('Gold', $user->level);

        $user = new User(['total_points' => 1000]);
        $this->assertEquals('Gold', $user->level);
    }

    public function test_level_calculation_platinum()
    {
        $user = new User(['total_points' => 1001]);
        $this->assertEquals('Platinum', $user->level);

        $user = new User(['total_points' => 5000]);
        $this->assertEquals('Platinum', $user->level);
    }
}
