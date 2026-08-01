import SecureStorage from '../services/SecureStorage';
import EncryptedStorage from 'react-native-encrypted-storage';

export const StorageKeys = {
  TOKEN: '@auth_token',
  USER: '@auth_user',
};

/**
 * Unified Storage Service
 *
 * PENTING: Token disimpan di Keychain/Keystore via SecureStorage
 * agar sinkron dengan AuthService. Data non-sensitif lainnya
 * tetap menggunakan EncryptedStorage.
 *
 * Sebelumnya ada bug dimana AuthService menyimpan token ke Keychain
 * tapi apiClient membaca dari EncryptedStorage — sehingga semua
 * API call gagal karena token tidak ditemukan.
 */
export const storage = {
  async setToken(token: string) {
    try {
      // Simpan ke Keychain via SecureStorage agar sinkron dengan AuthService
      // AuthService menyimpan access_token + refresh_token sebagai pasangan,
      // tapi di sini kita hanya perlu akses baca, jadi simpan juga ke
      // SecureStorage agar konsisten
      const existingTokens = await SecureStorage.getTokens();
      await SecureStorage.storeTokens(
        token,
        existingTokens?.refreshToken || '',
      );
    } catch (error) {
      console.error('Error setting token:', error);
    }
  },

  async getToken(): Promise<string | null> {
    try {
      // Baca dari Keychain (sumber yang sama dengan AuthService)
      const tokens = await SecureStorage.getTokens();
      return tokens?.accessToken || null;
    } catch (error) {
      console.error('Error getting token:', error);
      return null;
    }
  },

  async removeToken() {
    try {
      // Hapus dari Keychain
      await SecureStorage.clearTokens();
    } catch (error) {
      console.error('Error removing token:', error);
    }
  },

  async setUser(user: any) {
    try {
      // User data disimpan di SecureStorage (Keychain) agar konsisten
      await SecureStorage.storeUserData(user);
    } catch (error) {
      console.error('Error setting user:', error);
    }
  },

  async getUser() {
    try {
      const data = await SecureStorage.getUserData();
      return data || null;
    } catch (error) {
      console.error('Error getting user:', error);
      return null;
    }
  },

  async removeUser() {
    try {
      await SecureStorage.clearUserData();
    } catch (error) {
      console.error('Error removing user:', error);
    }
  },

  async clear() {
    try {
      await SecureStorage.clearAll();
      await EncryptedStorage.clear();
    } catch (error) {
      console.error('Error clearing storage:', error);
    }
  },
};
