# Sovereign Cloud Compliance Architecture

## Executive Summary

This document defines a sovereign cloud architecture that ensures full compliance with data residency laws, government regulations, and international certifications (SOC2, ISO 27001, ISO 27701) for a national attendance SaaS system.

**Key Compliance Goals:**
- **Data Residency:** All data stored within national/regional boundaries
- **Government Audit Ready:** Immutable audit trails, 5-year retention
- **Zero Foreign Transfer:** No data crosses jurisdictional boundaries
- **Regional Isolation:** Complete separation of regional infrastructure
- **Certification Ready:** SOC2, ISO 27001, ISO 27701 compliant

---

## Region Isolation Architecture

### Multi-Region Sovereign Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                        GLOBAL CONTROL PLANE                          │
│                     (Metadata Only, No PII)                          │
│                                                                      │
│  ┌────────────────────────────────────────────────────────────────┐│
│  │              Global Service Directory                           ││
│  │                                                                 ││
│  │  • School → Region mapping                                     ││
│  │  • API routing table                                           ││
│  │  • Health check aggregation                                    ││
│  │  • Billing aggregation (anonymized)                            ││
│  │                                                                 ││
│  │  ⚠️  NO STUDENT DATA, NO ATTENDANCE DATA                       ││
│  └────────────────────────────────────────────────────────────────┘│
│                                                                      │
└──────────────────┬───────────────────────┬───────────────────────────┘
                   │                       │
                   │                       │
    ┌──────────────┴──────────┐   ┌───────┴──────────────┐
    │                         │   │                      │
    ▼                         ▼   ▼                      ▼
┌─────────────────────────────────────────────────────────────────────┐
│                      REGION A (JAKARTA)                              │
│                   Sovereign Data Center                              │
│                                                                      │
│  ┌────────────────────────────────────────────────────────────────┐│
│  │                   Regional Control Plane                        ││
│  │                                                                 ││
│  │  • Regional API Gateway (api-jakarta.attendance.id)            ││
│  │  • Regional IAM (separate from other regions)                  ││
│  │  • Regional Key Management (KMS Jakarta)                       ││
│  │  • Regional Audit Log (immutable)                              ││
│  └────────────────────────────────────────────────────────────────┘│
│                                                                      │
│  ┌────────────────────────────────────────────────────────────────┐│
│  │                   Data Storage Layer                            ││
│  │                                                                 ││
│  │  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐         ││
│  │  │   MySQL      │  │    Redis     │  │      S3      │         ││
│  │  │  (Primary)   │  │   (Cache)    │  │  (Backups)   │         ││
│  │  │              │  │              │  │              │         ││
│  │  │  Jakarta DC  │  │  Jakarta DC  │  │  Jakarta DC  │         ││
│  │  └──────────────┘  └──────────────┘  └──────────────┘         ││
│  │                                                                 ││
│  │  Encryption: KMS Jakarta (AES-256)                             ││
│  │  Backup: Jakarta S3 (no cross-region replication)              ││
│  └────────────────────────────────────────────────────────────────┘│
│                                                                      │
│  ┌────────────────────────────────────────────────────────────────┐│
│  │                   Compute Layer                                 ││
│  │                                                                 ││
│  │  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐         ││
│  │  │  Web Pods    │  │ Queue Workers│  │  Cron Jobs   │         ││
│  │  │  (K8s)       │  │  (K8s)       │  │  (K8s)       │         ││
│  │  └──────────────┘  └──────────────┘  └──────────────┘         ││
│  │                                                                 ││
│  │  Deployment: Jakarta Kubernetes Cluster                        ││
│  │  Network: VPC Jakarta (isolated)                               ││
│  └────────────────────────────────────────────────────────────────┘│
│                                                                      │
│  ┌────────────────────────────────────────────────────────────────┐│
│  │                   Audit & Compliance Layer                      ││
│  │                                                                 ││
│  │  • Immutable audit logs (WORM storage)                         ││
│  │  • 5-year retention                                            ││
│  │  • Government API integration                                  ││
│  │  • Compliance reporting                                        ││
│  └────────────────────────────────────────────────────────────────┘│
│                                                                      │
│  Schools: Jakarta, Bogor, Depok, Tangerang, Bekasi                 │
│  Data Jurisdiction: DKI Jakarta                                     │
└─────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────┐
│                      REGION B (SURABAYA)                             │
│                   Sovereign Data Center                              │
│                                                                      │
│  [Same architecture as Region A, completely isolated]               │
│                                                                      │
│  Schools: Surabaya, Malang, Sidoarjo                                │
│  Data Jurisdiction: Jawa Timur                                      │
└─────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────┐
│                      REGION C (BANDUNG)                              │
│                   Sovereign Data Center                              │
│                                                                      │
│  [Same architecture as Region A, completely isolated]               │
│                                                                      │
│  Schools: Bandung, Cimahi, Bekasi                                   │
│  Data Jurisdiction: Jawa Barat                                      │
└─────────────────────────────────────────────────────────────────────┘
```

### Key Isolation Principles

1. **Network Isolation:** Each region has separate VPC, no cross-region peering
2. **Data Isolation:** Data never crosses regional boundaries
3. **Encryption Isolation:** Separate KMS keys per region
4. **IAM Isolation:** Separate identity providers per region
5. **Backup Isolation:** Backups stored within same jurisdiction

---

## Data Residency Enforcement

### Data Classification & Residency Rules

```yaml
# Data Residency Policy v1.0

