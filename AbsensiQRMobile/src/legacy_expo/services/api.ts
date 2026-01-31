import axios from 'axios';
import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

/**
 * Get the API Base URL from environment config
 * Falls back to development localhost only if no config is provided
 */
const getBaseUrl = () => {
  // 1. Try to get from environment variable first
  if (process.env.EXPO_PUBLIC_API_URL) {
    return process.env.EXPO_PUBLIC_API_URL;
  }

  // 2. Fallback for development only
  console.warn('⚠️  EXPO_PUBLIC_API_URL not configured, using development fallback');

  // Android Emulator uses 10.0.2.2 to access host machine's localhost
  if (Platform.OS === 'android') {
    return 'http://10.0.2.2:8000/api';
  }

  // iOS Simulator can use localhost/127.0.0.1 directly
  return 'http://127.0.0.1:8000/api';
};

const API_BASE_URL = getBaseUrl();

// Log API URL in development
if (__DEV__) {
  console.log('🌐 Legacy Expo API Base URL:', API_BASE_URL);
}

const api = axios.create({
  baseURL: API_BASE_URL,
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
});

api.interceptors.request.use(async (config) => {
  const token = await SecureStore.getItemAsync('authToken');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

export default api;
