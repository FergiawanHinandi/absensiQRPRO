/**
 * Mobile Security Configuration
 * 
 * Centralized security settings for the AbsensiQR mobile app.
 * This file controls security behavior across the application.
 */

import Config from 'react-native-config';

export interface SecurityConfig {
    // Root/Jailbreak Detection
    rootDetection: {
        enabled: boolean;
        blockOnDetection: boolean;
        logToBackend: boolean;
    };

    // Emulator Detection
    emulatorDetection: {
        enabled: boolean;
        blockOnDetection: boolean;
        allowInDevelopment: boolean;
    };

    // App Signature Verification (Android)
    signatureVerification: {
        enabled: boolean;
        productionHash: string;
        blockOnMismatch: boolean;
    };

    // SSL Pinning
    sslPinning: {
        enabled: boolean;
        blockOnFailure: boolean;
        reportFailures: boolean;
    };

    // Debug Mode Protection
    debugProtection: {
        enabled: boolean;
        allowInDevelopment: boolean;
        blockAttendanceInDebug: boolean;
    };

    // Screen Recording Protection
    screenRecording: {
        detectRecording: boolean;
        enableSecureScreen: boolean;
        blockOnRecording: boolean;
    };

    // Device Integrity
    deviceIntegrity: {
        sendFingerprintOnLogin: boolean;
        requireDeviceBinding: boolean;
        allowMultipleDevices: boolean;
    };

    // Security Event Reporting
    eventReporting: {
        enabled: boolean;
        batchSize: number;
        reportLowSeverity: boolean;
    };
}

/**
 * Get environment-specific security configuration
 */
export const getSecurityConfig = (): SecurityConfig => {
    const isProduction = !__DEV__;
    const enableSslPinning = Config.ENABLE_SSL_PINNING === 'true';

    return {
        rootDetection: {
            enabled: true,
            blockOnDetection: isProduction,
            logToBackend: true,
        },

        emulatorDetection: {
            enabled: true,
            blockOnDetection: isProduction,
            allowInDevelopment: true,
        },

        signatureVerification: {
            enabled: isProduction,
            productionHash: Config.APP_SIGNATURE_HASH || '',
            blockOnMismatch: isProduction,
        },

        sslPinning: {
            enabled: enableSslPinning && isProduction,
            blockOnFailure: true,
            reportFailures: true,
        },

        debugProtection: {
            enabled: true,
            allowInDevelopment: true,
            blockAttendanceInDebug: isProduction,
        },

        screenRecording: {
            detectRecording: true,
            enableSecureScreen: isProduction,
            blockOnRecording: false, // Warn only
        },

        deviceIntegrity: {
            sendFingerprintOnLogin: true,
            requireDeviceBinding: false, // Enable for teacher accounts
            allowMultipleDevices: true,
        },

        eventReporting: {
            enabled: true,
            batchSize: 10,
            reportLowSeverity: !isProduction, // Only in dev for debugging
        },
    };
};

/**
 * Security severity levels
 */
export const SecuritySeverity = {
    CRITICAL: 'critical',
    HIGH: 'high',
    MEDIUM: 'medium',
    LOW: 'low',
} as const;

/**
 * Security event types for logging
 */
export const SecurityEventType = {
    // Device Security
    ROOTED_DEVICE: 'rooted_device',
    JAILBROKEN_DEVICE: 'jailbroken_device',
    EMULATOR_DETECTED: 'emulator_detected',
    
    // App Integrity
    DEBUG_MODE: 'debug_mode',
    APP_SIGNATURE_MISMATCH: 'app_signature_mismatch',
    
    // Network Security
    SSL_PINNING_FAILURE: 'ssl_pinning_failure',
    
    // Screen Security
    SCREEN_RECORDING: 'screen_recording',
    
    // Device Settings
    DEVELOPER_OPTIONS: 'developer_options',
    USB_DEBUGGING: 'usb_debugging',
    UNKNOWN_SOURCES: 'unknown_sources',
    
    // Integrity Checks
    DEVICE_INTEGRITY_CHECK: 'device_integrity_check',
    DEVICE_BINDING_MISMATCH: 'device_binding_mismatch',
} as const;

/**
 * User-facing security messages (Indonesian)
 */
export const SecurityMessages = {
    DEVICE_INSECURE: 'Perangkat tidak aman untuk absensi',
    EMULATOR_BLOCKED: 'Aplikasi harus dijalankan pada perangkat fisik',
    APP_TAMPERED: 'Aplikasi termodifikasi terdeteksi. Silakan unduh dari sumber resmi',
    SSL_FAILURE: 'Koneksi tidak aman terdeteksi',
    DEBUG_BLOCKED: 'Mode debug tidak diizinkan untuk absensi',
    SCREEN_RECORDING_WARN: 'Perekaman layar terdeteksi',
    LOGIN_BLOCKED: 'Login diblokir karena masalah keamanan perangkat',
    RETRY_CHECK: 'Periksa Ulang',
    CONTACT_ADMIN: 'Hubungi administrator jika Anda yakin ini adalah kesalahan',
};

/**
 * Determine if a violation should block the user
 */
export const isBlockingViolation = (
    violationType: string,
    config: SecurityConfig
): boolean => {
    switch (violationType) {
        case SecurityEventType.ROOTED_DEVICE:
        case SecurityEventType.JAILBROKEN_DEVICE:
            return config.rootDetection.blockOnDetection;
        
        case SecurityEventType.EMULATOR_DETECTED:
            return config.emulatorDetection.blockOnDetection;
        
        case SecurityEventType.APP_SIGNATURE_MISMATCH:
            return config.signatureVerification.blockOnMismatch;
        
        case SecurityEventType.DEBUG_MODE:
            return config.debugProtection.blockAttendanceInDebug;
        
        case SecurityEventType.SSL_PINNING_FAILURE:
            return config.sslPinning.blockOnFailure;
        
        case SecurityEventType.SCREEN_RECORDING:
            return config.screenRecording.blockOnRecording;
        
        default:
            return false;
    }
};

// Export singleton config
export const securityConfig = getSecurityConfig();
export default securityConfig;
