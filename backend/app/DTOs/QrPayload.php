<?php

namespace App\DTOs;

/**
 * QR Payload Data Transfer Object
 *
 * Immutable representation of decoded QR token
 */
final class QrPayload
{
    public function __construct(
        private array $data
    ) {}

    public function scheduleId(): int
    {
        return $this->data['sid'];
    }

    public function qrCodeId(): int
    {
        return $this->data['qid'];
    }

    public function type(): string
    {
        return $this->data['typ']; // 'in' | 'out'
    }

    public function issuedAt(): int
    {
        return $this->data['iat'];
    }

    public function expiresAt(): int
    {
        return $this->data['exp'];
    }

    public function nonce(): string
    {
        return $this->data['nonce'];
    }

    public function version(): int
    {
        return $this->data['v'] ?? 1;
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
