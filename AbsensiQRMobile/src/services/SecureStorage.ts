import * as Keychain from 'react-native-keychain';

/**
 * Secure Storage Service
 *
 * CRITICAL SECURITY:
 * - Uses iOS Keychain (hardware-backed encryption)
 * - Uses Android Keystore (hardware-backed encryption)
 * - NEVER use AsyncStorage for tokens!
 *
 * Security Features:
 * - OS-level encryption
 * - Automatic key management
 * - Secure even on rooted/jailbroken devices (with hardware backing)
 * - Tokens cleared on logout/failure
 * - No tokens in logs
 */
class SecureStorageService {
  private readonly SERVICE_NAME = 'com.absensi.qr.auth';
  private readonly USER_SERVICE = 'com.absensi.qr.user';

  /**
   * Store authentication tokens securely
   *
   * @param accessToken - JWT access token
   * @param refreshToken - JWT refresh token
   */
  async storeTokens(accessToken: string, refreshToken: string): Promise<void> {
    try {
      await Keychain.setGenericPassword(
        'auth_tokens',
        JSON.stringify({
          accessToken,
          refreshToken,
          storedAt: new Date().toISOString(),
        }),
        {
          service: this.SERVICE_NAME,
          // iOS: kSecAttrAccessibleWhenUnlocked
          // Android: EncryptedSharedPreferences with AES256
          accessible: Keychain.ACCESSIBLE.WHEN_UNLOCKED,

          // Optional: Require biometric authentication
          // accessControl: Keychain.ACCESS_CONTROL.BIOMETRY_ANY,
        },
      );

      // ✅ NEVER log actual tokens
      console.log(
        '[SecureStorage] ✅ Tokens stored securely in Keychain/Keystore',
      );
    } catch (error) {
      console.error('[SecureStorage] ❌ Failed to store tokens:', error);
      throw new Error('Failed to store authentication tokens');
    }
  }

  /**
   * Retrieve authentication tokens
   *
   * @returns Tokens or null if not found
   */
  async getTokens(): Promise<{
    accessToken: string;
    refreshToken: string;
    storedAt: string;
  } | null> {
    try {
      const credentials = await Keychain.getGenericPassword({
        service: this.SERVICE_NAME,
      });

      if (!credentials || credentials === false) {
        console.log('[SecureStorage] ℹ️ No tokens found');
        return null;
      }

      const data = JSON.parse(credentials.password);

      // ✅ NEVER log actual tokens
      console.log('[SecureStorage] ✅ Tokens retrieved from Keychain/Keystore');

      return {
        accessToken: data.accessToken,
        refreshToken: data.refreshToken,
        storedAt: data.storedAt,
      };
    } catch (error) {
      console.error('[SecureStorage] ❌ Failed to retrieve tokens:', error);
      return null;
    }
  }

  /**
   * Clear all stored tokens
   *
   * CRITICAL: Call this on:
   * - User logout
   * - 401 Unauthorized (after refresh fails)
   * - 403 Forbidden (user disabled)
   * - Token refresh failure
   */
  async clearTokens(): Promise<void> {
    try {
      const result = await Keychain.resetGenericPassword({
        service: this.SERVICE_NAME,
      });

      if (result) {
        console.log('[SecureStorage] ✅ Tokens cleared from Keychain/Keystore');
      } else {
        console.log('[SecureStorage] ℹ️ No tokens to clear');
      }
    } catch (error) {
      console.error('[SecureStorage] ❌ Failed to clear tokens:', error);
      // Don't throw - clearing should always succeed
    }
  }

  /**
   * Check if tokens exist
   */
  async hasTokens(): Promise<boolean> {
    try {
      const credentials = await Keychain.getGenericPassword({
        service: this.SERVICE_NAME,
      });

      return !!credentials && credentials !== false;
    } catch (error) {
      console.error('[SecureStorage] ❌ Failed to check tokens:', error);
      return false;
    }
  }

  /**
   * Store user data (non-sensitive metadata)
   *
   * NOTE: For sensitive data, use storeTokens()
   * This is for user profile data like name, role, etc.
   */
  async storeUserData(userData: {
    id: number;
    name: string;
    username: string;
    email: string;
    role_type: string;
    school_id: number;
    school_name?: string;
  }): Promise<void> {
    try {
      await Keychain.setGenericPassword(
        'user_data',
        JSON.stringify({
          ...userData,
          updatedAt: new Date().toISOString(),
        }),
        {
          service: this.USER_SERVICE,
          accessible: Keychain.ACCESSIBLE.WHEN_UNLOCKED,
        },
      );

      console.log('[SecureStorage] ✅ User data stored');
    } catch (error) {
      console.error('[SecureStorage] ❌ Failed to store user data:', error);
      throw new Error('Failed to store user data');
    }
  }

