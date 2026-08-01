<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityMonitoringAnalyticsTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private $adminUser;

    private $nonAdminUser;

    private $school1;

    private $school2;

    private $baseUrl = '/api/v1/admin/security';

    protected function setUp(): void
    {
        parent::setUp();

        // Create test schools
        $this->school1 = School::factory()->create(['name' => 'School 1']);
        $this->school2 = School::factory()->create(['name' => 'School 2']);

        // Create admin user for school 1
        $this->adminUser = User::factory()->create([
            'role' => 'school_admin',
            'school_id' => $this->school1->id,
        ]);

        // Create non-admin user
        $this->nonAdminUser = User::factory()->create([
            'role' => 'student',
            'school_id' => $this->school1->id,
        ]);

        // Seed security events data
        $this->seedSecurityEvents();
    }

    private function seedSecurityEvents(): void
    {
        $eventTypes = [
            'suspicious_login',
            'multiple_device_access',
            'location_anomaly',
            'qr_tampering',
            'brute_force_attempt',
        ];

        $severities = ['low', 'medium', 'high', 'critical'];
        $dates = [
            Carbon::now()->subDays(1),
            Carbon::now()->subDays(2),
            Carbon::now()->subDays(3),
            Carbon::now()->subDays(7),
            Carbon::now()->subDays(14),
        ];

        // Create security_events table if not exists
        if (! DB::getSchemaBuilder()->hasTable('security_events')) {
            DB::statement('
                CREATE TABLE security_events (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    event_type VARCHAR(255) NOT NULL,
                    severity ENUM("low", "medium", "high", "critical") NOT NULL,
                    description TEXT,
                    user_id BIGINT UNSIGNED NULL,
                    school_id BIGINT UNSIGNED NOT NULL,
                    ip_address VARCHAR(45) NULL,
                    device_id VARCHAR(255) NULL,
                    is_resolved BOOLEAN DEFAULT FALSE,
                    metadata JSON NULL,
                    created_at TIMESTAMP NULL,
                    updated_at TIMESTAMP NULL,
                    INDEX idx_school_created (school_id, created_at),
                    INDEX idx_severity_created (severity, created_at),
                    INDEX idx_event_type (event_type)
                )
            ');
        }

        // Seed events for school 1 (admin's school)
        foreach ($dates as $date) {
            foreach ($eventTypes as $eventType) {
                foreach ($severities as $severity) {
                    // Create 2-5 events per combination
                    $count = rand(2, 5);
                    for ($i = 0; $i < $count; $i++) {
                        DB::table('security_events')->insert([
                            'event_type' => $eventType,
                            'severity' => $severity,
                            'description' => "Test {$eventType} event with {$severity} severity",
                            'user_id' => $this->adminUser->id,
                            'school_id' => $this->school1->id,
                            'ip_address' => $this->faker->ipv4,
                            'device_id' => $this->faker->uuid,
                            'is_resolved' => $severity === 'low' ? true : false,
                            'metadata' => json_encode(['test' => true]),
                            'created_at' => $date->copy()->addMinutes(rand(0, 1440)),
                            'updated_at' => $date->copy()->addMinutes(rand(0, 1440)),
                        ]);
                    }
                }
            }
        }

        // Seed events for school 2 (should not be visible to school 1 admin)
        foreach ($dates as $date) {
            foreach ($eventTypes as $eventType) {
                DB::table('security_events')->insert([
                    'event_type' => $eventType,
                    'severity' => 'critical',
                    'description' => "School 2 {$eventType} event",
                    'user_id' => null,
                    'school_id' => $this->school2->id,
                    'ip_address' => $this->faker->ipv4,
                    'device_id' => $this->faker->uuid,
                    'is_resolved' => false,
                    'created_at' => $date,
                    'updated_at' => $date,
                ]);
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function unauthenticated_user_cannot_access_security_trend()
    {
        $response = $this->getJson("{$this->baseUrl}/trend");

        $response->assertStatus(401);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function unauthenticated_user_cannot_access_security_by_type()
    {
        $response = $this->getJson("{$this->baseUrl}/by-type");

        $response->assertStatus(401);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function unauthenticated_user_cannot_access_security_by_severity()
    {
        $response = $this->getJson("{$this->baseUrl}/by-severity");

        $response->assertStatus(401);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function unauthenticated_user_cannot_access_critical_recent()
    {
        $response = $this->getJson("{$this->baseUrl}/critical-recent");

        $response->assertStatus(401);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function non_admin_user_cannot_access_security_trend()
    {
        Sanctum::actingAs($this->nonAdminUser);

        $response = $this->getJson("{$this->baseUrl}/trend");

        $response->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function non_admin_user_cannot_access_security_by_type()
    {
        Sanctum::actingAs($this->nonAdminUser);

        $response = $this->getJson("{$this->baseUrl}/by-type");

        $response->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function non_admin_user_cannot_access_security_by_severity()
    {
        Sanctum::actingAs($this->nonAdminUser);

        $response = $this->getJson("{$this->baseUrl}/by-severity");

        $response->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function non_admin_user_cannot_access_critical_recent()
    {
        Sanctum::actingAs($this->nonAdminUser);

        $response = $this->getJson("{$this->baseUrl}/critical-recent");

        $response->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_can_get_security_trend_data()
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson("{$this->baseUrl}/trend?range=7d");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'date',
                        'total',
                        'critical',
                        'high',
                        'medium',
                        'low',
                    ],
                ],
            ]);

        $data = $response->json('data');

        // Should have data for multiple dates
        $this->assertGreaterThan(0, count($data));

        // Verify no data from other schools
        $totalEventsInResponse = array_sum(array_column($data, 'total'));
        $eventsFromSchool1 = DB::table('security_events')
            ->where('school_id', $this->school1->id)
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->count();

        $this->assertEquals($eventsFromSchool1, $totalEventsInResponse);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_can_get_security_by_type_data()
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson("{$this->baseUrl}/by-type?range=30d");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'event_type',
                        'label',
                        'count',
                        'critical_count',
                    ],
                ],
            ]);

        $data = $response->json('data');

        // Should have data for different event types
        $this->assertGreaterThan(0, count($data));

        // Verify event types are present
        $eventTypes = array_column($data, 'event_type');
        $this->assertContains('suspicious_login', $eventTypes);
        $this->assertContains('multiple_device_access', $eventTypes);

        // Verify counts are positive integers
        foreach ($data as $item) {
            $this->assertIsInt($item['count']);
            $this->assertGreaterThan(0, $item['count']);
            $this->assertIsInt($item['critical_count']);
            $this->assertGreaterThanOrEqual(0, $item['critical_count']);
        }

        // Verify no data leak from other schools
        $totalCount = array_sum(array_column($data, 'count'));
        $expectedCount = DB::table('security_events')
            ->where('school_id', $this->school1->id)
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->count();

        $this->assertEquals($expectedCount, $totalCount);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_can_get_security_by_severity_data()
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson("{$this->baseUrl}/by-severity?range=30d");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'severity',
                        'count',
                    ],
                ],
            ]);

        $data = $response->json('data');

        // Should have data for different severities
        $this->assertGreaterThan(0, count($data));

        // Verify all severity levels are present
        $severities = array_column($data, 'severity');
        $this->assertContains('low', $severities);
        $this->assertContains('medium', $severities);
        $this->assertContains('high', $severities);
        $this->assertContains('critical', $severities);

        // Verify counts are positive integers
        foreach ($data as $item) {
            $this->assertIsInt($item['count']);
            $this->assertGreaterThan(0, $item['count']);
        }

        // Verify no data leak from other schools
        $totalCount = array_sum(array_column($data, 'count'));
        $expectedCount = DB::table('security_events')
            ->where('school_id', $this->school1->id)
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->count();

        $this->assertEquals($expectedCount, $totalCount);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_can_get_critical_recent_events()
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson("{$this->baseUrl}/critical-recent?limit=10");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'event_type',
                        'event_label',
                        'severity',
                        'description',
                        'user_name',
                        'user_email',
                        'user_role',
                        'school_name',
                        'school_id',
                        'ip_address',
                        'device_id',
                        'is_resolved',
                        'timestamp',
                        'time_ago',
                        'details',
                    ],
                ],
            ]);

        $data = $response->json('data');

        // Should have critical/high severity events only
        foreach ($data as $event) {
            $this->assertContains($event['severity'], ['high', 'critical']);
            $this->assertEquals($this->school1->id, $event['school_id']);
        }

        // Verify no data from other schools
        $schoolIds = array_unique(array_column($data, 'school_id'));
        $this->assertEquals([$this->school1->id], $schoolIds);

        // Verify events are ordered by timestamp (most recent first)
        $timestamps = array_column($data, 'timestamp');
        $sortedTimestamps = $timestamps;
        rsort($sortedTimestamps);
        $this->assertEquals($sortedTimestamps, $timestamps);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function security_trend_respects_date_range_parameter()
    {
        Sanctum::actingAs($this->adminUser);

        // Test 7d range
        $response7d = $this->getJson("{$this->baseUrl}/trend?range=7d");
        $response7d->assertStatus(200);

        // Test 30d range
        $response30d = $this->getJson("{$this->baseUrl}/trend?range=30d");
        $response30d->assertStatus(200);

        $data7d = $response7d->json('data');
        $data30d = $response30d->json('data');

        // 30d should have more or equal data points than 7d
        $this->assertGreaterThanOrEqual(count($data7d), count($data30d));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function security_analytics_handles_empty_data_gracefully()
    {
        // Clear all events for this school
        DB::table('security_events')->where('school_id', $this->school1->id)->delete();

        Sanctum::actingAs($this->adminUser);

        $endpoints = [
            "{$this->baseUrl}/trend",
            "{$this->baseUrl}/by-type",
            "{$this->baseUrl}/by-severity",
            "{$this->baseUrl}/critical-recent",
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->getJson($endpoint);

            $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'data' => [],
                ]);
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function security_analytics_validates_range_parameter()
    {
        Sanctum::actingAs($this->adminUser);

        // Test invalid range
        $response = $this->getJson("{$this->baseUrl}/trend?range=invalid");

        // Should default to 7d or return validation error
        $response->assertStatus(200); // Assuming it defaults to 7d
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function critical_recent_respects_limit_parameter()
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson("{$this->baseUrl}/critical-recent?limit=5");

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertLessThanOrEqual(5, count($data));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function multi_tenant_isolation_is_enforced_across_all_endpoints()
    {
        Sanctum::actingAs($this->adminUser);

        $endpoints = [
            "{$this->baseUrl}/trend?range=30d",
            "{$this->baseUrl}/by-type?range=30d",
            "{$this->baseUrl}/by-severity?range=30d",
            "{$this->baseUrl}/critical-recent?limit=100",
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->getJson($endpoint);

            $response->assertStatus(200);
            $data = $response->json('data');

            // Verify no data from school 2 appears in any response
            foreach ($data as $item) {
                if (isset($item['school_id'])) {
                    $this->assertEquals($this->school1->id, $item['school_id']);
                }
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function security_analytics_returns_correct_json_structure()
    {
        Sanctum::actingAs($this->adminUser);

        // Test trend endpoint structure
        $trendResponse = $this->getJson("{$this->baseUrl}/trend");
        $trendResponse->assertJsonStructure([
            'success',
            'data' => [
                '*' => [
                    'date',
                    'total',
                    'critical',
                    'high',
                    'medium',
                    'low',
                ],
            ],
        ]);

        // Test by-type endpoint structure
        $typeResponse = $this->getJson("{$this->baseUrl}/by-type");
        $typeResponse->assertJsonStructure([
            'success',
            'data' => [
                '*' => [
                    'event_type',
                    'label',
                    'count',
                    'critical_count',
                ],
            ],
        ]);

        // Test by-severity endpoint structure
        $severityResponse = $this->getJson("{$this->baseUrl}/by-severity");
        $severityResponse->assertJsonStructure([
            'success',
            'data' => [
                '*' => [
                    'severity',
                    'count',
                ],
            ],
        ]);

        // Test critical-recent endpoint structure
        $criticalResponse = $this->getJson("{$this->baseUrl}/critical-recent");
        $criticalResponse->assertJsonStructure([
            'success',
            'data' => [
                '*' => [
                    'id',
                    'event_type',
                    'event_label',
                    'severity',
                    'description',
                    'timestamp',
                    'time_ago',
                ],
            ],
        ]);
    }
}
