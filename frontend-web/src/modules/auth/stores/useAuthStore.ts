import { create } from 'zustand';

import type { User } from '../types';
import { authService } from '../services/authService';
import { tokenStore, sessionIndicator } from '../../../lib/secureTokenStore';

interface AuthState {
    user: User | null;
    token: string | null;
    isAuthenticated: boolean;
    isLoading: boolean;
    error: string | null;
    sessionExpired: boolean; // Track if session was active but expired

    login: (token: string, user: User) => void;
    logout: () => void;
    checkAuth: () => Promise<void>;
    setToken: (token: string) => void;
    getToken: () => string | null;
}

export const useAuthStore = create<AuthState>()((set) => ({
    user: null,
    token: null,
    isAuthenticated: false,
    isLoading: true,
    error: null,
    sessionExpired: false,

    /**
     * Store token in memory-only storage (not localStorage)
     * This prevents XSS attacks from stealing the token
     */
    setToken: (token: string) => {
        tokenStore.setToken(token);
        set({ token });
    },

    /**
     * Get token from memory-only storage
     */
    getToken: () => {
        return tokenStore.getToken();
    },

    login: (token, user) => {
        console.log('[AUTH] login() called with token:', token?.substring(0, 20) + '...');
        console.log('[AUTH] login() user:', user);
        
        // Store token in sessionStorage
        tokenStore.setToken(token);
        
        // Verify token was stored
        const storedToken = tokenStore.getToken();
        console.log('[AUTH] Token stored successfully:', !!storedToken);
        
        // Set session indicator (non-sensitive) for UX purposes
        sessionIndicator.setActive();

        set({
            user,
            token,
            isAuthenticated: true,
            isLoading: false,
            error: null,
            sessionExpired: false
        });
        
        console.log('[AUTH] State updated, isAuthenticated: true');
    },

    logout: () => {
        // Clear token from memory
        tokenStore.clearToken();
        // Clear session indicator
        sessionIndicator.clear();

        // Also clean up any legacy localStorage tokens (migration cleanup)
        try {
            localStorage.removeItem('token');
            localStorage.removeItem('user');
        } catch {
            // Ignore storage errors
        }

        // Call API logout in background
        authService.logout().catch(console.error);

        set({
            user: null,
            token: null,
            isAuthenticated: false,
            sessionExpired: false
        });
    },

    checkAuth: async () => {
        // First, check if there's a token in sessionStorage
        const memoryToken = tokenStore.getToken();

        // If no token, user is not authenticated
        if (!memoryToken) {
            const hadSession = sessionIndicator.wasActive();
            sessionIndicator.clear();

            // Clean up any legacy localStorage tokens
            try {
                localStorage.removeItem('token');
                localStorage.removeItem('user');
            } catch {
                // Ignore storage errors
            }

            set({
                isLoading: false,
                isAuthenticated: false,
                sessionExpired: hadSession // Show session expired message if they had one
            });
            return;
        }

        try {
            const user = await authService.me();
            set({
                user,
                token: memoryToken,
                isAuthenticated: true,
                isLoading: false,
                sessionExpired: false
            });
        } catch (error) {
            // Token invalid - clear everything
            tokenStore.clearToken();
            sessionIndicator.clear();

            // Clean up legacy storage
            try {
                localStorage.removeItem('token');
                localStorage.removeItem('user');
            } catch {
                // Ignore storage errors
            }

            set({
                user: null,
                token: null,
                isAuthenticated: false,
                isLoading: false,
                sessionExpired: false
            });
        }
    },
}));
