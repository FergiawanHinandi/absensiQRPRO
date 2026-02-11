/**
 * Encryption Utilities for Offline Data
 *
 * Uses react-native-encrypted-storage for secure offline data storage
 *
 * SECURITY:
 * - Hardware-backed encryption on supported devices
 * - Automatic key management
 * - Never log decrypted data
 *
 * @version 2.0.0
 */

import EncryptedStorage from 'react-native-encrypted-storage';

/**
 * Store encrypted data in secure storage
 *
 * @param key - Storage key
 * @param data - Plain text data to encrypt
 *
 * @example
 * await encryptOfflineData('queue', JSON.stringify(queueData));
 */
export const encryptOfflineData = async (
  key: string,
  data: string,
): Promise<void> => {
  try {
    await EncryptedStorage.setItem(key, data);
  } catch (error) {
    console.error('[ENCRYPTION] Failed to encrypt data:', error);
    throw new Error('Encryption failed');
  }
};

/**
 * Retrieve and decrypt data from secure storage
 *
 * @param key - Storage key
 * @returns Decrypted plain text or null if not found
 *
 * @example
 * const decrypted = await decryptOfflineData('queue');
 * const queueData = decrypted ? JSON.parse(decrypted) : [];
 */
export const decryptOfflineData = async (
  key: string,
): Promise<string | null> => {
  try {
    const data = await EncryptedStorage.getItem(key);
    return data;
  } catch (error) {
    console.error('[ENCRYPTION] Failed to decrypt data:', error);
    throw new Error('Decryption failed - data may be corrupted');
  }
};

/**
 * Remove encrypted data from secure storage
 *
 * @param key - Storage key
 */
export const removeEncryptedData = async (key: string): Promise<void> => {
  try {
    await EncryptedStorage.removeItem(key);
  } catch (error) {
    console.error('[ENCRYPTION] Failed to remove data:', error);
    throw new Error('Remove failed');
  }
};

/**
 * Clear all encrypted data from secure storage
 * Use with caution - this removes ALL encrypted data
 */
export const clearAllEncryptedData = async (): Promise<void> => {
  try {
    await EncryptedStorage.clear();
  } catch (error) {
    console.error('[ENCRYPTION] Failed to clear all data:', error);
    throw new Error('Clear failed');
  }
};
