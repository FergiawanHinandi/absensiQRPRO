import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity } from 'react-native';
import { storage } from '../../utils/storage';

export const DashboardScreen = ({ navigation }: any) => {
    const handleLogout = async () => {
        await storage.removeToken();
        await storage.removeUser();
        navigation.replace('Login');
    };

    return (
        <View style={styles.container}>
            <Text style={styles.title}>Dashboard Siswa</Text>
            <View style={styles.content}>
                <Text style={styles.text}>Selamat Datang!</Text>

                <TouchableOpacity
                    style={[styles.button, { backgroundColor: '#3B82F6', marginBottom: 10 }]}
                    onPress={() => navigation.navigate('ScanQR')}
                >
                    <Text style={styles.buttonText}>Scan QR Code</Text>
                </TouchableOpacity>

                <TouchableOpacity style={styles.button} onPress={handleLogout}>
                    <Text style={styles.buttonText}>Logout</Text>
                </TouchableOpacity>
            </View>
        </View>
    );
};

const styles = StyleSheet.create({
    container: {
        flex: 1,
        backgroundColor: '#F3F4F6',
        padding: 20,
    },
    title: {
        fontSize: 24,
        fontWeight: 'bold',
        marginBottom: 20,
        color: '#111827',
    },
    content: {
        backgroundColor: 'white',
        borderRadius: 12,
        padding: 20,
        alignItems: 'center',
    },
    text: {
        fontSize: 18,
        marginBottom: 20,
        color: '#4B5563',
    },
    button: {
        backgroundColor: '#EF4444',
        paddingHorizontal: 20,
        paddingVertical: 10,
        borderRadius: 8,
    },
    buttonText: {
        color: 'white',
        fontWeight: 'bold',
    },
});
