import axios, {AxiosInstance, AxiosError} from 'axios';
import Config from 'react-native-config';
import SecureStorage from './SecureStorage';

/**
 * Authentication Service
 *
 * SECURITY FEATURES:
 * - Automatic token refresh on 401
 * - Automatic token invalidation on 403 (user disabled)
 * - Secure token storage (Keychain/Keystore)
 * - No tokens in logs
 * - Token cleared on all failure scenarios
 *
 * CRITICAL:
 * - NEVER log tokens
 * - ALWAYS clear tokens on auth failure
 * - ALWAYS use SecureStorage, never AsyncStorage
 */
class AuthService {
  private api: AxiosInstance;
  private isRefreshing = false;
  private refreshSubscribers: ((token: string) => void)[] = [];

  constructor() {
    // NOTE: AuthService uses /api (tanpa /v1) karena endpoint auth
    // berada di /api/auth/*, berbeda dengan apiClient yang menggunakan
    // API_BASE_URL = /api/v1 untuk resource endpoints.
    this.api = axios.create({
      baseURL:
        Config.API_BASE_URL?.replace(/\/v1\/?$/, '') ||
        Config.API_URL ||
        'http://localhost:8000/api',
      timeout: 15000,
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
    });

    this.setupInterceptors();
  }

  /**
   * Setup axios interceptors
   *
   * Request: Add Authorization header
   * Response: Handle 401 (refresh token) and 403 (user disabled)
   */
  private setupInterceptors() {
    // ============================================
    // REQUEST INTERCEPTOR: Add token to headers
    // ============================================
    this.api.interceptors.request.use(
      async config => {
        const tokens = await SecureStorage.getTokens();

        if (tokens?.accessToken) {
          config.headers.Authorization = `Bearer ${tokens.accessToken}`;
        }

        return config;
      },
      error => {
        console.error('[Auth] Request interceptor error:', error);
        return Promise.reject(error);
      },
    );

    // ============================================
    // RESPONSE INTERCEPTOR: Handle auth errors
    // ============================================
    this.api.interceptors.response.use(
      response => response,
      async (error: AxiosError) => {
        const originalRequest: any = error.config;

        // ============================================
        // HANDLE 401 UNAUTHORIZED
        // ============================================
        if (error.response?.status === 401 && !originalRequest._retry) {
          // Prevent infinite loop
          if (originalRequest.url?.includes('/auth/refresh')) {
            console.error('[Auth] Refresh token is invalid');
            await this.handleAuthFailure('refresh_failed');
            return Promise.reject(error);
          }

          // If already refreshing, queue this request
          if (this.isRefreshing) {
            return new Promise(resolve => {
              this.refreshSubscribers.push((token: string) => {
                originalRequest.headers.Authorization = `Bearer ${token}`;
                resolve(this.api(originalRequest));
              });
            });
          }

          originalRequest._retry = true;
          this.isRefreshing = true;

          try {
            console.log('[Auth] ⚠️ Access token expired, refreshing...');

            const tokens = await SecureStorage.getTokens();

            if (!tokens?.refreshToken) {
              throw new Error('No refresh token available');
            }

            // Call refresh endpoint
            const response = await axios.post(
              `${Config.API_URL}/v1/auth/refresh`,
              {refresh_token: tokens.refreshToken},
              {
                headers: {
                  'Content-Type': 'application/json',
                  Accept: 'application/json',
                },
              },
            );

            const {access_token, refresh_token} = response.data.data;

            // ✅ Store new tokens securely
            await SecureStorage.storeTokens(access_token, refresh_token);

            console.log('[Auth] ✅ Token refreshed successfully');

            // Notify all queued requests
            this.refreshSubscribers.forEach(callback => callback(access_token));
            this.refreshSubscribers = [];

            // Retry original request with new token
            originalRequest.headers.Authorization = `Bearer ${access_token}`;
            return this.api(originalRequest);
          } catch (refreshError) {
            console.error('[Auth] ❌ Token refresh failed:', refreshError);
            await this.handleAuthFailure('refresh_failed');
            return Promise.reject(refreshError);
          } finally {
            this.isRefreshing = false;
          }
        }

        // ============================================
        // HANDLE 403 FORBIDDEN (User Disabled)
        // ============================================
        if (error.response?.status === 403) {
          const message = (error.response?.data as any)?.message || '';

          // Check if user account is disabled
          if (
            message.toLowerCase().includes('tidak aktif') ||
            message.toLowerCase().includes('disabled') ||
            message.toLowerCase().includes('inactive') ||
            message.toLowerCase().includes('nonaktif')
          ) {
            console.warn('[Auth] ⚠️ User account disabled by admin');
            await this.handleAuthFailure('user_disabled');
          }
        }

        return Promise.reject(error);
      },
    );
  }

  /**
   * Handle authentication failure
   *
   * CRITICAL: Clear all tokens and user data
   *
   * Scenarios:
   * - Token refresh failed
   * - User account disabled
   * - Token expired and no refresh token
   */
  private async handleAuthFailure(reason: string) {
    console.log(`[Auth] 🔒 Handling auth failure: ${reason}`);

    // ✅ CRITICAL: Clear all secure data
    await SecureStorage.clearAll();

    // Emit event for app to handle (navigate to login)
    // You can use EventEmitter, Redux, or navigation service
    // Example: EventEmitter.emit('AUTH_FAILURE', { reason });

    console.log('[Auth] ✅ All tokens and user data cleared');
  }

