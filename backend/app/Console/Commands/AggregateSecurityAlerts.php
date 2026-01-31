<?php

namespace App\Console\Commands;

use App\Models\SecurityAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class AggregateSecurityAlerts extends Command
{
    protected $signature = 'security:aggregate-alerts';
    protected $description = 'Scan security logs for anomalies and create alerts';

    public function handle()
    {
        $logPath = storage_path('logs/security.log');
        if (!File::exists($logPath)) {
            $this->info('No security log found.');
            return;
        }

        // track file position
        $cacheKey = 'security_log_pos';
        $lastPos = Cache::get($cacheKey, 0);
        $fileSize = File::size($logPath);

        // If file was rotated (smaller than last pos), reset
        if ($fileSize < $lastPos) {
            $lastPos = 0;
        }

        $handle = fopen($logPath, 'r');
        fseek($handle, $lastPos);

        $failedLogins = 0;
        $qrReplays = 0;
        $scanAnomalies = 0;
        $crossSchool = 0;
        
        $loginIps = [];

        while (!feof($handle)) {
            $line = fgets($handle);
            if (!$line) continue;

            if (str_contains($line, 'Failed Login Attempt')) {
                $failedLogins++;
                // Extract IP if possible used for detail
                // Log fmt: [Date] env.WARNING: Failed Login Attempt {"username":"...","ip":"127.0.0.1",...}
                preg_match('/"ip"\s*:\s*"([^"]+)"/', $line, $matches);
                if (isset($matches[1])) {
                    $loginIps[] = $matches[1];
                }
            }

            if (str_contains($line, 'nonce_replay_detected') || str_contains($line, 'duplicate_scan_attempt')) {
                $qrReplays++;
            }

            if (str_contains($line, 'Security Anomaly Detected')) { // From LocationAnomalyService
                $scanAnomalies++;
            }
            
            // Assume cross-school logs contain specific text if implemented
            if (str_contains($line, 'Cross-school') || str_contains($line, 'Unauthorized school access')) {
                $crossSchool++;
            }
        }

        $newPos = ftell($handle);
        fclose($handle);
        Cache::put($cacheKey, $newPos);

        // --- ANALYZE & ALERT ---

        // Rule 1: >3 Failed Logins (Burst)
        if ($failedLogins > 3) {
            $uniqueIps = array_unique($loginIps);
            $this->createAlert(
                'login_attack', 
                'high', 
                "Detected {$failedLogins} failed login attempts in the last 5 minutes.", 
                ['ips' => $uniqueIps, 'count' => $failedLogins]
            );
        }

        // Rule 2: >5 QR Replays
        if ($qrReplays > 5) {
            $this->createAlert(
                'qr_replay_spike', 
                'critical', 
                "High rate of QR replay attempts detected ({$qrReplays} attempts). Possible replay attack.",
                ['count' => $qrReplays]
            );
        }

        // Rule 3: Cross School (Any)
        if ($crossSchool > 0) {
            $this->createAlert(
                'cross_school_access', 
                'high', 
                "Unauthorized cross-school access attempts detected.",
                ['count' => $crossSchool]
            );
        }
        
        // Rule 4: Scan Anomalies (Location/Device) > 5 (Burst)
        if ($scanAnomalies > 5) {
             $this->createAlert(
                'location_anomaly_spike', 
                'medium', 
                "Multiple location/device anomalies detected ({$scanAnomalies}).",
                ['count' => $scanAnomalies]
            );
        }

        $this->info("Security log scan complete. Position updated to {$newPos}.");
    }

    private function createAlert($type, $severity, $desc, $details)
    {
        // Avoid duplicate active alerts for same type? 
        // Request says "Insert alerts". I'll insert new one.
        
        SecurityAlert::createAlert([
            'type' => $type,
            'severity' => $severity,
            'description' => $desc,
            'ip_address' => request()->ip(),
        ]);
        
        $this->warn("Alert Created: {$type}");
    }
}
