# 🔐 Tenant-Specific Data Encryption Strategy

**Data Security Architect**  
**Date**: 2026-02-10  
**Target**: Per-School Encryption (AES-256-GCM)  
**Compliance**: GDPR / PDP Ready

---

## 🏗️ Encryption Architecture (Envelope Encryption)

We use a hierarchy of keys to ensure isolation and manageability.

### 1. Key Hierarchy

*   **Master Key (KEK - Key Encryption Key)**:
    *   **Location**: AWS KMS (Key Management Service) or HashiCorp Vault.
    *   **Role**: Encrypts and decrypts the *School Keys*.
    *   **Access**: Never leaves the KMS hardware (HSM). App only sends data to KMS to be encrypted/decrypted.

*   **School Key (DEK - Data Encryption Key)**:
    *   **Location**: Stored in `schools` table (column `encrypted_key`), encrypted by the Master Key.
    *   **Role**: Encrypts actual student/payment data.
    *   **Uniqueness**: Generated randomly (32 bytes) for EACH school.
    *   **Lifecycle**: Rotated periodically or on-demand.

### 2. Database Schema

Add these columns to the `schools` table:

```sql
ALTER TABLE schools ADD COLUMN encrypted_key TEXT NOT NULL; -- The DEK encrypted by KMS
ALTER TABLE schools ADD COLUMN key_version INTEGER DEFAULT 1; -- For rotation tracking
```

---

## 🛠️ Implementation Logic

### 1. Encryption Service (`SchoolEncryptionService`)

This service handles the logic of retrieving the key and performing crypto operations.

```php
<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Cache;
use App\Models\School;
use Aws\Kms\KmsClient; // Assumption: Using AWS KMS

class SchoolEncryptionService
{
    private KmsClient $kms;
    private array $keyCache = []; // Request-level cache

    public function __construct(KmsClient $kms)
    {
        $this->kms = $kms; // Injected via ServiceProvider
    }

    /**
     * Get the Plaintext School Key (DEK)
     * CACHED for performance (Request & Short-term Redis)
     */
    private function getSchoolKey(int $schoolId): string
    {
        // 1. Check Request Cache
        if (isset($this->keyCache[$schoolId])) {
            return $this->keyCache[$schoolId];
        }

        // 2. Check Redis (Encrypted with a transient app key for speed, TTL 5 min)
        // We avoid calling KMS on every single row decryption
        $cacheKey = "school_key:{$schoolId}";
        if ($cached = Cache::get($cacheKey)) {
             $key = $this->localDecrypt($cached);
             $this->keyCache[$schoolId] = $key;
             return $key;
        }

        // 3. Retrieve from DB & Decrypt via KMS
        $school = School::find($schoolId);
        if (!$school || !$school->encrypted_key) {
            throw new \Exception("Encryption key not found for school {$schoolId}");
        }

        // Call KMS to decrypt the DEK
        $result = $this->kms->decrypt([
            'CiphertextBlob' => base64_decode($school->encrypted_key),
            'EncryptionContext' => ['school_id' => (string)$schoolId] // Bind to context!
        ]);

        $plainKey = $result['Plaintext'];

        // 4. Store in Cache
        $this->keyCache[$schoolId] = $plainKey;
        Cache::put($cacheKey, $this->localEncrypt($plainKey), 300); // 5 min TTL

        return $plainKey;
    }

    /**
     * Encrypt Data
     */
    public function encrypt(int $schoolId, string $value): string
    {
        $key = $this->getSchoolKey($schoolId);
        $nonce = random_bytes(12); // AES-GCM requires 12-byte nonce
        $tag = ""; // Passed by reference

        // AES-256-GCM
        $cipherText = openssl_encrypt(
            $value, 
            'aes-256-gcm', 
            $key, 
            OPENSSL_RAW_DATA, 
            $nonce, 
            $tag
        );

        // Result: Base64(Nonce + Tag + Ciphertext)
        return base64_encode($nonce . $tag . $cipherText);
    }

    /**
     * Decrypt Data
     */
    public function decrypt(int $schoolId, string $encryptedValue): string
    {
        $data = base64_decode($encryptedValue);
        
        // Extract components
        $nonce = substr($data, 0, 12);
        $tag = substr($data, 12, 16);
        $cipherText = substr($data, 28);
        
        $key = $this->getSchoolKey($schoolId);

        $plainText = openssl_decrypt(
            $cipherText, 
            'aes-256-gcm', 
            $key, 
            OPENSSL_RAW_DATA, 
            $nonce, 
            $tag
        );

        if ($plainText === false) {
            throw new \Exception("Decryption failed for school {$schoolId} - Integrity Check Failed");
        }

        return $plainText;
    }

    // Helper for transient local encryption (App Key)
    private function localEncrypt($data) { /* ... AES-256-CBC using APP_KEY ... */ }
    private function localDecrypt($data) { /* ... */ }
}
```

