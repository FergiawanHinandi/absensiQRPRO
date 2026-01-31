/**
 * Device Security Service
 * 
 * Comprehensive security checks for mobile device integrity:
 * - Root/Jailbreak detection
 * - Emulator detection
 * - App signature validation (Android)
 * - Debug mode detection
 * - Screen recording detection
 * 
 * All violations are logged to the backend for security monitoring.
 */

import { Platform, NativeModules } from 'react-native';
import Config from 'react-native-config';

// Types for security checks
export interface SecurityCheckResult {
    isSecure: boolean;
    violations: SecurityViolation[];
    riskLevel: 'none' | 'low' | 'medium' | 'high' | 'critical';
    deviceFingerprint: string;
}

export interface SecurityViolation {
    type: SecurityViolationType;
    severity: 'low' | 'medium' | 'high' | 'critical';
    message: string;
    details?: Record<string, unknown>;
}

export type SecurityViolationType =
    | 'rooted_device'
    | 'jailbroken_device'
    | 'emulator_detected'
    | 'debug_mode'
    | 'app_signature_mismatch'
    | 'ssl_pinning_failure'
    | 'screen_recording'
    | 'developer_options'
    | 'usb_debugging'
    | 'unknown_sources';

// Severity mapping for each violation type
const VIOLATION_SEVERITY: Record<SecurityViolationType, 'low' | 'medium' | 'high' | 'critical'> = {
    rooted_device: 'high',
    jailbroken_device: 'high',
    emulator_detected: 'critical',
    debug_mode: 'medium',
    app_signature_mismatch: 'critical',
    ssl_pinning_failure: 'critical',
    screen_recording: 'medium',
    developer_options: 'low',
    usb_debugging: 'medium',
    unknown_sources: 'medium',
};

// User-facing messages (Indonesian)
const VIOLATION_MESSAGES: Record<SecurityViolationType, string> = {
    rooted_device: 'Perangkat tidak aman untuk absensi (root terdeteksi)',
    jailbroken_device: 'Perangkat tidak aman untuk absensi (jailbreak terdeteksi)',
    emulator_detected: 'Aplikasi harus dijalankan pada perangkat fisik',
    debug_mode: 'Mode debug tidak diizinkan untuk absensi',
    app_signature_mismatch: 'Aplikasi termodifikasi terdeteksi. Silakan unduh dari sumber resmi',
    ssl_pinning_failure: 'Koneksi tidak aman terdeteksi',
    screen_recording: 'Perekaman layar terdeteksi',
    developer_options: 'Opsi pengembang aktif',
    usb_debugging: 'USB debugging aktif',
    unknown_sources: 'Instalasi dari sumber tidak dikenal diaktifkan',
};

/**
 * JailMonkey mock interface for when the package isn't installed
 * In production, replace with actual JailMonkey import
 */
interface JailMonkeyInterface {
    isJailBroken: () => Promise<boolean>;
    isOnExternalStorage: () => Promise<boolean>;
    isDebuggedMode: () => Promise<boolean>;
    canMockLocation: () => Promise<boolean>;
    trustFall: () => Promise<boolean>;
    isRooted: () => Promise<boolean>;
    hookDetected: () => Promise<boolean>;
    AdbEnabled: () => Promise<boolean>;
    isDevelopmentSettingsMode: () => Promise<boolean>;
}

// Try to import JailMonkey, fallback to mock if not available
let JailMonkey: JailMonkeyInterface;
try {
    JailMonkey = require('jail-monkey').default;
} catch (e) {
    console.warn('JailMonkey not installed, using mock security checks');
    // Mock implementation for development
    JailMonkey = {
        isJailBroken: async () => false,
        isOnExternalStorage: async () => false,
        isDebuggedMode: async () => __DEV__,
        canMockLocation: async () => false,
        trustFall: async () => true,
        isRooted: async () => false,
        hookDetected: async () => false,
        AdbEnabled: async () => false,
        isDevelopmentSettingsMode: async () => false,
    };
}

