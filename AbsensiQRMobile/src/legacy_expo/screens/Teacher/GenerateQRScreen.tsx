import React, { useState } from 'react';
import { View, Text, StyleSheet, TouchableOpacity, SafeAreaView, ScrollView, ActivityIndicator, Alert } from 'react-native';
import QRCode from 'react-native-qrcode-svg';
import { useQuery, useMutation } from '@tanstack/react-query';
import api from '../../services/api';
import { useAuthStore } from '../../store/useAuthStore';
import { Ionicons } from '@expo/vector-icons';

const fetchTodaySchedules = async () => {
    const { data } = await api.get('/v1/teacher/schedules/today');
    return data.schedules;
};

const generateQR = async (scheduleId: number) => {
    const { data } = await api.post('/v1/qr/generate', {
        schedule_id: scheduleId,
        qr_type: 'in', // default to check-in
        expiry_minutes: 15,
    });
    return data;
};

const closeQR = async (qrId: number) => {
    const { data } = await api.post('/v1/qr/close', {
        qr_code_id: qrId,
    });
    return data;
};

export const GenerateQRScreen = ({ navigation }: any) => {
    const [selectedSchedule, setSelectedSchedule] = useState<any>(null);
    const [qrData, setQrData] = useState<any>(null);

    const { data: schedules, isLoading: isLoadingSchedules } = useQuery({
        queryKey: ['teacher-schedules-today'],
        queryFn: fetchTodaySchedules,
    });

    const generateMutation = useMutation({
        mutationFn: generateQR,
        onSuccess: (data) => {
            setQrData(data.qr_code);
            Alert.alert('Sukses', 'QR Code berhasil dibuat');
        },
        onError: (error: any) => {
            Alert.alert('Error', error.response?.data?.message || 'Gagal membuat QR Code');
        },
    });

    const closeMutation = useMutation({
        mutationFn: closeQR,
        onSuccess: () => {
            setQrData(null);
            setSelectedSchedule(null);
            Alert.alert('Sukses', 'QR Code ditutup');
        },
        onError: (error: any) => {
            Alert.alert('Error', error.response?.data?.message || 'Gagal menutup QR Code');
        },
    });

    const handleSelectSchedule = (schedule: any) => {
        setSelectedSchedule(schedule);
        // Reset previous QR if any
        setQrData(null);
    };

    const handleGenerate = () => {
        if (!selectedSchedule) return;
        generateMutation.mutate(selectedSchedule.id);
    };

    const handleClose = () => {
        if (!qrData) return;
        closeMutation.mutate(qrData.id);
    };

    return (
        <SafeAreaView style={styles.container}>
            <View style={styles.header}>
                <TouchableOpacity onPress={() => navigation.goBack()} style={styles.backButton}>
                    <Ionicons name="arrow-back" size={24} color="#333" />
                </TouchableOpacity>
                <Text style={styles.headerTitle}>Generate QR Absensi</Text>
            </View>

            <ScrollView contentContainerStyle={styles.content}>
                {/* Step 1: Select Schedule */}
                <Text style={styles.sectionTitle}>1. Pilih Jadwal</Text>
                
                {isLoadingSchedules ? (
                    <ActivityIndicator size="large" color="#0ea5e9" />
                ) : schedules?.length === 0 ? (
                    <Text style={styles.emptyText}>Tidak ada jadwal hari ini.</Text>
                ) : (
                    <View style={styles.scheduleList}>
                        {schedules?.map((schedule: any) => (
                            <TouchableOpacity
                                key={schedule.id}
                                style={[
                                    styles.scheduleItem,
                                    selectedSchedule?.id === schedule.id && styles.selectedSchedule
                                ]}
                                onPress={() => handleSelectSchedule(schedule)}
                                disabled={!!qrData} // Disable selection if QR is active
                            >
                                <Text style={styles.scheduleSubject}>{schedule.subject?.name || 'Mata Pelajaran'}</Text>
                                <Text style={styles.scheduleTime}>
                                    {schedule.start_time} - {schedule.end_time}
                                </Text>
                                <Text style={styles.scheduleClass}>{schedule.class?.name}</Text>
                            </TouchableOpacity>
                        ))}
                    </View>
                )}

                {/* Step 2: Generate/Show QR */}
                {selectedSchedule && (
                    <View style={styles.qrSection}>
                        <Text style={styles.sectionTitle}>2. QR Code</Text>
                        
                        {qrData ? (
                            <View style={styles.qrContainer}>
                                <Text style={styles.qrInstruction}>
                                    Scan QR ini untuk absensi
                                </Text>
                                <View style={styles.qrWrapper}>
                                    <QRCode
                                        value={qrData.token}
                                        size={200}
                                    />
                                </View>
                                <Text style={styles.qrTimer}>
                                    Valid sampai: {new Date(qrData.valid_until).toLocaleTimeString()}
                                </Text>
                                
                                <TouchableOpacity 
                                    style={styles.closeButton}
                                    onPress={handleClose}
                                    disabled={closeMutation.isPending}
                                >
                                    {closeMutation.isPending ? (
                                        <ActivityIndicator color="#fff" />
                                    ) : (
                                        <Text style={styles.closeButtonText}>Tutup Sesi QR</Text>
                                    )}
                                </TouchableOpacity>
                            </View>
                        ) : (
                            <TouchableOpacity 
                                style={styles.generateButton}
                                onPress={handleGenerate}
                                disabled={generateMutation.isPending}
                            >
                                {generateMutation.isPending ? (
                                    <ActivityIndicator color="#fff" />
                                ) : (
                                    <Text style={styles.generateButtonText}>Generate QR Code</Text>
                                )}
                            </TouchableOpacity>
                        )}
                    </View>
                )}
            </ScrollView>
        </SafeAreaView>
    );
};

