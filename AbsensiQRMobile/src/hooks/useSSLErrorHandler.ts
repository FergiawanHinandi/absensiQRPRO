/**
 * SSL Error Handler Hook
 *
 * Provides consistent error handling and user feedback for SSL pinning failures.
 * Shows appropriate alerts/modals when secure connections fail.
 */

import {useState, useCallback} from 'react';
import {Alert, Platform} from 'react-native';
import {SecureApiError, SecureApiErrorType} from '../api/secureClient';

interface UseSSLErrorHandlerOptions {
  onAuthError?: () => void;
  onSecurityError?: () => void;
}

interface SSLErrorState {
  hasError: boolean;
  errorType: SecureApiErrorType | null;
  errorMessage: string | null;
}

export const useSSLErrorHandler = (options: UseSSLErrorHandlerOptions = {}) => {
  const [errorState, setErrorState] = useState<SSLErrorState>({
    hasError: false,
    errorType: null,
    errorMessage: null,
  });

  /**
   * Handle a SecureApiError and show appropriate user feedback
   */
  const handleError = useCallback(
    (error: SecureApiError | Error | unknown) => {
      // Check if it's a SecureApiError
      const secureError = error as SecureApiError;

      if (secureError?.type) {
        setErrorState({
          hasError: true,
          errorType: secureError.type,
          errorMessage: secureError.userMessage,
        });

        switch (secureError.type) {
          case SecureApiErrorType.SSL_PINNING_FAILED:
          case SecureApiErrorType.CERTIFICATE_ERROR:
            // Show security alert
            Alert.alert(
              '🔒 Koneksi Tidak Aman',
              secureError.userMessage +
                '\n\nJika masalah berlanjut, pastikan Anda tidak menggunakan WiFi publik yang tidak aman.',
              [
                {
                  text: 'Mengerti',
                  onPress: () => options.onSecurityError?.(),
                },
              ],
              {cancelable: false},
            );
            break;

          case SecureApiErrorType.UNAUTHORIZED:
            // Handle auth error
            Alert.alert(
              'Sesi Berakhir',
              secureError.userMessage,
              [
                {
                  text: 'Login Kembali',
                  onPress: () => options.onAuthError?.(),
                },
              ],
              {cancelable: false},
            );
            break;

          case SecureApiErrorType.NETWORK_ERROR:
          case SecureApiErrorType.TIMEOUT:
            // Show network error
            Alert.alert('Koneksi Gagal', secureError.userMessage, [
              {text: 'OK'},
            ]);
            break;

          default:
            // Generic error
            Alert.alert(
              'Terjadi Kesalahan',
              secureError.userMessage || 'Silakan coba lagi.',
              [{text: 'OK'}],
            );
        }
      } else {
        // Fallback for non-SecureApiError
        const errorMessage =
          error instanceof Error ? error.message : String(error);
        setErrorState({
          hasError: true,
          errorType: SecureApiErrorType.UNKNOWN,
          errorMessage,
        });

        Alert.alert(
          'Terjadi Kesalahan',
          'Silakan coba lagi. Jika masalah berlanjut, hubungi dukungan.',
          [{text: 'OK'}],
        );
      }
    },
    [options],
  );

  /**
   * Clear the current error state
   */
  const clearError = useCallback(() => {
    setErrorState({
      hasError: false,
      errorType: null,
      errorMessage: null,
    });
  }, []);

  /**
   * Check if the error is a security-related error
   */
  const isSecurityError = useCallback(
    (error: SecureApiError | Error | unknown): boolean => {
      const secureError = error as SecureApiError;
      return (
        secureError?.type === SecureApiErrorType.SSL_PINNING_FAILED ||
        secureError?.type === SecureApiErrorType.CERTIFICATE_ERROR
      );
    },
    [],
  );

  return {
    errorState,
    handleError,
    clearError,
    isSecurityError,
  };
};

/**
 * Security Error Component Props
 */
export interface SecurityErrorDisplayProps {
  visible: boolean;
  errorType: SecureApiErrorType | null;
  onRetry?: () => void;
  onDismiss?: () => void;
}

/**
 * Get user-friendly error title based on error type
 */
export const getErrorTitle = (type: SecureApiErrorType | null): string => {
  switch (type) {
    case SecureApiErrorType.SSL_PINNING_FAILED:
    case SecureApiErrorType.CERTIFICATE_ERROR:
      return 'Koneksi Tidak Aman';
    case SecureApiErrorType.NETWORK_ERROR:
      return 'Tidak Ada Koneksi';
    case SecureApiErrorType.TIMEOUT:
      return 'Waktu Habis';
    case SecureApiErrorType.UNAUTHORIZED:
      return 'Sesi Berakhir';
    case SecureApiErrorType.SERVER_ERROR:
      return 'Kesalahan Server';
    default:
      return 'Terjadi Kesalahan';
  }
};

/**
 * Get security tips based on error type
 */
export const getSecurityTips = (type: SecureApiErrorType | null): string[] => {
  switch (type) {
    case SecureApiErrorType.SSL_PINNING_FAILED:
    case SecureApiErrorType.CERTIFICATE_ERROR:
      return [
        'Pastikan Anda menggunakan koneksi internet yang aman',
        'Hindari menggunakan WiFi publik tanpa VPN',
        'Perbarui aplikasi ke versi terbaru',
        'Jika masalah berlanjut, hubungi administrator',
      ];
    case SecureApiErrorType.NETWORK_ERROR:
      return [
        'Periksa koneksi internet Anda',
        'Coba matikan dan nyalakan WiFi/data seluler',
        'Pastikan tidak dalam mode pesawat',
      ];
    default:
      return [];
  }
};

export default useSSLErrorHandler;
