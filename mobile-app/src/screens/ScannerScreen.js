import React, { useState } from 'react';
import {
  View,
  Text,
  TouchableOpacity,
  StyleSheet,
  Alert,
  ActivityIndicator,
} from 'react-native';
import QRCodeScanner from 'react-native-qrcode-scanner';
import { RNCamera } from 'react-native-camera';
import api from '../api';

const ScannerScreen = ({ navigation, route }) => {
  const { teacher } = route.params;
  const [scanning, setScanning] = useState(true);
  const [loading, setLoading] = useState(false);

  const onSuccess = async (e) => {
    if (!scanning) return;

    setScanning(false);
    setLoading(true);

    // Scan QR code and get student info
    const scanResult = await api.scanQRCode(e.data);

    if (scanResult.success) {
      const student = scanResult.data;

      // Show confirmation dialog
      Alert.alert(
        'Konfirmasi Absensi',
        `Nama: ${student.name}\nNIS: ${student.nis}\nKelas: ${student.class}\n\nTandai kehadiran?`,
        [
          {
            text: 'Batal',
            onPress: () => {
              setLoading(false);
              setScanning(true);
            },
            style: 'cancel',
          },
          {
            text: 'Ya, Hadir',
            onPress: async () => {
              await markAttendance(student.id, 'present');
            },
          },
          {
            text: 'Terlambat',
            onPress: async () => {
              await markAttendance(student.id, 'late');
            },
          },
        ]
      );
    } else {
      Alert.alert('Error', scanResult.message || 'Siswa tidak ditemukan');
      setLoading(false);
      setScanning(true);
    }
  };

  const markAttendance = async (studentId, status) => {
    const result = await api.markAttendance(studentId, teacher.id, status);

    if (result.success) {
      Alert.alert('Sukses', 'Absensi berhasil dicatat', [
        {
          text: 'OK',
          onPress: () => {
            setLoading(false);
            setScanning(true);
          },
        },
      ]);
    } else {
      Alert.alert('Error', result.message || 'Gagal mencatat absensi');
      setLoading(false);
      setScanning(true);
    }
  };

  return (
    <View style={styles.container}>
      <QRCodeScanner
        onRead={onSuccess}
        reactivate={scanning}
        reactivateTimeout={3000}
        flashMode={RNCamera.Constants.FlashMode.off}
        topContent={
          <View style={styles.topContent}>
            <Text style={styles.title}>Scan Kartu Pelajar Siswa</Text>
            <Text style={styles.subtitle}>
              Arahkan kamera ke QR Code pada kartu pelajar
            </Text>
          </View>
        }
        bottomContent={
          <View style={styles.bottomContent}>
            {loading && (
              <View style={styles.loadingContainer}>
                <ActivityIndicator size="large" color="#3498db" />
                <Text style={styles.loadingText}>Memproses...</Text>
              </View>
            )}
            <TouchableOpacity
              style={styles.backButton}
              onPress={() => navigation.goBack()}
            >
              <Text style={styles.backButtonText}>Kembali</Text>
            </TouchableOpacity>
          </View>
        }
        cameraStyle={styles.camera}
      />
    </View>
  );
};

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#000',
  },
  topContent: {
    padding: 20,
    backgroundColor: '#fff',
    alignItems: 'center',
  },
  title: {
    fontSize: 24,
    fontWeight: 'bold',
    color: '#2c3e50',
    marginBottom: 10,
  },
  subtitle: {
    fontSize: 16,
    color: '#7f8c8d',
    textAlign: 'center',
  },
  bottomContent: {
    padding: 20,
    backgroundColor: '#fff',
    alignItems: 'center',
  },
  loadingContainer: {
    alignItems: 'center',
    marginBottom: 20,
  },
  loadingText: {
    fontSize: 16,
    color: '#3498db',
    marginTop: 10,
  },
  backButton: {
    backgroundColor: '#3498db',
    paddingHorizontal: 40,
    paddingVertical: 15,
    borderRadius: 5,
  },
  backButtonText: {
    color: '#fff',
    fontSize: 18,
    fontWeight: 'bold',
  },
  camera: {
    height: 400,
  },
});

export default ScannerScreen;
