import {Platform} from 'react-native';
import Geolocation from '@react-native-community/geolocation';

/**
 * Location Validation Utility
 *
 * Provides GPS location with spoofing detection capabilities.
 * This helps detect common GPS spoofing apps and mock locations.
 */

// Define types locally since the library may not export them properly
interface GeoCoordinates {
  latitude: number;
  longitude: number;
  altitude: number | null;
  accuracy: number;
  altitudeAccuracy: number | null;
  heading: number | null;
  speed: number | null;
  mocked?: boolean; // Android only
}

interface GeoPosition {
  coords: GeoCoordinates;
  timestamp: number;
  mocked?: boolean; // Android only
}

interface GeoError {
  code: number;
  message: string;
}

interface GeoOptions {
  timeout?: number;
  maximumAge?: number;
  enableHighAccuracy?: boolean;
}

export interface LocationResult {
  latitude: number;
  longitude: number;
  accuracy: number;
  isMocked: boolean;
  timestamp: number;
}

export interface LocationError {
  code: string;
  message: string;
}

/**
 * Check if location might be mocked/spoofed
 *
 * Android: Check if isFromMockProvider flag is set
 * iOS: Mock locations are harder on iOS, but we check accuracy
 */
const checkIfMocked = (position: GeoPosition): boolean => {
  // Android provides isFromMockProvider
  if (Platform.OS === 'android') {
    // @ts-ignore - isFromMockProvider exists on Android
    const isMocked =
      position.mocked === true || position.coords?.mocked === true;
    return isMocked;
  }

  // iOS: Check for suspiciously high accuracy (mock apps often set perfect accuracy)
  // Real GPS typically has accuracy > 5 meters
  if (Platform.OS === 'ios') {
    // If accuracy is exactly 0 or suspiciously perfect, it might be mocked
    if (position.coords.accuracy === 0 || position.coords.accuracy < 1) {
      return true;
    }
  }

  return false;
};

/**
 * Validate location accuracy is reasonable
 * Extremely high accuracy (< 1m) on mobile is suspicious
 */
const validateAccuracy = (accuracy: number): boolean => {
  // Reasonable GPS accuracy is typically 5-50 meters
  // Anything claiming < 1 meter is suspicious
  if (accuracy < 1) {
    console.warn('Location: Suspiciously high accuracy detected');
    return false;
  }
  return true;
};

/**
 * Get current location with spoofing detection
 *
 * @returns Promise with location data including mock detection flag
 */
export const getCurrentLocation = (): Promise<LocationResult> => {
  return new Promise((resolve, reject) => {
    const options: GeoOptions = {
      enableHighAccuracy: true,
      timeout: 15000,
      maximumAge: 10000,
    };

    Geolocation.getCurrentPosition(
      (position: GeoPosition) => {
        const isMocked = checkIfMocked(position);
        const isValidAccuracy = validateAccuracy(position.coords.accuracy);

        // Log for debugging (will be stripped in production)
        if (__DEV__) {
          console.log('Location obtained:', {
            lat: position.coords.latitude,
            lng: position.coords.longitude,
            accuracy: position.coords.accuracy,
            isMocked,
            isValidAccuracy,
          });
        }

        resolve({
          latitude: position.coords.latitude,
          longitude: position.coords.longitude,
          accuracy: position.coords.accuracy,
          isMocked: isMocked || !isValidAccuracy,
          timestamp: position.timestamp,
        });
      },
      (error: GeoError) => {
        let errorMessage = 'Gagal mendapatkan lokasi';
        let errorCode = 'UNKNOWN';

        switch (error.code) {
          case 1: // PERMISSION_DENIED
            errorCode = 'PERMISSION_DENIED';
            errorMessage =
              'Izin lokasi ditolak. Aktifkan izin lokasi di pengaturan.';
            break;
          case 2: // POSITION_UNAVAILABLE
            errorCode = 'POSITION_UNAVAILABLE';
            errorMessage = 'Lokasi tidak tersedia. Pastikan GPS aktif.';
            break;
          case 3: // TIMEOUT
            errorCode = 'TIMEOUT';
            errorMessage = 'Waktu mendapatkan lokasi habis. Coba lagi.';
            break;
        }

        reject({code: errorCode, message: errorMessage} as LocationError);
      },
      options,
    );
  });
};

/**
 * Get location for attendance with metadata
 *
 * SECURITY PRINCIPLE: Send mock flag to server, don't block client-side.
 * Server has full visibility to log and decide on enforcement.
 *
 * This function:
 * 1. Gets current GPS location
 * 2. Detects mock/spoofed location
 * 3. Returns location WITH mock flag (server decides enforcement)
 */
export const getValidatedLocation = async (): Promise<LocationResult> => {
  const location = await getCurrentLocation();

  if (location.isMocked) {
    // Log security event but DON'T block - server will handle
    console.warn('SECURITY: Mock location detected - sending flag to server');
  }

  // Always return location with mock flag - server validates
  return location;
};

/**
 * @deprecated Use getValidatedLocation instead.
 * This strict version blocks on mock detection client-side.
 * Kept for backwards compatibility only.
 */
export const getValidatedLocationStrict = async (): Promise<LocationResult> => {
  const location = await getCurrentLocation();

  if (location.isMocked) {
    console.warn('SECURITY: Mock location detected - blocking scan');
    throw {
      code: 'MOCK_LOCATION',
      message: 'Terdeteksi lokasi palsu. Matikan aplikasi mock location.',
    } as LocationError;
  }

  return location;
};

export default {
  getCurrentLocation,
  getValidatedLocation,
  getValidatedLocationStrict, // Deprecated - use getValidatedLocation
};
