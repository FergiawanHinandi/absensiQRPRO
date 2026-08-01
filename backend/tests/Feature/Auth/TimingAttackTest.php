<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * TimingAttackTest
 * Test login response times to prevent timing attacks.
 * SCENARIO:
 * - Compare response time for:
 *   1. Valid email + wrong password
 *   2. Invalid email (non-existent)
 * - Response times should be similar (within acceptable variance)
 * PROTECTION MECHANISMS:
 * - Constant-time comparison
 * - Dummy hash check for non-existent users
 * - Minimum response time enforcement
 * - Random jitter
 */
#[\PHPUnit\Framework\Attributes\Group('security')]
#[\PHPUnit\Framework\Attributes\Group('timing-attack')]
#[\PHPUnit\Framework\Attributes\Group('critical')]
class TimingAttackTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected User $validUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Create school
        $this->school = School::factory()->create(['name' => 'Test School']);

        // Create valid user
        $this->validUser = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'teacher',
            'email' => 'valid.user@example.com',
            'password' => Hash::make('correct-password'),
        ]);
    }

    /**
     * Test response time comparison
     * SCENARIO:
     * - Measure response time for valid email + wrong password
     * - Measure response time for invalid email
     * - Compare times - should be similar
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_has_similar_response_times_for_valid_and_invalid_emails()
    {
        $iterations = 20; // Number of samples
        $validEmailTimes = [];
        $invalidEmailTimes = [];

        echo "\n=== Timing Attack Test ===\n";
        echo "Iterations: $iterations\n\n";

        // Measure response times for valid email + wrong password
        echo "Testing: Valid email + wrong password\n";
        for ($i = 0; $i < $iterations; $i++) {
            $start = hrtime(true);

            $response = $this->postJson('/api/v1/auth/login', [
                'email' => 'valid.user@example.com', // Valid email
                'password' => 'wrong-password-' . $i, // Wrong password
            ]);

            $end = hrtime(true);
            $duration = ($end - $start) / 1_000_000; // Convert to milliseconds

            $validEmailTimes[] = $duration;

            $response->assertStatus(422); // Validation error
            $response->assertJsonStructure(['message']);

            echo sprintf("  Iteration %2d: %.2f ms\n", $i + 1, $duration);
        }

        echo "\nTesting: Invalid email (non-existent)\n";
        // Measure response times for invalid email
        for ($i = 0; $i < $iterations; $i++) {
            $start = hrtime(true);

            $response = $this->postJson('/api/v1/auth/login', [
                'email' => 'invalid.user' . $i . '@example.com', // Invalid email
                'password' => 'any-password-' . $i,
            ]);

            $end = hrtime(true);
            $duration = ($end - $start) / 1_000_000; // Convert to milliseconds

            $invalidEmailTimes[] = $duration;

            $response->assertStatus(422); // Validation error
            $response->assertJsonStructure(['message']);

            echo sprintf("  Iteration %2d: %.2f ms\n", $i + 1, $duration);
        }

        // Calculate statistics
        $validEmailAvg = array_sum($validEmailTimes) / count($validEmailTimes);
        $validEmailMin = min($validEmailTimes);
        $validEmailMax = max($validEmailTimes);
        $validEmailStdDev = $this->calculateStdDev($validEmailTimes);

        $invalidEmailAvg = array_sum($invalidEmailTimes) / count($invalidEmailTimes);
        $invalidEmailMin = min($invalidEmailTimes);
        $invalidEmailMax = max($invalidEmailTimes);
        $invalidEmailStdDev = $this->calculateStdDev($invalidEmailTimes);

        // Calculate difference
        $avgDifference = abs($validEmailAvg - $invalidEmailAvg);
        $percentDifference = ($avgDifference / max($validEmailAvg, $invalidEmailAvg)) * 100;

        // Display statistics
        echo "\n=== STATISTICS ===\n";
        echo "\nValid Email + Wrong Password:\n";
        echo sprintf("  Average: %.2f ms\n", $validEmailAvg);
        echo sprintf("  Min:     %.2f ms\n", $validEmailMin);
        echo sprintf("  Max:     %.2f ms\n", $validEmailMax);
        echo sprintf("  Std Dev: %.2f ms\n", $validEmailStdDev);

        echo "\nInvalid Email:\n";
        echo sprintf("  Average: %.2f ms\n", $invalidEmailAvg);
        echo sprintf("  Min:     %.2f ms\n", $invalidEmailMin);
        echo sprintf("  Max:     %.2f ms\n", $invalidEmailMax);
        echo sprintf("  Std Dev: %.2f ms\n", $invalidEmailStdDev);

        echo "\nDifference:\n";
        echo sprintf("  Absolute: %.2f ms\n", $avgDifference);
        echo sprintf("  Percent:  %.2f%%\n", $percentDifference);

        // Assertions
        // Allow up to 20% difference (accounting for variance)
        $maxAllowedDifference = 20; // percent

        if ($percentDifference <= $maxAllowedDifference) {
            echo "\n✅ TEST PASSED!\n";
            echo "   - Response times are similar\n";
            echo "   - Timing attack protection is working\n";
            echo "   - Difference: {$percentDifference}% (allowed: {$maxAllowedDifference}%)\n";
        } else {
            echo "\n⚠️  WARNING: Significant timing difference detected!\n";
            echo "   - Difference: {$percentDifference}% (allowed: {$maxAllowedDifference}%)\n";
            echo "   - This may indicate timing attack vulnerability\n";
        }

        $this->assertLessThanOrEqual(
            $maxAllowedDifference,
            $percentDifference,
            "Response time difference ({$percentDifference}%) exceeds maximum allowed ({$maxAllowedDifference}%). " .
            "This indicates a potential timing attack vulnerability."
        );
    }

    /**
     * Test minimum response time enforcement
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_enforces_minimum_response_time()
    {
        $iterations = 10;
        $minExpectedTime = 100; // 100ms minimum
        $times = [];

        echo "\n=== Minimum Response Time Test ===\n";
        echo "Expected minimum: {$minExpectedTime}ms\n\n";

        for ($i = 0; $i < $iterations; $i++) {
            $start = hrtime(true);

            $this->postJson('/api/v1/auth/login', [
                'email' => 'test' . $i . '@example.com',
                'password' => 'password',
            ]);

            $end = hrtime(true);
            $duration = ($end - $start) / 1_000_000;

            $times[] = $duration;

            echo sprintf("Iteration %2d: %.2f ms %s\n",
                $i + 1,
                $duration,
                $duration >= $minExpectedTime ? '✅' : '⚠️'
            );
        }

        $average = array_sum($times) / count($times);
        $belowMinimum = array_filter($times, fn($t) => $t < $minExpectedTime);

        echo "\nAverage: " . sprintf("%.2f ms\n", $average);
        echo "Below minimum: " . count($belowMinimum) . " / $iterations\n";

        // Most responses should meet minimum time
        $this->assertLessThan(
            $iterations * 0.2, // Allow 20% to be below (due to variance)
            count($belowMinimum),
            "Too many responses below minimum time. Minimum response time may not be enforced."
        );
    }

    /**
     * Test random jitter is applied
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_applies_random_jitter()
    {
        $iterations = 20;
        $times = [];

        echo "\n=== Random Jitter Test ===\n";
        echo "Testing for response time variance...\n\n";

        // Send identical requests
        for ($i = 0; $i < $iterations; $i++) {
            $start = hrtime(true);

            $this->postJson('/api/v1/auth/login', [
                'email' => 'same.user@example.com', // Same email every time
                'password' => 'same-password', // Same password every time
            ]);

            $end = hrtime(true);
            $duration = ($end - $start) / 1_000_000;

            $times[] = $duration;

            echo sprintf("Iteration %2d: %.2f ms\n", $i + 1, $duration);
        }

        // Calculate variance
        $stdDev = $this->calculateStdDev($times);
        $average = array_sum($times) / count($times);
        $coefficientOfVariation = ($stdDev / $average) * 100;

        echo "\nStatistics:\n";
        echo sprintf("  Average:  %.2f ms\n", $average);
        echo sprintf("  Std Dev:  %.2f ms\n", $stdDev);
        echo sprintf("  CV:       %.2f%%\n", $coefficientOfVariation);

        // Expect some variance due to random jitter
        // But not too much (should still be consistent)
        $minExpectedCV = 0.5; // At least 0.5% variance
        $maxExpectedCV = 10;  // But not more than 10%

        if ($coefficientOfVariation >= $minExpectedCV && $coefficientOfVariation <= $maxExpectedCV) {
            echo "\n✅ TEST PASSED!\n";
            echo "   - Random jitter is applied\n";
            echo "   - Variance is within acceptable range\n";
        } else if ($coefficientOfVariation < $minExpectedCV) {
            echo "\n⚠️  WARNING: Very low variance detected!\n";
            echo "   - Random jitter may not be working\n";
        } else {
            echo "\n⚠️  WARNING: Very high variance detected!\n";
            echo "   - Response times are too inconsistent\n";
        }

        $this->assertGreaterThanOrEqual(
            $minExpectedCV,
            $coefficientOfVariation,
            "Variance too low. Random jitter may not be applied."
        );

        $this->assertLessThanOrEqual(
            $maxExpectedCV,
            $coefficientOfVariation,
            "Variance too high. Response times are too inconsistent."
        );
    }

    /**
     * Test constant-time comparison
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_uses_constant_time_comparison()
    {
        $iterations = 15;
        $shortPasswordTimes = [];
        $longPasswordTimes = [];

        echo "\n=== Constant-Time Comparison Test ===\n";
        echo "Comparing short vs long passwords...\n\n";

        // Test with short passwords
        echo "Testing: Short passwords (8 chars)\n";
        for ($i = 0; $i < $iterations; $i++) {
            $start = hrtime(true);

            $this->postJson('/api/v1/auth/login', [
                'email' => 'valid.user@example.com',
                'password' => 'short' . $i, // Short password
            ]);

            $end = hrtime(true);
            $duration = ($end - $start) / 1_000_000;

            $shortPasswordTimes[] = $duration;

            echo sprintf("  Iteration %2d: %.2f ms\n", $i + 1, $duration);
        }

        // Test with long passwords
        echo "\nTesting: Long passwords (64 chars)\n";
        for ($i = 0; $i < $iterations; $i++) {
            $start = hrtime(true);

            $this->postJson('/api/v1/auth/login', [
                'email' => 'valid.user@example.com',
                'password' => str_repeat('long-password-', 5) . $i, // Long password
            ]);

            $end = hrtime(true);
            $duration = ($end - $start) / 1_000_000;

            $longPasswordTimes[] = $duration;

            echo sprintf("  Iteration %2d: %.2f ms\n", $i + 1, $duration);
        }

        // Compare averages
        $shortAvg = array_sum($shortPasswordTimes) / count($shortPasswordTimes);
        $longAvg = array_sum($longPasswordTimes) / count($longPasswordTimes);
        $difference = abs($shortAvg - $longAvg);
        $percentDiff = ($difference / max($shortAvg, $longAvg)) * 100;

        echo "\nResults:\n";
        echo sprintf("  Short passwords avg: %.2f ms\n", $shortAvg);
        echo sprintf("  Long passwords avg:  %.2f ms\n", $longAvg);
        echo sprintf("  Difference:          %.2f ms (%.2f%%)\n", $difference, $percentDiff);

        // bcrypt/Hash::check should take similar time regardless of password length
        $maxAllowedDiff = 15; // percent

        if ($percentDiff <= $maxAllowedDiff) {
            echo "\n✅ TEST PASSED!\n";
            echo "   - Constant-time comparison is working\n";
            echo "   - Password length doesn't affect timing\n";
        } else {
            echo "\n⚠️  WARNING: Timing varies with password length!\n";
            echo "   - This may indicate non-constant-time comparison\n";
        }

        $this->assertLessThanOrEqual(
            $maxAllowedDiff,
            $percentDiff,
            "Password length affects response time. Constant-time comparison may not be working."
        );
    }

    /**
     * Calculate standard deviation
     */
    private function calculateStdDev(array $values): float
    {
        $count = count($values);
        if ($count === 0) {
            return 0;
        }

        $mean = array_sum($values) / $count;
        $variance = array_sum(array_map(function ($x) use ($mean) {
            return pow($x - $mean, 2);
        }, $values)) / $count;

        return sqrt($variance);
    }
}