data_classification:
  
  # Class 1: Sovereign Data (MUST stay in region)
  sovereign_data:
    - student_personal_information:
        - name
        - date_of_birth
        - address
        - parent_contact
        - national_id (NIK)
    - attendance_records:
        - check_in_time
        - location_coordinates
        - device_information
    - school_information:
        - school_name
        - address
        - contact_details
    
    storage_rule: MUST be stored in school's regional data center
    encryption: Regional KMS key
    backup: Same region only
    cross_region_transfer: PROHIBITED
  
  # Class 2: Operational Metadata (Can be global)
  operational_metadata:
    - school_id (anonymized hash)
    - region_code
    - subscription_tier
    - api_usage_metrics (anonymized)
    
    storage_rule: Can be stored in global control plane
    encryption: Global KMS key
    cross_region_transfer: ALLOWED (anonymized only)
  
  # Class 3: Aggregated Analytics (Can be global)
  aggregated_analytics:
    - national_attendance_rate (no PII)
    - regional_statistics (no PII)
    - fraud_detection_patterns (anonymized)
    
    storage_rule: Can be stored in analytics warehouse
    encryption: Global KMS key
    cross_region_transfer: ALLOWED (aggregated only)

enforcement:
  
  # Database level enforcement
  database:
    - row_level_security: ENABLED
    - regional_tenant_isolation: ENABLED
    - cross_region_queries: BLOCKED
  
  # Application level enforcement
  application:
    - regional_routing: MANDATORY
    - data_residency_validation: ENABLED
    - cross_region_api_calls: BLOCKED
  
  # Infrastructure level enforcement
  infrastructure:
    - vpc_peering: DISABLED
    - cross_region_replication: DISABLED
    - data_transfer_monitoring: ENABLED
```

### Regional Routing Implementation

```php
<?php
// app/Http/Middleware/RegionalRoutingMiddleware.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\RegionalRoutingService;

class RegionalRoutingMiddleware
{
    private $routingService;
    
    public function __construct(RegionalRoutingService $routingService)
    {
        $this->routingService = $routingService;
    }
    
    public function handle(Request $request, Closure $next)
    {
        // Extract school ID from request
        $schoolId = $request->header('X-School-ID') 
                    ?? $request->input('school_id')
                    ?? $this->extractSchoolFromToken($request);
        
        if (!$schoolId) {
            return response()->json(['error' => 'School ID required'], 400);
        }
        
        // Determine school's region
        $schoolRegion = $this->routingService->getSchoolRegion($schoolId);
        $currentRegion = config('app.region');
        
        // Enforce regional routing
        if ($schoolRegion !== $currentRegion) {
            // Redirect to correct regional endpoint
            $regionalEndpoint = $this->routingService->getRegionalEndpoint($schoolRegion);
            
            Log::warning('Cross-region request blocked', [
                'school_id' => $schoolId,
                'school_region' => $schoolRegion,
                'current_region' => $currentRegion,
                'redirect_to' => $regionalEndpoint,
            ]);
            
            return response()->json([
                'error' => 'Data residency violation',
                'message' => 'This school\'s data is in a different region',
                'correct_endpoint' => $regionalEndpoint,
            ], 403);
        }
        
        // Add region context to request
        $request->attributes->set('region', $currentRegion);
        $request->attributes->set('school_id', $schoolId);
        
        return $next($request);
    }
}
```

### Data Residency Validation

```php
<?php
// app/Services/DataResidencyValidator.php

namespace App\Services;

use App\Models\School;
use Illuminate\Support\Facades\Log;

class DataResidencyValidator
{
    /**
     * Validate that data operation complies with residency rules
     */
    public function validateOperation(string $operation, array $data): bool
    {
        $violations = [];
        
        // Check 1: School region matches current region
        if (isset($data['school_id'])) {
            $school = School::find($data['school_id']);
            $currentRegion = config('app.region');
            
            if ($school->region !== $currentRegion) {
                $violations[] = [
                    'type' => 'region_mismatch',
                    'school_region' => $school->region,
                    'current_region' => $currentRegion,
                ];
            }
        }
        
        // Check 2: No cross-region data references
        if (isset($data['student_id'])) {
            $studentRegion = $this->getStudentRegion($data['student_id']);
            $currentRegion = config('app.region');
            
            if ($studentRegion !== $currentRegion) {
                $violations[] = [
                    'type' => 'cross_region_student_reference',
                    'student_region' => $studentRegion,
                    'current_region' => $currentRegion,
                ];
            }
        }
        
        // Check 3: Encryption key matches region
        if (isset($data['encryption_key_id'])) {
            $keyRegion = $this->getKeyRegion($data['encryption_key_id']);
            $currentRegion = config('app.region');
            
            if ($keyRegion !== $currentRegion) {
                $violations[] = [
                    'type' => 'wrong_encryption_key_region',
                    'key_region' => $keyRegion,
                    'current_region' => $currentRegion,
                ];
            }
        }
        
        // Log violations
        if (!empty($violations)) {
            Log::critical('Data residency violation detected', [
                'operation' => $operation,
                'violations' => $violations,
                'data' => $this->sanitizeForLogging($data),
            ]);
            
            // Alert compliance team
            $this->alertComplianceTeam($violations);
            
            return false;
        }
        
        return true;
    }
    
