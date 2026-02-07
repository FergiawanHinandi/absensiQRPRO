<?php

namespace App\Services\Security;

/**
 * DeviceAnomalyResult Data Transfer Object
 *
 * Contains results from device anomaly detection.
 */
final readonly class DeviceAnomalyResult
{
    public function __construct(
        /**
         * Whether any anomalies were detected
         */
        public bool $hasAnomalies,

        /**
         * List of detected anomalies
         * Each anomaly has: type, severity, details
         */
        public array $anomalies,

        /**
         * Risk score (0-100)
         * 0 = no risk, 100 = maximum risk
         */
        public int $riskScore,

        /**
         * Recommended action based on risk score
         */
        public string $recommendation,
    ) {}

    /**
     * Check if should block the request
     */
    public function shouldBlock(): bool
    {
        return $this->riskScore >= 80;
    }

    /**
     * Check if should challenge (additional verification)
     */
    public function shouldChallenge(): bool
    {
        return $this->riskScore >= 50 && $this->riskScore < 80;
    }

    /**
     * Check if should monitor (log for review)
     */
    public function shouldMonitor(): bool
    {
        return $this->riskScore >= 30 && $this->riskScore < 50;
    }

    /**
     * Get anomalies by severity
     */
    public function getAnomaliesBySeverity(string $severity): array
    {
        return array_filter(
            $this->anomalies,
            fn ($a) => ($a['severity'] ?? '') === $severity
        );
    }

    /**
     * Convert to array for logging
     */
    public function toArray(): array
    {
        return [
            'has_anomalies' => $this->hasAnomalies,
            'anomaly_count' => count($this->anomalies),
            'anomalies' => $this->anomalies,
            'risk_score' => $this->riskScore,
            'recommendation' => $this->recommendation,
        ];
    }
}
