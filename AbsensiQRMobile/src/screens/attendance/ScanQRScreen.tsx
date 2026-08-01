import React, {useState, useEffect} from 'react';
import {
  StyleSheet,
  View,
  Text,
  Alert,
  TouchableOpacity,
  ActivityIndicator,
} from 'react-native';
import {
  Camera,
  useCameraDevice,
  useCodeScanner,
} from 'react-native-vision-camera';
import {LocationService} from '../../services/LocationService';
import {SecurityGuard, useDeviceSecurity} from '../../components/SecurityGuard';
import {mobileSecurityApi} from '../../api/mobileSecurityApi';
import attendanceApi, {ScanPayload} from '../../api/attendance';
import {v4 as uuidv4} from 'uuid';

/**
 * ScanQRScreen - Refactored for Server-Side Logic
 *
 * CHANGES:
 * 1. Removed client-side status computation
 * 2. Removed client-side radius validation
 * 3. Send RAW data only to server
 * 4. Server determines ALL business logic
 * 5. Offline queue with encryption
 *
 * @version 2.0.0 - Server-side logic only
 */

// Inner component that handles QR scanning
const ScanQRContent = ({navigation}: any) => {
  const device = useCameraDevice('back');
  const [hasPermission, setHasPermission] = useState(false);
  const [isActive, setIsActive] = useState(true);
  const [isProcessing, setIsProcessing] = useState(false);
  const {deviceFingerprint, isSecure, violations, riskLevel} =
    useDeviceSecurity();

  useEffect(() => {
    (async () => {
      const status = await Camera.requestCameraPermission();
      setHasPermission(status === 'granted');
    })();
  }, []);

  // Log any non-blocking violations
  useEffect(() => {
    if (violations.length > 0 && deviceFingerprint) {
      mobileSecurityApi.reportViolations(violations, deviceFingerprint);
    }
  }, [violations, deviceFingerprint]);

  const codeScanner = useCodeScanner({
    codeTypes: ['qr', 'ean-13'],
    onCodeScanned: codes => {
      if (codes.length > 0 && isActive && !isProcessing) {
        const value = codes[0].value;
        if (value) {
          handleScan(value);
        }
      }
    },
  });

  const handleScan = async (qrToken: string) => {
    setIsActive(false); // Stop scanning
    setIsProcessing(true);

    try {
      // STEP 1: Get RAW location data with HIGH ACCURACY
      const location = await LocationService.getCurrentLocation();

      // STEP 2: Build payload with RAW DATA ONLY
      // NO status computation, NO radius validation
      // Server will determine EVERYTHING
      const payload: ScanPayload = {
        // QR Token (as-is from scan)
        qr_token: qrToken,

        // RAW Location Data (server validates radius & speed)
        latitude: location.latitude,
        longitude: location.longitude,
        accuracy: location.accuracy,
        altitude: null, // Not available from basic geolocation
        speed: null, // Not available from basic geolocation
        heading: null, // Not available from basic geolocation

        // Security Metadata (server validates & logs)
        is_mocked: location.isMocked || false,
        device_fingerprint: deviceFingerprint || 'unknown',
        security_context: {
          is_secure: isSecure,
          risk_level: riskLevel === 'none' ? 'low' : riskLevel || 'unknown',
          violation_count: violations.length,
          violations: violations.map(v => v.type),
        },

        // Idempotency & Retry Support
        request_id: uuidv4(),

        // Timestamps (for server validation, NOT for record)
        client_timestamp: Date.now(),
        scanned_at: new Date().toISOString(),
      };

      // STEP 3: Send to server (server determines status)
      const response = await attendanceApi.scan(payload);

      // STEP 4: Handle success
      if (response.success) {
        const attendance = response.data;

        // Check if this was an idempotent replay
        if (response._idempotent_replay) {
          Alert.alert(
            'Sudah Tercatat',
            `Absensi Anda sudah tercatat sebelumnya pada ${
              response._original_timestamp
            }.\n\nStatus: ${attendance.status.toUpperCase()}`,
            [{text: 'OK', onPress: () => navigation.navigate('Dashboard')}],
          );
        } else {
          // New attendance record
          Alert.alert(
            'Berhasil!',
            'Absensi berhasil dicatat!\n\n' +
              `Kelas: ${attendance.class}\n` +
              `Mata Pelajaran: ${attendance.subject}\n` +
              `Status: ${attendance.status.toUpperCase()}\n` +
              `Waktu: ${attendance.check_in_time}`,
            [{text: 'OK', onPress: () => navigation.navigate('Dashboard')}],
          );
        }
      } else {
        throw new Error(response.message || 'Gagal mencatat absensi');
      }
    } catch (error: any) {
      console.error('Attendance scan error:', error);

      // Handle offline queue
      if (error.code === 'QUEUED_OFFLINE') {
        Alert.alert(
          'Tersimpan Offline',
          'Tidak ada koneksi internet. Absensi Anda akan dikirim otomatis saat online kembali.',
          [{text: 'OK', onPress: () => navigation.navigate('Dashboard')}],
        );
        return;
      }

      // Handle location errors
      if (
        error.code === 'PERMISSION_DENIED' ||
        error.code === 'POSITION_UNAVAILABLE' ||
        error.code === 'TIMEOUT'
      ) {
        Alert.alert('Error Lokasi', error.message, [
          {text: 'Coba Lagi', onPress: () => resetScanner()},
        ]);
        return;
      }

      // Handle API errors
      const msg =
        error.response?.data?.message ||
        error.message ||
        'Gagal mengirim data absensi';

      Alert.alert('Gagal', msg, [
        {text: 'Coba Lagi', onPress: () => resetScanner()},
        {text: 'Kembali', onPress: () => navigation.goBack()},
      ]);
    } finally {
      setIsProcessing(false);
    }
  };

  const resetScanner = () => {
    setIsActive(true);
    setIsProcessing(false);
  };

  if (!hasPermission) {
    return (
      <View style={styles.center}>
        <Text style={styles.errorText}>Izin kamera diperlukan</Text>
        <TouchableOpacity
          style={styles.retryBtn}
          onPress={() => Camera.requestCameraPermission()}>
          <Text style={styles.btnText}>Minta Izin</Text>
        </TouchableOpacity>
      </View>
    );
  }

  if (!device) {
    return (
      <View style={styles.center}>
        <Text style={styles.errorText}>Kamera tidak tersedia</Text>
      </View>
    );
  }

  return (
    <View style={styles.container}>
      <Camera
        style={StyleSheet.absoluteFill}
        device={device}
        isActive={isActive && !isProcessing}
        codeScanner={codeScanner}
      />

      <View style={styles.overlay}>
        <Text style={styles.text}>Scan QR Code Absensi</Text>

        {/* Security Warning Badge */}
        {!isSecure && (
          <View style={styles.warningBadge}>
            <Text style={styles.warningText}>⚠️ Peringatan Keamanan Aktif</Text>
          </View>
        )}

        {/* Processing Indicator */}
        {isProcessing && (
          <View style={styles.processingBadge}>
            <ActivityIndicator color="#fff" size="small" />
            <Text style={styles.processingText}>Memproses...</Text>
          </View>
        )}

        {/* Cancel Button */}
        <TouchableOpacity
          style={styles.cancelBtn}
          onPress={() => navigation.goBack()}
          disabled={isProcessing}>
          <Text style={styles.btnText}>Batal</Text>
        </TouchableOpacity>
      </View>
    </View>
  );
};

