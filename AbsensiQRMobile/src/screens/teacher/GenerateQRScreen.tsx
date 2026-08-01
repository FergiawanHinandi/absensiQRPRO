import React, {useEffect, useState, useCallback} from 'react';
import {
  View,
  Text,
  StyleSheet,
  TouchableOpacity,
  ActivityIndicator,
  Alert,
  ScrollView,
} from 'react-native';
import QRCode from 'react-native-qrcode-svg';
import apiClient from '../../api/client';

interface Schedule {
  id: number;
  subject_name: string;
  class_name: string;
  start_time: string;
  end_time: string;
}

interface QRData {
  qr_token: string;
  expires_at: string;
  schedule_id: number;
  type: 'in' | 'out';
}

export const GenerateQRScreen = ({route}: any) => {
  const scheduleId = route?.params?.scheduleId;
  const [schedules, setSchedules] = useState<Schedule[]>([]);
  const [selectedSchedule, setSelectedSchedule] = useState<number | null>(
    scheduleId || null,
  );
  const [qrData, setQRData] = useState<QRData | null>(null);
  const [loading, setLoading] = useState(false);
  const [countdown, setCountdown] = useState(0);

  // Fetch available schedules
  useEffect(() => {
    const fetchSchedules = async () => {
      try {
        const response = await apiClient.get('/teacher/schedules');
        if (response.data?.success) {
          const todaySchedules =
            response.data.data?.today || response.data.data || [];
          setSchedules(todaySchedules);
          if (todaySchedules.length > 0 && !selectedSchedule) {
            setSelectedSchedule(todaySchedules[0].id);
          }
        }
      } catch (error) {
        console.log('Failed to fetch schedules:', error);
      }
    };
    fetchSchedules();
  }, []);

  // Countdown timer for QR expiry
  useEffect(() => {
    if (!qrData) {
      return;
    }

    const expiresAt = new Date(qrData.expires_at).getTime();
    const interval = setInterval(() => {
      const remaining = Math.max(
        0,
        Math.floor((expiresAt - Date.now()) / 1000),
      );
      setCountdown(remaining);
      if (remaining <= 0) {
        setQRData(null);
        clearInterval(interval);
      }
    }, 1000);

    return () => clearInterval(interval);
  }, [qrData]);

  const generateQR = useCallback(
    async (type: 'in' | 'out' = 'in') => {
      if (!selectedSchedule) {
        Alert.alert('Error', 'Pilih jadwal terlebih dahulu');
        return;
      }

      setLoading(true);
      try {
        const response = await apiClient.post(
          '/attendance/secure/generate-qr',
          {
            schedule_id: selectedSchedule,
            type,
          },
        );

        if (response.data?.success) {
          setQRData(response.data.data);
        } else {
          Alert.alert('Gagal', response.data?.message || 'Gagal generate QR');
        }
      } catch (error: any) {
        const msg = error.response?.data?.message || 'Gagal generate QR code';
        Alert.alert('Error', msg);
      } finally {
        setLoading(false);
      }
    },
    [selectedSchedule],
  );

  const formatTime = (seconds: number) => {
    const min = Math.floor(seconds / 60);
    const sec = seconds % 60;
    return `${min}:${sec.toString().padStart(2, '0')}`;
  };

  return (
    <ScrollView style={styles.container}>
      {/* Schedule Selection */}
      <View style={styles.card}>
        <Text style={styles.cardTitle}>Pilih Jadwal</Text>
        {schedules.length > 0 ? (
          schedules.map(schedule => (
            <TouchableOpacity
              key={schedule.id}
              style={[
                styles.scheduleOption,
                selectedSchedule === schedule.id && styles.scheduleSelected,
              ]}
              onPress={() => {
                setSelectedSchedule(schedule.id);
                setQRData(null);
              }}>
              <Text
                style={[
                  styles.scheduleSubject,
                  selectedSchedule === schedule.id && styles.textSelected,
                ]}>
                {schedule.subject_name}
              </Text>
              <Text
                style={[
                  styles.scheduleDetail,
                  selectedSchedule === schedule.id && styles.textSelectedSub,
                ]}>
                {schedule.class_name} • {schedule.start_time} -{' '}
                {schedule.end_time}
              </Text>
            </TouchableOpacity>
          ))
        ) : (
          <Text style={styles.noData}>Tidak ada jadwal tersedia hari ini</Text>
        )}
      </View>

      {/* QR Display */}
      {qrData && (
        <View style={styles.card}>
          <Text style={styles.cardTitle}>QR Code Aktif</Text>
          <View style={styles.qrContainer}>
            {/* Render gambar QR code yang bisa di-scan oleh siswa */}
            <View style={styles.qrImageWrapper}>
              <QRCode
                value={qrData.qr_token}
                size={200}
                backgroundColor="#FFFFFF"
                color="#111827"
                ecl="M"
              />
            </View>
            <Text style={styles.qrNote}>
              Tampilkan QR ini ke siswa untuk di-scan
            </Text>

            <View
              style={[
                styles.countdownContainer,
                countdown <= 30 ? styles.countdownDanger : styles.countdownSafe,
              ]}>
              <Text style={styles.countdownLabel}>Sisa waktu:</Text>
              <Text style={styles.countdownValue}>{formatTime(countdown)}</Text>
            </View>

            <Text style={styles.qrType}>
              Tipe: {qrData.type === 'in' ? 'Masuk' : 'Keluar'}
            </Text>
          </View>
        </View>
      )}

      {/* Generate Buttons */}
      <View style={styles.card}>
        <TouchableOpacity
          style={[styles.generateButton, {backgroundColor: '#10B981'}]}
          onPress={() => generateQR('in')}
          disabled={loading || !selectedSchedule}>
          {loading ? (
            <ActivityIndicator color="#fff" />
          ) : (
            <Text style={styles.generateText}>Generate QR Masuk</Text>
          )}
        </TouchableOpacity>

        <TouchableOpacity
          style={[styles.generateButton, {backgroundColor: '#F59E0B'}]}
          onPress={() => generateQR('out')}
          disabled={loading || !selectedSchedule}>
          <Text style={styles.generateText}>Generate QR Keluar</Text>
        </TouchableOpacity>

        {qrData && (
          <TouchableOpacity
            style={[styles.generateButton, {backgroundColor: '#3B82F6'}]}
            onPress={() => generateQR(qrData.type)}>
            <Text style={styles.generateText}>Refresh QR</Text>
          </TouchableOpacity>
        )}
      </View>
    </ScrollView>
  );
};

