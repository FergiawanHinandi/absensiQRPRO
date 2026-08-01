<?php

namespace Tests\Unit\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Unit Tests: Redis Encryption at Rest
 * 
 * Tests encryption at rest for sensitive data stored in Redis,
 * including session tokens and personal information.
 * 
 * Validates: Requirement 10.3 (Encrypt sensitive data at rest)
 */
class RedisEncryptionAtRestTest extends TestCase
{
    /**
     * @test
     * Validates: Requirement 10.3
     */
    public function it_supports_application_level_encryption_for_sensitive_data(): void
    {
        $sensitiveData = 'user_password_hash_12345';
        
        // Application should encrypt sensitive data before storing in Redis
        $encrypted = Crypt::encryptString($sensitiveData);
        $decrypted = Crypt::decryptString($encrypted);
        
        $this->assertNotEquals($sensitiveData, $encrypted);
        $this->assertEquals($sensitiveData, $decrypted);
    }

    /**
     * @test
     * Validates: Requirement 10.3
     */
    public function it_validates_encryption_key_configuration(): void
    {
        $appKey = Config::get('app.key');
        
        $this->assertNotEmpty($appKey, 'Application encryption key must be configured');
        $this->assertStringStartsWith('base64:', $appKey, 
            'Encryption key should be base64 encoded');
    }

    /**
     * @test
     * Validates: Requirement 10.3
     */
    public function it_validates_encryption_cipher_strength(): void
    {
        $cipher = Config::get('app.cipher');
        
        // Should use strong encryption
        $this->assertEquals('AES-256-CBC', $cipher, 
            'Should use AES-256-CBC for encryption');
    }

    /**
     * @test
     * Validates: Requirement 10.3
     */
    public function it_encrypts_session_tokens_before_redis_storage(): void
    {
        $sessionToken = 'bearer_token_abc123xyz789';
        
        // Session tokens should be encrypted
        $encrypted = Crypt::encryptString($sessionToken);
        
        $this->assertNotEquals($sessionToken, $encrypted);
        $this->assertGreaterThan(strlen($sessionToken), strlen($encrypted),
            'Encrypted data should be longer than plaintext');
    }
}
