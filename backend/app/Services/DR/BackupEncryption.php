<?php

namespace App\Services\DR;

use App\Models\School;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Backup Encryption Service
 *
 * AES-256-GCM encryption for backup files with school-specific key derivation.
 *
 * Spec: disaster-recovery-audit-improvements / tasks.md Task 13.1
 */
class BackupEncryption
{
    private const ALGORITHM     = 'aes-256-gcm';
    private const TAG_LENGTH    = 16;
    private const IV_LENGTH     = 12; // GCM recommended

    /**
     * Encrypt backup file content.
     *
     * @param  string $data       Raw backup data
     * @param  int|null $schoolId School ID for tenant-specific key derivation
     * @return array{encrypted: string, iv: string, tag: string, school_id: int|null}
     */
    public function encrypt(string $data, ?int $schoolId = null): array
    {
        $key = $this->deriveKey($schoolId);
        $iv  = random_bytes(self::IV_LENGTH);
        $tag = '';

        $encrypted = openssl_encrypt(
            $data,
            self::ALGORITHM,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($encrypted === false) {
            throw new \RuntimeException('BackupEncryption: openssl_encrypt failed');
        }

        return [
            'encrypted' => base64_encode($encrypted),
            'iv'        => base64_encode($iv),
            'tag'       => base64_encode($tag),
            'school_id' => $schoolId,
            'algorithm' => self::ALGORITHM,
            'version'   => 1,
        ];
    }

    /**
     * Decrypt backup data.
     *
     * @param  array $payload Encrypted payload from encrypt()
     * @return string Decrypted raw data
     */
    public function decrypt(array $payload): string
    {
        $schoolId  = $payload['school_id'] ?? null;
        $key       = $this->deriveKey($schoolId);
        $encrypted = base64_decode($payload['encrypted']);
        $iv        = base64_decode($payload['iv']);
        $tag       = base64_decode($payload['tag']);

        $decrypted = openssl_decrypt(
            $encrypted,
            self::ALGORITHM,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($decrypted === false) {
            throw new \RuntimeException('BackupEncryption: Decryption failed — wrong key or corrupted data');
        }

        return $decrypted;
    }

    /**
     * Encrypt a file on disk and save as .enc file.
     */
    public function encryptFile(string $sourcePath, ?int $schoolId = null): string
    {
        $data    = file_get_contents($sourcePath);
        $payload = $this->encrypt($data, $schoolId);

        $encPath = $sourcePath . '.enc';
        file_put_contents($encPath, json_encode($payload));

        Log::info('BackupEncryption: File encrypted', [
            'source'    => basename($sourcePath),
            'school_id' => $schoolId,
        ]);

        return $encPath;
    }

    /**
     * Decrypt a .enc file.
     */
    public function decryptFile(string $encPath): string
    {
        $payload   = json_decode(file_get_contents($encPath), true);
        $decrypted = $this->decrypt($payload);

        $outputPath = str_replace('.enc', '', $encPath);
        file_put_contents($outputPath, $decrypted);

        return $outputPath;
    }

    /**
     * Derive encryption key.
     *
     * For school-specific backups: derives unique key from master key + school_id
     * For global backups: uses master key directly
     */
    private function deriveKey(?int $schoolId): string
    {
        $masterKey = config('disaster_recovery.backup.encryption.key_env');
        $keyValue  = env($masterKey) ?: config('app.key');

        if (!$keyValue) {
            throw new \RuntimeException('BackupEncryption: No encryption key configured');
        }

        // Remove Laravel's 'base64:' prefix if present
        if (str_starts_with($keyValue, 'base64:')) {
            $keyValue = base64_decode(substr($keyValue, 7));
        }

        if ($schoolId !== null) {
            // PBKDF2 derivation: master key + school_id salt
            return hash_pbkdf2('sha256', $keyValue, "school_{$schoolId}", 1000, 32, true);
        }

        return substr(hash('sha256', $keyValue, true), 0, 32);
    }
}
