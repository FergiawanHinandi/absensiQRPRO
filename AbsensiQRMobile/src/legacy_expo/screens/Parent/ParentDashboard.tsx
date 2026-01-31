import React from 'react';
import { View, Text, StyleSheet, TouchableOpacity, SafeAreaView, FlatList, ActivityIndicator, RefreshControl } from 'react-native';
import { useAuthStore } from '../../store/useAuthStore';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';

const fetchChildren = async () => {
    const { data } = await api.get('/v1/parent/my-children');
    return data;
};

export const ParentDashboard = ({ navigation }: any) => {
    const { user, logout } = useAuthStore();
    const { data, isLoading, refetch, isRefetching } = useQuery({
        queryKey: ['my-children'],
        queryFn: fetchChildren,
    });

    const renderChild = ({ item }: { item: any }) => (
        <View style={styles.childCard}>
            <View style={styles.childAvatar}>
                <Text style={styles.avatarText}>{item.name.charAt(0).toUpperCase()}</Text>
            </View>
            <View style={styles.childInfo}>
                <Text style={styles.childName}>{item.name}</Text>
                <Text style={styles.childStatus}>
                    {item.class_name} • {item.latest_attendance ? 'Hadir Hari Ini' : 'Belum Absen'}
                </Text>
            </View>
            <TouchableOpacity 
                style={styles.viewButton}
                onPress={() => navigation.navigate('ChildHistory', { childId: item.id, childName: item.name })}
            >
                <Text style={styles.viewButtonText}>Lihat</Text>
            </TouchableOpacity>
        </View>
    );

    return (
        <SafeAreaView style={styles.container}>
            <View style={styles.header}>
                <Text style={styles.greeting}>Halo, Orang Tua</Text>
                <Text style={styles.name}>{user?.name}</Text>
            </View>

            <View style={styles.content}>
                <Text style={styles.sectionTitle}>Anak Saya</Text>
                
                {isLoading ? (
                    <ActivityIndicator size="large" color="#8b5cf6" />
                ) : (
                    <FlatList
                        data={data?.data || []}
                        keyExtractor={(item) => item.id.toString()}
                        renderItem={renderChild}
                        refreshControl={
                            <RefreshControl refreshing={isRefetching} onRefresh={refetch} />
                        }
                        ListEmptyComponent={
                            <Text style={styles.emptyText}>Tidak ada data anak ditemukan.</Text>
                        }
                    />
                )}

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
        backgroundColor: '#8b5cf6', // Violet for parents
        padding: 24,
        paddingTop: 40,
    },
    greeting: {
        fontSize: 16,
        color: '#ede9fe',
    },
    name: {
        fontSize: 24,
        fontWeight: 'bold',
        color: '#fff',
    },
    content: {
        flex: 1,
        padding: 20,
    },
    sectionTitle: {
        fontSize: 18,
        fontWeight: 'bold',
        color: '#1f2937',
        marginBottom: 16,
    },
    childCard: {
        backgroundColor: '#fff',
        padding: 16,
        borderRadius: 16,
        flexDirection: 'row',
        alignItems: 'center',
        elevation: 2,
        marginBottom: 16,
    },
    childAvatar: {
        width: 48,
        height: 48,
        backgroundColor: '#f3e8ff',
        borderRadius: 24,
        justifyContent: 'center',
        alignItems: 'center',
        marginRight: 16,
    },
    avatarText: {
        fontSize: 20,
        fontWeight: 'bold',
        color: '#7c3aed',
    },
    childInfo: {
        flex: 1,
    },
    childName: {
        fontSize: 16,
        fontWeight: '600',
        color: '#1f2937',
    },
    childStatus: {
        fontSize: 14,
        color: '#16a34a',
        marginTop: 2,
    },
    viewButton: {
        backgroundColor: '#f3f4f6',
        paddingHorizontal: 12,
        paddingVertical: 6,
        borderRadius: 8,
    },
    viewButtonText: {
        fontSize: 12,
        fontWeight: '600',
        color: '#4b5563',
    },
    menuButton: {
        backgroundColor: '#fff',
        padding: 16,
        borderRadius: 12,
        elevation: 2,
        marginTop: 'auto',
    },
    logoutButton: {
        backgroundColor: '#fee2e2',
        marginTop: 'auto',
    },
    logoutButtonText: {
        fontSize: 16,
        color: '#dc2626',
        fontWeight: '600',
        textAlign: 'center',
    },
});
