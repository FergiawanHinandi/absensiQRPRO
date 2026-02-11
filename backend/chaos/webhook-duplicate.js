import http from 'k6/http';
import { check } from 'k6';
import { Rate } from 'k6/metrics';

// Custom metrics
const idempotencyRate = new Rate('idempotency_success');
const duplicateCreationRate = new Rate('duplicate_creation');

// Test configuration
export const options = {
    vus: 50,              // 50 concurrent users
    iterations: 50,       // Each sends the same webhook
    duration: '30s',
};

// Test data
const BASE_URL = __ENV.BASE_URL || 'http://localhost';

// Same webhook payload for all requests
const webhookPayload = JSON.stringify({
    order_id: 'ORDER-CHAOS-TEST-001',
    transaction_status: 'settlement',
    gross_amount: '100000',
    payment_type: 'credit_card',
    transaction_time: '2026-02-09 23:00:00',
    signature_key: 'test-signature',
});

export default function () {
    const params = {
        headers: {
            'Content-Type': 'application/json',
        },
    };

    const response = http.post(
        `${BASE_URL}/api/webhooks/midtrans`,
        webhookPayload,
        params
    );

    // Check response
    const success = check(response, {
        'status is 200': (r) => r.status === 200,
        'response has message': (r) => r.json('message') !== undefined,
    });

    // Check if idempotency worked
    const body = response.json();
    const isIdempotent = body.message && (
        body.message.includes('already processed') ||
        body.message.includes('duplicate')
    );

    if (isIdempotent) {
        idempotencyRate.add(1);
    } else {
        idempotencyRate.add(0);
    }

    // Check if new record was created (should only happen once)
    const wasCreated = body.message && body.message.includes('success');
    if (wasCreated) {
        duplicateCreationRate.add(1);
    } else {
        duplicateCreationRate.add(0);
    }
}

export function teardown() {
    console.log('Webhook duplicate test completed');
    console.log('Expected: 1 creation, 49 idempotent rejections');
}