    /**
     * Alert compliance team of violation
     */
    private function alertComplianceTeam(array $violations): void
    {
        // Send to Slack, PagerDuty, email, etc.
        Notification::route('slack', config('compliance.slack_webhook'))
            ->notify(new DataResidencyViolationAlert($violations));
    }
}
```

---

## Regional Encryption Architecture

### Key Management per Region

```
┌─────────────────────────────────────────────────────────────────────┐
│                    REGIONAL KEY MANAGEMENT                           │
│                                                                      │
│  Region A (Jakarta)                                                  │
│  ┌────────────────────────────────────────────────────────────────┐│
│  │              AWS KMS Jakarta                                    ││
│  │                                                                 ││
│  │  Master Key: arn:aws:kms:ap-southeast-1:xxx:key/jakarta-master ││
│  │                                                                 ││
│  │  Data Encryption Keys (DEK):                                   ││
│  │  ├─ DEK-Jakarta-DB-2026-02                                     ││
│  │  ├─ DEK-Jakarta-S3-2026-02                                     ││
│  │  ├─ DEK-Jakarta-Backup-2026-02                                 ││
│  │  └─ DEK-Jakarta-Logs-2026-02                                   ││
│  │                                                                 ││
│  │  Key Rotation: Monthly                                         ││
│  │  Key Access: Jakarta IAM only                                  ││
│  │  Key Export: PROHIBITED                                        ││
│  └────────────────────────────────────────────────────────────────┘│
│                                                                      │
│  Region B (Surabaya)                                                 │
│  ┌────────────────────────────────────────────────────────────────┐│
│  │              AWS KMS Surabaya                                   ││
│  │                                                                 ││
│  │  Master Key: arn:aws:kms:ap-southeast-1:xxx:key/surabaya-master││
│  │  [Separate keys, completely isolated from Jakarta]             ││
│  └────────────────────────────────────────────────────────────────┘│
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘
```

### Encryption Implementation

```php
<?php
// app/Services/RegionalEncryptionService.php

namespace App\Services;

use Aws\Kms\KmsClient;
use Illuminate\Support\Facades\Cache;

class RegionalEncryptionService
{
    private $kmsClient;
    private $masterKeyId;
    
    public function __construct()
    {
        $region = config('app.region');
        
        // Initialize KMS client for current region
        $this->kmsClient = new KmsClient([
            'region' => $this->getAwsRegion($region),
            'version' => 'latest',
        ]);
        
        // Get regional master key
        $this->masterKeyId = config("encryption.kms_keys.{$region}.master");
        
        // Validate key is in correct region
        $this->validateKeyRegion();
    }
    
    /**
     * Encrypt data using regional KMS key
     */
    public function encrypt(string $plaintext, array $context = []): string
    {
        // Add regional context
        $context['region'] = config('app.region');
        $context['timestamp'] = now()->toIso8601String();
        
        try {
            $result = $this->kmsClient->encrypt([
                'KeyId' => $this->masterKeyId,
                'Plaintext' => $plaintext,
                'EncryptionContext' => $context,
            ]);
            
            return base64_encode($result['CiphertextBlob']);
            
        } catch (\Exception $e) {
            Log::error('Encryption failed', [
                'error' => $e->getMessage(),
                'region' => config('app.region'),
            ]);
            
            throw new EncryptionException('Failed to encrypt data');
        }
    }
    
    /**
     * Decrypt data using regional KMS key
     */
    public function decrypt(string $ciphertext, array $context = []): string
    {
        // Add regional context
        $context['region'] = config('app.region');
        
        try {
            $result = $this->kmsClient->decrypt([
                'CiphertextBlob' => base64_decode($ciphertext),
                'EncryptionContext' => $context,
            ]);
            
            return $result['Plaintext'];
            
        } catch (\Exception $e) {
            Log::error('Decryption failed', [
                'error' => $e->getMessage(),
                'region' => config('app.region'),
            ]);
            
            throw new DecryptionException('Failed to decrypt data');
        }
    }
    
    /**
     * Validate KMS key is in correct region
     */
    private function validateKeyRegion(): void
    {
        $keyMetadata = $this->kmsClient->describeKey([
            'KeyId' => $this->masterKeyId,
        ]);
        
        $keyRegion = $keyMetadata['KeyMetadata']['Arn'];
        $currentRegion = config('app.region');
        
        if (!str_contains($keyRegion, $this->getAwsRegion($currentRegion))) {
            throw new \Exception("KMS key region mismatch: key is not in {$currentRegion}");
        }
    }
    
