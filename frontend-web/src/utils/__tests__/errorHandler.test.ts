import { describe, it, expect, vi } from 'vitest';
import { getErrorMessage, getValidationErrors, isNetworkError, isAuthError, isValidationError, isForbiddenError } from '../../utils/errorHandler';

// Helper to create Axios-like error objects
function createAxiosError(status: number, data?: any, message?: string) {
  return {
    isAxiosError: true,
    message: message || 'Request failed',
    response: status ? { status, data } : undefined,
  };
}

describe('errorHandler', () => {
  describe('getErrorMessage', () => {
    it('returns API message for 422 validation error', () => {
      const error = createAxiosError(422, {
        message: 'Validation failed',
        errors: { username: ['Username is required'] },
      });
      const msg = getErrorMessage(error);
      expect(msg).toBe('Validation failed');
    });

    it('returns first validation error when no message field', () => {
      const error = createAxiosError(422, {
        errors: { username: ['Username is required'] },
      });
      expect(getErrorMessage(error)).toBe('Username is required');
    });

    it('returns API message field for structured error', () => {
      const error = createAxiosError(400, {
        message: 'Invalid request',
      });
      expect(getErrorMessage(error)).toBe('Invalid request');
    });

    it('returns nested error message', () => {
      const error = createAxiosError(400, {
        error: { message: 'Account locked' },
      });
      expect(getErrorMessage(error)).toBe('Account locked');
    });

    it('returns fallback for network error', () => {
      const error = createAxiosError(0, undefined, 'Network Error');
      expect(getErrorMessage(error)).toContain('koneksi internet');
    });

    it('returns fallback for 500 error', () => {
      const error = createAxiosError(500);
      expect(getErrorMessage(error)).toContain('Server sedang sibuk');
    });

    it('returns fallback for 401 error', () => {
      const error = createAxiosError(401);
      expect(getErrorMessage(error)).toContain('Sesi Anda telah berakhir');
    });

    it('returns fallback for 429 rate limit', () => {
      const error = createAxiosError(429);
      expect(getErrorMessage(error)).toContain('Terlalu banyak permintaan');
    });

    it('returns generic message for unknown error', () => {
      expect(getErrorMessage('something')).toContain('kendala teknis');
    });

    it('returns message from Error instance', () => {
      const error = new Error('Custom error message');
      expect(getErrorMessage(error)).toBe('Custom error message');
    });

    it('returns network fallback for timeout with no response', () => {
      const error = { isAxiosError: true, message: 'timeout of 15000ms exceeded', code: 'ECONNABORTED', response: undefined };
      expect(getErrorMessage(error)).toContain('koneksi internet');
    });

    it('returns timeout fallback for ECONNABORTED with response', () => {
      const error = { isAxiosError: true, message: 'timeout of 15000ms exceeded', code: 'ECONNABORTED', response: { status: 408, data: {} } };
      expect(getErrorMessage(error)).toContain('timeout');
    });
  });

  describe('getValidationErrors', () => {
    it('extracts first error per field from 422 response', () => {
      const error = createAxiosError(422, {
        errors: { email: ['Required'], password: ['Too short'] },
      });
      const result = getValidationErrors(error);
      expect(result).toEqual({ email: 'Required', password: 'Too short' });
    });

    it('returns empty object for non-422 errors', () => {
      const error = createAxiosError(500, { message: 'Server error' });
      expect(getValidationErrors(error)).toEqual({});
    });
  });

  describe('type checkers', () => {
    it('isNetworkError detects network errors', () => {
      expect(isNetworkError(createAxiosError(0, undefined, 'Network Error'))).toBe(true);
      expect(isNetworkError(createAxiosError(200))).toBe(false);
    });

    it('isAuthError detects 401', () => {
      expect(isAuthError(createAxiosError(401))).toBe(true);
      expect(isAuthError(createAxiosError(403))).toBe(false);
    });

    it('isValidationError detects 422', () => {
      expect(isValidationError(createAxiosError(422))).toBe(true);
      expect(isValidationError(createAxiosError(400))).toBe(false);
    });

    it('isForbiddenError detects 403', () => {
      expect(isForbiddenError(createAxiosError(403))).toBe(true);
      expect(isForbiddenError(createAxiosError(401))).toBe(false);
    });
  });
});
