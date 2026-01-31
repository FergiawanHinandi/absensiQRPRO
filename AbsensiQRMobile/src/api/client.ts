import axios from 'axios';
import { storage } from '../utils/storage';
import Config from 'react-native-config';
import { Platform } from 'react-native';

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
    console.warn('⚠️  API_BASE_URL not configured in .env file, using development fallback');

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
        'Accept': 'application/json',
    },
});

// Request interceptor - add token
apiClient.interceptors.request.use(
    async (config) => {
        const token = await storage.getToken();
        if (token) {
            config.headers.Authorization = `Bearer ${token}`;
        }
        return config;
    },
    (error) => Promise.reject(error)
);

// Response interceptor - handle 401
apiClient.interceptors.response.use(
    (response) => response,
    async (error) => {
        if (error.response?.status === 401) {
            await storage.removeToken();
            await storage.removeUser();
            // Optionally navigate to login here or let the UI handle the auth state change
            // console.log('Session expired, logging out...');
        }
        return Promise.reject(error);
    }
);

export default apiClient;