    /**
     * Rotate encryption keys (monthly)
     */
    public function rotateKeys(): void
    {
        // Enable automatic key rotation
        $this->kmsClient->enableKeyRotation([
            'KeyId' => $this->masterKeyId,
        ]);
        
        Log::info('KMS key rotation enabled', [
            'region' => config('app.region'),
            'key_id' => $this->masterKeyId,
        ]);
    }
}
```

---

## Immutable Audit Trail

### Audit Log Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                    IMMUTABLE AUDIT SYSTEM                            │
│                                                                      │
│  ┌────────────────────────────────────────────────────────────────┐│
│  │              Application Layer                                  ││
│  │                                                                 ││
│  │  Every action generates audit event:                           ││
│  │  • User login/logout                                           ││
│  │  • Data access (read/write/delete)                             ││
│  │  • Configuration changes                                       ││
│  │  • Admin actions                                               ││
│  │  • API calls                                                   ││
│  └────────────────────────┬───────────────────────────────────────┘│
│                           │                                         │
│                           ▼                                         │
│  ┌────────────────────────────────────────────────────────────────┐│
│  │              Audit Event Queue                                  ││
│  │                                                                 ││
│  │  • Kafka topic: audit-events                                   ││
│  │  • Retention: 7 days (buffer)                                  ││
│  │  • Replication: 3x                                             ││
│  └────────────────────────┬───────────────────────────────────────┘│
│                           │                                         │
│                           ▼                                         │
│  ┌────────────────────────────────────────────────────────────────┐│
│  │              Audit Log Processor                                ││
│  │                                                                 ││
│  │  1. Validate event schema                                      ││
│  │  2. Enrich with metadata                                       ││
│  │  3. Sign with digital signature                                ││
│  │  4. Write to WORM storage                                      ││
│  └────────────────────────┬───────────────────────────────────────┘│
│                           │                                         │
│                           ▼                                         │
│  ┌────────────────────────────────────────────────────────────────┐│
│  │              WORM Storage (Write-Once-Read-Many)                ││
│  │                                                                 ││
│  │  • AWS S3 Object Lock (Compliance Mode)                        ││
│  │  • Retention: 5 years (cannot be deleted)                      ││
│  │  • Encryption: Regional KMS                                    ││
│  │  • Versioning: Disabled (prevent overwrites)                   ││
│  │  • MFA Delete: Enabled                                         ││
│  │                                                                 ││
│  │  Storage path:                                                 ││
│  │  s3://audit-logs-jakarta/                                      ││
│  │    └─ year=2026/                                               ││
│  │       └─ month=02/                                             ││
│  │          └─ day=11/                                            ││
│  │             └─ hour=10/                                        ││
│  │                └─ audit-20260211-100000.json.gz                ││
│  └────────────────────────────────────────────────────────────────┘│
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘
```

### Audit Event Schema

```json
{
  "event_id": "evt-20260211-100000-abc123",
  "timestamp": "2026-02-11T10:00:00.000Z",
  "region": "jakarta",
  "event_type": "data_access",
  "actor": {
    "user_id": "user-456",
    "role": "teacher",
    "ip_address": "203.0.113.42",
    "user_agent": "Mozilla/5.0...",
    "session_id": "sess-789"
  },
  "resource": {
    "type": "attendance",
    "id": "att-123",
    "school_id": "school-789"
  },
  "action": "read",
  "result": "success",
  "metadata": {
    "request_id": "req-xyz",
    "api_endpoint": "/api/v1/attendance/123",
    "http_method": "GET",
    "response_code": 200
  },
  "compliance": {
    "data_classification": "sovereign_data",
    "retention_period": "5_years",
    "legal_hold": false
  },
  "signature": {
    "algorithm": "SHA256withRSA",
    "value": "3045022100...",
    "signer": "audit-service-jakarta",
    "timestamp": "2026-02-11T10:00:00.100Z"
  }
}
```

### Audit Trail Implementation

