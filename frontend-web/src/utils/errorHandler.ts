/**
 * Error Handler Utility
 * CRITICAL: All error messages MUST come from API responses
 * Frontend should NEVER create business error messages
 *
 * RULES:
 * 1. Always use API's message field first
 * 2. Only use generic fallbacks for network/technical errors
 * 3. Never guess what went wrong - let the server tell us
 */

import type { AxiosError } from 'axios';

// API Error Response Interface (matches backend)
// Backend may return either flat or nested structure:
// Flat:   { success: false, message: "...", errors: {...} }
// Nested: { error: { code: "...", message: "...", details: { errors: {...} } } }
interface ApiErrorResponse {
    success?: false;
    message?: string;
    error?: string | { code?: string; message?: string; details?: { errors?: Record<string, string[]> } };
    errors?: Record<string, string[]>;
    details?: {
        reason?: string;
        action?: string;
        contact?: string;
    };
}

// Type guard for API error response
function isApiErrorResponse(data: unknown): data is ApiErrorResponse {
    return (
        typeof data === 'object' &&
        data !== null &&
        ('message' in data || 'error' in data || 'errors' in data)
    );
}

// Generic fallbacks ONLY for non-API errors (network, timeout, etc.)
const TECHNICAL_FALLBACKS: Record<number, string> = {
    0: 'Tidak dapat terhubung ke server. Periksa koneksi internet Anda.',
    401: 'Sesi Anda telah berakhir. Silakan login kembali.',
    403: 'Anda tidak memiliki akses untuk melakukan ini.',
    404: 'Data tidak ditemukan.',
    408: 'Permintaan timeout. Silakan coba lagi.',
    429: 'Terlalu banyak permintaan. Mohon tunggu sebentar.',
    500: 'Server sedang sibuk. Silakan coba beberapa saat lagi.',
    502: 'Server tidak dapat dijangkau. Silakan coba lagi.',
    503: 'Layanan sedang dalam pemeliharaan.',
};

/**
 * Extract error message from Axios error
 *
 * Priority:
 * 1. API response.data.message (backend's domain error)
 * 2. API response.data.error (alternative field)
 * 3. Validation errors (422) - first error message
 * 4. Technical fallback based on status code
 * 5. Network error message
 * 6. Generic "something went wrong"
 */
export function getErrorMessage(error: unknown): string {
    // Handle Axios errors
    if (isAxiosError(error)) {
        const { response, message: axiosMessage } = error;

        // 1. Check for API response with structured data
        if (response?.data && isApiErrorResponse(response.data)) {
            const apiError = response.data;

            // Handle nested structure: { error: { code, message, details: { errors } } }
            if (apiError.error && typeof apiError.error === 'object') {
                const nested = apiError.error;
                // Extract validation errors from nested details
                if (nested.details?.errors && Object.keys(nested.details.errors).length > 0) {
                    const firstField = Object.keys(nested.details.errors)[0];
                    const firstError = nested.details.errors[firstField]?.[0];
                    if (firstError) {
                        return firstError;
                    }
                }
                // Use nested message
                if (nested.message) {
                    return nested.message;
                }
            }

            // Primary: Use top-level message field
            if (apiError.message) {
                return apiError.message;
            }

            // Alternative: Use error field (string)
            if (apiError.error && typeof apiError.error === 'string') {
                return apiError.error;
            }

            // Validation errors (422): Return first validation message
            if (apiError.errors && Object.keys(apiError.errors).length > 0) {
                const firstField = Object.keys(apiError.errors)[0];
                const firstError = apiError.errors[firstField]?.[0];
                if (firstError) {
                    return firstError;
                }
            }
        }

        // 2. Technical fallback based on status code
        const status = response?.status ?? 0;
        if (TECHNICAL_FALLBACKS[status]) {
            return TECHNICAL_FALLBACKS[status];
        }

        // 3. Network error
        if (axiosMessage === 'Network Error') {
            return TECHNICAL_FALLBACKS[0];
        }

        // 4. Timeout
        if (error.code === 'ECONNABORTED') {
            return TECHNICAL_FALLBACKS[408];
        }
    }

    // Handle standard Error objects (e.g. re-thrown from authService)
    if (error instanceof Error) {
        if (error.message.includes('Network Error')) {
            return TECHNICAL_FALLBACKS[0];
        }
        // Return the error message if it was explicitly set (not a raw stack trace)
        if (error.message && !error.message.includes('at ') && error.message.length < 200) {
            return error.message;
        }
    }

    // 5. Ultimate fallback - never show technical details
    return 'Terjadi kendala teknis. Tim kami sedang menanganinya.';
}

/**
 * Extract validation errors as a map
 * Useful for form field-level error display
 */
export function getValidationErrors(error: unknown): Record<string, string> {
    if (!isAxiosError(error)) return {};

    const response = error.response;
    if (response?.status !== 422) return {};

    const data = response.data;
    if (!isApiErrorResponse(data) || !data.errors) return {};

    const result: Record<string, string> = {};
    for (const [field, messages] of Object.entries(data.errors)) {
        if (Array.isArray(messages) && messages.length > 0) {
            result[field] = messages[0];
        }
    }
    return result;
}

/**
 * Check if error is a specific type
 */
export function isNetworkError(error: unknown): boolean {
    if (!isAxiosError(error)) return false;
    return error.message === 'Network Error' || !error.response;
}

export function isAuthError(error: unknown): boolean {
    if (!isAxiosError(error)) return false;
    return error.response?.status === 401;
}

export function isValidationError(error: unknown): boolean {
    if (!isAxiosError(error)) return false;
    return error.response?.status === 422;
}

export function isForbiddenError(error: unknown): boolean {
    if (!isAxiosError(error)) return false;
    return error.response?.status === 403;
}

// Type guard for Axios errors
function isAxiosError(error: unknown): error is AxiosError {
    return (
        typeof error === 'object' &&
        error !== null &&
        'isAxiosError' in error &&
        (error as AxiosError).isAxiosError === true
    );
}

/**
 * Handle error with toast notification
 * Convenience function that extracts message and shows toast
 */
export function handleApiError(
    error: unknown,
    showToast: { error: (msg: string) => void }
): void {
    const message = getErrorMessage(error);
    showToast.error(message);
}

export default { getErrorMessage, getValidationErrors, handleApiError };
