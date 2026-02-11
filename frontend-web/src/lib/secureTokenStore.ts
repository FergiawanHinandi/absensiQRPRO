/**
 * Secure Token Store
 * 
 * This module provides sessionStorage-based storage for authentication tokens.
 * Tokens persist during the browser session but are cleared when the tab closes.
 * 
 * Security Benefits:
 * - Tokens are not accessible from other tabs/windows
 * - Tokens are cleared when browser/tab is closed
 * - More secure than localStorage (session-scoped)
 * 
 * Trade-offs:
 * - Token persists within the same tab/window session
 * - Allows page refresh without re-authentication
 */

const TOKEN_KEY = '__auth_token__';
const TOKEN_EXPIRY_KEY = '__auth_token_expiry__';

// Private closure to prevent direct access
const tokenStore = (() => {
    // Memory cache for performance
    let _cachedToken: string | null = null;
    let _cachedExpiry: number | null = null;

    const loadFromStorage = (): void => {
        try {
            _cachedToken = sessionStorage.getItem(TOKEN_KEY);
            const expiryStr = sessionStorage.getItem(TOKEN_EXPIRY_KEY);
            _cachedExpiry = expiryStr ? parseInt(expiryStr, 10) : null;
        } catch {
            _cachedToken = null;
            _cachedExpiry = null;
        }
    };

    // Load on initialization
    loadFromStorage();

    return {
        /**
         * Store the authentication token in sessionStorage
         * @param token - The JWT or API token
         * @param expiresIn - Optional expiry time in seconds
         */
        setToken: (token: string, expiresIn?: number): void => {
            // SECURITY: No logging of token operations in production
            
            const expiry = expiresIn 
                ? Date.now() + (expiresIn * 1000)
                : Date.now() + (24 * 60 * 60 * 1000); // Default 24 hours

            _cachedToken = token;
            _cachedExpiry = expiry;

            try {
                sessionStorage.setItem(TOKEN_KEY, token);
                sessionStorage.setItem(TOKEN_EXPIRY_KEY, expiry.toString());
                // SECURITY: Removed token logging - verify only in DEV if needed
            } catch (e) {
                // Silent fail in production, or use error reporting service
                if (import.meta.env.DEV) {
                    console.error('[TOKEN_STORE] Storage error');
                }
            }
        },

        /**
         * Retrieve the stored token
         * Returns null if token is expired or not set
         */
        getToken: (): string | null => {
            // Always reload from storage to ensure fresh data
            loadFromStorage();

            if (!_cachedToken) return null;

            // Check if token has expired
            if (_cachedExpiry && Date.now() > _cachedExpiry) {
                tokenStore.clearToken();
                return null;
            }

            return _cachedToken;
        },

        /**
         * Check if a valid token exists
         */
        hasToken: (): boolean => {
            return tokenStore.getToken() !== null;
        },

        /**
         * Clear the stored token (logout)
         */
        clearToken: (): void => {
            _cachedToken = null;
            _cachedExpiry = null;

            try {
                sessionStorage.removeItem(TOKEN_KEY);
                sessionStorage.removeItem(TOKEN_EXPIRY_KEY);
            } catch {
                // Ignore storage errors
            }
        },

        /**
         * Get time until token expiry in milliseconds
         * Returns 0 if no token or already expired
         */
        getTimeUntilExpiry: (): number => {
            if (!_cachedToken || !_cachedExpiry) return 0;
            const remaining = _cachedExpiry - Date.now();
            return remaining > 0 ? remaining : 0;
        }
    };
})();

// Freeze the store to prevent modifications
Object.freeze(tokenStore);

export { tokenStore };

/**
 * Session indicator storage
 * 
 * We can optionally store a non-sensitive "session active" indicator
 * to provide better UX (e.g., show "Session expired" vs "Please login")
 * 
 * This does NOT contain the token - just indicates a session was active
 */
export const sessionIndicator = {
    SESSION_KEY: 'session_active',

    setActive: (): void => {
        try {
            sessionStorage.setItem(sessionIndicator.SESSION_KEY, '1');
        } catch {
            // Ignore storage errors (private browsing, etc.)
        }
    },

    wasActive: (): boolean => {
        try {
            return sessionStorage.getItem(sessionIndicator.SESSION_KEY) === '1';
        } catch {
            return false;
        }
    },

    clear: (): void => {
        try {
            sessionStorage.removeItem(sessionIndicator.SESSION_KEY);
        } catch {
            // Ignore storage errors
        }
    }
};
