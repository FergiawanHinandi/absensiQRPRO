import {Alert, Platform} from 'react-native';
import JailMonkey from 'jail-monkey';
import DeviceInfo from 'react-native-device-info';
import {storage} from './storage';

export class SecurityUtils {
  /**
   * Check if device is rooted/jailbroken
   * Uses jail-monkey library for accurate detection
   */
  static async checkDeviceSecurity(): Promise<boolean> {
    try {
      // Check for root/jailbreak using jail-monkey
      const isRooted = JailMonkey.isJailBroken();

      // Check if running on emulator
      const isEmulator = await DeviceInfo.isEmulator();

      if (isRooted) {
        Alert.alert(
          'Peringatan Keamanan',
          'Aplikasi tidak dapat berjalan pada device yang sudah di-root/jailbreak untuk keamanan data.',
          [{text: 'OK', onPress: () => {}}],
        );
        return false;
      }

      if (isEmulator) {
        Alert.alert(
          'Peringatan',
          'Aplikasi tidak mendukung emulator. Silakan gunakan device fisik.',
          [{text: 'OK', onPress: () => {}}],
        );
        return false;
      }

      return true;
    } catch (error) {
      console.error('Security check failed:', error);
      return true; // Allow if check fails
    }
  }

  /**
   * Generate device fingerprint for identification
   */
  static async getDeviceFingerprint(): Promise<string> {
    try {
      const deviceId = await DeviceInfo.getUniqueId();
      const model = await DeviceInfo.getModel();
      const systemVersion = await DeviceInfo.getSystemVersion();
      const brand = await DeviceInfo.getBrand();

      return `${brand}-${model}-${systemVersion}-${deviceId}`;
    } catch (error) {
      console.error('Failed to generate device fingerprint:', error);
      return 'unknown-device';
    }
  }

  /**
   * Validate JWT token expiry
   */
  static async validateTokenExpiry(): Promise<boolean> {
    try {
      const token = await storage.getToken();
      if (!token) {
        return false;
      }

      // Decode JWT token (basic validation)
      const parts = token.split('.');
      if (parts.length !== 3) {
        return false;
      }

      const payload = JSON.parse(atob(parts[1]));
      const currentTime = Math.floor(Date.now() / 1000);

      if (payload.exp && payload.exp < currentTime) {
        await storage.removeToken();
        await storage.removeUser();
        return false;
      }

      return true;
    } catch (error) {
      console.error('Token validation failed:', error);
      return false;
    }
  }

  /**
   * Log security events for monitoring
   */
  static logSecurityEvent(
    event: string,
    details: Record<string, unknown> = {},
  ) {
    if (__DEV__) {
      console.warn(`SECURITY EVENT: ${event}`, details);
    }
    // In production, send to security monitoring service
  }

  /**
   * Check device integrity (combination of checks)
   */
  static async runFullSecurityCheck(): Promise<{
    isSecure: boolean;
    issues: string[];
  }> {
    const issues: string[] = [];

    try {
      // Root/jailbreak check
      if (JailMonkey.isJailBroken()) {
        issues.push('Device is rooted/jailbroken');
      }

      // Emulator check
      const isEmulator = await DeviceInfo.isEmulator();
      if (isEmulator) {
        issues.push('Running on emulator');
      }

      // Debug mode check (Android only)
      if (Platform.OS === 'android') {
        const isDebugged = await JailMonkey.isDebuggedMode();
        if (isDebugged) {
          issues.push('Running in debug mode');
        }
      }

      return {
        isSecure: issues.length === 0,
        issues,
      };
    } catch (error) {
      console.error('Security check failed:', error);
      return {
        isSecure: true,
        issues: [],
      };
    }
  }
}