### 2. Transparent Integration (Laravel Casts)

Using Custom Casts makes encryption invisible to the Controller/Business Logic.

```php
<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use App\Facades\SchoolEncryption; // Facade for the service above

class EncryptedSchoolField implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes)
    {
        if (is_null($value)) return null;
        // Require school_id to be loaded on the model
        return SchoolEncryption::decrypt($attributes['school_id'], $value);
    }

    public function set($model, string $key, $value, array $attributes)
    {
        if (is_null($value)) return null;
        // Require school_id to be available
        $schoolId = $attributes['school_id'] ?? $model->school_id;
        return SchoolEncryption::encrypt($schoolId, $value);
    }
}
```

**Usage in Model:**

```php
class Student extends Model {
    protected $casts = [
        'name' => EncryptedSchoolField::class,
        'parent_phone' => EncryptedSchoolField::class,
    ];
}
```

---

## 🔄 Key Rotation Strategy

Rotation is critical if a School Key is suspected to be compromised.

**Command**: `php artisan security:rotate-key {school_id}`

**Flow:**

1.  **Maintenance Mode**: Lock the school scope (prevent writes).
2.  **Generate New Key**: create `$newKey`.
3.  **Load All Data**: Iterate through all encrypted tables (Students, Payments) in chunks.
4.  **Re-Encrypt**:
    *   Decrypt with `$oldKey`.
    *   Encrypt with `$newKey`.
5.  **Save New Key**: Encrypt `$newKey` with KMS and update `schools.encrypted_key`.
6.  **Release Lock**: Resume operations.

**Code Logic**:

```php
DB::transaction(function () use ($schoolId, $kms) {
    $school = School::lockForUpdate()->find($schoolId);
    $oldKey = SchoolEncryption::getSchoolKey($schoolId);
    $newKey = random_bytes(32);

    // 1. Students
    foreach (Student::where('school_id', $schoolId)->cursor() as $student) {
        // Manually decrypt/encrypt raw attributes to bypass Casts for rotation
        $plainName = decrypt_raw($student->getRawOriginal('name'), $oldKey);
        $student->name = encrypt_raw($plainName, $newKey); // Save raw
        $student->save();
    }
    
    // ... repeat for other tables ...

    // 2. Update School Key
    $encryptedNewKey = $kms->encrypt(['Plaintext' => $newKey, ...]);
    $school->encrypted_key = base64_encode($encryptedNewKey['CiphertextBlob']);
    $school->key_version++;
    $school->save();
});
```

---

## 📊 Performance Estimation

*   **AES-256-GCM**: Extremely fast on modern CPUs (AES-NI). Check < 0.1ms per field.
*   **KMS Latency**: ~20-50ms per call. **This is why Caching (Redis) is mandatory.**
    *   Without Cache: Every request = KMS Call = Slow.
    *   With Cache: First request = 50ms, Subsequent = 0.1ms.
*   **Database**: Ciphertext is larger (~+40 bytes). Storage increase is negligible for text fields.

---

## 🛡️ Recovery & Backup Strategy

### Scenario 1: Database Compromise NOT Master Key
*   **Risk**: Attacker dumps `schools` and `students` tables.
*   **Result**: They have `encrypted_key` (useless without KMS access) and encrypted student data (useless without School Key). **Data is Safe.**

### Scenario 2: Master Key Compromise (KMS)
*   **Risk**: Attacker has access to AWS KMS.
*   **Response**: 
    1.  Rotate Master Key in KMS immediately.
    2.  Trigger `Re-Key` of all `schools.encrypted_key` (KMS Re-wrap).
    3.  Database data does *not* need re-encryption (DEK is unchanged, only its envelope changed).

### Scenario 3: Lost Master Key
*   **Result**: **CATASTROPHIC DATA LOSS.**
*   **Prevention**: Enable **KMS Key Deletion Protection** and strictly limit `kms:ScheduleKeyDeletion` permission.

---

**Status**: ✅ Strategy Complete  
**Docs**: `DATA_ENCRYPTION_STRATEGY.md`
