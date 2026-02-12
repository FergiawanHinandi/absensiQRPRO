<?php

namespace App\Console\Commands;

use App\Models\ImmutableSecurityLog;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class RunComplianceAudit extends Command
{
    protected $signature = 'audit:compliance {--report : Generate PDF/JSON report}';
    protected $description = 'Run automated compliance checks for Sovereign Cloud Indonesia standards (PDP/ISO 27001)';

    private array $results = [];
    private int $score = 0;
    private int $totalChecks = 0;

    public function handle()
    {
        $this->info("🛡️  Starting Sovereign Cloud Compliance Audit...");
        $this->line("Target Standards: PDP Indonesia, ISO 27001, SOC 2 Type II");
        $this->newLine();

        // 1. Data Residency Check
        $this->checkDataResidency();

        // 2. Encryption At-Rest Check
        $this->checkEncryptionAtRest();

        // 3. Database Security Check
        $this->checkDatabaseSecurity();

        // 4. Audit Trail Integrity Check
        $this->checkAuditIntegrity();

        // 5. Access Control Check
        $this->checkAccessControl();

        $this->printSummary();

        return 0;
    }

    private function checkDataResidency()
    {
        $this->startSection("A. Data Residency (Lokalisasi Data)");

        // Check 1: AWS Region
        $region = config('filesystems.disks.s3.region');
        $isIndonesia = in_array($region, ['ap-southeast-3']); // Jakarta
        
        $this->recordResult(
            "Cloud Storage Region (Jakarta)",
            $isIndonesia ? "PASS" : "FAIL",
            "Current region: {$region}. Must be 'ap-southeast-3' for Sovereign Cloud compliance.",
            $isIndonesia ? 10 : 0
        );

        // Check 2: Timezone
        $timezone = config('app.timezone');
        $isWIB = in_array($timezone, ['Asia/Jakarta', 'UTC+7']);
        
        $this->recordResult(
            "System Timezone",
            $isWIB ? "PASS" : "WARN",
            "Current timezone: {$timezone}. Recommended: Asia/Jakarta.",
            $isWIB ? 5 : 2
        );
    }

    private function checkEncryptionAtRest()
    {
        $this->startSection("B. Encryption At-Rest (PII Protection)");

        // Check UserProfile model casts
        $userProfile = new UserProfile();
        $casts = $userProfile->getCasts();
        
        $piiFields = ['nisn', 'phone', 'address', 'birth_date'];
        $encryptedCount = 0;

        foreach ($piiFields as $field) {
            $isEncrypted = isset($casts[$field]) && str_starts_with($casts[$field], 'encrypted');
            if ($isEncrypted) $encryptedCount++;
        }

        $status = $encryptedCount === count($piiFields) ? "PASS" : ($encryptedCount > 0 ? "PARTIAL" : "FAIL");
        
        $this->recordResult(
            "PII Field Encryption (UserProfile)",
            $status,
            "Encrypted fields: {$encryptedCount}/" . count($piiFields) . ". Missing encryption on sensitive data violates PDP Law.",
            $encryptedCount * 2.5 // Max 10
        );
    }

    private function checkDatabaseSecurity()
    {
        $this->startSection("C. Database Security");

        // Check SSL Mode
        $sslMode = config('database.connections.pgsql.sslmode');
        $isSecure = in_array($sslMode, ['require', 'verify-ca', 'verify-full']);

        $this->recordResult(
            "DB Connection SSL Enforcement",
            $isSecure ? "PASS" : "FAIL",
            "Current mode: '{$sslMode}'. Must be 'require' or stricter to prevent MITM.",
            $isSecure ? 10 : 0
        );
    }

    private function checkAuditIntegrity()
    {
        $this->startSection("D. Audit Trail Integrity");

        // Check 1: Immutable Log Table Existence
        $hasTable = Schema::hasTable('immutable_security_logs');
        $this->recordResult(
            "Immutable Log Table",
            $hasTable ? "PASS" : "FAIL",
            "Table 'immutable_security_logs' exists.",
            $hasTable ? 10 : 0
        );

        if ($hasTable) {
            // Check 2: Hash Chain Integrity (Sample check)
            $latest = ImmutableSecurityLog::latest()->take(10)->get();
            $chainValid = true;
            // Simplified check: just ensuring we have records and current_hash exists
            if ($latest->count() > 0) {
                 foreach($latest as $log) {
                     if (empty($log->current_hash) || empty($log->previous_hash)) {
                         $chainValid = false;
                         break;
                     }
                 }
            } else {
                $chainValid = false; // No logs is suspicious/fresh
            }

            $this->recordResult(
                "Hash Chain Implementation",
                $chainValid ? "PASS" : "WARN",
                "Hash chain fields detected and populated.",
                $chainValid ? 10 : 5
            );
        }
    }

    private function checkAccessControl()
    {
        $this->startSection("E. Access Control & Isolation");

        // Check 1: SchoolScope existence
        $scopeFile = app_path('Models/Scopes/SchoolScope.php');
        $hasScope = File::exists($scopeFile);

        $this->recordResult(
            "Multi-Tenant Isolation (SchoolScope)",
            $hasScope ? "PASS" : "FAIL",
            "Tenancy isolation code found.",
            $hasScope ? 10 : 0
        );

        // Check 2: 2FA / Sanctum Config
        $expiration = config('sanctum.expiration');
        $isShortLived = $expiration !== null && $expiration <= 60; // Max 60 mins

        $this->recordResult(
            "Token Expiration Policy",
            $isShortLived ? "PASS" : "WARN",
            "Token lifetime: " . ($expiration ?? 'Infinite') . " mins. Should be <= 60 mins.",
            $isShortLived ? 10 : 5
        );
    }

    private function startSection($title)
    {
        $this->newLine();
        $this->warn($title);
        $this->line(str_repeat('-', 50));
    }

    private function recordResult($checkName, $status, $message, $points)
    {
        $color = match($status) {
            'PASS' => 'green',
            'FAIL' => 'red',
            'WARN' => 'yellow',
            'PARTIAL' => 'yellow',
            default => 'white'
        };

        $this->line(sprintf(
            "[%s] %s", 
            $this->colorize($status, $color),
            $checkName
        ));
        $this->line("      Details: {$message}");

        $this->results[] = compact('checkName', 'status', 'message', 'points');
        $this->score += $points;
        $this->totalChecks++;
    }

    private function colorize($text, $color)
    {
        return "<fg=$color>$text</>";
    }

    private function printSummary()
    {
        $this->newLine();
        $this->info("=== Audit Summary ===");
        
        $maxScore = 75; // Approx total points available in this simplified script
        $percentage = round(($this->score / $maxScore) * 100);

        $this->line("Compliance Score: {$percentage}% ({$this->score}/{$maxScore})");

        if ($percentage < 100) {
            $this->error("Status: NON-COMPLIANT");
            $this->line("Please remediate the FAIL/WARN items above to achieve full Sovereign Cloud status.");
        } else {
            $this->info("Status: COMPLIANT");
        }
    }
}
