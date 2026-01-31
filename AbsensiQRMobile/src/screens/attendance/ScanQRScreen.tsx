import React, { useState, useEffect } from 'react';
import { StyleSheet, View, Text, Alert, TouchableOpacity } from 'react-native';
import { Camera, useCameraDevice, useCodeScanner } from 'react-native-vision-camera';
import { getValidatedLocation, LocationError } from '../../utils/locationValidator';
import apiClient from '../../api/client';
import { SecurityGuard, useDeviceSecurity } from '../../components/SecurityGuard';
import { mobileSecurityApi } from '../../api/mobileSecurityApi';

// Inner component that handles QR scanning
const ScanQRContent = ({ navigation }: any) => {
    const device = useCameraDevice('back');
    const [hasPermission, setHasPermission] = useState(false);
    const [isActive, setIsActive] = useState(true);
    const { deviceFingerprint, isSecure, violations } = useDeviceSecurity();

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
        onCodeScanned: (codes) => {
            if (codes.length > 0 && isActive) {
                const value = codes[0].value;
                if (value) handleScan(value);
            }
        },
    });

    const handleScan = async (qrCode: string) => {
        setIsActive(false); // Stop scanning

        try {
            // SECURITY: Get validated location with spoofing detection
            const location = await getValidatedLocation();

            // Include device fingerprint for backend verification
            const response = await apiClient.post('/attendance/scan', {
                qr_code: qrCode,
                latitude: location.latitude,
                longitude: location.longitude,
                device_fingerprint: deviceFingerprint,
            });

            if (response.data.success) {
                Alert.alert('Berhasil', 'Absensi berhasil dicatat!', [
                    { text: 'OK', onPress: () => navigation.navigate('Dashboard') }
                ]);
            } else {
                throw new Error(response.data.message || 'Gagal absen');
            }
        } catch (error: any) {
            // Handle location errors specifically
            if (error.code === 'MOCK_LOCATION') {
                Alert.alert('Lokasi Palsu Terdeteksi', error.message, [
                    { text: 'OK', onPress: () => navigation.goBack() }
                ]);
                return;
            }
            
            if (error.code === 'PERMISSION_DENIED' || error.code === 'POSITION_UNAVAILABLE' || error.code === 'TIMEOUT') {
                Alert.alert('Error Lokasi', error.message, [
                    { text: 'Coba Lagi', onPress: () => setIsActive(true) }
                ]);
                return;
            }

            // Handle API errors
            const msg = error.response?.data?.message || error.message || 'Gagal mengirim data absensi';
            Alert.alert('Gagal', msg, [
                { text: 'Coba Lagi', onPress: () => setIsActive(true) },
                { text: 'Kembali', onPress: () => navigation.goBack() }
            ]);
        }
    };

    if (!hasPermission) return <View style={styles.center}><Text>No Camera Permission</Text></View>;
    if (!device) return <View style={styles.center}><Text>No Camera Device</Text></View>;

    return (
        <View style={styles.container}>
            <Camera
                style={StyleSheet.absoluteFill}
                device={device}
                isActive={isActive}
                codeScanner={codeScanner}
            />
            <View style={styles.overlay}>
                <Text style={styles.text}>Scan QR Code Absensi</Text>
                {!isSecure && (
                    <View style={styles.warningBadge}>
                        <Text style={styles.warningText}>⚠️ Peringatan Keamanan Aktif</Text>
                    </View>
                )}
                <TouchableOpacity style={styles.cancelBtn} onPress={() => navigation.goBack()}>
                    <Text style={styles.btnText}>Batal</Text>
                </TouchableOpacity>
            </View>
        </View>
    );
};

// Wrapped component with security guard
export const ScanQRScreen = ({ navigation }: any) => {
    return (
        <SecurityGuard
            mode="strict"
            requiredFeatures={['qr_scan', 'attendance']}
            onSecurityFailure={(violations) => {
                console.log('Security violations blocked QR scan:', violations);
            }}
        >
            <ScanQRContent navigation={navigation} />
        </SecurityGuard>
    );
};

const styles = StyleSheet.create({
    container: { flex: 1, backgroundColor: 'black' },
    center: { flex: 1, justifyContent: 'center', alignItems: 'center' },
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
    cancelBtn: {
        backgroundColor: '#EF4444',
        paddingHorizontal: 30,
        paddingVertical: 12,
        borderRadius: 25,
    },
    btnText: { color: 'white', fontWeight: 'bold' }
});
