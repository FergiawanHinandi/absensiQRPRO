import axios from 'axios';
import { Platform } from 'react-native';
import Config from 'react-native-config';
import { storage } from '../utils/storage';

/**
 * Get the API Base URL from environment config
 * Falls back to development localhost only if no config is provided
 */
const getBaseUrl = () => {
  // Use Config (react-native-config) for consistency across all API clients
  if (Config.API_BASE_URL) {
    return Config.API_BASE_URL;
  }

  // Fallback for development only
  if (__DEV__) {
    console.warn('⚠️  API_BASE_URL not configured in .env file, using development fallback');
  }

  // Android Emulator uses 10.0.2.2 to access host machine's localhost
  if (Platform.OS === 'android') {
    return 'http://10.0.2.2:8000/api/v1';
  }

  // iOS Simulator can use localhost directly
  return 'http://localhost:8000/api/v1';
};

const API_BASE_URL = getBaseUrl();

// Log API URL in development only
if (__DEV__) {
  console.log('🌐 API Core Base URL:', API_BASE_URL);
}

const apiClient = axios.create({
  baseURL: API_BASE_URL,
  timeout: parseInt(Config.API_TIMEOUT || '30000'),
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
});

// Request interceptor - add token
apiClient.interceptors.request.use(async (config) => {
  const token = await storage.getToken();
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Response interceptor - handle 401 and 429
apiClient.interceptors.response.use(
  (response) => response,
  async (error) => {
    if (error.response?.status === 401) {
      // Unauthorized - clear auth data
      await storage.removeToken();
      await storage.removeUser();
    } else if (error.response?.status === 429) {
      // Rate limit exceeded - structured error response
      const retryAfter = parseInt(error.response.headers['retry-after'] || '60');

      // Enhance error with rate limit information
      error.rateLimitInfo = {
        type: 'RATE_LIMIT',
        retryAfter: retryAfter,
        message: error.response.data?.message || 'Terlalu banyak permintaan. Coba lagi nanti.',
        limit: error.response.headers['x-ratelimit-limit'],
        remaining: error.response.headers['x-ratelimit-remaining'],
      };

      if (__DEV__) {
        console.warn('🚫 Rate limit exceeded:', {
          retryAfter,
          message: error.response.data?.message,
        });
      }
    }
    return Promise.reject(error);
  }
);

export default apiClient;
