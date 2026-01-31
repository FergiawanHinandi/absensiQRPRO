import axios from 'axios';
import type { AxiosInstance, AxiosError } from 'axios';
import { useMaintenanceStore } from '../store/maintenanceStore';
import showToast from '../utils/toast';
import { tokenStore, sessionIndicator } from './secureTokenStore';

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api/v1';

export const apiClient: AxiosInstance = axios.create({
    baseURL: API_URL,
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
    },
});

/**
 * Request interceptor - adds auth token from memory-only storage
 * Token is NEVER read from localStorage (XSS protection)
 */
apiClient.interceptors.request.use((config) => {
    const token = tokenStore.getToken();
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
});

/**
 * Response interceptor - handles auth errors and clears memory token
 */
apiClient.interceptors.response.use(
    (response) => response,
    (error: AxiosError) => {
        if (error.response) {
            const { status, data } = error.response;

            // Handle 503 Service Unavailable (Maintenance Mode)
            if (status === 503) {
                useMaintenanceStore.getState().setMaintenance(true);
                return Promise.reject(error);
            }

            // Handle 401 Unauthorized
            if (status === 401) {
                // Clear token from memory (not localStorage)
                tokenStore.clearToken();
                sessionIndicator.clear();

                // Cleanup legacy localStorage (migration)
                try {
                    localStorage.removeItem('token');
                    localStorage.removeItem('user');
                } catch {
                    // Ignore storage errors
                }

                window.location.href = '/login';
            }

            // Handle 403 Account Inactive
            if (status === 403 && (data as any)?.error === 'ACCOUNT_INACTIVE') {
                const errorData = data as any;

                // Clear token from memory
                tokenStore.clearToken();
                sessionIndicator.clear();

                // Cleanup legacy localStorage
                try {
                    localStorage.removeItem('token');
                    localStorage.removeItem('user');
                } catch {
                    // Ignore storage errors
                }

                // Show detailed error message
                showToast.error(
                    `⚠️ ${errorData.message}\n\n` +
                    `${errorData.details?.reason || ''}\n\n` +
                    `${errorData.details?.action || ''}\n\n` +
                    `📞 Kontak:\n${errorData.details?.contact || 'Hubungi administrator sistem'}`,
                    6000
                );

                // Redirect to login
                window.location.href = '/login';
            }

            // Handle 403 School Inactive
            if (status === 403 && (data as any)?.error === 'SCHOOL_INACTIVE') {
                const errorData = data as any;

                // Clear token from memory
                tokenStore.clearToken();
                sessionIndicator.clear();

                // Cleanup legacy localStorage
                try {
                    localStorage.removeItem('token');
                    localStorage.removeItem('user');
                } catch {
                    // Ignore storage errors
                }

                // Show detailed error message
                showToast.error(
                    `🏫 ${errorData.message}\n\n` +
                    `${errorData.details?.reason || ''}\n\n` +
                    `${errorData.details?.action || ''}\n\n` +
                    `📞 Kontak:\n${errorData.details?.contact || 'Hubungi administrator sistem'}`,
                    6000
                );

                // Redirect to login
                window.location.href = '/login';
            }
        }

        return Promise.reject(error);
    }
);