  /**
   * Login
   *
   * @param username - Username or email
   * @param password - Password
   * @returns User data and success status
   */
  async login(username: string, password: string) {
    try {
      console.log(`[Auth] 🔐 Logging in user: ${username}`);

      const response = await this.api.post('/v1/auth/login', {
        username,
        password,
      });

      const {access_token, refresh_token, user} = response.data.data;

      // ✅ Store tokens securely (Keychain/Keystore)
      await SecureStorage.storeTokens(access_token, refresh_token);

      // ✅ Store user data
      await SecureStorage.storeUserData(user);

      console.log(
        `[Auth] ✅ Login successful for ${user.role_type}: ${user.name}`,
      );

      return {
        success: true,
        user,
      };
    } catch (error: any) {
      console.error('[Auth] ❌ Login failed:', {
        status: error.response?.status,
        message: error.response?.data?.message || error.message,
      });

      throw error;
    }
  }

  /**
   * Logout
   *
   * CRITICAL: Always clear tokens, even if API call fails
   */
  async logout() {
    try {
      console.log('[Auth] 🚪 Logging out...');

      // Try to call backend logout endpoint
      await this.api.post('/v1/auth/logout');

      console.log('[Auth] ✅ Backend logout successful');
    } catch (error) {
      console.error(
        '[Auth] ⚠️ Backend logout failed (continuing with local logout):',
        error,
      );
      // Continue with local logout even if API fails
    } finally {
      // ✅ CRITICAL: Always clear tokens on logout
      await SecureStorage.clearAll();

      console.log('[Auth] ✅ Logout complete - all data cleared');
    }
  }

  /**
   * Get current user from secure storage
   */
  async getCurrentUser() {
    try {
      const user = await SecureStorage.getUserData();

      if (user) {
        console.log(`[Auth] ℹ️ Current user: ${user.role_type} - ${user.name}`);
      }

      return user;
    } catch (error) {
      console.error('[Auth] ❌ Failed to get current user:', error);
      return null;
    }
  }

  /**
   * Check if user is authenticated
   */
  async isAuthenticated(): Promise<boolean> {
    try {
      const hasTokens = await SecureStorage.hasTokens();

      console.log(`[Auth] ℹ️ Is authenticated: ${hasTokens}`);

      return hasTokens;
    } catch (error) {
      console.error('[Auth] ❌ Failed to check authentication:', error);
      return false;
    }
  }

  /**
   * Get API instance for other services to use
   *
   * This instance has interceptors configured for:
   * - Automatic token injection
   * - Automatic token refresh
   * - Automatic auth failure handling
   */
  getApi(): AxiosInstance {
    return this.api;
  }

  /**
   * Manually refresh token
   *
   * Useful for proactive token refresh before expiry
   */
  async refreshToken(): Promise<boolean> {
    try {
      console.log('[Auth] 🔄 Manually refreshing token...');

      const tokens = await SecureStorage.getTokens();

      if (!tokens?.refreshToken) {
        throw new Error('No refresh token available');
      }

      const response = await axios.post(
        `${Config.API_URL}/v1/auth/refresh`,
        {refresh_token: tokens.refreshToken},
        {
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
          },
        },
      );

      const {access_token, refresh_token} = response.data.data;

      await SecureStorage.storeTokens(access_token, refresh_token);

      console.log('[Auth] ✅ Token refreshed successfully');

      return true;
    } catch (error) {
      console.error('[Auth] ❌ Manual token refresh failed:', error);
      await this.handleAuthFailure('manual_refresh_failed');
      return false;
    }
  }

  /**
   * Get current access token (for debugging only)
   *
   * WARNING: Never log this token!
   */
  async getAccessToken(): Promise<string | null> {
    const tokens = await SecureStorage.getTokens();
    return tokens?.accessToken || null;
  }

  /**
   * Check if token is about to expire
   *
   * @param minutesBeforeExpiry - Threshold in minutes
   * @returns true if token will expire soon
   */
  async isTokenExpiringSoon(minutesBeforeExpiry: number = 5): Promise<boolean> {
    try {
      const tokens = await SecureStorage.getTokens();

      if (!tokens) {
        return true;
      }

      // Decode JWT to get expiry (without verification)
      const accessToken = tokens.accessToken;
      const payload = JSON.parse(
        Buffer.from(accessToken.split('.')[1], 'base64').toString(),
      );

      const expiryTime = payload.exp * 1000; // Convert to milliseconds
      const now = Date.now();
      const timeUntilExpiry = expiryTime - now;
      const minutesUntilExpiry = timeUntilExpiry / 1000 / 60;

      return minutesUntilExpiry <= minutesBeforeExpiry;
    } catch (error) {
      console.error('[Auth] Failed to check token expiry:', error);
      return true; // Assume expired if can't check
    }
  }
}

export default new AuthService();