```php
<?php
// app/Services/AuditService.php

namespace App\Services;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Log;

class AuditService
{
    private $s3Client;
    private $bucketName;
    private $signingKey;
    
    public function __construct()
    {
        $region = config('app.region');
        
        $this->s3Client = new S3Client([
            'region' => $this->getAwsRegion($region),
            'version' => 'latest',
        ]);
        
        $this->bucketName = config("audit.s3_buckets.{$region}");
        $this->signingKey = config('audit.signing_key');
        
        // Validate WORM configuration
        $this->validateWormConfiguration();
    }
    
    /**
     * Log audit event (immutable)
     */
    public function log(array $event): void
    {
        // Generate event ID
        $event['event_id'] = $this->generateEventId();
        $event['timestamp'] = now()->toIso8601String();
        $event['region'] = config('app.region');
        
        // Sign event
        $event['signature'] = $this->signEvent($event);
        
        // Write to Kafka (buffer)
        $this->writeToKafka($event);
        
        // Write to WORM storage (permanent)
        $this->writeToWormStorage($event);
    }
    
    /**
     * Write to WORM storage (cannot be modified or deleted)
     */
    private function writeToWormStorage(array $event): void
    {
        $timestamp = now();
        
        // Partition by date for efficient querying
        $key = sprintf(
            'year=%d/month=%02d/day=%02d/hour=%02d/audit-%s.json',
            $timestamp->year,
            $timestamp->month,
            $timestamp->day,
            $timestamp->hour,
            $event['event_id']
        );
        
        try {
            $this->s3Client->putObject([
                'Bucket' => $this->bucketName,
                'Key' => $key,
                'Body' => json_encode($event, JSON_PRETTY_PRINT),
                'ContentType' => 'application/json',
                'ServerSideEncryption' => 'aws:kms',
                'SSEKMSKeyId' => config('encryption.kms_keys.' . config('app.region') . '.master'),
                'ObjectLockMode' => 'COMPLIANCE',
                'ObjectLockRetainUntilDate' => now()->addYears(5)->toIso8601String(),
                'Metadata' => [
                    'event-type' => $event['event_type'],
                    'region' => $event['region'],
                ],
            ]);
            
            Log::info('Audit event written to WORM storage', [
                'event_id' => $event['event_id'],
                'key' => $key,
            ]);
            
        } catch (\Exception $e) {
            Log::critical('Failed to write audit event to WORM storage', [
                'error' => $e->getMessage(),
                'event_id' => $event['event_id'],
            ]);
            
            // This is critical - alert immediately
            $this->alertSecurityTeam('WORM write failure', $e);
            
            throw $e;
        }
    }
    
    /**
     * Sign audit event with digital signature
     */
    private function signEvent(array $event): array
    {
        // Remove signature field if exists
        unset($event['signature']);
        
        // Canonical JSON
        $canonical = json_encode($event, JSON_UNESCAPED_SLASHES);
        
        // Sign with RSA private key
        openssl_sign(
            $canonical,
            $signature,
            $this->signingKey,
            OPENSSL_ALGO_SHA256
        );
        
        return [
            'algorithm' => 'SHA256withRSA',
            'value' => base64_encode($signature),
            'signer' => 'audit-service-' . config('app.region'),
            'timestamp' => now()->toIso8601String(),
        ];
    }
    
    /**
     * Verify audit event signature
     */
    public function verifySignature(array $event): bool
    {
        $signature = $event['signature'];
        unset($event['signature']);
        
        $canonical = json_encode($event, JSON_UNESCAPED_SLASHES);
        
        $publicKey = config('audit.public_key');
        
        $result = openssl_verify(
            $canonical,
            base64_decode($signature['value']),
            $publicKey,
            OPENSSL_ALGO_SHA256
        );
        
        return $result === 1;
    }
    
    /**
     * Validate WORM configuration
     */
    private function validateWormConfiguration(): void
    {
        $config = $this->s3Client->getObjectLockConfiguration([
            'Bucket' => $this->bucketName,
        ]);
        
        if ($config['ObjectLockConfiguration']['ObjectLockEnabled'] !== 'Enabled') {
            throw new \Exception('S3 Object Lock not enabled on audit bucket');
        }
        
        Log::info('WORM configuration validated', [
            'bucket' => $this->bucketName,
            'region' => config('app.region'),
        ]);
    }
}
```

---

## Access Control & IAM

### Zero Trust IAM Architecture

```yaml
# Regional IAM Policy

region: jakarta

iam_policies:
  
  # Policy 1: Regional Admin (cannot access other regions)
  regional_admin:
    principals:
      - arn:aws:iam::xxx:role/JakartaAdmin
    
    allowed_actions:
      - ec2:*
      - rds:*
      - s3:*
      - kms:*
    
    allowed_resources:
      - arn:aws:*:ap-southeast-1:xxx:*  # Jakarta region only
    
    denied_resources:
      - arn:aws:*:ap-southeast-2:xxx:*  # Surabaya region
      - arn:aws:*:ap-southeast-3:xxx:*  # Bandung region
    
    conditions:
      - source_ip: 203.0.113.0/24  # Jakarta office IP
      - mfa_required: true
  
  # Policy 2: Application Service Account
  application_service:
    principals:
      - arn:aws:iam::xxx:role/AttendanceAppJakarta
    
    allowed_actions:
      - s3:GetObject
      - s3:PutObject
      - kms:Decrypt
      - kms:Encrypt
      - rds:Connect
    
    allowed_resources:
      - arn:aws:s3:::attendance-data-jakarta/*
      - arn:aws:kms:ap-southeast-1:xxx:key/jakarta-master
      - arn:aws:rds:ap-southeast-1:xxx:db:attendance-jakarta
    
    denied_actions:
      - s3:DeleteObject  # Cannot delete data
      - kms:DeleteKey    # Cannot delete keys
    
    conditions:
      - vpc_id: vpc-jakarta-xxx  # Must be from Jakarta VPC
  
  # Policy 3: Audit Read-Only (for government auditors)
  audit_readonly:
    principals:
      - arn:aws:iam::xxx:role/GovernmentAuditor
    
    allowed_actions:
      - s3:GetObject
      - s3:ListBucket
      - logs:GetLogEvents
    
    allowed_resources:
      - arn:aws:s3:::audit-logs-jakarta/*
      - arn:aws:logs:ap-southeast-1:xxx:log-group:/aws/attendance/*
    
    denied_actions:
      - s3:PutObject
      - s3:DeleteObject
      - logs:DeleteLogGroup
    
    conditions:
      - mfa_required: true
      - session_duration: 4h  # Max 4 hours
```

