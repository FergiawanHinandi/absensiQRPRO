import React, {useEffect, useState} from 'react';
import {
  View,
  Text,
  StyleSheet,
  TouchableOpacity,
  ScrollView,
  RefreshControl,
  ActivityIndicator,
} from 'react-native';
import {useAuth} from '../../contexts/AuthContext';
import apiClient from '../../api/client';

interface TodayStatus {
  has_attendance: boolean;
  status?: string;
  check_in_time?: string;
  subject?: string;
}

interface MonthlySummary {
  total_present: number;
  total_late: number;
  total_absent: number;
  total_permit: number;
  attendance_rate: number;
}

export const DashboardScreen = ({navigation}: any) => {
  const {user, logout} = useAuth();
  const [todayStatus, setTodayStatus] = useState<TodayStatus | null>(null);
  const [summary, setSummary] = useState<MonthlySummary | null>(null);
  const [refreshing, setRefreshing] = useState(false);
  const [loading, setLoading] = useState(true);

  const fetchDashboard = async () => {
    try {
      const response = await apiClient.get('/student/dashboard');
      if (response.data?.success) {
        setTodayStatus(response.data.data?.today_status);
        setSummary(response.data.data?.monthly_summary);
      }
    } catch (error) {
      console.log('Dashboard fetch error:', error);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchDashboard();
  }, []);

  const onRefresh = async () => {
    setRefreshing(true);
    await fetchDashboard();
    setRefreshing(false);
  };

  const handleLogout = async () => {
    await logout();
  };

  if (loading) {
    return (
      <View
        style={{
          flex: 1,
          justifyContent: 'center',
          alignItems: 'center',
          backgroundColor: '#F3F4F6',
        }}>
        <ActivityIndicator size="large" color="#3B82F6" />
        <Text style={{fontSize: 14, color: '#6B7280', marginTop: 12}}>
          Memuat dashboard...
        </Text>
      </View>
    );
  }

  return (
    <ScrollView
      style={styles.container}
      refreshControl={
        <RefreshControl refreshing={refreshing} onRefresh={onRefresh} />
      }>
      {/* Header */}
      <View style={styles.header}>
        <Text style={styles.greeting}>Halo, {user?.name || 'Siswa'}! 👋</Text>
        <Text style={styles.role}>Siswa</Text>
      </View>

      {/* Today Status */}
      <View style={styles.card}>
        <Text style={styles.cardTitle}>Status Hari Ini</Text>
        {todayStatus?.has_attendance ? (
          <View style={styles.statusRow}>
            <View style={[styles.statusBadge, {backgroundColor: '#D1FAE5'}]}>
              <Text style={[styles.statusText, {color: '#065F46'}]}>
                {todayStatus.status?.toUpperCase() || 'HADIR'}
              </Text>
            </View>
            <Text style={styles.statusDetail}>
              {todayStatus.subject} - {todayStatus.check_in_time}
            </Text>
          </View>
        ) : (
          <Text style={styles.noData}>Belum ada absensi hari ini</Text>
        )}
      </View>

      {/* Monthly Summary */}
      {summary && (
        <View style={styles.card}>
          <Text style={styles.cardTitle}>Ringkasan Bulan Ini</Text>
          <View style={styles.statsGrid}>
            <View style={[styles.statBox, {backgroundColor: '#EFF6FF'}]}>
              <Text style={[styles.statNumber, {color: '#2563EB'}]}>
                {summary.total_present}
              </Text>
              <Text style={styles.statLabel}>Hadir</Text>
            </View>
            <View style={[styles.statBox, {backgroundColor: '#FEF3C7'}]}>
              <Text style={[styles.statNumber, {color: '#D97706'}]}>
                {summary.total_late}
              </Text>
              <Text style={styles.statLabel}>Terlambat</Text>
            </View>
            <View style={[styles.statBox, {backgroundColor: '#FEE2E2'}]}>
              <Text style={[styles.statNumber, {color: '#DC2626'}]}>
                {summary.total_absent}
              </Text>
              <Text style={styles.statLabel}>Alpa</Text>
            </View>
            <View style={[styles.statBox, {backgroundColor: '#F3E8FF'}]}>
              <Text style={[styles.statNumber, {color: '#7C3AED'}]}>
                {summary.total_permit}
              </Text>
              <Text style={styles.statLabel}>Izin</Text>
            </View>
          </View>
          <View style={styles.rateContainer}>
            <Text style={styles.rateLabel}>Tingkat Kehadiran</Text>
            <Text style={styles.rateValue}>{summary.attendance_rate}%</Text>
          </View>
        </View>
      )}

      {/* Quick Actions */}
      <View style={styles.card}>
        <Text style={styles.cardTitle}>Menu</Text>
        <TouchableOpacity
          style={[styles.actionButton, {backgroundColor: '#3B82F6'}]}
          onPress={() => navigation.navigate('ScanQR')}>
          <Text style={styles.actionText}>📷 Scan QR Absensi</Text>
        </TouchableOpacity>

        <TouchableOpacity
          style={[styles.actionButton, {backgroundColor: '#10B981'}]}
          onPress={() => navigation.navigate('StudentHistory')}>
          <Text style={styles.actionText}>📋 Riwayat Absensi</Text>
        </TouchableOpacity>

        <TouchableOpacity
          style={[styles.actionButton, {backgroundColor: '#8B5CF6'}]}
          onPress={() => navigation.navigate('StudentProfile')}>
          <Text style={styles.actionText}>👤 Profil Saya</Text>
        </TouchableOpacity>

        <TouchableOpacity
          style={[styles.actionButton, {backgroundColor: '#F59E0B'}]}
          onPress={() => navigation.navigate('Leaderboard')}>
          <Text style={styles.actionText}>🏆 Leaderboard</Text>
        </TouchableOpacity>

        <TouchableOpacity
          style={[styles.actionButton, {backgroundColor: '#EC4899'}]}
          onPress={() => navigation.navigate('Badges')}>
          <Text style={styles.actionText}>🎖️ Lencana Saya</Text>
        </TouchableOpacity>

        <TouchableOpacity
          style={[styles.actionButton, {backgroundColor: '#6366F1'}]}
          onPress={() => navigation.navigate('Announcements')}>
          <Text style={styles.actionText}>📢 Pengumuman</Text>
        </TouchableOpacity>

        <TouchableOpacity
          style={[styles.actionButton, {backgroundColor: '#EF4444'}]}
          onPress={handleLogout}>
          <Text style={styles.actionText}>🚪 Keluar</Text>
        </TouchableOpacity>
      </View>
    </ScrollView>
  );
};

