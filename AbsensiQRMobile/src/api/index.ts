/**
 * API Module Exports
 *
 * Provides access to both regular and SSL-pinned API clients.
 *
 * Usage:
 * - Development: Uses regular axios client (no pinning)
 * - Production: Uses SSL-pinned client (MITM protection)
 */

// Regular axios-based client (for development/backward compatibility)
export {default as apiClient, API_URL} from './client';

// Secure SSL-pinned client (for production)
export {
  secureApi,
  secureFetch,
  API_BASE_URL,
  SecureApiErrorType,
  type SecureApiError,
  type SecureRequestOptions,
  type SecureResponse,
} from './secureClient';

// Attendance API
export {default as attendanceApi} from './attendance';
