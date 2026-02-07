<?php

namespace App\Exceptions;

use Exception;

/**
 * Exception for QR code validation errors
 *
 * Messages from this exception are safe to show to end users.
 * Use this for QR validation failures, signature mismatches, etc.
 */
class QrValidationException extends Exception
{
    /**
     * Create a new QR validation exception
     */
    public function __construct(string $message = '', int $code = 400, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Invalid signature
     */
    public static function invalidSignature(): self
    {
        return new self('QR Code tidak valid - signature tidak cocok.');
    }

    /**
     * QR code expired
     */
    public static function expired(): self
    {
        return new self('QR Code sudah kadaluarsa.');
    }

    /**
     * Missing required data
     */
    public static function missingData(): self
    {
        return new self('QR Code tidak lengkap atau rusak.');
    }

    /**
     * Student not found
     */
    public static function studentNotFound(): self
    {
        return new self('Data siswa tidak ditemukan.');
    }

    /**
     * Invalid QR format
     */
    public static function invalidFormat(): self
    {
        return new self('Format QR Code tidak valid.');
    }

    /**
     * Replay attack detected
     */
    public static function replayDetected(): self
    {
        return new self('QR Code sudah pernah digunakan.');
    }
}
