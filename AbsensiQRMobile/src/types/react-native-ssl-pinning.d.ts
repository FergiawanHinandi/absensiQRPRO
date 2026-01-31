/**
 * Type declarations for react-native-ssl-pinning
 * 
 * This library provides SSL certificate pinning for React Native apps
 * to prevent MITM attacks.
 */

declare module 'react-native-ssl-pinning' {
    export interface SSLPinningOptions {
        /**
         * Certificate file names (without extension) located in:
         * - Android: android/app/src/main/res/raw/
         * - iOS: Added to Xcode project
         */
        certs?: string[];

        /**
         * Use public key pinning instead of certificate pinning
         * Recommended for better flexibility during certificate rotation
         */
        pkPinning?: boolean;

        /**
         * Disable all security checks (NEVER use in production!)
         */
        disableAllSecurity?: boolean;

        /**
         * Trust self-signed certificates (for development only)
         */
        trustSelfSignedCertificates?: boolean;
    }

    export interface FetchOptions {
        method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE' | 'HEAD';
        headers?: Record<string, string>;
        body?: string;
        timeoutInterval?: number;
        sslPinning?: SSLPinningOptions;
        pkPinning?: boolean;
        disableAllSecurity?: boolean;
    }

    export interface FetchResponse {
        status: number;
        headers: Record<string, string>;
        bodyString: string;
        url: string;
    }

    /**
     * Perform a fetch request with SSL pinning
     */
    export function fetch(url: string, options?: FetchOptions): Promise<FetchResponse>;

    /**
     * Remove all cached cookies
     */
    export function removeCookieByName(name: string): Promise<void>;

    /**
     * Get all cookies
     */
    export function getCookies(domain: string): Promise<Record<string, string>>;
}
