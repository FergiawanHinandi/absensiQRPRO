/**
 * Secure API Client with SSL Certificate Pinning
 * 
 * This module provides a secure fetch wrapper that:
 * 1. Implements SSL certificate pinning (MITM protection)
 * 2. Falls back gracefully when pinning is disabled (development)
 * 3. Provides clear error messages for certificate validation failures
 * 
 * Uses react-native-ssl-pinning for native certificate validation
 */

import { fetch as sslFetch } from 'react-native-ssl-pinning';
import { Platform } from 'react-native';
import Config from 'react-native-config';
import { storage } from '../utils/storage';
import { sslPinningConfig, getSSLPinningOptions, shouldPinURL } from '../config/sslPinning';

// Error types for specific handling
export enum SecureApiErrorType {
    SSL_PINNING_FAILED = 'SSL_PINNING_FAILED',
    CERTIFICATE_ERROR = 'CERTIFICATE_ERROR',
    NETWORK_ERROR = 'NETWORK_ERROR',
    TIMEOUT = 'TIMEOUT',
    UNAUTHORIZED = 'UNAUTHORIZED',
    SERVER_ERROR = 'SERVER_ERROR',
    UNKNOWN = 'UNKNOWN',
}

export interface SecureApiError {
    type: SecureApiErrorType;
    message: string;
    userMessage: string;
    originalError?: unknown;
}

/**
 * Get the API Base URL
 */
const getApiBaseUrl = (): string => {
    if (Config.API_BASE_URL) {
        return Config.API_BASE_URL;
    }

    console.warn('⚠️  API_BASE_URL not configured, using development fallback');

    if (Platform.OS === 'android') {
        return 'http://10.0.2.2:8000/api/v1';
    }

    return 'http://localhost:8000/api/v1';
};

export const API_BASE_URL = getApiBaseUrl();

/**
 * SSL Pinning Error Messages (Localized)
 */
const SSL_ERROR_MESSAGES = {
    en: {
        pinningFailed: 'Secure connection failed. Please check your internet connection and try again.',
        certificateError: 'Cannot verify server security. Please update your app or contact support.',
        networkError: 'Network connection failed. Please check your internet connection.',
        timeout: 'Request timed out. Please try again.',
    },
    id: {
        pinningFailed: 'Koneksi aman gagal. Silakan periksa koneksi internet Anda dan coba lagi.',
        certificateError: 'Tidak dapat memverifikasi keamanan server. Silakan perbarui aplikasi atau hubungi support.',
        networkError: 'Koneksi jaringan gagal. Silakan periksa koneksi internet Anda.',
        timeout: 'Waktu permintaan habis. Silakan coba lagi.',
    },
};

// Use Indonesian by default (matching the app's primary language)
const lang = 'id';

/**
 * Create a SecureApiError from various error types
 */
const createSecureApiError = (error: unknown): SecureApiError => {
    const errorStr = String(error).toLowerCase();

    // SSL Pinning specific errors
    if (
        errorStr.includes('ssl') ||
        errorStr.includes('certificate') ||
        errorStr.includes('pin') ||
        errorStr.includes('trust')
    ) {
        return {
            type: SecureApiErrorType.SSL_PINNING_FAILED,
            message: 'SSL certificate pinning validation failed',
            userMessage: SSL_ERROR_MESSAGES[lang].pinningFailed,
            originalError: error,
        };
    }

    // Network errors
    if (
        errorStr.includes('network') ||
        errorStr.includes('connection') ||
        errorStr.includes('unreachable')
    ) {
        return {
            type: SecureApiErrorType.NETWORK_ERROR,
            message: 'Network connection error',
            userMessage: SSL_ERROR_MESSAGES[lang].networkError,
            originalError: error,
        };
    }

    // Timeout errors
    if (errorStr.includes('timeout')) {
        return {
            type: SecureApiErrorType.TIMEOUT,
            message: 'Request timeout',
            userMessage: SSL_ERROR_MESSAGES[lang].timeout,
            originalError: error,
        };
    }

    // Generic error
    return {
        type: SecureApiErrorType.UNKNOWN,
        message: String(error),
        userMessage: SSL_ERROR_MESSAGES[lang].networkError,
        originalError: error,
    };
};

/**
 * Request options for the secure fetch
 */
export interface SecureRequestOptions {
    method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
    headers?: Record<string, string>;
    body?: unknown;
    timeout?: number;
    skipAuth?: boolean;
}

/**
 * Response from secure fetch
 */
export interface SecureResponse<T = unknown> {
    ok: boolean;
    status: number;
    data: T;
    headers?: Record<string, string>;
}

/**
 * Perform a secure fetch request with SSL pinning
 * 
 * @param endpoint - API endpoint (relative to base URL)
 * @param options - Request options
 * @returns Promise with response data
 * @throws SecureApiError on failure
 */
