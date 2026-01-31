/**
 * SSL Certificate Pinning Configuration
 * 
 * This module provides certificate pinning to protect against MITM attacks.
 * The app will only trust connections to servers with matching certificates.
 * 
 * IMPORTANT: Update the certificate hashes when:
 * - Server certificate is renewed
 * - Switching to a different SSL provider
 * 
 * How to get SHA256 hash of your server certificate:
 * 
 * Method 1 - Using OpenSSL:
 * openssl s_client -connect your-api-domain.com:443 -servername your-api-domain.com 2>/dev/null | openssl x509 -pubkey -noout | openssl pkey -pubin -outform der | openssl dgst -sha256 -binary | openssl enc -base64
 * 
 * Method 2 - Using ssl-pinning-generator online tool
 * 
 * Method 3 - Using cURL (shows Subject Public Key Info):
 * curl -v https://your-api-domain.com 2>&1 | grep -A 2 "SSL certificate"
 */

import { Platform } from 'react-native';
import Config from 'react-native-config';

export interface SSLPinConfig {
    hostname: string;
    publicKeyHashes: string[];
    includeSubdomains?: boolean;
    enabled: boolean;
}

/**
 * SSL Pinning configuration for the application
 * 
 * PRODUCTION: Replace these hashes with your actual server certificate hashes
 * 
 * You can include multiple hashes for:
 * - Current certificate
 * - Backup certificate (for certificate rotation)
 * - Intermediate CA certificates
 */
export const sslPinningConfig: SSLPinConfig = {
    // Production API hostname (without https://)
    hostname: Config.API_HOSTNAME || 'api.absensi.example.com',

    // SHA256 public key hashes (Base64 encoded)
    // IMPORTANT: Replace with your actual certificate hashes!
    publicKeyHashes: [
        // Primary certificate hash
        Config.SSL_PIN_HASH_PRIMARY || 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        // Backup certificate hash (for certificate rotation)
        Config.SSL_PIN_HASH_BACKUP || 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=',
    ],

    // Include subdomains in pinning
    includeSubdomains: true,

    // Enable/disable pinning (disable for development)
    enabled: !__DEV__ && Config.ENABLE_SSL_PINNING === 'true',
};

/**
 * Get SSL pinning options formatted for react-native-ssl-pinning
 */
export const getSSLPinningOptions = () => {
    if (!sslPinningConfig.enabled) {
        return null;
    }

    return {
        certs: sslPinningConfig.publicKeyHashes.map(hash => hash),
        // Additional options
        disableAllSecurity: false,
        trustSelfSignedCertificates: false,
    };
};

/**
 * Check if a URL should have SSL pinning applied
 */
export const shouldPinURL = (url: string): boolean => {
    if (!sslPinningConfig.enabled) {
        return false;
    }

    try {
        const urlObj = new URL(url);
        const hostname = urlObj.hostname;

        if (hostname === sslPinningConfig.hostname) {
            return true;
        }

        if (sslPinningConfig.includeSubdomains && hostname.endsWith(`.${sslPinningConfig.hostname}`)) {
            return true;
        }

        return false;
    } catch {
        return false;
    }
};

/**
 * Instructions for extracting certificate hashes
 */
export const CERTIFICATE_EXTRACTION_GUIDE = `
================================================================================
SSL CERTIFICATE PINNING SETUP GUIDE
================================================================================

Step 1: Extract your server's public key hash
-----------------------------------------------

Option A - Using OpenSSL (recommended):

  openssl s_client -connect YOUR_DOMAIN:443 -servername YOUR_DOMAIN 2>/dev/null | \\
    openssl x509 -pubkey -noout | \\
    openssl pkey -pubin -outform der | \\
    openssl dgst -sha256 -binary | \\
    openssl enc -base64

Option B - Using curl with verbose output:

  curl -v --silent https://YOUR_DOMAIN 2>&1 | grep "public key"

Option C - Online tools:
  - https://www.ssllabs.com/ssltest/
  - https://ssl-pinning-generator.vercel.app/


Step 2: Add hashes to .env file
-----------------------------------------------

  SSL_PIN_HASH_PRIMARY=sha256/YOUR_HASH_HERE=
  SSL_PIN_HASH_BACKUP=sha256/BACKUP_HASH_HERE=
  ENABLE_SSL_PINNING=true
  API_HOSTNAME=api.yourdomain.com


Step 3: Certificate Rotation Strategy
-----------------------------------------------

IMPORTANT: Certificate pinning can lock users out if not managed properly!

1. Always include multiple hashes:
   - Current production certificate
   - Next certificate (before rotation)
   - Backup/intermediate CA certificate

2. Before certificate renewal:
   - Generate hash for new certificate
   - Add new hash via app update
   - Wait for update to propagate to users
   - Then rotate the certificate

3. Monitor certificate expiry dates and plan updates accordingly


Step 4: Testing
-----------------------------------------------

1. Enable pinning in staging environment first
2. Use a proxy tool (Charles, mitmproxy) to verify pinning works
3. Verify app rejects connections through the proxy
4. Test fallback error messages

================================================================================
`;
