# HTTP Security Headers

This document describes the HTTP security headers implemented in the AbsensiQRPro API.

## Overview

All API responses include comprehensive security headers to protect against common web vulnerabilities.

## Headers Applied

### Core Security Headers

| Header | Value | Purpose |
|--------|-------|---------|
| `X-Content-Type-Options` | `nosniff` | Prevents MIME type sniffing attacks |
| `X-Frame-Options` | `DENY` | Prevents clickjacking by denying all framing |
| `X-XSS-Protection` | `1; mode=block` | Legacy XSS filter for older browsers |
| `Referrer-Policy` | `no-referrer` | Prevents referrer information leakage |
| `X-DNS-Prefetch-Control` | `off` | Prevents DNS prefetching (privacy) |
| `X-Download-Options` | `noopen` | Prevents automatic file execution in IE |
| `X-Permitted-Cross-Domain-Policies` | `none` | Prevents Adobe cross-domain requests |

### Permissions Policy

```
Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), magnetometer=(), gyroscope=(), accelerometer=()
```

All sensitive browser features are disabled by default:
- **camera()** - No camera access
- **microphone()** - No microphone access
- **geolocation()** - No location access
- **payment()** - No payment API access
- **usb()** - No USB device access
- **magnetometer()** - No magnetometer access
- **gyroscope()** - No gyroscope access
- **accelerometer()** - No accelerometer access

### Strict-Transport-Security (HSTS)

**Production only:**
```
Strict-Transport-Security: max-age=31536000; includeSubDomains; preload
```

- Forces HTTPS for 1 year (31536000 seconds)
- Applies to all subdomains
- Eligible for browser preload lists

> ⚠️ **Note**: HSTS is only applied in production environment to avoid issues with local HTTP development.

### Content-Security-Policy (CSP)

```
Content-Security-Policy:
  default-src 'self';
  script-src 'self' 'unsafe-inline' 'unsafe-eval';
  style-src 'self' 'unsafe-inline' https://fonts.googleapis.com;
  img-src 'self' data: https:;
  font-src 'self' data: https://fonts.gstatic.com;
  connect-src 'self' ws: wss: [dynamic];
  frame-ancestors 'none';
  base-uri 'self';
  form-action 'self';
  object-src 'none';
  upgrade-insecure-requests (production only)
```

### Request ID Tracking

Every request/response includes a unique tracking ID:
```
X-Request-ID: req_abc123...
```

This enables:
- Request correlation in logs
- Debugging distributed systems
- Error tracking and support

## Middleware Location

```
app/Http/Middleware/SecurityHeaders.php
```

## Registration

The middleware is registered globally for all API routes in:
```
bootstrap/app.php
```

```php
$middleware->api(append: [
    \App\Http\Middleware\CheckUserActive::class,
    \App\Http\Middleware\SecurityHeaders::class,
]);
```

## Verification

### Using cURL

```bash
curl -I https://your-api.com/api/health
```

Expected headers:
```
HTTP/2 200
x-content-type-options: nosniff
x-frame-options: DENY
x-xss-protection: 1; mode=block
referrer-policy: no-referrer
permissions-policy: camera=(), microphone=(), geolocation=(), ...
strict-transport-security: max-age=31536000; includeSubDomains; preload
content-security-policy: default-src 'self'; ...
x-request-id: req_65a1b2c3...
```

### Using Browser DevTools

1. Open Chrome DevTools (F12)
2. Go to **Network** tab
3. Make an API request
4. Click on the request
5. Check **Response Headers** section

### Using Security Scanners

- [Mozilla Observatory](https://observatory.mozilla.org/)
- [SecurityHeaders.com](https://securityheaders.com/)
- [SSL Labs](https://www.ssllabs.com/ssltest/)

## Environment-Specific Behavior

| Header | Local/Development | Production |
|--------|-------------------|------------|
| HSTS | ❌ Not applied | ✅ Applied |
| upgrade-insecure-requests | ❌ Not applied | ✅ Applied |
| connect-src localhost | ✅ Allowed | ❌ Not allowed |

## Customization

### Enabling HSTS in Development

Add to `.env`:
```
FORCE_HTTPS=true
```

### Modifying CSP

Edit `app/Http/Middleware/SecurityHeaders.php`:

```php
private function buildCSP(): string
{
    $directives = [
        // Add your custom directives here
    ];
    
    return implode('; ', $directives);
}
```

## Security Recommendations

### Production Checklist

1. ✅ Ensure HTTPS is enforced
2. ✅ Verify HSTS is active
3. ✅ Test with security scanners
4. ✅ Monitor CSP violations
5. ✅ Review Permissions-Policy regularly

### CSP Improvements (Future)

Replace `'unsafe-inline'` with nonces:

```php
// Generate nonce per request
$nonce = base64_encode(random_bytes(16));

// In CSP
"script-src 'self' 'nonce-{$nonce}'"

// In Blade templates
<script nonce="{{ $nonce }}">...</script>
```

### CSP Reporting

Add violation reporting:

```php
"report-uri /api/csp-report"
// or
"report-to csp-endpoint"
```

## References

- [OWASP Secure Headers Project](https://owasp.org/www-project-secure-headers/)
- [MDN HTTP Headers](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers)
- [Content Security Policy](https://developer.mozilla.org/en-US/docs/Web/HTTP/CSP)
- [Permissions Policy](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Permissions-Policy)

---

*Last updated: 2026-01-29*
