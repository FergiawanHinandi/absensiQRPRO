<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * CRITICAL: ProcessedWebhook Model for Idempotency
 *
 * PREVENTS:
 * - Duplicate webhook processing
 * - Replay attacks
 * - Data corruption from multiple processing
 */
class ProcessedWebhook extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'transaction_id',
        'webhook_type',
        'payment_method',
        'status',
        'webhook_payload',
        'signature_hash',
        'source_ip',
        'user_agent',
        'processed_at',
        'processed_by',
        'processing_notes',
    ];

    protected $casts = [
        'webhook_payload' => 'array',
        'processed_at' => 'datetime',
    ];

    /**
     * CRITICAL: Check if webhook already processed
     */
    public static function isAlreadyProcessed(string $orderId): bool
    {
        return self::where('order_id', $orderId)->exists();
    }

    /**
     * CRITICAL: Check if transaction already processed
     */
    public static function isTransactionProcessed(string $transactionId): bool
    {
        return self::where('transaction_id', $transactionId)->exists();
    }

    /**
     * CRITICAL: Create processed webhook record
     */
    public static function markAsProcessed(array $data): self
    {
        return self::create([
            'order_id' => $data['order_id'],
            'transaction_id' => $data['transaction_id'],
            'webhook_type' => $data['webhook_type'] ?? 'payment',
            'payment_method' => $data['payment_method'] ?? 'midtrans',
            'status' => $data['status'] ?? 'success',
            'webhook_payload' => $data['payload'] ?? [],
            'signature_hash' => $data['signature_hash'] ?? null,
            'source_ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'processed_at' => now(),
            'processed_by' => $data['processed_by'] ?? 'system',
            'processing_notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * Get processing history for order
     */
    public static function getProcessingHistory(string $orderId)
    {
        return self::where('order_id', $orderId)
            ->orderBy('processed_at', 'desc')
            ->get();
    }

    /**
     * Scopes
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('webhook_type', $type);
    }

    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('processed_at', '>=', now()->subHours($hours));
    }
}