const styles = StyleSheet.create({
  container: {flex: 1, backgroundColor: '#F3F4F6', padding: 16},
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
  scheduleOption: {
    borderWidth: 1,
    borderColor: '#E5E7EB',
    borderRadius: 10,
    padding: 12,
    marginBottom: 8,
  },
  scheduleSelected: {
    borderColor: '#10B981',
    backgroundColor: '#F0FDF4',
  },
  scheduleSubject: {fontSize: 15, fontWeight: '600', color: '#374151'},
  scheduleDetail: {fontSize: 13, color: '#6B7280', marginTop: 2},
  textSelected: {color: '#065F46'},
  textSelectedSub: {color: '#047857'},
  noData: {
    fontSize: 14,
    color: '#9CA3AF',
    fontStyle: 'italic',
    textAlign: 'center',
    paddingVertical: 20,
  },
  qrContainer: {alignItems: 'center', paddingVertical: 16},
  qrImageWrapper: {
    padding: 16,
    backgroundColor: '#FFFFFF',
    borderRadius: 12,
    borderWidth: 1,
    borderColor: '#E5E7EB',
    marginBottom: 12,
  },
  qrNote: {
    fontSize: 12,
    color: '#9CA3AF',
    textAlign: 'center',
    marginBottom: 16,
  },
  countdownContainer: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 16,
    paddingVertical: 8,
    borderRadius: 20,
    gap: 8,
    marginBottom: 8,
  },
  countdownSafe: {backgroundColor: '#D1FAE5'},
  countdownDanger: {backgroundColor: '#FEE2E2'},
  countdownLabel: {fontSize: 13, color: '#374151'},
  countdownValue: {fontSize: 18, fontWeight: 'bold', color: '#111827'},
  qrType: {fontSize: 13, color: '#6B7280'},
  generateButton: {
    borderRadius: 10,
    padding: 14,
    marginBottom: 8,
    alignItems: 'center',
  },
  generateText: {color: '#fff', fontSize: 15, fontWeight: '600'},
});
