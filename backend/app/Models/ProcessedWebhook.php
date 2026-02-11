<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class ProcessedWebhook extends Model
{
    protected $fillable = [
        'order_id',
        'transaction_id',
        'status',
        'transaction_status',
        'payment_type',
        'payment_method',
        'gross_amount',
        'payload',
        'signature_hash',
        'processing_notes',
        'processed_at',
    ];
    
    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime'
    ];

    /**
     * Mark webhook as being processed (initial state)
     * 
     * @param array $data
     * @return self
     */
    public static function markAsProcessing(array $data): self
    {
        $webhook = self::create([
            'order_id' => $data['order_id'],
            'transaction_id' => $data['transaction_id'],
            'status' => 'processing',
            'payload' => $data['payload'] ?? null,
            'signature_hash' => $data['signature_hash'] ?? null,
            'payment_method' => $data['payment_method'] ?? null,
            'processing_notes' => $data['notes'] ?? 'Processing started',
        ]);

        Log::info('Webhook marked as processing', [
            'order_id' => $data['order_id'],
            'transaction_id' => $data['transaction_id'],
            'webhook_id' => $webhook->id,
        ]);

        return $webhook;
    }

    /**
     * Mark webhook as processed (final state)
     * 
     * @param array $data
     * @return self
     */
    public static function markAsProcessed(array $data): self
    {
        // Try to find existing processing record
        $webhook = self::where('order_id', $data['order_id'])
            ->orWhere('transaction_id', $data['transaction_id'])
            ->first();

        if ($webhook) {
            // Update existing record
            $webhook->update([
                'status' => $data['status'] ?? 'success',
                'transaction_status' => $data['transaction_status'] ?? null,
                'payment_type' => $data['payment_type'] ?? null,
                'payment_method' => $data['payment_method'] ?? $webhook->payment_method,
                'gross_amount' => $data['gross_amount'] ?? null,
                'payload' => $data['payload'] ?? $webhook->payload,
                'signature_hash' => $data['signature_hash'] ?? $webhook->signature_hash,
                'processing_notes' => $data['notes'] ?? 'Processed successfully',
                'processed_at' => now(),
            ]);

            Log::info('Webhook updated to processed', [
                'order_id' => $data['order_id'],
                'transaction_id' => $data['transaction_id'],
                'status' => $webhook->status,
                'webhook_id' => $webhook->id,
            ]);
        } else {
            // Create new record if not exists
            $webhook = self::create([
                'order_id' => $data['order_id'],
                'transaction_id' => $data['transaction_id'],
                'status' => $data['status'] ?? 'success',
                'transaction_status' => $data['transaction_status'] ?? null,
                'payment_type' => $data['payment_type'] ?? null,
                'payment_method' => $data['payment_method'] ?? null,
                'gross_amount' => $data['gross_amount'] ?? null,
                'payload' => $data['payload'] ?? null,
                'signature_hash' => $data['signature_hash'] ?? null,
                'processing_notes' => $data['notes'] ?? 'Processed successfully',
                'processed_at' => now(),
            ]);

            Log::info('Webhook marked as processed', [
                'order_id' => $data['order_id'],
                'transaction_id' => $data['transaction_id'],
                'status' => $webhook->status,
                'webhook_id' => $webhook->id,
            ]);
        }

        return $webhook;
    }

    /**
     * Check if order has already been processed
     * 
     * @param string $orderId
     * @return bool
     */
    public static function isAlreadyProcessed(string $orderId): bool
    {
        return self::where('order_id', $orderId)
            ->whereIn('status', ['success', 'failed'])
            ->exists();
    }

    /**
     * Check if transaction has already been processed
     * 
     * @param string $transactionId
     * @return bool
     */
    public static function isTransactionProcessed(string $transactionId): bool
    {
        return self::where('transaction_id', $transactionId)
            ->whereIn('status', ['success', 'failed'])
            ->exists();
    }

    /**
     * Check if webhook is currently being processed
     * 
     * @param string $orderId
     * @return bool
     */
    public static function isProcessing(string $orderId): bool
    {
        return self::where('order_id', $orderId)
            ->where('status', 'processing')
            ->exists();
    }

    /**
     * Get processing history for an order
     * 
     * @param string $orderId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getProcessingHistory(string $orderId)
    {
        return self::where('order_id', $orderId)
            ->orderBy('created_at', 'desc')
            ->get();
    }
}
