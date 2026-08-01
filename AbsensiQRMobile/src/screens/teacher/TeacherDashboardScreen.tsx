import React, {useEffect, useState} from 'react';
import {
  View,
  Text,
  StyleSheet,
  ScrollView,
  TouchableOpacity,
  RefreshControl,
  ActivityIndicator,
} from 'react-native';
import {useAuth} from '../../contexts/AuthContext';
import apiClient from '../../api/client';

interface Schedule {
  id: number;
  subject_name: string;
  class_name: string;
  day: string;
  start_time: string;
  end_time: string;
  room?: string;
  attendance_count?: number;
}

interface DashboardData {
  today_schedules: Schedule[];
  total_classes: number;
  total_students: number;
  today_attendance_rate: number;
}

export const TeacherDashboardScreen = ({navigation}: any) => {
  const {user, logout} = useAuth();
  const [data, setData] = useState<DashboardData | null>(null);
  const [refreshing, setRefreshing] = useState(false);
  const [loading, setLoading] = useState(true);

  const fetchDashboard = async () => {
    try {
      const response = await apiClient.get('/teacher/dashboard');
      if (response.data?.success) {
        setData(response.data.data);
      }
    } catch (error) {
      console.log('Teacher dashboard error:', error);
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

  if (loading) {
    return (
      <View
        style={{
          flex: 1,
          justifyContent: 'center',
          alignItems: 'center',
          backgroundColor: '#F3F4F6',
        }}>
        <ActivityIndicator size="large" color="#10B981" />
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
        <Text style={styles.greeting}>Halo, {user?.name || 'Guru'}! 👋</Text>
        <Text style={styles.role}>
          {user?.role_type === 'homeroom_teacher' ? 'Wali Kelas' : 'Guru'}
        </Text>
      </View>

      {/* Stats */}
      {data && (
        <View style={styles.statsRow}>
          <View style={[styles.statCard, {backgroundColor: '#EFF6FF'}]}>
            <Text style={[styles.statNum, {color: '#2563EB'}]}>
              {data.total_classes}
            </Text>
            <Text style={styles.statLabel}>Kelas</Text>
          </View>
          <View style={[styles.statCard, {backgroundColor: '#F0FDF4'}]}>
            <Text style={[styles.statNum, {color: '#16A34A'}]}>
              {data.total_students}
            </Text>
            <Text style={styles.statLabel}>Siswa</Text>
          </View>
          <View style={[styles.statCard, {backgroundColor: '#FEF3C7'}]}>
            <Text style={[styles.statNum, {color: '#D97706'}]}>
              {data.today_attendance_rate}%
            </Text>
            <Text style={styles.statLabel}>Kehadiran</Text>
          </View>
        </View>
      )}

      {/* Today's Schedule */}
      <View style={styles.card}>
        <Text style={styles.cardTitle}>Jadwal Hari Ini</Text>
        {data?.today_schedules && data.today_schedules.length > 0 ? (
          data.today_schedules.map(schedule => (
            <TouchableOpacity
              key={schedule.id}
              style={styles.scheduleItem}
              onPress={() =>
                navigation.navigate('GenerateQR', {scheduleId: schedule.id})
              }>
              <View style={styles.timeCol}>
                <Text style={styles.timeText}>{schedule.start_time}</Text>
                <Text style={styles.timeSep}>—</Text>
                <Text style={styles.timeText}>{schedule.end_time}</Text>
              </View>
              <View style={styles.scheduleInfo}>
                <Text style={styles.subjectText}>{schedule.subject_name}</Text>
                <Text style={styles.classText}>
                  {schedule.class_name}
                  {schedule.room ? ` • ${schedule.room}` : ''}
                </Text>
              </View>
              <Text style={styles.qrIcon}>📱</Text>
            </TouchableOpacity>
          ))
        ) : (
          <Text style={styles.noData}>Tidak ada jadwal hari ini</Text>
        )}
      </View>

      {/* Quick Actions */}
      <View style={styles.card}>
        <Text style={styles.cardTitle}>Menu</Text>
        <TouchableOpacity
          style={[styles.actionButton, {backgroundColor: '#10B981'}]}
          onPress={() => navigation.navigate('GenerateQR')}>
          <Text style={styles.actionText}>📱 Generate QR Absensi</Text>
        </TouchableOpacity>

        <TouchableOpacity
          style={[styles.actionButton, {backgroundColor: '#3B82F6'}]}
          onPress={() => navigation.navigate('TeacherSchedule')}>
          <Text style={styles.actionText}>📅 Jadwal Mengajar</Text>
        </TouchableOpacity>

        <TouchableOpacity
          style={[styles.actionButton, {backgroundColor: '#8B5CF6'}]}
          onPress={() => navigation.navigate('TeacherProfile')}>
          <Text style={styles.actionText}>👤 Profil Saya</Text>
        </TouchableOpacity>

        <TouchableOpacity
          style={[styles.actionButton, {backgroundColor: '#F59E0B'}]}
          onPress={() => navigation.navigate('DeviceManagement')}>
          <Text style={styles.actionText}>📱 Perangkat Terdaftar</Text>
        </TouchableOpacity>

        <TouchableOpacity
          style={[styles.actionButton, {backgroundColor: '#6366F1'}]}
          onPress={() => navigation.navigate('Announcements')}>
          <Text style={styles.actionText}>📢 Pengumuman</Text>
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
    backgroundColor: '#10B981',
    borderRadius: 16,
    padding: 20,
    marginBottom: 16,
  },
  greeting: {fontSize: 22, fontWeight: 'bold', color: '#fff'},
  role: {fontSize: 14, color: '#A7F3D0', marginTop: 4},
  statsRow: {flexDirection: 'row', gap: 8, marginBottom: 16},
  statCard: {
    flex: 1,
    borderRadius: 12,
    padding: 14,
    alignItems: 'center',
  },
  statNum: {fontSize: 22, fontWeight: 'bold'},
  statLabel: {fontSize: 11, color: '#6B7280', marginTop: 4},
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
  scheduleItem: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: 10,
    borderBottomWidth: 1,
    borderBottomColor: '#F3F4F6',
  },
  timeCol: {width: 50, alignItems: 'center'},
  timeText: {fontSize: 12, fontWeight: '600', color: '#374151'},
  timeSep: {fontSize: 10, color: '#9CA3AF'},
  scheduleInfo: {flex: 1, marginLeft: 12},
  subjectText: {fontSize: 14, fontWeight: '600', color: '#111827'},
  classText: {fontSize: 12, color: '#6B7280', marginTop: 2},
  qrIcon: {fontSize: 20},
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