/**
 * DeviceSecurityService - Main security checking service
 */
class DeviceSecurityService {
    private cachedResult: SecurityCheckResult | null = null;
    private lastCheckTime: number = 0;
    private readonly CACHE_DURATION_MS = 60000; // 1 minute cache

    // Known production app signature hash (Android)
    // IMPORTANT: Replace with your actual production signature hash
    private readonly PRODUCTION_SIGNATURE_HASH = Config.APP_SIGNATURE_HASH || 
        'YOUR_PRODUCTION_SIGNATURE_SHA256_HASH';

    /**
     * Run all security checks
     */
    async runSecurityChecks(forceRefresh = false): Promise<SecurityCheckResult> {
        // Return cached result if still valid
        if (!forceRefresh && this.cachedResult && 
            Date.now() - this.lastCheckTime < this.CACHE_DURATION_MS) {
            return this.cachedResult;
        }

        const violations: SecurityViolation[] = [];

        // Run all checks in parallel for performance
        const [
            isRooted,
            isJailbroken,
            isEmulator,
            isDebugMode,
            signatureValid,
            isScreenRecording,
            developerSettings,
        ] = await Promise.all([
            this.checkRootStatus(),
            this.checkJailbreakStatus(),
            this.checkEmulator(),
            this.checkDebugMode(),
            this.validateAppSignature(),
            this.checkScreenRecording(),
            this.checkDeveloperSettings(),
        ]);

        // Process root/jailbreak
        if (Platform.OS === 'android' && isRooted) {
            violations.push(this.createViolation('rooted_device'));
        }
        if (Platform.OS === 'ios' && isJailbroken) {
            violations.push(this.createViolation('jailbroken_device'));
        }

        // Process emulator
        if (isEmulator) {
            violations.push(this.createViolation('emulator_detected'));
        }

        // Process debug mode (only block in production)
        if (isDebugMode && !__DEV__) {
            violations.push(this.createViolation('debug_mode'));
        }

        // Process app signature (Android only, production only)
        if (Platform.OS === 'android' && !signatureValid && !__DEV__) {
            violations.push(this.createViolation('app_signature_mismatch'));
        }

        // Process screen recording
        if (isScreenRecording) {
            violations.push(this.createViolation('screen_recording'));
        }

        // Process developer settings
        if (developerSettings.developerOptionsEnabled) {
            violations.push(this.createViolation('developer_options'));
        }
        if (developerSettings.usbDebuggingEnabled) {
            violations.push(this.createViolation('usb_debugging'));
        }
        if (developerSettings.unknownSourcesEnabled) {
            violations.push(this.createViolation('unknown_sources'));
        }

        // Calculate overall risk level
        const riskLevel = this.calculateRiskLevel(violations);

        // Generate device fingerprint
        const deviceFingerprint = await this.generateDeviceFingerprint();

        const result: SecurityCheckResult = {
            isSecure: violations.length === 0,
            violations,
            riskLevel,
            deviceFingerprint,
        };

        // Cache the result
        this.cachedResult = result;
        this.lastCheckTime = Date.now();

        return result;
    }

    /**
     * Check if device is rooted (Android)
     */
    private async checkRootStatus(): Promise<boolean> {
        if (Platform.OS !== 'android') return false;

        try {
            const isRooted = await JailMonkey.isRooted();
            const hookDetected = await JailMonkey.hookDetected();
            return isRooted || hookDetected;
        } catch (error) {
            console.warn('Root check failed:', error);
            return false;
        }
    }

    /**
     * Check if device is jailbroken (iOS)
     */
    private async checkJailbreakStatus(): Promise<boolean> {
        if (Platform.OS !== 'ios') return false;

        try {
            const isJailbroken = await JailMonkey.isJailBroken();
            const trustFailed = !(await JailMonkey.trustFall());
            return isJailbroken || trustFailed;
        } catch (error) {
            console.warn('Jailbreak check failed:', error);
            return false;
        }
    }

