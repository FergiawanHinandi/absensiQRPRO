import React, {useEffect, useState} from 'react';
import {View, Text, StyleSheet, FlatList, RefreshControl} from 'react-native';
import apiClient from '../../api/client';

interface Schedule {
  id: number;
  subject_name: string;
  class_name: string;
  day: string;
  start_time: string;
  end_time: string;
  room?: string;
}

const dayOrder = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

export const TeacherScheduleScreen = () => {
  const [schedules, setSchedules] = useState<Schedule[]>([]);
  const [refreshing, setRefreshing] = useState(false);
  const [loading, setLoading] = useState(true);

  const fetchSchedules = async () => {
    try {
      const response = await apiClient.get('/teacher/schedules');
      if (response.data?.success) {
        const allSchedules =
          response.data.data?.all || response.data.data || [];
        // Sort by day order then start_time
        allSchedules.sort((a: Schedule, b: Schedule) => {
          const dayDiff = dayOrder.indexOf(a.day) - dayOrder.indexOf(b.day);
          if (dayDiff !== 0) {
            return dayDiff;
          }
          return a.start_time.localeCompare(b.start_time);
        });
        setSchedules(allSchedules);
      }
    } catch (error) {
      console.log('Failed to fetch schedules:', error);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchSchedules();
  }, []);

  const onRefresh = async () => {
    setRefreshing(true);
    await fetchSchedules();
    setRefreshing(false);
  };

  // Group by day
  const grouped = schedules.reduce((acc, schedule) => {
    if (!acc[schedule.day]) {
      acc[schedule.day] = [];
    }
    acc[schedule.day].push(schedule);
    return acc;
  }, {} as Record<string, Schedule[]>);

  const sections = Object.entries(grouped).sort(
    ([a], [b]) => dayOrder.indexOf(a) - dayOrder.indexOf(b),
  );

  if (loading) {
    return (
      <View style={styles.center}>
        <Text style={styles.loadingText}>Memuat jadwal...</Text>
      </View>
    );
  }

  return (
    <FlatList
      style={styles.container}
      data={sections}
      keyExtractor={([day]) => day}
      refreshControl={
        <RefreshControl refreshing={refreshing} onRefresh={onRefresh} />
      }
      renderItem={({item: [day, items]}) => (
        <View style={styles.section}>
          <Text style={styles.dayHeader}>{day}</Text>
          {items.map(schedule => (
            <View key={schedule.id} style={styles.scheduleCard}>
              <View style={styles.timeCol}>
                <Text style={styles.time}>{schedule.start_time}</Text>
                <Text style={styles.timeSep}>|</Text>
                <Text style={styles.time}>{schedule.end_time}</Text>
              </View>
              <View style={styles.infoCol}>
                <Text style={styles.subject}>{schedule.subject_name}</Text>
                <Text style={styles.detail}>
                  {schedule.class_name}
                  {schedule.room ? ` • Ruang ${schedule.room}` : ''}
                </Text>
              </View>
            </View>
          ))}
        </View>
      )}
      ListEmptyComponent={
        <View style={styles.center}>
          <Text style={styles.emptyText}>Belum ada jadwal mengajar</Text>
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
  section: {marginBottom: 16},
  dayHeader: {
    fontSize: 16,
    fontWeight: '700',
    color: '#10B981',
    marginBottom: 8,
    paddingLeft: 4,
  },
  scheduleCard: {
    flexDirection: 'row',
    backgroundColor: '#fff',
    borderRadius: 10,
    padding: 12,
    marginBottom: 6,
    shadowColor: '#000',
    shadowOffset: {width: 0, height: 1},
    shadowOpacity: 0.03,
    shadowRadius: 2,
    elevation: 1,
  },
  timeCol: {width: 50, alignItems: 'center', justifyContent: 'center'},
  time: {fontSize: 12, fontWeight: '600', color: '#374151'},
  timeSep: {fontSize: 10, color: '#D1D5DB'},
  infoCol: {flex: 1, marginLeft: 12, justifyContent: 'center'},
  subject: {fontSize: 14, fontWeight: '600', color: '#111827'},
  detail: {fontSize: 12, color: '#6B7280', marginTop: 2},
});