const styles = StyleSheet.create({
  container: {flex: 1, backgroundColor: '#F3F4F6', padding: 16},
  header: {
    backgroundColor: '#3B82F6',
    borderRadius: 16,
    padding: 20,
    marginBottom: 16,
  },
  greeting: {fontSize: 22, fontWeight: 'bold', color: '#fff'},
  role: {fontSize: 14, color: '#BFDBFE', marginTop: 4},
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
  statusRow: {flexDirection: 'row', alignItems: 'center', gap: 12},
  statusBadge: {paddingHorizontal: 12, paddingVertical: 6, borderRadius: 8},
  statusText: {fontSize: 13, fontWeight: '700'},
  statusDetail: {fontSize: 13, color: '#6B7280', flex: 1},
  noData: {fontSize: 14, color: '#9CA3AF', fontStyle: 'italic'},
  statsGrid: {flexDirection: 'row', flexWrap: 'wrap', gap: 8},
  statBox: {
    flex: 1,
    minWidth: '45%',
    borderRadius: 10,
    padding: 12,
    alignItems: 'center',
  },
  statNumber: {fontSize: 24, fontWeight: 'bold'},
  statLabel: {fontSize: 12, color: '#6B7280', marginTop: 4},
  rateContainer: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginTop: 12,
    paddingTop: 12,
    borderTopWidth: 1,
    borderTopColor: '#E5E7EB',
  },
  rateLabel: {fontSize: 14, color: '#374151', fontWeight: '600'},
  rateValue: {fontSize: 20, fontWeight: 'bold', color: '#10B981'},
  actionButton: {
    borderRadius: 10,
    padding: 14,
    marginBottom: 8,
    alignItems: 'center',
  },
  actionText: {color: '#fff', fontSize: 15, fontWeight: '600'},
});
