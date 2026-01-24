import React, { useState, useEffect } from 'react';
import {
  View,
  Text,
  FlatList,
  StyleSheet,
  TouchableOpacity,
  ActivityIndicator,
} from 'react-native';
import api from '../api';

const AttendanceHistoryScreen = () => {
  const [attendance, setAttendance] = useState([]);
  const [loading, setLoading] = useState(false);
  const [selectedDate, setSelectedDate] = useState(new Date().toISOString().split('T')[0]);

  useEffect(() => {
    loadAttendance();
  }, [selectedDate]);

  const loadAttendance = async () => {
    setLoading(true);
    const result = await api.getAttendance(selectedDate);
    setLoading(false);

    if (result.success) {
      setAttendance(result.data);
    } else {
      setAttendance([]);
    }
  };

  const formatTime = (time) => {
    return time ? time.substring(0, 5) : '-';
  };

  const formatStatus = (status) => {
    const statusMap = {
      'present': 'Hadir',
      'late': 'Terlambat',
      'absent': 'Tidak Hadir',
      'excused': 'Izin',
    };
    return statusMap[status] || status;
  };

  const getStatusColor = (status) => {
    const colorMap = {
      'present': '#27ae60',
      'late': '#f39c12',
      'absent': '#e74c3c',
      'excused': '#3498db',
    };
    return colorMap[status] || '#95a5a6';
  };

  const renderItem = ({ item }) => (
    <View style={styles.card}>
      <View style={styles.cardHeader}>
        <Text style={styles.studentName}>{item.student_name}</Text>
        <View style={[styles.statusBadge, { backgroundColor: getStatusColor(item.status) }]}>
          <Text style={styles.statusText}>{formatStatus(item.status)}</Text>
        </View>
      </View>
      <View style={styles.cardBody}>
        <Text style={styles.cardText}>NIS: {item.nis}</Text>
        <Text style={styles.cardText}>Kelas: {item.class}</Text>
        <Text style={styles.cardText}>Waktu: {formatTime(item.time)}</Text>
        {item.teacher_name && (
          <Text style={styles.cardText}>Guru: {item.teacher_name}</Text>
        )}
        {item.notes && (
          <Text style={styles.cardText}>Catatan: {item.notes}</Text>
        )}
      </View>
    </View>
  );

  return (
    <View style={styles.container}>
      <View style={styles.header}>
        <Text style={styles.headerTitle}>Tanggal: {selectedDate}</Text>
        <Text style={styles.headerSubtitle}>
          Total: {attendance.length} siswa
        </Text>
      </View>

      {loading ? (
        <View style={styles.loadingContainer}>
          <ActivityIndicator size="large" color="#3498db" />
          <Text style={styles.loadingText}>Memuat data...</Text>
        </View>
      ) : attendance.length > 0 ? (
        <FlatList
          data={attendance}
          renderItem={renderItem}
          keyExtractor={(item) => item.id.toString()}
          contentContainerStyle={styles.listContainer}
        />
      ) : (
        <View style={styles.emptyContainer}>
          <Text style={styles.emptyText}>Tidak ada data absensi</Text>
        </View>
      )}
    </View>
  );
};

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#f5f5f5',
  },
  header: {
    backgroundColor: '#3498db',
    padding: 20,
  },
  headerTitle: {
    fontSize: 20,
    fontWeight: 'bold',
    color: '#fff',
  },
  headerSubtitle: {
    fontSize: 16,
    color: '#fff',
    opacity: 0.9,
    marginTop: 5,
  },
  listContainer: {
    padding: 15,
  },
  card: {
    backgroundColor: '#fff',
    borderRadius: 10,
    padding: 15,
    marginBottom: 15,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.1,
    shadowRadius: 3.84,
    elevation: 3,
  },
  cardHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 10,
    paddingBottom: 10,
    borderBottomWidth: 1,
    borderBottomColor: '#ecf0f1',
  },
  studentName: {
    fontSize: 18,
    fontWeight: 'bold',
    color: '#2c3e50',
    flex: 1,
  },
  statusBadge: {
    paddingHorizontal: 10,
    paddingVertical: 5,
    borderRadius: 15,
  },
  statusText: {
    color: '#fff',
    fontSize: 12,
    fontWeight: 'bold',
  },
  cardBody: {
    marginTop: 5,
  },
  cardText: {
    fontSize: 14,
    color: '#7f8c8d',
    marginBottom: 5,
  },
  loadingContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
  },
  loadingText: {
    fontSize: 16,
    color: '#7f8c8d',
    marginTop: 10,
  },
  emptyContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
  },
  emptyText: {
    fontSize: 18,
    color: '#7f8c8d',
  },
});

export default AttendanceHistoryScreen;
