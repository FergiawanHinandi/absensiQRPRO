<?php

namespace Tests\Unit\Services;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\SecurityAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SecurityAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    private SecurityAuditService $service;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SecurityAuditService();
        $this->user = User::factory()->create([
            'role_type' => 'teacher',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_logs_security_event_successfully()
    {
        $event = $this->service->logEvent(
            $this->user,
            SecurityEvent::EVENT_UNAUTHORIZED_ACCESS,
            ['resource' => 'admin_panel'],
            SecurityEvent::SEVERITY_HIGH
        );

        $this->assertDatabaseHas('security_events', [
            'id' => $event->id,
            'user_id' => $this->user->id,
            'event_type' => SecurityEvent::EVENT_UNAUTHORIZED_ACCESS,
            'severity' => SecurityEvent::SEVERITY_HIGH,
            'is_resolved' => false,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_generates_correct_event_message()
    {
        $event = $this->service->logEvent(
            $this->user,
            SecurityEvent::EVENT_OUTSIDE_RADIUS,
            ['distance' => 150],
            SecurityEvent::SEVERITY_MEDIUM
        );

        $this->assertStringContainsString('150', $event->message);
        $this->assertStringContainsString('allowed area', $event->message);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_calculates_threat_score_correctly()
    {
        Cache::flush();

        // Log some events to build threat score
        $this->service->logEvent(
            $this->user,
            SecurityEvent::EVENT_MOCK_LOCATION,
            [],
            SecurityEvent::SEVERITY_CRITICAL
        );

        $this->service->logEvent(
            $this->user,
            SecurityEvent::EVENT_QR_REPLAY,
            [],
            SecurityEvent::SEVERITY_HIGH
        );

        $score = $this->service->getThreatScore($this->user->id);

        // Critical (25) + High (15) = 40
        $this->assertEquals(40, $score);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_caps_threat_score_at_100()
    {
        Cache::flush();

        // Log many critical events
        for ($i = 0; $i < 10; $i++) {
            $this->service->logEvent(
                $this->user,
                SecurityEvent::EVENT_QR_REPLAY,
                [],
                SecurityEvent::SEVERITY_CRITICAL
            );
        }

        $score = $this->service->getThreatScore($this->user->id);

        $this->assertEquals(100, $score);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_detects_impossible_travel()
    {
        // Create initial location event
        SecurityEvent::create([
            'school_id' => $this->user->school_id,
            'user_id' => $this->user->id,
            'event_type' => 'location_check',
            'severity' => SecurityEvent::SEVERITY_LOW,
            'latitude' => -6.2088, // Jakarta
            'longitude' => 106.8456,
            'created_at' => now()->subMinutes(30),
        ]);

        // Try to detect impossible travel to far location
        $isSuspicious = $this->service->detectImpossibleTravel(
            $this->user,
            40.7128, // New York (would require impossible speed)
            -74.0060
        );

        $this->assertTrue($isSuspicious);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_does_not_flag_normal_travel()
    {
        // Create initial location event
        SecurityEvent::create([
            'school_id' => $this->user->school_id,
            'user_id' => $this->user->id,
            'event_type' => 'location_check',
            'severity' => SecurityEvent::SEVERITY_LOW,
            'latitude' => -6.2088,
            'longitude' => 106.8456,
            'created_at' => now()->subHours(24), // 24 hours ago
        ]);

        // Try nearby location after 24 hours (very slow travel)
        $isSuspicious = $this->service->detectImpossibleTravel(
            $this->user,
            -6.9175, // Bandung (150km from Jakarta)
            107.6191
        );

        $this->assertFalse($isSuspicious);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_resolves_events_correctly()
    {
        $event = $this->service->logEvent(
            $this->user,
            SecurityEvent::EVENT_DUPLICATE_ATTEMPT,
            [],
            SecurityEvent::SEVERITY_MEDIUM
        );

        $resolver = User::factory()->create(['role_type' => 'admin']);

        $resolvedEvent = $this->service->resolveEvent($event, $resolver, 'False positive');

        $this->assertTrue($resolvedEvent->is_resolved);
        $this->assertEquals($resolver->id, $resolvedEvent->resolved_by);
        $this->assertEquals('False positive', $resolvedEvent->resolution_notes);
        $this->assertNotNull($resolvedEvent->resolved_at);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_bulk_resolves_events_by_type()
    {
        // Create multiple events of same type
        for ($i = 0; $i < 5; $i++) {
            $this->service->logEvent(
                $this->user,
                SecurityEvent::EVENT_POOR_GPS_ACCURACY,
                ['accuracy' => 100 + $i],
                SecurityEvent::SEVERITY_LOW
            );
        }

        $resolver = User::factory()->create(['role_type' => 'admin']);

        $count = $this->service->bulkResolve(
            SecurityEvent::EVENT_POOR_GPS_ACCURACY,
            $resolver,
            'Known GPS issues in area'
        );

        $this->assertEquals(5, $count);
        $this->assertEquals(0, SecurityEvent::unresolved()
            ->where('event_type', SecurityEvent::EVENT_POOR_GPS_ACCURACY)
            ->count()
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_generates_dashboard_summary()
    {
        // Create various events
        $this->service->logEvent($this->user, SecurityEvent::EVENT_QR_REPLAY, [], SecurityEvent::SEVERITY_CRITICAL);
        $this->service->logEvent($this->user, SecurityEvent::EVENT_MOCK_LOCATION, [], SecurityEvent::SEVERITY_HIGH);
        $this->service->logEvent($this->user, SecurityEvent::EVENT_OUTSIDE_RADIUS, ['distance' => 50], SecurityEvent::SEVERITY_MEDIUM);

        $summary = $this->service->getDashboardSummary($this->user->school_id);

        $this->assertArrayHasKey('today_events', $summary);
        $this->assertArrayHasKey('unresolved_critical', $summary);
        $this->assertArrayHasKey('unresolved_high', $summary);
        $this->assertArrayHasKey('top_event_types', $summary);
        $this->assertArrayHasKey('weekly_trend', $summary);
        $this->assertArrayHasKey('risk_level', $summary);

        $this->assertEquals(3, $summary['today_events']);
        $this->assertEquals(1, $summary['unresolved_critical']);
        $this->assertEquals('critical', $summary['risk_level']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_sanitizes_sensitive_context_data()
    {
        $event = $this->service->logEvent(
            $this->user,
            SecurityEvent::EVENT_UNAUTHORIZED_ACCESS,
            [
                'resource' => 'admin_panel',
                'password' => 'should_be_removed',
                'token' => 'secret_token',
                'normal_data' => 'should_stay',
            ],
            SecurityEvent::SEVERITY_HIGH
        );

        $this->assertArrayNotHasKey('password', $event->context);
        $this->assertArrayNotHasKey('token', $event->context);
        $this->assertArrayHasKey('normal_data', $event->context);
        $this->assertEquals('should_stay', $event->context['normal_data']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_analyzes_login_attempts()
    {
        $request = Request::create('/api/v1/auth/login', 'POST');
        $request->headers->set('X-Device-ID', 'unknown-device-123');
        $request->server->set('REMOTE_ADDR', '192.168.1.100');

        $analysis = $this->service->analyzeLoginAttempt($this->user, $request);

        $this->assertArrayHasKey('is_suspicious', $analysis);
        $this->assertArrayHasKey('reasons', $analysis);
        $this->assertArrayHasKey('threat_score', $analysis);

        // New device should trigger suspicious flag
        $this->assertContains('new_device', $analysis['reasons']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_generates_audit_report()
    {
        // Create events over several days
        $this->service->logEvent($this->user, SecurityEvent::EVENT_QR_REPLAY, [], SecurityEvent::SEVERITY_HIGH);

        $startDate = now()->subWeek();
        $endDate = now();

        $report = $this->service->generateAuditReport($startDate, $endDate, $this->user->school_id);

        $this->assertArrayHasKey('period', $report);
        $this->assertArrayHasKey('summary', $report);
        $this->assertArrayHasKey('timeline', $report);
        $this->assertArrayHasKey('response_metrics', $report);

        $this->assertEquals($startDate->toIso8601String(), $report['period']['start']);
        $this->assertArrayHasKey('total_events', $report['summary']);
        $this->assertArrayHasKey('resolution_rate', $report['response_metrics']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_gets_high_risk_users()
    {
        // Create events for user
        for ($i = 0; $i < 5; $i++) {
            $this->service->logEvent(
                $this->user,
                SecurityEvent::EVENT_MOCK_LOCATION,
                [],
                SecurityEvent::SEVERITY_HIGH
            );
        }

        $highRiskUsers = $this->service->getHighRiskUsers($this->user->school_id, 5);

        $this->assertGreaterThanOrEqual(1, $highRiskUsers->count());
        
        $userEntry = $highRiskUsers->firstWhere('user_id', $this->user->id);
        $this->assertNotNull($userEntry);
        $this->assertEquals(5, $userEntry->event_count);
    }
}
