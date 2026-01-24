import React, { useState, useEffect } from 'react';
import {
  View,
  Text,
  TouchableOpacity,
  StyleSheet,
  Alert,
  ScrollView,
} from 'react-native';
import api from '../api';

const HomeScreen = ({ navigation }) => {
  const [teacher, setTeacher] = useState(null);
  const [stats, setStats] = useState({
    todayAttendance: 0,
    totalStudents: 0,
  });

  useEffect(() => {
    loadTeacherInfo();
    loadStats();
  }, []);

  const loadTeacherInfo = async () => {
    const result = await api.getTeacher();
    if (result.success) {
      setTeacher(result.data);
    }
  };

  const loadStats = async () => {
    // Load today's stats
    const today = new Date().toISOString().split('T')[0];
    const attendanceResult = await api.getAttendance(today);
    const studentsResult = await api.getStudents();

    setStats({
      todayAttendance: attendanceResult.success ? attendanceResult.data.length : 0,
      totalStudents: studentsResult.success ? studentsResult.data.length : 0,
    });
  };

  const handleLogout = async () => {
    Alert.alert(
      'Logout',
      'Apakah Anda yakin ingin keluar?',
      [
        { text: 'Batal', style: 'cancel' },
        {
          text: 'Ya',
          onPress: async () => {
            await api.logout();
            navigation.replace('Login');
          },
        },
      ]
    );
  };

  return (
    <ScrollView style={styles.container}>
      <View style={styles.header}>
        <Text style={styles.welcomeText}>Selamat Datang,</Text>
        <Text style={styles.teacherName}>{teacher?.name || 'Guru'}</Text>
      </View>

      <View style={styles.statsContainer}>
        <View style={styles.statCard}>
          <Text style={styles.statNumber}>{stats.totalStudents}</Text>
          <Text style={styles.statLabel}>Total Siswa</Text>
        </View>
        <View style={styles.statCard}>
          <Text style={styles.statNumber}>{stats.todayAttendance}</Text>
          <Text style={styles.statLabel}>Hadir Hari Ini</Text>
        </View>
      </View>

      <View style={styles.menuContainer}>
        <TouchableOpacity
          style={styles.menuButton}
          onPress={() => navigation.navigate('Scanner', { teacher })}
        >
          <Text style={styles.menuIcon}>📷</Text>
          <Text style={styles.menuText}>Scan QR Code</Text>
          <Text style={styles.menuSubtext}>Absen siswa dengan scan kartu</Text>
        </TouchableOpacity>

        <TouchableOpacity
          style={styles.menuButton}
          onPress={() => navigation.navigate('AttendanceHistory')}
        >
          <Text style={styles.menuIcon}>📊</Text>
          <Text style={styles.menuText}>Riwayat Absensi</Text>
          <Text style={styles.menuSubtext}>Lihat data absensi harian</Text>
        </TouchableOpacity>

        <TouchableOpacity
          style={[styles.menuButton, styles.logoutButton]}
          onPress={handleLogout}
        >
          <Text style={styles.menuIcon}>🚪</Text>
          <Text style={styles.menuText}>Logout</Text>
          <Text style={styles.menuSubtext}>Keluar dari aplikasi</Text>
        </TouchableOpacity>
      </View>

      <View style={styles.infoBox}>
        <Text style={styles.infoTitle}>📱 Cara Menggunakan:</Text>
        <Text style={styles.infoText}>1. Tekan "Scan QR Code"</Text>
        <Text style={styles.infoText}>2. Arahkan kamera ke kartu pelajar siswa</Text>
        <Text style={styles.infoText}>3. Konfirmasi kehadiran siswa</Text>
        <Text style={styles.infoText}>4. Selesai! Absensi tercatat</Text>
      </View>
    </ScrollView>
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
    paddingTop: 30,
    paddingBottom: 30,
  },
  welcomeText: {
    fontSize: 18,
    color: '#fff',
    opacity: 0.9,
  },
  teacherName: {
    fontSize: 28,
    fontWeight: 'bold',
    color: '#fff',
    marginTop: 5,
  },
  statsContainer: {
    flexDirection: 'row',
    padding: 15,
    gap: 15,
  },
  statCard: {
    flex: 1,
    backgroundColor: '#fff',
    padding: 20,
    borderRadius: 10,
    alignItems: 'center',
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.1,
    shadowRadius: 3.84,
    elevation: 3,
  },
  statNumber: {
    fontSize: 36,
    fontWeight: 'bold',
    color: '#3498db',
  },
  statLabel: {
    fontSize: 14,
    color: '#666',
    marginTop: 5,
  },
  menuContainer: {
    padding: 15,
  },
  menuButton: {
    backgroundColor: '#fff',
    padding: 20,
    borderRadius: 10,
    marginBottom: 15,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.1,
    shadowRadius: 3.84,
    elevation: 3,
  },
  menuIcon: {
    fontSize: 40,
    marginBottom: 10,
  },
  menuText: {
    fontSize: 20,
    fontWeight: 'bold',
    color: '#2c3e50',
    marginBottom: 5,
  },
  menuSubtext: {
    fontSize: 14,
    color: '#7f8c8d',
  },
  logoutButton: {
    backgroundColor: '#e74c3c',
  },
  infoBox: {
    margin: 15,
    padding: 20,
    backgroundColor: '#e8f4f8',
    borderRadius: 10,
    borderLeftWidth: 4,
    borderLeftColor: '#3498db',
  },
  infoTitle: {
    fontSize: 16,
    fontWeight: 'bold',
    color: '#2c3e50',
    marginBottom: 10,
  },
  infoText: {
    fontSize: 14,
    color: '#666',
    marginBottom: 5,
    paddingLeft: 10,
  },
});

export default HomeScreen;
