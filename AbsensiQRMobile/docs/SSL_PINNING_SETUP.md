# SSL Certificate Pinning Setup Guide

This guide explains how to configure SSL certificate pinning for the AbsensiQR Mobile app to protect against Man-in-the-Middle (MITM) attacks.

## Overview

SSL certificate pinning ensures that the app only trusts connections to servers with specific, pre-defined certificates. This prevents attackers from intercepting traffic even if they have a valid SSL certificate from a trusted CA.

## Installation

### Step 1: Install the dependency

```bash
cd AbsensiQRMobile
npm install react-native-ssl-pinning --save
```

### Step 2: Link native modules

For React Native 0.60+, auto-linking should handle this. If not:

```bash
# iOS
cd ios && pod install && cd ..

# Android - no additional steps needed
```

## Certificate Extraction

### Method 1: Using OpenSSL (Recommended)

Extract the SHA256 public key hash from your server:

```bash
# Replace YOUR_DOMAIN with your actual API domain
openssl s_client -connect YOUR_DOMAIN:443 -servername YOUR_DOMAIN 2>/dev/null | \
  openssl x509 -pubkey -noout | \
  openssl pkey -pubin -outform der | \
  openssl dgst -sha256 -binary | \
  openssl enc -base64
```

Example output:
```
47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU=
```

### Method 2: Download and extract from certificate file

```bash
# Download the certificate
openssl s_client -connect YOUR_DOMAIN:443 -servername YOUR_DOMAIN 2>/dev/null | \
  openssl x509 -outform DER > server_cert.der

# Extract hash
openssl x509 -inform DER -in server_cert.der -pubkey -noout | \
  openssl pkey -pubin -outform der | \
  openssl dgst -sha256 -binary | \
  openssl enc -base64
```

### Method 3: Using SSL Labs

1. Go to https://www.ssllabs.com/ssltest/
2. Enter your domain
3. Look for "Pin SHA256" in the certificate details

## Platform Configuration

### Android Setup

