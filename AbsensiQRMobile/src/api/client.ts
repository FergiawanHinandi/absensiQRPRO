import axios from 'axios';
import {storage} from '../utils/storage';
import Config from 'react-native-config';
import {Platform, Alert} from 'react-native';

/**
 * Get the API Base URL from environment config
 * Falls back to development localhost only if no config is provided
 */
const getApiBaseUrl = (): string => {
  // 1. Try to get from environment config first
  if (Config.API_BASE_URL) {
    return Config.API_BASE_URL;
  }

  // 2. Fallback for development only
  console.warn(
    '⚠️  API_BASE_URL not configured in .env file, using development fallback',
  );

  // Android Emulator uses 10.0.2.2 to access host machine's localhost
  if (Platform.OS === 'android') {
    return 'http://10.0.2.2:8000/api/v1';
  }

  // iOS Simulator can use localhost directly
  return 'http://localhost:8000/api/v1';
};

export const API_URL = getApiBaseUrl();

// Log API URL in development
if (__DEV__) {
  console.log('🌐 API Base URL:', API_URL);
}

const apiClient = axios.create({
  baseURL: API_URL,
  timeout: parseInt(Config.API_TIMEOUT || '30000'),
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
});

// Request interceptor - add token
apiClient.interceptors.request.use(
  async config => {
    const token = await storage.getToken();
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
  },
  error => Promise.reject(error),
);

// Response interceptor - handle 401 and 429
apiClient.interceptors.response.use(
  response => response,
  async error => {
    if (error.response?.status === 401) {
      await storage.removeToken();
      await storage.removeUser();
      // Optionally navigate to login here or let the UI handle the auth state change
      // console.log('Session expired, logging out...');
    } else if (error.response?.status === 429) {
      // Rate limit exceeded - add user-friendly error handling
      const retryAfter = error.response.headers['retry-after'] || 60;
      const rateLimitType =
        error.response.headers['x-ratelimit-type'] || 'API request';

      // Enhance error with rate limit information
      error.rateLimitInfo = {
        retryAfter: parseInt(retryAfter),
        type: rateLimitType,
        message:
          error.response.data?.message ||
          'Terlalu banyak permintaan. Coba lagi nanti.',
        limit: error.response.headers['x-ratelimit-limit'],
        remaining: error.response.headers['x-ratelimit-remaining'],
        resetTime: error.response.headers['x-ratelimit-reset'],
      };

      // Global rate limit error handling
      error.type = 'RATE_LIMIT';

      // Show alert with retry time
      const retrySeconds = parseInt(retryAfter);
      Alert.alert(
        'Batas Permintaan Tercapai',
        `Terlalu banyak permintaan. Coba lagi dalam ${retrySeconds} detik.`,
        [{text: 'OK', style: 'default'}],
      );

      if (__DEV__) {
        console.warn('🚫 Rate limit exceeded:', {
          type: rateLimitType,
          retryAfter: retryAfter,
          message: error.response.data?.message,
        });
      }

      // Only retry automatically if explicitly enabled
      if (Config.RATE_LIMIT_RETRY_ENABLED === 'true') {
        // Automatic retry logic would go here if enabled
        // Currently disabled by default for safety
      }
    }
    return Promise.reject(error);
  },
);

export default apiClient;
