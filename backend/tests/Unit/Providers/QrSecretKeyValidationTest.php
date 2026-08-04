<?php

namespace Tests\Unit\Providers;

use App\Providers\AppServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * SEC-2: QR_SECRET_KEY must never fall back to a public default value.
 *
 * Regression guards:
 * 1. config('qr.secret') must not contain any known public default string.
 * 2. Boot validation must hard-fail in non-local envs when QR_SECRET_KEY
 *    is missing, too short, or still set to a known default.
 */
class QrSecretKeyValidationTest extends TestCase
{
    private const FORBIDDEN_DEFAULTS = [
        'change-this-in-production-must-be-32-chars-minimum',
        'generate_with_command_above_32_chars_minimum',
    ];

    #[Test]
    public function qr_secret_never_uses_a_public_default_fallback(): void
    {
        $secret = config('qr.secret');

        $this->assertNotContains($secret, self::FORBIDDEN_DEFAULTS, 'config(qr.secret) must not be a public default value');
        $this->assertNotEmpty($secret);
    }

    private function provider(): AppServiceProvider
    {
        return new AppServiceProvider($this->app);
    }

    #[Test]
    public function missing_qr_secret_key_fails_fast_in_non_local_environment(): void
    {
        $method = new ReflectionMethod($this->provider(), 'validateCriticalEnvVars');

        putenv('QR_SECRET_KEY');
        unset($_ENV['QR_SECRET_KEY'], $_SERVER['QR_SECRET_KEY']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/QR_SECRET_KEY/');

        $method->invoke($this->provider(), 'staging');
    }

    #[Test]
    public function default_placeholder_qr_secret_key_fails_fast_in_non_local_environment(): void
    {
        $method = new ReflectionMethod($this->provider(), 'validateCriticalEnvVars');

        putenv('QR_SECRET_KEY=generate_with_command_above_32_chars_minimum');
        $_ENV['QR_SECRET_KEY'] = 'generate_with_command_above_32_chars_minimum';
        $_SERVER['QR_SECRET_KEY'] = 'generate_with_command_above_32_chars_minimum';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/QR_SECRET_KEY/');

        $method->invoke($this->provider(), 'staging');
    }

    #[Test]
    public function valid_qr_secret_key_passes_validation_in_non_local_environment(): void
    {
        $method = new ReflectionMethod($this->provider(), 'validateCriticalEnvVars');

        $validKey = base64_encode(random_bytes(32));
        putenv("QR_SECRET_KEY={$validKey}");
        $_ENV['QR_SECRET_KEY'] = $validKey;
        $_SERVER['QR_SECRET_KEY'] = $validKey;

        // Should not throw
        $method->invoke($this->provider(), 'staging');

        $this->addToAssertionCount(1);
    }

    protected function tearDown(): void
    {
        putenv('QR_SECRET_KEY');
        unset($_ENV['QR_SECRET_KEY'], $_SERVER['QR_SECRET_KEY']);

        parent::tearDown();
    }
}
