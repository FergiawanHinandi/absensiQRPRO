/**
 * Global Response Formatter
 * 
 * Automatically unwraps Laravel API responses and provides consistent error handling
 * 
 * Laravel Response Format:
 * {
 *   success: boolean,
 *   message: string,
 *   data: T
 * }
 * 
 * Formatted Response:
 * {
 *   data: T,
 *   _meta: { success, message }
 * }
 * 
 * @version 1.0.0
 */

import type { AxiosResponse, AxiosError } from 'axios';

/**
 * Laravel API Response Structure
 */
export interface LaravelResponse<T = unknown> {
  success: boolean;
  message?: string;
  data?: T;
  errors?: Record<string, string[]>;
}

/**
 * Formatted Response Structure
 */
export interface FormattedResponse<T = unknown> extends AxiosResponse<T> {
  data: T;
  _meta?: {
    success: boolean;
    message?: string;
  };
}

/**
 * Response Formatter Interceptor
 * 
 * Automatically unwraps Laravel response format
 * 
 * @param response - Axios response
 * @returns Formatted response with unwrapped data
 */
export const formatResponse = <T = unknown>(
  response: AxiosResponse<LaravelResponse<T>>
): FormattedResponse<T> => {
  // Check if response follows Laravel format
  if (response.data && typeof response.data === 'object' && 'success' in response.data) {
    const laravelResponse = response.data as LaravelResponse<T>;

    // Unwrap data and preserve metadata
    return {
      ...response,
      data: laravelResponse.data as T,
      _meta: {
        success: laravelResponse.success,
        message: laravelResponse.message,
      },
    } as FormattedResponse<T>;
  }

  // Return as-is if not Laravel format
  return response as FormattedResponse<T>;
};

/**
 * Error Formatter Interceptor
 * 
 * Provides consistent error structure
 * 
 * @param error - Axios error
 * @returns Formatted error
 */
export const formatError = (error: AxiosError<LaravelResponse>): never => {
  // Network error
  if (!error.response) {
    throw {
      code: 'NETWORK_ERROR',
      message: 'Tidak dapat terhubung ke server. Periksa koneksi internet Anda.',
      originalError: error,
    };
  }

  const { status, data } = error.response;

  // Validation error (422)
  if (status === 422 && data?.errors) {
    throw {
      code: 'VALIDATION_ERROR',
      message: data.message || 'Data tidak valid',
      errors: data.errors,
      originalError: error,
    };
  }

  // Unauthorized (401)
  if (status === 401) {
    throw {
      code: 'UNAUTHORIZED',
      message: data?.message || 'Sesi Anda telah berakhir. Silakan login kembali.',
      originalError: error,
    };
  }

  // Forbidden (403)
  if (status === 403) {
    throw {
      code: 'FORBIDDEN',
      message: data?.message || 'Anda tidak memiliki akses ke resource ini.',
      originalError: error,
    };
  }

  // Not Found (404)
  if (status === 404) {
    throw {
      code: 'NOT_FOUND',
      message: data?.message || 'Resource tidak ditemukan.',
      originalError: error,
    };
  }

  // Rate Limited (429)
  if (status === 429) {
    const retryAfter = error.response.headers['retry-after'] || 60;
    throw {
      code: 'RATE_LIMITED',
      message: `Terlalu banyak permintaan. Coba lagi dalam ${retryAfter} detik.`,
      retryAfter: parseInt(retryAfter as string, 10),
      originalError: error,
    };
  }

  // Server Error (500+)
  if (status >= 500) {
    throw {
      code: 'SERVER_ERROR',
      message: data?.message || 'Terjadi kesalahan pada server. Silakan coba lagi nanti.',
      originalError: error,
    };
  }

  // Generic error
  throw {
    code: 'UNKNOWN_ERROR',
    message: data?.message || 'Terjadi kesalahan. Silakan coba lagi.',
    originalError: error,
  };
};

/**
 * Extract error message from formatted error
 * 
 * @param error - Error object
 * @returns User-friendly error message
 */
export const getErrorMessage = (error: unknown): string => {
  if (typeof error === 'string') return error;
  if (error && typeof error === 'object') {
    if ('message' in error && typeof error.message === 'string') return error.message;
    if ('response' in error && error.response && typeof error.response === 'object') {
      const response = error.response as { data?: { message?: string } };
      if (response.data?.message) return response.data.message;
    }
  }
  return 'Terjadi kesalahan yang tidak diketahui';
};

/**
 * Check if error is a specific type
 * 
 * @param error - Error object
 * @param code - Error code to check
 * @returns true if error matches code
 */
export const isErrorCode = (error: unknown, code: string): boolean => {
  if (error && typeof error === 'object' && 'code' in error) {
    return error.code === code;
  }
  return false;
};