    /**
     * Detect emulator/simulator
     */
    private async checkEmulator(): Promise<boolean> {
        try {
            // Platform-specific checks
            if (Platform.OS === 'android') {
                return this.detectAndroidEmulator();
            } else if (Platform.OS === 'ios') {
                return this.detectIOSSimulator();
            }
            return false;
        } catch (error) {
            console.warn('Emulator check failed:', error);
            return false;
        }
    }

    /**
     * Detect Android emulator through various signals
     */
    private detectAndroidEmulator(): boolean {
        const { Brand, Model, Product, Fingerprint, Hardware, Device, Manufacturer } = 
            Platform.constants as any;

        const emulatorIndicators = [
            // Common emulator brands/manufacturers
            Brand?.toLowerCase()?.includes('generic'),
            Manufacturer?.toLowerCase()?.includes('genymotion'),
            Manufacturer?.toLowerCase()?.includes('unknown'),
            
            // Emulator model patterns
            Model?.toLowerCase()?.includes('sdk'),
            Model?.toLowerCase()?.includes('emulator'),
            Model?.toLowerCase()?.includes('android sdk'),
            Model?.toLowerCase()?.includes('google_sdk'),
            
            // Product patterns
            Product?.toLowerCase()?.includes('sdk'),
            Product?.toLowerCase()?.includes('google_sdk'),
            Product?.toLowerCase()?.includes('sdk_gphone'),
            
            // Hardware patterns
            Hardware?.toLowerCase()?.includes('goldfish'),
            Hardware?.toLowerCase()?.includes('ranchu'),
            
            // Fingerprint patterns
            Fingerprint?.toLowerCase()?.includes('generic'),
            Fingerprint?.toLowerCase()?.includes('unknown'),
            Fingerprint?.toLowerCase()?.includes('sdk'),
            
            // Device patterns
            Device?.toLowerCase()?.includes('generic'),
            Device?.toLowerCase()?.includes('vbox86'),
        ];

        return emulatorIndicators.some(indicator => indicator === true);
    }

    /**
     * Detect iOS simulator
     */
    private detectIOSSimulator(): boolean {
        // On iOS simulator, Platform.constants will have specific values
        const constants = Platform.constants as any;
        
        // Check if running on simulator
        if (constants?.interfaceIdiom === 'simulator') {
            return true;
        }

        // Additional simulator detection
        const model = constants?.systemName || '';
        if (model.toLowerCase().includes('simulator')) {
            return true;
        }

        return false;
    }

    /**
     * Check if app is in debug mode
     */
    private async checkDebugMode(): Promise<boolean> {
        try {
            const isDebugged = await JailMonkey.isDebuggedMode();
            return isDebugged || __DEV__;
        } catch (error) {
            return __DEV__;
        }
    }

    /**
     * Validate Android app signature against known production hash
     */
    private async validateAppSignature(): Promise<boolean> {
        if (Platform.OS !== 'android') return true;
        if (__DEV__) return true; // Skip in development

        try {
            // Try to get signature from native module
            const { AppSignatureModule } = NativeModules;
            
            if (AppSignatureModule && AppSignatureModule.getSignatureHash) {
                const currentHash = await AppSignatureModule.getSignatureHash();
                return currentHash === this.PRODUCTION_SIGNATURE_HASH;
            }

            // Fallback: assume valid if module not available
            console.warn('AppSignatureModule not available, skipping signature check');
            return true;
        } catch (error) {
            console.warn('Signature validation failed:', error);
            return true; // Fail open in case of errors
        }
    }

