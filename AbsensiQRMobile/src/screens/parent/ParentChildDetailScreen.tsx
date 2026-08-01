import React, {useEffect, useState} from 'react';
import {View, Text, StyleSheet, FlatList, RefreshControl} from 'react-native';
import apiClient from '../../api/client';

interface AttendanceRecord {
  id: number;
  attendance_date: string;
  subject: string;
  status: string;
  check_in_time: string;
}

const statusConfig: Record<string, {label: string; bg: string; color: string}> =
  {
    present: {label: 'Hadir', bg: '#D1FAE5', color: '#065F46'},
    late: {label: 'Terlambat', bg: '#FEF3C7', color: '#92400E'},
    sick: {label: 'Sakit', bg: '#DBEAFE', color: '#1E40AF'},
    permit: {label: 'Izin', bg: '#F3E8FF', color: '#6B21A8'},
    alpha: {label: 'Alpa', bg: '#FEE2E2', color: '#991B1B'},
  };

export const ParentChildDetailScreen = ({route}: any) => {
  const {childId, childName} = route.params;
  const [records, setRecords] = useState<AttendanceRecord[]>([]);
  const [refreshing, setRefreshing] = useState(false);
  const [loading, setLoading] = useState(true);

  const fetchHistory = async () => {
    try {
      const response = await apiClient.get(
        `/parent/children/${childId}/attendance`,
      );
      if (response.data?.success) {
        setRecords(response.data.data || []);
      }
    } catch (error) {
      console.log('Failed to fetch child attendance:', error);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchHistory();
  }, [childId]);

  const onRefresh = async () => {
    setRefreshing(true);
    await fetchHistory();
    setRefreshing(false);
  };

  const renderItem = ({item}: {item: AttendanceRecord}) => {
    const config = statusConfig[item.status] || statusConfig.alpha;
    return (
      <View style={styles.card}>
        <View style={styles.cardHeader}>
          <Text style={styles.date}>{item.attendance_date}</Text>
          <View style={[styles.badge, {backgroundColor: config.bg}]}>
            <Text style={[styles.badgeText, {color: config.color}]}>
              {config.label}
            </Text>
          </View>
        </View>
        <Text style={styles.subject}>{item.subject}</Text>
        <Text style={styles.time}>Masuk: {item.check_in_time}</Text>
      </View>
    );
  };

  if (loading) {
    return (
      <View style={styles.center}>
        <Text style={styles.loadingText}>Memuat data {childName}...</Text>
      </View>
    );
  }

  return (
    <FlatList
      style={styles.container}
      data={records}
      keyExtractor={item => String(item.id)}
      renderItem={renderItem}
      refreshControl={
        <RefreshControl refreshing={refreshing} onRefresh={onRefresh} />
      }
      ListHeaderComponent={
        <Text style={styles.header}>Riwayat Absensi - {childName}</Text>
      }
      ListEmptyComponent={
        <View style={styles.center}>
          <Text style={styles.emptyText}>Belum ada data absensi</Text>
        </View>
      }
    />
  );
};

const styles = StyleSheet.create({
  container: {flex: 1, backgroundColor: '#F3F4F6', padding: 16},
  center: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    padding: 40,
  },
  loadingText: {fontSize: 16, color: '#6B7280'},
  emptyText: {fontSize: 16, color: '#9CA3AF', fontStyle: 'italic'},
  header: {fontSize: 18, fontWeight: '700', color: '#111827', marginBottom: 12},
  card: {
    backgroundColor: '#fff',
    borderRadius: 10,
    padding: 14,
    marginBottom: 8,
    shadowColor: '#000',
    shadowOffset: {width: 0, height: 1},
    shadowOpacity: 0.03,
    shadowRadius: 2,
    elevation: 1,
  },
  cardHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 6,
  },
  date: {fontSize: 13, color: '#6B7280', fontWeight: '500'},
  badge: {paddingHorizontal: 10, paddingVertical: 3, borderRadius: 6},
  badgeText: {fontSize: 11, fontWeight: '700'},
  subject: {fontSize: 14, fontWeight: '600', color: '#111827'},
  time: {fontSize: 12, color: '#6B7280', marginTop: 2},
});
