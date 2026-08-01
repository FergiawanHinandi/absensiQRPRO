import Geolocation from 'react-native-geolocation-service';
import {PermissionsAndroid, Platform} from 'react-native';

export interface LocationData {
  latitude: number;
  longitude: number;
  accuracy: number;
  timestamp: number;
  isMocked?: boolean;
}

export class LocationService {
  private static readonly MAX_ACCURACY = 50; // meters
  private static readonly TIMEOUT = 15000; // 15 seconds
  private static readonly MAX_AGE = 5000; // 5 seconds

  /**
   * Request location permissions
   */
  static async requestPermission(): Promise<boolean> {
    if (Platform.OS === 'android') {
      const granted = await PermissionsAndroid.request(
        PermissionsAndroid.PERMISSIONS.ACCESS_FINE_LOCATION,
        {
          title: 'Izin Akses Lokasi',
          message:
            'Aplikasi membutuhkan akses lokasi untuk presensi kehadiran.',
          buttonPositive: 'OK',
          buttonNegative: 'Batal',
        },
      );
      return granted === PermissionsAndroid.RESULTS.GRANTED;
    }
    return true; // iOS handles via Info.plist automatically most of the time
  }

  /**
   * Get current location with high accuracy.
   * Retries up to 3 times if accuracy is poor.
   */
  static async getCurrentLocation(): Promise<LocationData> {
    const hasPermission = await this.requestPermission();
    if (!hasPermission) {
      throw new Error('PERMISSION_DENIED');
    }

    let attempts = 0;
    const MAX_ATTEMPTS = 3;

    while (attempts < MAX_ATTEMPTS) {
      try {
        const location = await this.getLocationOnce();

        // Check accuracy
        if (location.accuracy <= this.MAX_ACCURACY) {
          console.log(
            `✅ [LocationService] Akurasi baik: ${location.accuracy}m`,
          );
          return location;
        }

        console.warn(
          `⚠️ [LocationService] Akurasi buruk (${
            location.accuracy
          }m), retrying... (${attempts + 1}/${MAX_ATTEMPTS})`,
        );
        attempts++;

        // Wait 2 seconds before retry
        await new Promise(resolve => setTimeout(resolve, 2000));
      } catch (error) {
        attempts++;
        if (attempts >= MAX_ATTEMPTS) {
          throw error;
        }
      }
    }

    throw new Error('LOCATION_ACCURACY_TOO_LOW');
  }

  /**
   * Get location once (internal method)
   */
  private static getLocationOnce(): Promise<LocationData> {
    return new Promise((resolve, reject) => {
      Geolocation.getCurrentPosition(
        position => {
          resolve({
            latitude: position.coords.latitude,
            longitude: position.coords.longitude,
            accuracy: position.coords.accuracy,
            timestamp: position.timestamp,
          });
        },
        error => {
          console.error('[LocationService] Location error:', error);
          reject(new Error(`LOCATION_ERROR: ${error.message}`));
        },
        {
          accuracy: {
            android: 'high',
            ios: 'best',
          },
          enableHighAccuracy: true,
          timeout: this.TIMEOUT,
          maximumAge: this.MAX_AGE,
          distanceFilter: 0,
        },
      );
    });
  }

  /**
   * Calculate distance between two points (Haversine formula in meters)
   */
  static calculateDistance(
    lat1: number,
    lon1: number,
    lat2: number,
    lon2: number,
  ): number {
    const R = 6371e3; // Earth radius in meters
    const toRad = Math.PI / 180;

    const phi1 = lat1 * toRad;
    const phi2 = lat2 * toRad;
    const deltaPhi = (lat2 - lat1) * toRad;
    const deltaLambda = (lon2 - lon1) * toRad;

    const a =
      Math.sin(deltaPhi / 2) * Math.sin(deltaPhi / 2) +
      Math.cos(phi1) *
        Math.cos(phi2) *
        Math.sin(deltaLambda / 2) *
        Math.sin(deltaLambda / 2);

    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));

    return R * c; // meters
  }

  /**
   * Validate if user is within school radius
   */
  static isWithinSchoolRadius(
    userLat: number,
    userLon: number,
    schoolLat: number,
    schoolLon: number,
    radiusMeters: number = 100,
  ): {valid: boolean; distance: number} {
    const distance = this.calculateDistance(
      userLat,
      userLon,
      schoolLat,
      schoolLon,
    );
    return {
      valid: distance <= radiusMeters,
      distance: Math.round(distance),
    };
  }
}
