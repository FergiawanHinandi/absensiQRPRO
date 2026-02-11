/**
 * API Client with Standardized Response Handling
 *
 * Axios client configured to handle standardized API responses.
 *
 * @version 1.0.0
 */

import axios, { AxiosError, AxiosResponse } from 'axios';
import { ApiResponse, ApiError, isApiResponse } from '../types/api';
import AsyncStorage from '@react-native-async-storage/async-storage';

// Create axios instance
const apiClient = axios.create({
    baseURL: process.env.REACT_APP_API_URL || 'http://localhost:8000/api/v1',
    timeout: 30000,
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
    },
});

// Request interceptor - Add auth token
apiClient.interceptors.request.use(
    async (config) => {
        try {
            const token = await AsyncStorage.getItem('auth_token');
            if (token) {
                config.headers.Authorization = `Bearer ${token}`;
            }
        } catch (error) {
            console.error('Error getting auth token:', error);
        }
        return config;
    },
    (error) => {
        return Promise.reject(error);
    }
);

// Response interceptor - Handle standardized responses
apiClient.interceptors.response.use(
    (response: AxiosResponse<ApiResponse>) => {
        // All successful responses should have standardized format
        const data = response.data;

        // Validate response format
        if (!isApiResponse(data)) {
            console.warn('Response does not match ApiResponse format:', data);
            // Wrap non-standard response
            response.data = {
                success: true,
                code: response.status,
                message: 'Success',
                data: data,
            } as ApiResponse;
        }

        // Check if API-level error (success: false)
        if (!data.success) {
            throw new ApiError(data.message, data.code, data.errors);
        }

        return response;
    },
    (error: AxiosError<ApiResponse>) => {
        // Handle network errors and HTTP errors
        if (error.response) {
            // Server responded with error status
            const data = error.response.data;

            if (isApiResponse(data)) {
                // Standardized error response
                throw new ApiError(data.message, data.code, data.errors);
            } else {
                // Non-standard error response
                throw new ApiError(
                    'An error occurred',
                    error.response.status,
                    undefined
                );
            }
        } else if (error.request) {
            // Request made but no response
            throw new ApiError('Network error. Please check your connection.', 0);
        } else {
            // Something else happened
            throw new ApiError(error.message || 'An unexpected error occurred', 0);
        }
    }
);

export default apiClient;