export const secureFetch = async <T = unknown>(
    endpoint: string,
    options: SecureRequestOptions = {}
): Promise<SecureResponse<T>> => {
    const {
        method = 'GET',
        headers = {},
        body,
        timeout = parseInt(Config.API_TIMEOUT || '30000'),
        skipAuth = false,
    } = options;

    // Build full URL
    const url = endpoint.startsWith('http') ? endpoint : `${API_BASE_URL}${endpoint}`;

    // Build headers
    const requestHeaders: Record<string, string> = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        ...headers,
    };

    // Add auth token if available and not skipped
    if (!skipAuth) {
        try {
            const token = await storage.getToken();
            if (token) {
                requestHeaders['Authorization'] = `Bearer ${token}`;
            }
        } catch (e) {
            console.warn('Failed to get auth token:', e);
        }
    }

    // Determine if we should use SSL pinning
    const usePinning = sslPinningConfig.enabled && shouldPinURL(url);

    if (__DEV__) {
        console.log(`🔒 Secure fetch: ${method} ${endpoint}`);
        console.log(`   SSL Pinning: ${usePinning ? 'ENABLED' : 'DISABLED'}`);
    }

    try {
        if (usePinning) {
            // Use SSL pinned fetch
            const sslOptions = {
                method,
                headers: requestHeaders,
                body: body ? JSON.stringify(body) : undefined,
                timeoutInterval: timeout,
                sslPinning: {
                    certs: ['server_cert'], // Certificate filename in Android res/raw and iOS bundle
                },
                // For public key pinning (preferred)
                pkPinning: true,
                disableAllSecurity: false,
            };

            const response = await sslFetch(url, sslOptions);

            // Parse response
            let data: T;
            try {
                data = typeof response.bodyString === 'string'
                    ? JSON.parse(response.bodyString)
                    : response.bodyString;
            } catch {
                data = response.bodyString as T;
            }

            // Handle HTTP errors
            if (response.status >= 400) {
                if (response.status === 401) {
                    // Handle unauthorized - clear auth data
                    await storage.removeToken();
                    await storage.removeUser();
                    throw {
                        type: SecureApiErrorType.UNAUTHORIZED,
                        message: 'Unauthorized',
                        userMessage: 'Sesi Anda telah berakhir. Silakan login kembali.',
                    };
                }

                if (response.status === 429) {
                    // Rate limit exceeded - structured error response
                    const retryAfter = parseInt(response.headers?.['retry-after'] || '60');
                    throw {
                        type: 'RATE_LIMIT',
                        retryAfter: retryAfter,
                        message: data?.message || 'Terlalu banyak permintaan. Coba lagi nanti.',
                        userMessage: 'Terlalu banyak permintaan. Coba lagi nanti.',
                    };
                }

                throw {
                    type: SecureApiErrorType.SERVER_ERROR,
                    message: `Server error: ${response.status}`,
                    userMessage: 'Terjadi kesalahan server. Silakan coba lagi.',
                    originalError: data,
                };
            }

            return {
                ok: response.status >= 200 && response.status < 300,
                status: response.status,
                data,
                headers: response.headers,
            };
        } else {
            // Use regular fetch (development or pinning disabled)
            const fetchOptions: RequestInit = {
                method,
                headers: requestHeaders,
                body: body ? JSON.stringify(body) : undefined,
            };

            // Create timeout controller
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), timeout);
            fetchOptions.signal = controller.signal;

            try {
                const response = await fetch(url, fetchOptions);
                clearTimeout(timeoutId);

                let data: T;
                const contentType = response.headers.get('content-type');
                if (contentType?.includes('application/json')) {
                    data = await response.json();
                } else {
                    data = await response.text() as T;
                }

                // Handle HTTP errors
                if (response.status >= 400) {
                    if (response.status === 401) {
                        await storage.removeToken();
                        await storage.removeUser();
                        throw {
                            type: SecureApiErrorType.UNAUTHORIZED,
                            message: 'Unauthorized',
                            userMessage: 'Sesi Anda telah berakhir. Silakan login kembali.',
                        };
                    }

                    if (response.status === 429) {
                        // Rate limit exceeded - structured error response
                        const retryAfter = parseInt(response.headers.get('retry-after') || '60');
                        throw {
                            type: 'RATE_LIMIT',
                            retryAfter: retryAfter,
                            message: data?.message || 'Terlalu banyak permintaan. Coba lagi nanti.',
                            userMessage: 'Terlalu banyak permintaan. Coba lagi nanti.',
                        };
                    }

                    throw {
                        type: SecureApiErrorType.SERVER_ERROR,
                        message: `Server error: ${response.status}`,
                        userMessage: 'Terjadi kesalahan server. Silakan coba lagi.',
                        originalError: data,
                    };
                }

                return {
                    ok: response.ok,
                    status: response.status,
                    data,
                };
            } finally {
                clearTimeout(timeoutId);
            }
        }
    } catch (error) {
        // If already a SecureApiError, rethrow
        if ((error as SecureApiError).type) {
            throw error;
        }

        // Convert to SecureApiError
        throw createSecureApiError(error);
    }
};

/**
 * Convenience methods for common HTTP verbs
 */
export const secureApi = {
    get: <T = unknown>(endpoint: string, options: Omit<SecureRequestOptions, 'method' | 'body'> = {}) =>
        secureFetch<T>(endpoint, { ...options, method: 'GET' }),

    post: <T = unknown>(endpoint: string, body?: unknown, options: Omit<SecureRequestOptions, 'method' | 'body'> = {}) =>
        secureFetch<T>(endpoint, { ...options, method: 'POST', body }),

    put: <T = unknown>(endpoint: string, body?: unknown, options: Omit<SecureRequestOptions, 'method' | 'body'> = {}) =>
        secureFetch<T>(endpoint, { ...options, method: 'PUT', body }),

    patch: <T = unknown>(endpoint: string, body?: unknown, options: Omit<SecureRequestOptions, 'method' | 'body'> = {}) =>
        secureFetch<T>(endpoint, { ...options, method: 'PATCH', body }),

    delete: <T = unknown>(endpoint: string, options: Omit<SecureRequestOptions, 'method' | 'body'> = {}) =>
        secureFetch<T>(endpoint, { ...options, method: 'DELETE' }),
};

export default secureApi;
