import React from 'react';
import {
    View,
    Text,
    StyleSheet,
    TouchableOpacity,
    SafeAreaView,
} from 'react-native';
import { useAuthStore } from '../../store/useAuthStore';

export const StudentDashboard = ({ navigation }: any) => {
    const { user, logout } = useAuthStore();

    return (
        <SafeAreaView style={styles.container}>
            <View style={styles.header}>
                <Text style={styles.greeting}>Halo, {user?.name}!</Text>
                <Text style={styles.role}>Siswa</Text>
            </View>

            <View style={styles.content}>
                <TouchableOpacity
                    style={styles.scanButton}
                    onPress={() => navigation.navigate('ScanQR')}
                >
                    <Text style={styles.scanButtonText}>📱 Scan QR Absensi</Text>
                </TouchableOpacity>

                <TouchableOpacity
                    style={styles.menuButton}
                    onPress={() => navigation.navigate('History')}
                >
                    <Text style={styles.menuButtonText}>📊 Riwayat Absensi</Text>
                </TouchableOpacity>

                <TouchableOpacity
                    style={[styles.menuButton, styles.logoutButton]}
                    onPress={async () => {
                        await logout();
                    }}
                >
                    <Text style={styles.logoutButtonText}>🚪 Keluar</Text>
                </TouchableOpacity>
            </View>
        </SafeAreaView>
    );
};

const styles = StyleSheet.create({
    container: {
        flex: 1,
        backgroundColor: '#f5f5f5',
    },
    header: {
        backgroundColor: '#2563eb',
        padding: 24,
        paddingTop: 40,
    },
    greeting: {
        fontSize: 24,
        fontWeight: 'bold',
        color: '#fff',
    },
    role: {
        fontSize: 14,
        color: '#bfdbfe',
        marginTop: 4,
    },
    content: {
        flex: 1,
        padding: 20,
    },
    scanButton: {
        backgroundColor: '#2563eb',
        padding: 24,
        borderRadius: 12,
        alignItems: 'center',
        marginBottom: 16,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 2 },
        shadowOpacity: 0.1,
        shadowRadius: 8,
        elevation: 5,
    },
    scanButtonText: {
        color: '#fff',
        fontSize: 20,
        fontWeight: 'bold',
    },
    menuButton: {
        backgroundColor: '#fff',
        padding: 16,
        borderRadius: 8,
        marginBottom: 12,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 1 },
        shadowOpacity: 0.05,
        shadowRadius: 4,
        elevation: 2,
    },
    menuButtonText: {
        fontSize: 16,
        color: '#1f2937',
        fontWeight: '500',
    },
    logoutButton: {
        marginTop: 'auto',
        backgroundColor: '#fee2e2',
    },
    logoutButtonText: {
        fontSize: 16,
        color: '#dc2626',
        fontWeight: '600',
    },
});