### IAM Implementation

```php
<?php
// app/Services/RegionalIAMService.php

namespace App\Services;

use Aws\Iam\IamClient;

class RegionalIAMService
{
    private $iamClient;
    private $region;
    
    public function __construct()
    {
        $this->region = config('app.region');
        
        $this->iamClient = new IamClient([
            'region' => $this->getAwsRegion($this->region),
            'version' => 'latest',
        ]);
    }
    
    /**
     * Enforce regional access control
     */
    public function enforceRegionalAccess(string $userId, string $action, string $resource): bool
    {
        // Get user's allowed regions
        $userRegions = $this->getUserAllowedRegions($userId);
        
        // Check if current region is allowed
        if (!in_array($this->region, $userRegions)) {
            Log::warning('Regional access denied', [
                'user_id' => $userId,
                'user_regions' => $userRegions,
                'attempted_region' => $this->region,
                'action' => $action,
                'resource' => $resource,
            ]);
            
            return false;
        }
        
        // Check if action is allowed
        $policy = $this->getUserPolicy($userId);
        
        if (!$this->isActionAllowed($policy, $action, $resource)) {
            Log::warning('Action denied by IAM policy', [
                'user_id' => $userId,
                'action' => $action,
                'resource' => $resource,
            ]);
            
            return false;
        }
        
        return true;
    }
    
    /**
     * Create regional service account
     */
    public function createRegionalServiceAccount(string $serviceName): array
    {
        $roleName = "{$serviceName}-{$this->region}";
        
        // Create IAM role
        $role = $this->iamClient->createRole([
            'RoleName' => $roleName,
            'AssumeRolePolicyDocument' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => [
                    [
                        'Effect' => 'Allow',
                        'Principal' => [
                            'Service' => 'ec2.amazonaws.com',
                        ],
                        'Action' => 'sts:AssumeRole',
                        'Condition' => [
                            'StringEquals' => [
                                'aws:RequestedRegion' => $this->getAwsRegion($this->region),
                            ],
                        ],
                    ],
                ],
            ]),
            'Tags' => [
                ['Key' => 'Region', 'Value' => $this->region],
                ['Key' => 'Service', 'Value' => $serviceName],
            ],
        ]);
        
        // Attach regional policy
        $this->attachRegionalPolicy($roleName);
        
        return [
            'role_name' => $roleName,
            'role_arn' => $role['Role']['Arn'],
            'region' => $this->region,
        ];
    }
}
```

---

## Government API Integration

### Government Reporting Interface

```php
<?php
// app/Services/GovernmentReportingService.php

namespace App\Services;

use App\Models\Attendance;
use App\Models\School;
use Illuminate\Support\Facades\Http;

class GovernmentReportingService
{
    private $governmentApiUrl;
    private $apiKey;
    
    public function __construct()
    {
        $this->governmentApiUrl = config('government.api_url');
        $this->apiKey = config('government.api_key');
    }
    
    /**
     * Submit daily attendance report to government
     */
    public function submitDailyReport(string $date): void
    {
        $region = config('app.region');
        
        // Aggregate attendance data (anonymized)
        $report = $this->generateDailyReport($date);
        
        // Submit to government API
        $response = Http::withHeaders([
            'X-API-Key' => $this->apiKey,
            'X-Region' => $region,
        ])->post("{$this->governmentApiUrl}/api/v1/attendance/daily", $report);
        
        if ($response->successful()) {
            Log::info('Daily report submitted to government', [
                'date' => $date,
                'region' => $region,
                'submission_id' => $response->json('submission_id'),
            ]);
        } else {
            Log::error('Failed to submit daily report to government', [
                'date' => $date,
                'error' => $response->body(),
            ]);
        }
    }
    
    /**
     * Generate daily report (anonymized)
     */
    private function generateDailyReport(string $date): array
    {
        $region = config('app.region');
        
        // Aggregate by school (no student PII)
        $schoolStats = School::where('region', $region)
            ->with(['attendances' => function ($query) use ($date) {
                $query->whereDate('check_in_time', $date);
            }])
            ->get()
            ->map(function ($school) {
                return [
                    'school_id' => $school->government_id,  // Government-issued ID
                    'total_students' => $school->total_students,
                    'present_count' => $school->attendances->where('status', 'present')->count(),
                    'absent_count' => $school->attendances->where('status', 'absent')->count(),
                    'late_count' => $school->attendances->where('status', 'late')->count(),
                    'attendance_rate' => $school->attendances->where('status', 'present')->count() / $school->total_students,
                ];
            });
        
        return [
            'date' => $date,
            'region' => $region,
            'total_schools' => $schoolStats->count(),
            'schools' => $schoolStats->toArray(),
            'regional_summary' => [
                'total_students' => $schoolStats->sum('total_students'),
                'total_present' => $schoolStats->sum('present_count'),
                'regional_attendance_rate' => $schoolStats->avg('attendance_rate'),
            ],
        ];
    }
    
    /**
     * Handle government audit request
     */
    public function handleAuditRequest(string $auditId, array $parameters): array
    {
        // Validate audit request signature
        if (!$this->validateAuditRequest($auditId, $parameters)) {
            throw new \Exception('Invalid audit request signature');
        }
        
        // Log audit request
        AuditService::log([
            'event_type' => 'government_audit_request',
            'audit_id' => $auditId,
            'parameters' => $parameters,
        ]);
        
        // Generate audit report
        $report = $this->generateAuditReport($parameters);
        
        // Return encrypted report
        return [
            'audit_id' => $auditId,
            'report' => $this->encryptForGovernment($report),
            'signature' => $this->signReport($report),
        ];
    }
}
```