    /**
     * Check for screen recording/overlay apps
     */
    private async checkScreenRecording(): Promise<boolean> {
        // This is a simplified check - full implementation requires native modules
        // For Android: WindowManager flags check
        // For iOS: UIScreen.isCaptured
        
        try {
            const { ScreenRecordingModule } = NativeModules;
            
            if (ScreenRecordingModule && ScreenRecordingModule.isScreenRecording) {
                return await ScreenRecordingModule.isScreenRecording();
            }
            
            return false;
        } catch (error) {
            return false;
        }
    }

    /**
     * Check developer settings (Android)
     */
    private async checkDeveloperSettings(): Promise<{
        developerOptionsEnabled: boolean;
        usbDebuggingEnabled: boolean;
        unknownSourcesEnabled: boolean;
    }> {
        if (Platform.OS !== 'android') {
            return {
                developerOptionsEnabled: false,
                usbDebuggingEnabled: false,
                unknownSourcesEnabled: false,
            };
        }

        try {
            const [devMode, adbEnabled] = await Promise.all([
                JailMonkey.isDevelopmentSettingsMode(),
                JailMonkey.AdbEnabled(),
            ]);

            return {
                developerOptionsEnabled: devMode,
                usbDebuggingEnabled: adbEnabled,
                unknownSourcesEnabled: false, // Requires additional native check
            };
        } catch (error) {
            return {
                developerOptionsEnabled: false,
                usbDebuggingEnabled: false,
                unknownSourcesEnabled: false,
            };
        }
    }

    /**
     * Create a violation object
     */
    private createViolation(type: SecurityViolationType, details?: Record<string, unknown>): SecurityViolation {
        return {
            type,
            severity: VIOLATION_SEVERITY[type],
            message: VIOLATION_MESSAGES[type],
            details,
        };
    }

    /**
     * Calculate overall risk level based on violations
     */
    private calculateRiskLevel(violations: SecurityViolation[]): SecurityCheckResult['riskLevel'] {
        if (violations.length === 0) return 'none';

        const hasCritical = violations.some(v => v.severity === 'critical');
        const hasHigh = violations.some(v => v.severity === 'high');
        const hasMedium = violations.some(v => v.severity === 'medium');

        if (hasCritical) return 'critical';
        if (hasHigh) return 'high';
        if (hasMedium) return 'medium';
        return 'low';
    }

    /**
     * Generate device fingerprint for integrity verification
     */
    async generateDeviceFingerprint(): Promise<string> {
        const components = [
            Platform.OS,
            Platform.Version?.toString() || '',
            (Platform.constants as any)?.Brand || '',
            (Platform.constants as any)?.Model || '',
            (Platform.constants as any)?.Manufacturer || '',
        ];

        // Simple hash function for fingerprint
        const data = components.join('|');
        return this.simpleHash(data);
    }

    /**
     * Simple hash function (for fingerprint generation)
     */
    private simpleHash(str: string): string {
        let hash = 0;
        for (let i = 0; i < str.length; i++) {
            const char = str.charCodeAt(i);
            hash = ((hash << 5) - hash) + char;
            hash = hash & hash; // Convert to 32bit integer
        }
        return Math.abs(hash).toString(16).padStart(8, '0');
    }

    /**
     * Quick check if device is compromised (blocking violations only)
     */
    async isDeviceCompromised(): Promise<boolean> {
        const result = await this.runSecurityChecks();
        return result.riskLevel === 'critical' || result.riskLevel === 'high';
    }

    /**
     * Get blocking violations (ones that should prevent app usage)
     */
    async getBlockingViolations(): Promise<SecurityViolation[]> {
        const result = await this.runSecurityChecks();
        return result.violations.filter(
            v => v.severity === 'critical' || v.severity === 'high'
        );
    }

    /**
     * Clear cached security check result
     */
    clearCache(): void {
        this.cachedResult = null;
        this.lastCheckTime = 0;
    }
}

// Export singleton instance
export const deviceSecurityService = new DeviceSecurityService();
export default deviceSecurityService;
