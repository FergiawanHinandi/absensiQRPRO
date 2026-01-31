import { Alert } from 'react-native';
import DeviceInfo from 'react-native-device-info';
import { storage } from './storage';

export class SecurityUtils {
    // Check if device is rooted/jailbroken
    static async checkDeviceSecurity(): Promise<boolean> {
        try {
            const isRooted = await DeviceInfo.isEmulator();
            const isJailbroken = await DeviceInfo.isPinOrFingerprintSet();
            
            if (isRooted) {
                Alert.alert(
                    'Peringatan Keamanan',
                    'Aplikasi tidak dapat berjalan pada device yang sudah di-root/jailbreak untuk keamanan data.',
                    [{ text: 'OK', onPress: () => {} }]
                );
                return false;
            }
            
            return true;
        } catch (error) {
            console.error('Security check failed:', error);
            return true; // Allow if check fails
        }
    }

    // Generate device fingerprint
    static async getDeviceFingerprint(): Promise<string> {
        try {
            const deviceId = await DeviceInfo.getUniqueId();
            const model = await DeviceInfo.getModel();
            const systemVersion = await DeviceInfo.getSystemVersion();
            
            return `${deviceId}-${model}-${systemVersion}`;
        } catch (error) {
            console.error('Failed to generate device fingerprint:', error);
            return 'unknown-device';
        }
    }

    // Validate token expiry
    static async validateTokenExpiry(): Promise<boolean> {
        try {
            const token = await storage.getToken();
            if (!token) return false;

            // Decode JWT token (basic validation)
            const payload = JSON.parse(atob(token.split('.')[1]));
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

    // Log security events
    static logSecurityEvent(event: string, details: any = {}) {
        console.warn(`SECURITY EVENT: ${event}`, details);
        // In production, send to security monitoring service
    }
}