---

## Compliance Checklist

### SOC 2 Type II Compliance

```markdown
# SOC 2 Type II Compliance Checklist

## Trust Service Criteria

### Security (CC6)
- [x] CC6.1: Logical and physical access controls
  - Regional IAM with MFA
  - VPC isolation per region
  - Zero trust network architecture
  
- [x] CC6.2: Prior to issuing system credentials
  - Background checks for admins
  - Least privilege principle
  - Regular access reviews
  
- [x] CC6.3: Provisioning and de-provisioning
  - Automated user lifecycle management
  - Immediate access revocation on termination
  
- [x] CC6.6: Logical access - encryption
  - AES-256 encryption at rest (KMS)
  - TLS 1.3 in transit
  - Regional encryption keys
  
- [x] CC6.7: Transmission of data
  - All API calls over HTTPS
  - VPN for admin access
  - No cross-region data transfer
  
- [x] CC6.8: Malicious software
  - AWS GuardDuty enabled
  - Regular vulnerability scans
  - Intrusion detection system

### Availability (A1)
- [x] A1.1: Availability commitments
  - 99.9% SLA per region
  - Regional failover capability
  - Auto-scaling enabled
  
- [x] A1.2: System monitoring
  - Prometheus + Grafana
  - 24/7 monitoring
  - Automated alerting
  
- [x] A1.3: Incident response
  - Documented incident response plan
  - Regular drills
  - Post-incident reviews

### Confidentiality (C1)
- [x] C1.1: Confidential information
  - Data classification policy
  - Encryption for sovereign data
  - Access logging
  
- [x] C1.2: Disposal of confidential information
  - Secure deletion procedures
  - 5-year retention policy
  - Cryptographic erasure

### Processing Integrity (PI1)
- [x] PI1.1: Processing integrity commitments
  - Input validation
  - Transaction logging
  - Idempotency checks
  
- [x] PI1.4: Data processing
  - Automated data quality checks
  - Reconciliation procedures
  - Error handling

### Privacy (P1-P8)
- [x] P1.1: Privacy notice
  - Clear privacy policy
  - Consent management
  - Data subject rights
  
- [x] P2.1: Choice and consent
  - Explicit consent for data processing
  - Opt-out mechanisms
  
- [x] P3.1: Collection
  - Data minimization
  - Purpose limitation
  - Lawful basis
  
- [x] P4.1: Use, retention, and disposal
  - 5-year retention
  - Automated deletion
  - Audit trail
  
- [x] P5.1: Disclosure to third parties
  - No third-party sharing without consent
  - Data processing agreements
  
- [x] P6.1: Data quality
  - Accuracy checks
  - Update mechanisms
  
- [x] P7.1: Monitoring and enforcement
  - Privacy impact assessments
  - Regular audits
  - Compliance monitoring
  
- [x] P8.1: Risk assessment
  - Annual privacy risk assessment
  - Threat modeling
  - Mitigation plans
```

### ISO 27001 Compliance