// Wrapped component with security guard
export const ScanQRScreen = ({navigation}: any) => {
  return (
    <SecurityGuard
      mode="strict"
      requiredFeatures={['qr_scan', 'attendance']}
      onSecurityFailure={violations => {
        console.log('Security violations blocked QR scan:', violations);
      }}>
      <ScanQRContent navigation={navigation} />
    </SecurityGuard>
  );
};

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: 'black',
  },
  center: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    backgroundColor: '#1F2937',
    padding: 20,
  },
  overlay: {
    position: 'absolute',
    bottom: 50,
    left: 0,
    right: 0,
    alignItems: 'center',
  },
  text: {
    color: 'white',
    fontSize: 18,
    fontWeight: 'bold',
    marginBottom: 20,
    backgroundColor: 'rgba(0,0,0,0.5)',
    padding: 10,
    borderRadius: 8,
  },
  errorText: {
    color: 'white',
    fontSize: 16,
    textAlign: 'center',
    marginBottom: 20,
  },
  warningBadge: {
    backgroundColor: 'rgba(245, 158, 11, 0.9)',
    paddingHorizontal: 12,
    paddingVertical: 6,
    borderRadius: 16,
    marginBottom: 16,
  },
  warningText: {
    color: 'white',
    fontSize: 12,
    fontWeight: '600',
  },
  processingBadge: {
    backgroundColor: 'rgba(59, 130, 246, 0.9)',
    paddingHorizontal: 16,
    paddingVertical: 8,
    borderRadius: 16,
    marginBottom: 16,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
  },
  processingText: {
    color: 'white',
    fontSize: 14,
    fontWeight: '600',
  },
  cancelBtn: {
    backgroundColor: '#EF4444',
    paddingHorizontal: 30,
    paddingVertical: 12,
    borderRadius: 25,
  },
  retryBtn: {
    backgroundColor: '#3B82F6',
    paddingHorizontal: 30,
    paddingVertical: 12,
    borderRadius: 25,
  },
  btnText: {
    color: 'white',
    fontWeight: 'bold',
    fontSize: 16,
  },
});
