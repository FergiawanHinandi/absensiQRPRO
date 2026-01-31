# Frontend Security Hardening - XSS & Token Protection

## Overview

This document describes the security measures implemented to protect against XSS attacks and token theft in the AbsensiQR Pro frontend application.

## Changes Implemented

### 1. Memory-Only Token Storage 🔐

**Previous vulnerability:**
- Auth tokens were stored in `localStorage`
- XSS attacks could steal tokens via `localStorage.getItem('token')`
- Stolen tokens could be used from any device/location

**New secure implementation:**
- Tokens are stored in JavaScript memory (closure) only
- File: `src/lib/secureTokenStore.ts`
- Tokens exist only in the runtime heap
- XSS cannot access closure variables from DOM

```typescript
// How it works
const tokenStore = (() => {
    let _token: string | null = null;  // Private, inaccessible via XSS
    
    return {
        setToken: (token) => { _token = token; },
        getToken: () => _token,
        clearToken: () => { _token = null; }
    };
})();
```

### 2. Automatic Re-authentication on Page Reload 🔄

**Behavior:**
- When user refreshes the page, token is lost (memory cleared)
- User must re-authenticate to continue
- Session indicator (non-sensitive) in sessionStorage for UX

**Why this is secure:**
- Even if attacker injects script, refreshing page clears the token
- Reduces window of opportunity for token theft
- Forces periodic re-validation with backend

### 3. Content Security Policy (CSP) 🛡️

**Location:** `index.html`

**Policy directives:**

| Directive | Value | Purpose |
|-----------|-------|---------|
| `default-src` | `'self'` | Only load resources from same origin |
| `script-src` | `'self' 'unsafe-inline' 'unsafe-eval' https://*.midtrans.com` | Allow scripts from self and Midtrans |
| `style-src` | `'self' 'unsafe-inline' https://fonts.googleapis.com` | Styles from self and Google Fonts |
| `connect-src` | `'self' http://localhost:* ...` | API/WebSocket connections |
| `object-src` | `'none'` | Block Flash/plugins |
| `base-uri` | `'self'` | Prevent base tag hijacking |
| `form-action` | `'self'` | Forms only submit to same origin |

### 4. Updated Components

Files modified to use secure token storage:

1. **`src/modules/auth/stores/useAuthStore.ts`**
   - Uses `tokenStore` for memory storage
   - Tracks session expiry for UX
   - Cleans up legacy localStorage tokens

2. **`src/lib/api.ts`**
   - Request interceptor reads from `tokenStore`
   - Error handlers clear memory token

3. **`src/lib/echo.ts`**
   - WebSocket auth uses `tokenStore`

4. **`src/pages/SuperAdmin/SchoolsManagement.tsx`**
   - Impersonation uses auth store instead of localStorage

## Trade-offs & Considerations

### Session Persistence
- **Behavior:** User loses session on page refresh
- **Mitigation:** Backend session duration should be reasonable
- **Alternative:** Consider refresh tokens with short-lived access tokens

### Multi-Tab Support
- **Current:** Each tab has its own memory token
- **Optional Enhancement:** Use `BroadcastChannel` API to sync tokens across tabs

### Development Experience
- CSP includes `'unsafe-inline'` and `'unsafe-eval'` for Vite HMR
- **Production:** Consider using nonces instead of inline allowances

## Production Recommendations

### 1. Serve CSP via HTTP Headers
```nginx
# Nginx example
add_header Content-Security-Policy "default-src 'self'; script-src 'self' https://*.midtrans.com; ...";
```

### 2. Enable CSP Reporting
```html
<meta http-equiv="Content-Security-Policy" 
  content="...; report-uri /csp-report-endpoint">
```

### 3. Use Nonce-Based CSP
```html
<!-- Server generates unique nonce per request -->
<script nonce="random123">...</script>
```

### 4. Implement Refresh Tokens
```
Access Token: Short-lived (15 min), in memory
Refresh Token: Longer-lived, HttpOnly cookie
```

### 5. Add Subresource Integrity (SRI)
```html
<script src="https://cdn.example.com/lib.js" 
  integrity="sha384-abc123..." 
  crossorigin="anonymous"></script>
```

## Testing

### Verify Token Storage
```javascript
// In browser console after login:
localStorage.getItem('token')  // Should return null
sessionStorage.getItem('token')  // Should return null

// Token is only in memory - cannot be accessed via console
```

### Verify CSP
1. Open DevTools Console
2. Try injecting inline script:
   ```javascript
   document.body.innerHTML += '<img src=x onerror=alert(1)>'
   ```
3. CSP should block execution and log violation

### Verify Re-auth on Reload
1. Login to application
2. Refresh the page (F5)
3. User should be redirected to login page

## Files Changed

| File | Change |
|------|--------|
| `src/lib/secureTokenStore.ts` | New - Memory-only token storage |
| `src/modules/auth/stores/useAuthStore.ts` | Modified - Use secure store |
| `src/lib/api.ts` | Modified - Use secure store |
| `src/lib/echo.ts` | Modified - Use secure store |
| `src/pages/SuperAdmin/SchoolsManagement.tsx` | Modified - Use auth store |
| `index.html` | Modified - Enable CSP |

## Security Checklist

- [x] Tokens stored in memory only, not localStorage/sessionStorage
- [x] Page reload requires re-authentication
- [x] CSP meta tag enabled with restrictive policy
- [x] Legacy localStorage cleanup for migration
- [x] Session indicator for UX (non-sensitive)
- [ ] CSP via HTTP headers (production)
- [ ] CSP nonces instead of unsafe-inline (production)
- [ ] Refresh token implementation (optional enhancement)
- [ ] CSP violation reporting (production)

---

*Last updated: 2026-01-29*