  /**
   * Get user data
   */
  async getUserData(): Promise<any | null> {
    try {
      const credentials = await Keychain.getGenericPassword({
        service: this.USER_SERVICE,
      });

      if (!credentials || credentials === false) {
        return null;
      }

      return JSON.parse(credentials.password);
    } catch (error) {
      console.error('[SecureStorage] ❌ Failed to get user data:', error);
      return null;
    }
  }

  /**
   * Clear user data
   */
  async clearUserData(): Promise<void> {
    try {
      await Keychain.resetGenericPassword({
        service: this.USER_SERVICE,
      });

      console.log('[SecureStorage] ✅ User data cleared');
    } catch (error) {
      console.error('[SecureStorage] ❌ Failed to clear user data:', error);
    }
  }

  /**
   * Clear all data (tokens + user data)
   *
   * Call this on complete logout
   */
  async clearAll(): Promise<void> {
    await Promise.all([this.clearTokens(), this.clearUserData()]);

    console.log('[SecureStorage] ✅ All secure data cleared');
  }

  /**
   * Get supported biometry type
   *
   * @returns 'FaceID' | 'TouchID' | 'Fingerprint' | 'Iris' | null
   */
  async getSupportedBiometry(): Promise<Keychain.BIOMETRY_TYPE | null> {
    try {
      const biometryType = await Keychain.getSupportedBiometryType();
      return biometryType;
    } catch (error) {
      console.error('[SecureStorage] Failed to get biometry type:', error);
      return null;
    }
  }

  /**
   * Store tokens with biometric protection
   *
   * Requires biometric authentication to retrieve tokens
   */
  async storeTokensWithBiometric(
    accessToken: string,
    refreshToken: string,
  ): Promise<void> {
    try {
      await Keychain.setGenericPassword(
        'auth_tokens',
        JSON.stringify({
          accessToken,
          refreshToken,
          storedAt: new Date().toISOString(),
        }),
        {
          service: this.SERVICE_NAME,
          accessible: Keychain.ACCESSIBLE.WHEN_UNLOCKED_THIS_DEVICE_ONLY,
          accessControl: Keychain.ACCESS_CONTROL.BIOMETRY_ANY,
          authenticationType: Keychain.AUTHENTICATION_TYPE.BIOMETRICS,
        },
      );

      console.log('[SecureStorage] ✅ Tokens stored with biometric protection');
    } catch (error) {
      console.error(
        '[SecureStorage] ❌ Failed to store tokens with biometric:',
        error,
      );
      throw new Error('Failed to store tokens with biometric protection');
    }
  }

  /**
   * Store FCM token securely
   *
   * @param token - FCM device token
   */
  async setItem(key: string, value: string): Promise<void> {
    try {
      await Keychain.setGenericPassword(key, value, {
        service: 'com.absensi.qr.fcm',
        accessible: Keychain.ACCESSIBLE.WHEN_UNLOCKED,
      });

      console.log(`[SecureStorage] ✅ ${key} stored securely`);
    } catch (error) {
      console.error(`[SecureStorage] ❌ Failed to store ${key}:`, error);
    }
  }

  /**
   * Get FCM token securely
   *
   * @param key - Key to retrieve
   * @returns Value or null if not found
   */
  async getItem(key: string): Promise<string | null> {
    try {
      const credentials = await Keychain.getGenericPassword({
        service: 'com.absensi.qr.fcm',
      });

      if (!credentials || credentials === false) {
        return null;
      }

      return credentials.password;
    } catch (error) {
      console.error(`[SecureStorage] ❌ Failed to get ${key}:`, error);
      return null;
    }
  }

  /**
   * Remove FCM token securely
   *
   * @param key - Key to remove
   */
  async removeItem(key: string): Promise<void> {
    try {
      await Keychain.resetGenericPassword({
        service: 'com.absensi.qr.fcm',
      });

      console.log(`[SecureStorage] ✅ ${key} removed`);
    } catch (error) {
      console.error(`[SecureStorage] ❌ Failed to remove ${key}:`, error);
    }
  }
}

export const SecureStorage = new SecureStorageService();
export default SecureStorageService;