const styles = StyleSheet.create({
    container: {
        flex: 1,
        backgroundColor: '#f5f5f5',
    },
    header: {
        flexDirection: 'row',
        alignItems: 'center',
        padding: 16,
        backgroundColor: '#fff',
        elevation: 2,
    },
    backButton: {
        marginRight: 16,
    },
    headerTitle: {
        fontSize: 20,
        fontWeight: 'bold',
        color: '#333',
    },
    content: {
        padding: 20,
    },
    sectionTitle: {
        fontSize: 18,
        fontWeight: 'bold',
        marginBottom: 12,
        color: '#1f2937',
    },
    emptyText: {
        color: '#6b7280',
        fontStyle: 'italic',
    },
    scheduleList: {
        marginBottom: 24,
    },
    scheduleItem: {
        backgroundColor: '#fff',
        padding: 16,
        borderRadius: 12,
        marginBottom: 10,
        borderWidth: 1,
        borderColor: 'transparent',
        elevation: 1,
    },
    selectedSchedule: {
        borderColor: '#0ea5e9',
        backgroundColor: '#e0f2fe',
    },
    scheduleSubject: {
        fontSize: 16,
        fontWeight: 'bold',
        color: '#1f2937',
    },
    scheduleTime: {
        fontSize: 14,
        color: '#4b5563',
        marginTop: 4,
    },
    scheduleClass: {
        fontSize: 14,
        color: '#0ea5e9',
        marginTop: 4,
        fontWeight: '500',
    },
    qrSection: {
        alignItems: 'center',
    },
    qrContainer: {
        backgroundColor: '#fff',
        padding: 24,
        borderRadius: 16,
        alignItems: 'center',
        width: '100%',
        elevation: 3,
    },
    qrInstruction: {
        fontSize: 16,
        color: '#4b5563',
        marginBottom: 20,
        textAlign: 'center',
    },
    qrWrapper: {
        padding: 10,
        backgroundColor: '#fff',
        borderRadius: 8,
    },
    qrTimer: {
        marginTop: 16,
        color: '#dc2626',
        fontWeight: 'bold',
    },
    generateButton: {
        backgroundColor: '#0ea5e9',
        paddingVertical: 14,
        paddingHorizontal: 32,
        borderRadius: 30,
        width: '100%',
        alignItems: 'center',
    },
    generateButtonText: {
        color: '#fff',
        fontSize: 16,
        fontWeight: 'bold',
    },
    closeButton: {
        backgroundColor: '#ef4444',
        paddingVertical: 12,
        paddingHorizontal: 24,
        borderRadius: 30,
        marginTop: 20,
        width: '100%',
        alignItems: 'center',
    },
    closeButtonText: {
        color: '#fff',
        fontSize: 16,
        fontWeight: 'bold',
    },
});
