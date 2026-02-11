<?php

declare(strict_types=1);

namespace App\Application\Guards;

use App\Domain\Subscription\ValueObjects\PlanType;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Service-layer subscription enforcement.
 *
 * Unlike HTTP middleware (which guards routes), this guard can be
 * used inside command handlers and domain services to enforce
 * subscription policies at the business logic level.
 */
class SubscriptionPolicyGuard
{
    private const CACHE_TTL_SECONDS = 300; // 5 minutes

    public function ensureCanRecordAttendance(int $schoolId): void
    {
        $this->ensureActiveSubscription($schoolId);
        $this->ensureFeatureAvailable($schoolId, 'basic_attendance');
    }

    public function ensureCanGenerateQR(int $schoolId): void
    {
        $this->ensureActiveSubscription($schoolId);
        $this->ensureFeatureAvailable($schoolId, 'qr_scan');
    }

    public function ensureWithinStudentLimit(int $schoolId): void
    {
        $subscription = $this->getSubscription($schoolId);

        if (! $subscription || ! $subscription->max_students) {
            return; // No limit configured
        }

        $currentStudentCount = User::where('school_id', $schoolId)
            ->where('role_type', 'student')
            ->where('is_active', true)
            ->count();

        if ($currentStudentCount >= $subscription->max_students) {
            throw new HttpException(
                402,
                "Student limit reached ({$subscription->max_students}). Upgrade your subscription plan."
            );
        }
    }

    public function ensureWithinTeacherLimit(int $schoolId): void
    {
        $subscription = $this->getSubscription($schoolId);

        if (! $subscription || ! $subscription->max_teachers) {
            return;
        }

        $currentTeacherCount = User::where('school_id', $schoolId)
            ->whereIn('role_type', ['teacher', 'homeroom_teacher'])
            ->where('is_active', true)
            ->count();

        if ($currentTeacherCount >= $subscription->max_teachers) {
            throw new HttpException(
                402,
                "Teacher limit reached ({$subscription->max_teachers}). Upgrade your subscription plan."
            );
        }
    }

    public function hasFeature(int $schoolId, string $feature): bool
    {
        $subscription = $this->getSubscription($schoolId);

        if (! $subscription) {
            // Default to free plan features
            $plan = PlanType::free();

            return $plan->includes($feature);
        }

        // Check explicit features list on subscription
        $features = $subscription->features ?? [];
        if (! empty($features) && in_array($feature, $features, true)) {
            return true;
        }

        // Fall back to plan-based features
        try {
            $plan = new PlanType($subscription->plan_type ?? 'free');

            return $plan->includes($feature);
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    public function getPlanType(int $schoolId): PlanType
    {
        $subscription = $this->getSubscription($schoolId);

        try {
            return new PlanType($subscription->plan_type ?? 'free');
        } catch (\InvalidArgumentException) {
            return PlanType::free();
        }
    }

    private function ensureActiveSubscription(int $schoolId): void
    {
        $subscription = $this->getSubscription($schoolId);

        if (! $subscription) {
            Log::channel('audit')->warning('No subscription found for school', [
                'school_id' => $schoolId,
            ]);

            // Allow free tier access
            return;
        }

        if (! $subscription->is_active) {
            throw new HttpException(402, 'Your school subscription is inactive. Please contact administrator.');
        }

        if ($subscription->expires_at && $subscription->expires_at->isPast()) {
            // Check grace period (3 days)
            $graceEnd = $subscription->expires_at->addDays(3);
            if (now()->isAfter($graceEnd)) {
                throw new HttpException(402, 'Your school subscription has expired. Please renew to continue.');
            }

            Log::channel('audit')->warning('School using subscription in grace period', [
                'school_id' => $schoolId,
                'expired_at' => $subscription->expires_at->toIso8601String(),
                'grace_ends' => $graceEnd->toIso8601String(),
            ]);
        }
    }

    private function ensureFeatureAvailable(int $schoolId, string $feature): void
    {
        if (! $this->hasFeature($schoolId, $feature)) {
            throw new HttpException(
                403,
                "Feature '{$feature}' is not available on your current plan. Please upgrade."
            );
        }
    }

    private function getSubscription(int $schoolId): ?Subscription
    {
        return Cache::remember(
            "school_sub_{$schoolId}",
            self::CACHE_TTL_SECONDS,
            fn () => Subscription::where('school_id', $schoolId)
                ->where('is_active', true)
                ->latest('created_at')
                ->first()
        );
    }
}
