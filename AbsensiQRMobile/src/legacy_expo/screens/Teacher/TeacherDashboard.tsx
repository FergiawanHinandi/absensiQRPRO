import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity, SafeAreaView } from 'react-native';
import { useAuthStore } from '../../store/useAuthStore';

export const TeacherDashboard = ({ navigation }: any) => {
    const { user, logout } = useAuthStore();

    return (
        <SafeAreaView style={styles.container}>
            <View style={styles.header}>
                <Text style={styles.greeting}>Selamat Datang,</Text>
                <Text style={styles.name}>{user?.name}</Text>
                <Text style={styles.role}>Guru</Text>
            </View>

            <View style={styles.content}>
                <View style={styles.card}>
                    <Text style={styles.cardTitle}>Jadwal Hari Ini</Text>
                    <Text style={styles.cardContent}>Anda belum memiliki jadwal aktif.</Text>
                </View>

                <TouchableOpacity 
                    style={styles.menuButton}
                    onPress={() => navigation.navigate('GenerateQR')}
                >
                    <Text style={styles.menuButtonText}>📱 Buat QR Code Absensi</Text>
                </TouchableOpacity>

                <TouchableOpacity 
                    style={styles.menuButton}
                    onPress={() => console.log('Navigate to Manual Attendance')}
                >
                    <Text style={styles.menuButtonText}>📝 Input Absensi Manual</Text>
                </TouchableOpacity>

                <TouchableOpacity 
                    style={[styles.menuButton, styles.logoutButton]}
                    onPress={async () => await logout()}
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
        backgroundColor: '#0ea5e9', // Sky blue for teachers
        padding: 24,
        paddingTop: 40,
    },
    greeting: {
        fontSize: 16,
        color: '#e0f2fe',
    },
    name: {
        fontSize: 24,
        fontWeight: 'bold',
        color: '#fff',
    },
    role: {
        fontSize: 14,
        color: '#bae6fd',
        marginTop: 4,
        fontWeight: '600',
    },
    content: {
        flex: 1,
        padding: 20,
        gap: 16,
    },
    card: {
        backgroundColor: '#fff',
        padding: 20,
        borderRadius: 12,
        marginBottom: 8,
        elevation: 2,
    },
    cardTitle: {
        fontSize: 16,
        fontWeight: 'bold',
        marginBottom: 8,
        color: '#1f2937',
    },
    cardContent: {
        color: '#6b7280',
    },
    menuButton: {
        backgroundColor: '#fff',
        padding: 16,
        borderRadius: 12,
        elevation: 2,
    },
    menuButtonText: {
        fontSize: 16,
        color: '#0284c7',
        fontWeight: '600',
    },
    logoutButton: {
        marginTop: 'auto',
        backgroundColor: '#fee2e2',
    },
    logoutButtonText: {
        fontSize: 16,
        color: '#dc2626',
        fontWeight: '600',
        textAlign: 'center',
    },
});
