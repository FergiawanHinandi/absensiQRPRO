# Security Alerting System

## Overview

The `SecurityAlertService` provides real-time threshold-based alerting for high-risk security events. It uses Redis atomic counters to track velocity and prevent log flooding via deduplication execution.

---

## Alert Rules & Thresholds

| Event Type | Threshold | Window | Description |
|------------|-----------|--------|-------------|
| `invalid_signature` | **> 20** | **5 mins** | Signature mismatch storm. Possible API key leakage or brute force attack on signature verification. |
| `replay_attempt` | **> 5** | **1 min** | Rapid replay of old QR tokens. Distributed replay attack attempt. |
| `device_mismatch` | **> 1** | **1 min** | Immediate alert. High confidence device spoofing or unauthorized device usage. |

---

## Implementation Design

### Redis Counter Logic

1.  **Atomic Increment:** `Cache::increment('key')` ensures thread-safety even with concurrent requests.
2.  **Auto-Expiration:** TTL is set on the first write. Windows reset automatically.
3.  **Cooldown Lock:** `sec_alert_sent:{type}:{id}` prevents spamming logs (alert once per 5 minutes per identifier).

### Usage Example

```php
// In QR Signature Verification
if (!$isValidSignature) {
    app(SecurityAlertService::class)->trackEvent(
        'invalid_signature',
        $request->ip(), // Identifier (IP-based tracking)
        ['qr_token_sample' => substr($token, 0, 10)]
    );
    throw new InvalidSignatureException();
}
```

```php
// In Replay Protection
if ($isReplay) {
    app(SecurityAlertService::class)->trackEvent(
        'replay_attempt',
        $request->user()->id, // Identifier (User-based tracking)
        ['nonce' => $nonce]
    );
}
```

---

## Alert Output

### Log Payload (CRITICAL)

The system writes structured JSON logs to `security_json` channel:

```json
{
  "level": "CRITICAL",
  "message": "SECURITY ALERT: Threshold exceeded for invalid_signature",
  "context": {
    "event": "security.threshold_breach",
    "type": "invalid_signature",
    "identifier": "192.168.1.50",
    "count": 21,
    "limit": 20,
    "timestamp": "2026-01-31T12:50:00+07:00",
    "metadata": {
      "qr_token_sample": "eyJhbGciOi..."
    },
    "server_env": "production"
  }
}
```

### Actionable Response

When this alert triggers:
1.  **Block IP/User:** The identifier provided can be fed into a firewall or ban list.
2.  **Rotate Keys:** If `invalid_signature` spikes globally, consider rotating `QR_SECRET_KEY`.
3.  **Audit Logs:** Check access logs for the specific identifier.

---

## Extension: Notifications

To enable Email/Slack notifications, update the `dispatchExternalAlert` method in `SecurityAlertService`:

```php
private function dispatchExternalAlert(array $payload): void
{
    // Send to Admin Slack Channel
    Notification::route('slack', config('logging.channels.slack.url'))
        ->notify(new SecurityBreachNotification($payload));
}
```
