import { apiClient } from '../../../lib/api';
import type { AuthResponse, LoginCredentials, User } from '../types';

export const authService = {
    login: async (credentials: LoginCredentials): Promise<AuthResponse> => {
        // apiClient interceptor auto-unwraps: { success, data: {...} } → response.data = {...}
        // So response.data already contains: { access_token, user, redirect_url, ... }
        const response = await apiClient.post<{
            access_token: string;
            user: User;
            redirect_url?: string;
        }>('/auth/login', credentials);

        const data = response.data;

        // Validate response structure (already unwrapped by interceptor)
        if (!data?.access_token || !data?.user) {
            throw new Error('Struktur respons server tidak valid');
        }

        return {
            token: data.access_token,
            user: data.user,
            redirect_url: data.redirect_url,
        };
    },

    logout: async (): Promise<void> => {
        try {
            await apiClient.post('/auth/logout');
        } catch {
            // Ignore logout errors
        }
    },

    me: async (): Promise<User> => {
        // Interceptor unwraps: { success, data: { user } } → response.data = { user } OR user directly
        const response = await apiClient.get('/auth/me');
        // Handle both unwrapped (response.data = user object) and nested (response.data.user)
        return response.data?.user || response.data;
    }
};
