<?php

namespace App\Exceptions;

use Exception;

/**
 * Exception for attendance-related business logic errors
 *
 * Messages from this exception are safe to show to end users.
 * Use this for validation errors, business rule violations, etc.
 */
class AttendanceException extends Exception
{
    /**
     * Create a new attendance exception
     */
    public function __construct(string $message = '', int $code = 400, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * QR code is invalid or expired
     */
    public static function invalidQrCode(): self
    {
        return new self('QR Code tidak valid atau sudah kadaluarsa.');
    }

    /**
     * Student is not in the class
     */
    public static function studentNotInClass(): self
    {
        return new self('Siswa tidak terdaftar di kelas ini.');
    }

    /**
     * Attendance already recorded
     */
    public static function alreadyRecorded(): self
    {
        return new self('Absensi sudah dicatat sebelumnya.');
    }

    /**
     * Outside allowed time window
     */
    public static function outsideTimeWindow(): self
    {
        return new self('Di luar waktu absensi yang diizinkan.');
    }

    /**
     * Location is outside allowed radius
     */
    public static function outsideRadius(): self
    {
        return new self('Lokasi di luar radius yang diizinkan.');
    }

    /**
     * Schedule not found or inactive
     */
    public static function scheduleNotFound(): self
    {
        return new self('Tidak ada jadwal mengajar aktif saat ini.');
    }

    /**
     * User doesn't have the required role
     */
    public static function invalidRole(): self
    {
        return new self('Anda tidak memiliki akses untuk melakukan absensi.');
    }

    /**
     * QR code has expired
     */
    public static function expired(): self
    {
        return new self('QR Code sudah kadaluarsa.');
    }

    /**
     * Teacher doesn't own the schedule
     */
    public static function unauthorizedSchedule(): self
    {
        return new self('Anda bukan pengajar pada jadwal ini.');
    }

    /**
     * Replay attack detected (nonce reused)
     */
    public static function replayDetected(): self
    {
        return new self('QR Code sudah digunakan. Setiap QR hanya dapat digunakan satu kali.');
    }

    /**
     * Cross-school scan attempt
     */
    public static function crossSchoolAttempt(): self
    {
        return new self('QR Code tidak valid untuk sekolah ini.');
    }

    /**
     * Student not found or inactive
     */
    public static function studentNotFound(): self
    {
        return new self('Siswa tidak ditemukan atau tidak aktif.');
    }

    /**
     * No active schedule for today
     */
    public static function noActiveSchedule(): self
    {
        return new self('Tidak ada jadwal aktif untuk hari ini.');
    }

    /**
     * Device ID mismatch (anti-joki protection)
     */
    public static function deviceMismatch(): self
    {
        return new self('Perangkat tidak dikenali. Harap gunakan HP Anda sendiri yang terdaftar.');
    }

    /**
     * QR signature invalid
     */
    public static function invalidSignature(): self
    {
        return new self('Tanda tangan QR tidak valid. QR mungkin telah dimodifikasi.');
    }

    /**
     * Generic error with custom message
     */
    public static function custom(string $message, int $code = 400): self
    {
        return new self($message, $code);
    }
}
