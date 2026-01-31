import React from 'react';
import { View, Text, StyleSheet, FlatList, RefreshControl } from 'react-native';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';

const fetchHistory = async () => {
    const { data } = await api.get('/v1/attendance/history');
    return data;
};

export const AttendanceHistoryScreen = () => {
    const { data, isLoading, refetch, isRefetching } = useQuery({
        queryKey: ['attendance-history'],
        queryFn: fetchHistory,
    });

    const renderItem = ({ item }: { item: any }) => (
        <View style={styles.card}>
            <View style={styles.cardHeader}>
                <Text style={styles.date}>
                    {format(new Date(item.created_at), 'EEEE, d MMMM yyyy', { locale: id })}
                </Text>
                <View style={[
                    styles.statusBadge, 
                    { backgroundColor: item.status === 'present' ? '#dcfce7' : '#fee2e2' }
                ]}>
                    <Text style={[
                        styles.statusText,
                        { color: item.status === 'present' ? '#166534' : '#991b1b' }
                    ]}>
                        {item.status === 'present' ? 'Hadir' : 'Absen'}
                    </Text>
                </View>
            </View>
            <View style={styles.cardBody}>
                <Text style={styles.time}>
                    🕒 {format(new Date(item.created_at), 'HH:mm')} WIB
                </Text>
                <Text style={styles.location}>
                    📍 {item.latitude}, {item.longitude}
                </Text>
            </View>
        </View>
    );

    return (
        <View style={styles.container}>
            <FlatList
                data={data?.data || []} // Adjust based on actual API response structure
                keyExtractor={(item) => item.id.toString()}
                renderItem={renderItem}
                contentContainerStyle={styles.listContent}
                refreshControl={
                    <RefreshControl refreshing={isLoading || isRefetching} onRefresh={refetch} />
                }
                ListEmptyComponent={
                    !isLoading ? (
                        <View style={styles.emptyContainer}>
                            <Text style={styles.emptyText}>Belum ada riwayat absensi.</Text>
                        </View>
                    ) : null
                }
            />
        </View>
    );
};

const styles = StyleSheet.create({
    container: {
        flex: 1,
        backgroundColor: '#f5f5f5',
    },
    listContent: {
        padding: 16,
    },
    card: {
        backgroundColor: '#fff',
        borderRadius: 12,
        padding: 16,
        marginBottom: 12,
        shadowColor: '#000',
        shadowOffset: { width: 0, height: 1 },
        shadowOpacity: 0.05,
        shadowRadius: 4,
        elevation: 2,
    },
    cardHeader: {
        flexDirection: 'row',
        justifyContent: 'space-between',
        alignItems: 'center',
        marginBottom: 12,
    },
    date: {
        fontSize: 16,
        fontWeight: '600',
        color: '#1f2937',
    },
    statusBadge: {
        paddingHorizontal: 8,
        paddingVertical: 4,
        borderRadius: 6,
    },
    statusText: {
        fontSize: 12,
        fontWeight: '600',
    },
    cardBody: {
        gap: 8,
    },
    time: {
        fontSize: 14,
        color: '#4b5563',
    },
    location: {
        fontSize: 12,
        color: '#6b7280',
    },
    emptyContainer: {
        padding: 32,
        alignItems: 'center',
    },
    emptyText: {
        color: '#6b7280',
        fontSize: 14,
    },
});
