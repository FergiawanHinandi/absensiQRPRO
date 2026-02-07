import axios from 'axios';
import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';
import Config from 'react-native-config';

/**
 * Get the API Base URL from environment config
 * Falls back to development localhost only if no config is provided
 */
const getBaseUrl = () => {
  // Use Config (react-native-config) for consistency
  if (Config.API_BASE_URL) {
    return Config.API_BASE_URL;
  }

  // Fallback for development only
  console.warn('⚠️  API_BASE_URL not configured in .env file, using development fallback');

  // Android Emulator uses 10.0.2.2 to access host machine's localhost
  if (Platform.OS === 'android') {
    return 'http://10.0.2.2:8000/api/v1';
  }

  // iOS Simulator can use localhost directly
  return 'http://localhost:8000/api/v1';
};

const API_BASE_URL = getBaseUrl();

// Log API URL in development
if (__DEV__) {
  console.log('🌐 Legacy Expo API Base URL:', API_BASE_URL);
}

const api = axios.create({
  baseURL: API_BASE_URL,
  timeout: parseInt(Config.API_TIMEOUT || '30000'),
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
});

// Request interceptor - add token
api.interceptors.request.use(async (config) => {
  const token = await SecureStore.getItemAsync('authToken');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Response interceptor - handle 429 rate limiting
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 429) {
      // Rate limit exceeded - structured error response
      const retryAfter = parseInt(error.response.headers['retry-after'] || '60');

      error.rateLimitInfo = {
        type: 'RATE_LIMIT',
        retryAfter: retryAfter,
        message: error.response.data?.message || 'Terlalu banyak permintaan. Coba lagi nanti.',
      };

      if (__DEV__) {
        console.warn('🚫 Rate limit exceeded:', { retryAfter });
      }
    }
    return Promise.reject(error);
  }
);

export default api;
