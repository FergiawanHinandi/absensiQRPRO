import React, {useEffect, useState} from 'react';
import {
  View,
  Text,
  StyleSheet,
  ScrollView,
  TouchableOpacity,
  RefreshControl,
} from 'react-native';
import {useAuth} from '../../contexts/AuthContext';
import apiClient from '../../api/client';

interface Child {
  id: number;
  name: string;
  class_name: string;
  nis: string;
  attendance_rate?: number;
  today_status?: string;
}

export const ParentDashboardScreen = ({navigation}: any) => {
  const {user, logout} = useAuth();
  const [children, setChildren] = useState<Child[]>([]);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const fetchChildren = async () => {
    try {
      setError(null);
      // Endpoint backend adalah /parent/my-children
      const response = await apiClient.get('/parent/my-children');
      if (response.data?.success) {
        setChildren(response.data.data || []);
      }
    } catch (err: any) {
      const message =
        err?.response?.data?.message ||
        err?.message ||
        'Gagal memuat data anak';
      setError(message);
      console.log('Failed to fetch children:', err);
    }
  };

  useEffect(() => {
    fetchChildren();
  }, []);

  const onRefresh = async () => {
    setRefreshing(true);
    await fetchChildren();
    setRefreshing(false);
  };

  const getStatusColor = (status?: string) => {
    switch (status) {
      case 'present':
        return {bg: '#D1FAE5', text: '#065F46', label: 'Hadir'};
      case 'late':
        return {bg: '#FEF3C7', text: '#92400E', label: 'Terlambat'};
      case 'absent':
      case 'alpha':
        return {bg: '#FEE2E2', text: '#991B1B', label: 'Alpa'};
      default:
        return {bg: '#F3F4F6', text: '#6B7280', label: 'Belum Absen'};
    }
  };

  return (
    <ScrollView
      style={styles.container}
      refreshControl={
        <RefreshControl refreshing={refreshing} onRefresh={onRefresh} />
      }>
      <View style={styles.header}>
        <Text style={styles.greeting}>
          Halo, {user?.name || 'Orang Tua'}! 👋
        </Text>
        <Text style={styles.role}>Orang Tua/Wali</Text>
      </View>

      {error && (
        <Text
          style={{
            color: '#DC2626',
            textAlign: 'center',
            padding: 10,
            backgroundColor: '#FEE2E2',
            borderRadius: 8,
            marginBottom: 12,
          }}>
          {error}
        </Text>
      )}

      <View style={styles.card}>
        <Text style={styles.cardTitle}>Anak Saya</Text>
        {children.length > 0 ? (
          children.map(child => {
            const statusInfo = getStatusColor(child.today_status);
            return (
              <TouchableOpacity
                key={child.id}
                style={styles.childCard}
                onPress={() =>
                  navigation.navigate('ParentChildDetail', {
                    childId: child.id,
                    childName: child.name,
                  })
                }>
                <View style={styles.childAvatar}>
                  <Text style={styles.childAvatarText}>
                    {child.name.charAt(0).toUpperCase()}
                  </Text>
                </View>
                <View style={styles.childInfo}>
                  <Text style={styles.childName}>{child.name}</Text>
                  <Text style={styles.childClass}>
                    {child.class_name} • NIS: {child.nis}
                  </Text>
                  {child.attendance_rate !== undefined && (
                    <Text style={styles.childRate}>
                      Kehadiran: {child.attendance_rate}%
                    </Text>
                  )}
                </View>
                <View
                  style={[
                    styles.statusBadge,
                    {backgroundColor: statusInfo.bg},
                  ]}>
                  <Text style={[styles.statusText, {color: statusInfo.text}]}>
                    {statusInfo.label}
                  </Text>
                </View>
              </TouchableOpacity>
            );
          })
        ) : (
          <Text style={styles.noData}>Belum ada data anak terdaftar</Text>
        )}
      </View>

      <View style={styles.card}>
        <TouchableOpacity
          style={[styles.actionButton, {backgroundColor: '#8B5CF6'}]}
          onPress={() => navigation.navigate('ParentProfile')}>
          <Text style={styles.actionText}>👤 Profil Saya</Text>
        </TouchableOpacity>

        <TouchableOpacity
          style={[styles.actionButton, {backgroundColor: '#EF4444'}]}
          onPress={logout}>
          <Text style={styles.actionText}>🚪 Keluar</Text>
        </TouchableOpacity>
      </View>
    </ScrollView>
  );
};

const styles = StyleSheet.create({
  container: {flex: 1, backgroundColor: '#F3F4F6', padding: 16},
  header: {
    backgroundColor: '#8B5CF6',
    borderRadius: 16,
    padding: 20,
    marginBottom: 16,
  },
  greeting: {fontSize: 22, fontWeight: 'bold', color: '#fff'},
  role: {fontSize: 14, color: '#DDD6FE', marginTop: 4},
  card: {
    backgroundColor: '#fff',
    borderRadius: 12,
    padding: 16,
    marginBottom: 16,
    shadowColor: '#000',
    shadowOffset: {width: 0, height: 1},
    shadowOpacity: 0.05,
    shadowRadius: 3,
    elevation: 2,
  },
  cardTitle: {
    fontSize: 16,
    fontWeight: '700',
    color: '#111827',
    marginBottom: 12,
  },
  childCard: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: 12,
    borderBottomWidth: 1,
    borderBottomColor: '#F3F4F6',
  },
  childAvatar: {
    width: 44,
    height: 44,
    borderRadius: 22,
    backgroundColor: '#8B5CF6',
    justifyContent: 'center',
    alignItems: 'center',
  },
  childAvatarText: {fontSize: 18, fontWeight: 'bold', color: '#fff'},
  childInfo: {flex: 1, marginLeft: 12},
  childName: {fontSize: 15, fontWeight: '600', color: '#111827'},
  childClass: {fontSize: 12, color: '#6B7280', marginTop: 2},
  childRate: {fontSize: 12, color: '#10B981', marginTop: 2},
  statusBadge: {paddingHorizontal: 8, paddingVertical: 4, borderRadius: 6},
  statusText: {fontSize: 11, fontWeight: '700'},
  noData: {
    fontSize: 14,
    color: '#9CA3AF',
    fontStyle: 'italic',
    textAlign: 'center',
    paddingVertical: 20,
  },
  actionButton: {
    borderRadius: 10,
    padding: 14,
    marginBottom: 8,
    alignItems: 'center',
  },
  actionText: {color: '#fff', fontSize: 15, fontWeight: '600'},
});
