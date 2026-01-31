import { apiClient } from '../../../lib/api';
import type { AuthResponse, LoginCredentials, User } from '../types';

export const authService = {
    login: async (credentials: LoginCredentials): Promise<AuthResponse> => {
        try {
            const response = await apiClient.post<{ success: boolean; data: { access_token: string; user: User } }>('/auth/login', credentials);
            
            // Validate response structure
            if (!response.data?.success || !response.data?.data?.access_token || !response.data?.data?.user) {
                throw new Error('Invalid response structure from server');
            }
            
            // Transform backend response to match frontend expectations
            return {
                token: response.data.data.access_token,
                user: response.data.data.user
            };
        } catch (error: any) {
            // Handle specific error cases
            if (error.response?.status === 422) {
                throw new Error(error.response.data.message || 'Kredensial tidak valid');
            } else if (error.response?.status === 429) {
                throw new Error('Terlalu banyak percobaan login. Silakan coba lagi nanti.');
            } else if (error.response?.status >= 500) {
                throw new Error('Server sedang bermasalah. Silakan coba lagi nanti.');
            } else if (!error.response) {
                throw new Error('Tidak dapat terhubung ke server. Periksa koneksi internet Anda.');
            }
            
            throw error;
        }
    },

    logout: async (): Promise<void> => {
        try {
            await apiClient.post('/auth/logout');
        } catch {
            // Ignore logout errors
        }
    },

    me: async (): Promise<User> => {
        const response = await apiClient.get<{ user: User }>('/auth/me');
        return response.data.user;
    }
};
