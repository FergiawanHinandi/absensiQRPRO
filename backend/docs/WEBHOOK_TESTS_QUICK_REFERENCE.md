# Webhook Tests - Quick Reference Guide

## Running the Tests

### Quick Run (PowerShell)
```powershell
cd backend
.\verify-webhook-tests.ps1
```

### Manual Run
```bash
cd backend
php vendor/phpunit/phpunit/phpunit --filter=WebhookConcurrencyTest
```

### With Detailed Output
```bash
cd backend
php vendor/phpunit/phpunit/phpunit --filter=WebhookConcurrencyTest --testdox
```

---

## Test List (12 Tests)

### Lock Management
1. `webhook_acquires_lock_with_300_second_timeout`
2. `webhook_returns_429_when_lock_timeout_exceeded`
3. `webhook_releases_lock_on_exception`
4. `webhook_lock_prevents_race_condition_on_payment_update`

### Processing State
5. `webhook_marks_as_processing_before_execution`
6. `webhook_processing_status_prevents_duplicate_execution`
7. `webhook_updates_processing_to_success_after_completion`
8. `webhook_records_failed_status_on_processing_error`

### Idempotency
9. `webhook_prevents_double_subscription_processing`
10. `webhook_handles_transaction_id_idempotency`
11. `concurrent_webhooks_return_processing_status`

### History & Audit
12. `webhook_processing_history_tracks_all_attempts`

---

## Key Files

### Test File
- `tests/Feature/WebhookConcurrencyTest.php`

### Implementation Files
- `app/Models/ProcessedWebhook.php`
- `app/Http/Controllers/Api/V1/WebhookController.php`
- `app/Listeners/SendMonitoringAlert.php`

### Migrations
- `database/migrations/2026_02_09_141000_create_processed_webhooks_table.php`
- `database/migrations/2026_02_11_080000_add_package_fields_to_schools_table.php`

---

## ProcessedWebhook Model API

```php
// Mark as processing (initial state)
ProcessedWebhook::markAsProcessing([
    'order_id' => $orderId,
    'transaction_id' => $transactionId,
    'payload' => $data,
]);

// Mark as processed (final state)
ProcessedWebhook::markAsProcessed([
    'order_id' => $orderId,
    'transaction_id' => $transactionId,
    'status' => 'success', // or 'failed'
    'payload' => $data,
]);

// Check if already processed
if (ProcessedWebhook::isAlreadyProcessed($orderId)) {
    // Return idempotent response
}

// Check if currently processing
if (ProcessedWebhook::isProcessing($orderId)) {
    // Return 429 or processing flag
}

// Get processing history
$history = ProcessedWebhook::getProcessingHistory($orderId);
```

---

## WebhookController Flow

```php
1. Acquire Redis lock (300s timeout)
   ↓
2. Check isAlreadyProcessed()
   ↓
3. Check isTransactionProcessed()
   ↓
4. Check isProcessing()
   ↓
5. markAsProcessing()
   ↓
6. Process webhook in DB transaction
   ↓
7. markAsProcessed()
   ↓
8. Release lock (in finally block)
```

---

## Expected Test Results

```
✓ webhook acquires lock with 300 second timeout
✓ webhook marks as processing before execution
✓ concurrent webhooks return processing status
✓ webhook returns 429 when lock timeout exceeded
✓ webhook releases lock on exception
✓ webhook prevents double subscription processing
✓ webhook handles transaction id idempotency
✓ webhook processing status prevents duplicate execution
✓ webhook updates processing to success after completion
✓ webhook records failed status on processing error
✓ webhook lock prevents race condition on payment update
✓ webhook processing history tracks all attempts

Tests: 12 passed
```

---

## Troubleshooting

### Test Failures

**Lock not released:**
- Check Redis connection
- Verify Cache facade is working
- Ensure finally block executes

**Idempotency not working:**
- Check unique constraints on processed_webhooks table
- Verify order_id and transaction_id are set correctly
- Run migrations: `php artisan migrate`

**State transitions failing:**
- Check ProcessedWebhook model methods
- Verify status values ('processing', 'success', 'failed')
- Check database schema

### Database Issues

**Missing columns:**
```bash
php artisan migrate
```

**Duplicate column errors:**
- Migration includes Schema::hasColumn() checks
- Safe to run multiple times

**Table not found:**
```bash
php artisan migrate:fresh --seed
```

---

## Integration Testing

### Test Webhook Endpoint
```bash
curl -X POST http://localhost:8000/api/v1/webhooks/payment \
  -H "Content-Type: application/json" \
  -d '{
    "order_id": "TEST_ORDER_001",
    "transaction_id": "TEST_TXN_001",
    "status": "settlement"
  }'
```

### Check Processing Status
```php
$webhook = ProcessedWebhook::where('order_id', 'TEST_ORDER_001')->first();
echo $webhook->status; // processing, success, or failed
```

### View Processing History
```php
$history = ProcessedWebhook::getProcessingHistory('TEST_ORDER_001');
foreach ($history as $record) {
    echo "{$record->status} at {$record->created_at}\n";
}
```

---

## Performance Considerations

### Lock Timeout
- Default: 300 seconds (5 minutes)
- Adjust in WebhookController if needed
- Consider webhook processing time

### Database Queries
- Indexed on order_id and transaction_id
- Unique constraints prevent duplicates
- Efficient lookups for idempotency checks

### Redis Usage
- Lock keys: `webhook_lock:{order_id}`
- Automatic expiration after timeout
- Cleanup in finally block

---

## Security Notes

### Payload Sanitization
- Sensitive data redacted in logs
- Signature validation required (production)
- IP address logged for audit

### Idempotency Protection
- Prevents replay attacks
- Unique constraints on order_id and transaction_id
- Processing status prevents concurrent execution

### Error Handling
- Exceptions logged with context
- Failed webhooks marked in database
- Lock always released (finally block)

---

## Monitoring

### Key Metrics
- Lock acquisition time
- Processing duration
- Idempotency hit rate
- Failed webhook count

### Alerts
- Lock timeout exceeded
- High failure rate
- Duplicate webhook attempts

### Logs
- Webhook received (sanitized payload)
- Lock acquisition/release
- Processing status changes
- Errors and exceptions

---

## Documentation

- **Detailed Report**: `backend/docs/TASK_8.3_WEBHOOK_TESTS_COMPLETION.md`
- **Summary**: `TASK_8.3_COMPLETION_SUMMARY.md`
- **This Guide**: `backend/docs/WEBHOOK_TESTS_QUICK_REFERENCE.md`

---

**Last Updated**: February 11, 2026  
**Task Status**: ✅ Completed