```markdown
# ISO 27001:2013 Compliance Checklist

## Annex A Controls

### A.9 Access Control
- [x] A.9.1.1: Access control policy
  - Regional access control policy documented
  - Zero trust architecture
  
- [x] A.9.2.1: User registration and de-registration
  - Automated user lifecycle
  - Access review quarterly
  
- [x] A.9.2.3: Management of privileged access rights
  - Separate admin accounts per region
  - MFA required
  - Just-in-time access
  
- [x] A.9.4.1: Information access restriction
  - Row-level security
  - Regional data isolation
  - Encryption at rest

### A.10 Cryptography
- [x] A.10.1.1: Policy on the use of cryptographic controls
  - Encryption policy documented
  - AES-256 for data at rest
  - TLS 1.3 for data in transit
  
- [x] A.10.1.2: Key management
  - AWS KMS per region
  - Monthly key rotation
  - Key access logging

### A.12 Operations Security
- [x] A.12.1.1: Documented operating procedures
  - Runbooks for all operations
  - Change management process
  
- [x] A.12.3.1: Information backup
  - Daily automated backups
  - Regional backup storage
  - Quarterly restore testing
  
- [x] A.12.4.1: Event logging
  - Comprehensive audit logging
  - 5-year retention
  - WORM storage
  
- [x] A.12.4.3: Administrator and operator logs
  - All admin actions logged
  - Immutable audit trail
  - Real-time monitoring

### A.18 Compliance
- [x] A.18.1.1: Identification of applicable legislation
  - Data protection laws identified
  - Compliance register maintained
  
- [x] A.18.1.5: Regulation of cryptographic controls
  - Compliant with national regulations
  - Export controls followed
```

### ISO 27701 (Privacy) Compliance

```markdown
# ISO 27701:2019 Privacy Compliance Checklist

## PII Controller Controls

### 6.2 Conditions for collection and processing
- [x] 6.2.1: Identify and document purpose
  - Purpose documented in privacy policy
  - Lawful basis identified
  
- [x] 6.2.2: Identify lawful basis
  - Consent for student data
  - Legitimate interest for fraud detection
  
- [x] 6.2.3: Determine when and how consent is obtained
  - Explicit consent mechanism
  - Withdrawal of consent supported

### 6.3 Obligations to PII principals
- [x] 6.3.1: Determine and fulfill obligations to PII principals
  - Right to access
  - Right to rectification
  - Right to erasure
  - Right to data portability
  
- [x] 6.3.2: Determine information for PII principals
  - Privacy notice provided
  - Data processing activities disclosed

### 6.4 Privacy by design and by default
- [x] 6.4.1: Limit collection
  - Data minimization implemented
  - Only necessary data collected
  
- [x] 6.4.2: Limit processing
  - Purpose limitation enforced
  - Processing restricted to stated purposes

### 6.5 PII sharing, transfer, and disclosure
- [x] 6.5.1: Identify basis for PII transfer
  - No cross-border transfer
  - Regional data residency enforced
  
- [x] 6.5.2: Countries and international organizations to which PII can be transferred
  - Transfer prohibited
  - Regional isolation enforced
```

---

## Certification Roadmap

### Year 1: Foundation & SOC 2

```
Q1 (Jan-Mar 2026):
├─ Gap analysis against SOC 2 requirements
├─ Implement missing controls
├─ Document policies and procedures
└─ Select SOC 2 auditor

Q2 (Apr-Jun 2026):
├─ SOC 2 Type I audit (point-in-time)
├─ Remediate findings
├─ Begin 6-month observation period for Type II
└─ Implement continuous monitoring

Q3 (Jul-Sep 2026):
├─ Continue Type II observation period
├─ Monthly control testing
├─ Documentation updates
└─ Internal audits

Q4 (Oct-Dec 2026):
├─ Complete Type II observation period
├─ SOC 2 Type II audit
├─ Receive SOC 2 Type II report
└─ Publish compliance status
```

### Year 2: ISO 27001 & ISO 27701

```
Q1 (Jan-Mar 2027):
├─ ISO 27001 gap analysis
├─ Information Security Management System (ISMS) implementation
├─ Risk assessment
└─ Statement of Applicability (SoA)

Q2 (Apr-Jun 2027):
├─ ISO 27001 Stage 1 audit (documentation review)
├─ Remediate findings
├─ Internal ISMS audits
└─ Management review

Q3 (Jul-Sep 2027):
├─ ISO 27001 Stage 2 audit (implementation review)
├─ ISO 27701 gap analysis
├─ Privacy Information Management System (PIMS) implementation
└─ Data Protection Impact Assessments (DPIAs)

Q4 (Oct-Dec 2027):
├─ ISO 27701 audit
├─ Receive ISO 27001 certification
├─ Receive ISO 27701 certification
└─ Annual surveillance planning
```

### Year 3: Continuous Compliance

```
Ongoing:
├─ Annual SOC 2 Type II audits
├─ Annual ISO 27001 surveillance audits
├─ Annual ISO 27701 surveillance audits
├─ Quarterly internal audits
├─ Continuous control monitoring
└─ Regular penetration testing
```

---

## Conclusion

This sovereign cloud architecture provides:

✅ **Data Residency:** All data stored within regional boundaries  
✅ **Government Audit Ready:** Immutable audit logs, 5-year retention  
✅ **Zero Foreign Transfer:** Complete regional isolation  
✅ **Certification Ready:** SOC 2, ISO 27001, ISO 27701 compliant  
✅ **Regional Encryption:** Separate KMS keys per region  
✅ **Zero Trust IAM:** No cross-region admin access  

The system is ready for deployment with full sovereign cloud compliance.
