/**
 * Type definitions for native security modules
 */

declare module 'react-native' {
  interface NativeModulesStatic {
    /**
     * Native module for Android app signature verification
     */
    AppSignatureModule?: {
      /**
       * Get the SHA-256 hash of the app's signing certificate
       * @returns Base64-encoded SHA-256 hash
       */
      getSignatureHash(): Promise<string>;

      /**
       * Check if the app is running in debug mode
       * @returns true if debug build
       */
      isDebugBuild(): Promise<boolean>;

      /**
       * Get the installer package name
       * @returns Package name (e.g., "com.android.vending" for Play Store)
       */
      getInstallerPackage(): Promise<string>;
    };

    /**
     * Native module for screen recording detection and prevention
     */
    ScreenRecordingModule?: {
      /**
       * Check if screen recording is active
       * @returns true if recording detected
       */
      isScreenRecording(): Promise<boolean>;

      /**
       * Enable secure screen mode (prevents screenshots/recording)
       * @returns true if successfully enabled
       */
      enableSecureScreen(): Promise<boolean>;

      /**
       * Disable secure screen mode
       * @returns true if successfully disabled
       */
      disableSecureScreen(): Promise<boolean>;
    };
  }
}

/**
 * JailMonkey type definitions
 */
declare module 'jail-monkey' {
  interface JailMonkey {
    /**
     * Check if device is jailbroken (iOS)
     */
    isJailBroken(): Promise<boolean>;

    /**
     * Check if app is on external storage (Android)
     */
    isOnExternalStorage(): Promise<boolean>;

    /**
     * Check if app is being debugged
     */
    isDebuggedMode(): Promise<boolean>;

    /**
     * Check if device allows mock locations
     */
    canMockLocation(): Promise<boolean>;

    /**
     * iOS trust fall test - checks for common jailbreak indicators
     */
    trustFall(): Promise<boolean>;

    /**
     * Check if device is rooted (Android)
     */
    isRooted(): Promise<boolean>;

    /**
     * Check if Frida or similar hooks are detected
     */
    hookDetected(): Promise<boolean>;

    /**
     * Check if ADB is enabled (Android)
     */
    AdbEnabled(): Promise<boolean>;

    /**
     * Check if developer settings mode is enabled (Android)
     */
    isDevelopmentSettingsMode(): Promise<boolean>;
  }

  const jailMonkey: JailMonkey;
  export default jailMonkey;
}

export {};