1. **Create the raw resources directory** (if it doesn't exist):
   ```
   android/app/src/main/res/raw/
   ```

2. **Add your certificate file**:
   - Download your server's certificate as DER format
   - Name it `server_cert.cer` or `server_cert.der`
   - Place it in `android/app/src/main/res/raw/`

3. **For public key pinning**, add to `android/app/src/main/res/xml/network_security_config.xml`:

```xml
<?xml version="1.0" encoding="utf-8"?>
<network-security-config>
    <domain-config cleartextTrafficPermitted="false">
        <domain includeSubdomains="true">api.absensi.example.com</domain>
        <pin-set expiration="2025-12-31">
            <!-- Primary certificate hash -->
            <pin digest="SHA-256">YOUR_PRIMARY_HASH=</pin>
            <!-- Backup certificate hash -->
            <pin digest="SHA-256">YOUR_BACKUP_HASH=</pin>
        </pin-set>
    </domain-config>
    
    <!-- Development: Allow localhost -->
    <domain-config cleartextTrafficPermitted="true">
        <domain includeSubdomains="true">10.0.2.2</domain>
        <domain includeSubdomains="true">localhost</domain>
    </domain-config>
</network-security-config>
```

4. **Reference in AndroidManifest.xml**:
```xml
<application
    android:networkSecurityConfig="@xml/network_security_config"
    ... >
```

### iOS Setup

1. **Add certificate to Xcode project**:
   - Open `ios/AbsensiQRMobile.xcworkspace` in Xcode
   - Right-click on the project → Add Files
   - Select your `server_cert.cer` file
   - Ensure "Copy items if needed" is checked
   - Add to target: AbsensiQRMobile

2. **Update Info.plist** (if using localhost in development):
```xml
<key>NSAppTransportSecurity</key>
<dict>
    <key>NSAllowsArbitraryLoads</key>
    <false/>
    <key>NSExceptionDomains</key>
    <dict>
        <key>localhost</key>
        <dict>
            <key>NSExceptionAllowsInsecureHTTPLoads</key>
            <true/>
        </dict>
    </dict>
</dict>
```

## Environment Configuration

Update your `.env` file:

```bash
# Enable SSL pinning (set to 'true' for production)
ENABLE_SSL_PINNING=true

# Your API hostname (without https://)
API_HOSTNAME=api.absensi.example.com

# Certificate hashes (SHA256, Base64)
SSL_PIN_HASH_PRIMARY=sha256/YOUR_HASH_FROM_STEP_1=
SSL_PIN_HASH_BACKUP=sha256/YOUR_BACKUP_HASH=
```

## Usage

### Using the Secure API Client

```typescript
import { secureApi, SecureApiErrorType } from './api/secureClient';
import { useSSLErrorHandler } from './hooks/useSSLErrorHandler';

// In your component
const { handleError, isSecurityError } = useSSLErrorHandler({
    onAuthError: () => navigation.navigate('Login'),
    onSecurityError: () => console.log('Security error occurred'),
});

const fetchData = async () => {
    try {
        const response = await secureApi.get('/attendance/today');
        console.log('Data:', response.data);
    } catch (error) {
        handleError(error);
    }
};
```

### Example: Login with SSL Pinning

```typescript
import { secureApi } from './api/secureClient';

const login = async (username: string, password: string) => {
    try {
        const response = await secureApi.post('/auth/login', {
            username,
            password,
        }, { skipAuth: true }); // Skip auth token for login
        
        return response.data;
    } catch (error) {
        if (error.type === SecureApiErrorType.SSL_PINNING_FAILED) {
            // Handle security error specifically
            Alert.alert(
                'Koneksi Tidak Aman',
                'Tidak dapat memverifikasi keamanan server. Pastikan Anda tidak menggunakan WiFi publik.'
            );
        }
        throw error;
    }
};
```

## Testing

### Verify Pinning Works

1. **Using Charles Proxy / mitmproxy**:
   - Configure your device to use the proxy
   - Install the proxy's CA certificate on your device
   - Make an API request
   - The request should **fail** with an SSL error (pinning working correctly)

2. **Using curl to test hashes**:
   ```bash
   curl -v --pinnedpubkey "sha256//YOUR_HASH=" https://api.yourdomain.com/health
   ```

### Development Testing

SSL pinning is automatically disabled when `__DEV__` is true or `ENABLE_SSL_PINNING` is not set to `'true'`.

## Certificate Rotation Strategy

⚠️ **CRITICAL**: Improper certificate rotation can lock users out of your app!

### Before Certificate Renewal

1. **Generate hash for new certificate** before it's deployed
2. **Add the new hash** to `SSL_PIN_HASH_BACKUP` in your app
3. **Release an app update** with both old and new hashes
4. **Wait for the update to propagate** to most users (check analytics)
5. **Then deploy the new certificate** on your server

### Recommended Timeline

```
Day 0:    Generate new certificate hash
Day 1:    Release app update with new hash as backup
Day 7-14: Wait for update propagation (monitor adoption rate)
Day 14:   Deploy new certificate on server
Day 30:   Remove old hash from next app update
```

## Troubleshooting

### Common Issues

#### 1. "SSL Pinning Failed" on Valid Server

**Causes:**
- Wrong certificate hash
- Certificate chain issue
- Using intermediate CA instead of leaf certificate

**Solution:**
- Verify hash matches your server certificate
- Try extracting hash from the leaf certificate, not CA

#### 2. App Works in Development but Not Production

**Causes:**
- SSL pinning enabled in production
- Environment variable not set correctly

**Solution:**
- Verify `ENABLE_SSL_PINNING=true` in production `.env`
- Check the certificate is included in the app bundle

#### 3. Certificate Renewal Broke the App

**Causes:**
- New certificate hash not included in app

**Solution:**
- Emergency: Deploy app update with new hash
- Prevention: Always include backup hash before renewal

#### 4. iOS Build Error: Certificate Not Found

**Causes:**
- Certificate not added to Xcode target
- Wrong file format

**Solution:**
- Verify certificate is in DER format (.cer or .der)
- Check it's added to the correct target in Xcode

### Debug Mode

Enable debug logging:

```typescript
// In development
if (__DEV__) {
    console.log('SSL Pinning enabled:', sslPinningConfig.enabled);
    console.log('API Hostname:', sslPinningConfig.hostname);
}
```

## Security Considerations

1. **Never disable pinning in production** - It defeats the purpose entirely
2. **Don't hardcode sensitive data** - Use environment variables
3. **Monitor for attacks** - Log SSL failures (server-side) for security monitoring
4. **Have a backup plan** - Always include backup certificate hashes
5. **Test thoroughly** - Test on both Android and iOS before release

## Files Reference

| File | Purpose |
|------|---------|
| `src/config/sslPinning.ts` | SSL pinning configuration |
| `src/api/secureClient.ts` | Secure fetch wrapper with pinning |
| `src/hooks/useSSLErrorHandler.ts` | Error handling hook |
| `src/types/react-native-ssl-pinning.d.ts` | TypeScript declarations |
| `android/app/src/main/res/raw/server_cert.cer` | Android certificate |
| `ios/server_cert.cer` | iOS certificate (added via Xcode) |

---

*Last updated: 2026-01-29*
