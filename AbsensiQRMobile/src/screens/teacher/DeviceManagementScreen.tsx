import React, {useEffect, useState, useCallback} from 'react';
import {
  View,
  Text,
  StyleSheet,
  ScrollView,
  RefreshControl,
  TouchableOpacity,
  Alert,
  ActivityIndicator,
} from 'react-native';
import apiClient from '../../api/client';

interface DeviceInfo {
  id: number;
  device_name: string;
  device_model: string;
  os_version: string;
  app_version: string;
  last_active_at: string;
  is_current: boolean;
  registered_at: string;
  status: 'active' | 'inactive' | 'blocked';
}

export const DeviceManagementScreen = () => {
  const [devices, setDevices] = useState<DeviceInfo[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const fetchDevices = useCallback(async () => {
    try {
      setError(null);
      // Endpoint backend: /teacher/attendance/devices
      const response = await apiClient.get('/teacher/attendance/devices');
      if (response.data?.success) {
        setDevices(response.data.data || []);
      }
    } catch (err: any) {
      const message =
        err?.response?.data?.message ||
        err?.message ||
        'Gagal memuat perangkat';
      setError(message);
      console.log('Failed to fetch devices:', err);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchDevices();
  }, [fetchDevices]);

  const onRefresh = async () => {
    setRefreshing(true);
    await fetchDevices();
    setRefreshing(false);
  };

  const handleRemoveDevice = (device: DeviceInfo) => {
    if (device.is_current) {
      Alert.alert(
        'Peringatan',
        'Tidak dapat menghapus perangkat yang sedang digunakan.',
      );
      return;
    }

    Alert.alert(
      'Hapus Perangkat',
      `Apakah Anda yakin ingin menghapus "${device.device_name}" dari daftar perangkat terdaftar?`,
      [
        {text: 'Batal', style: 'cancel'},
        {
          text: 'Hapus',
          style: 'destructive',
          onPress: async () => {
            try {
              // Endpoint backend: /teacher/attendance/devices/{id}
              await apiClient.delete(
                `/teacher/attendance/devices/${device.id}`,
              );
              setDevices(prev => prev.filter(d => d.id !== device.id));
              Alert.alert('Berhasil', 'Perangkat berhasil dihapus.');
            } catch (error) {
              Alert.alert('Gagal', 'Gagal menghapus perangkat. Coba lagi.');
            }
          },
        },
      ],
    );
  };

  const formatDate = (dateStr: string) => {
    return new Date(dateStr).toLocaleDateString('id-ID', {
      day: 'numeric',
      month: 'short',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  };

  const statusConfig: Record<
    string,
    {label: string; color: string; bg: string}
  > = {
    active: {label: 'Aktif', color: '#065F46', bg: '#D1FAE5'},
    inactive: {label: 'Tidak Aktif', color: '#92400E', bg: '#FEF3C7'},
    blocked: {label: 'Diblokir', color: '#991B1B', bg: '#FEE2E2'},
  };

  if (loading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator size="large" color="#10B981" />
        <Text style={styles.loadingText}>Memuat perangkat...</Text>
      </View>
    );
  }

  return (
    <ScrollView
      style={styles.container}
      refreshControl={
        <RefreshControl refreshing={refreshing} onRefresh={onRefresh} />
      }>
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

      {/* Info Card */}
      <View style={styles.infoCard}>
        <Text style={styles.infoIcon}>📱</Text>
        <View style={styles.infoContent}>
          <Text style={styles.infoTitle}>Manajemen Perangkat</Text>
          <Text style={styles.infoDesc}>
            Perangkat yang terdaftar untuk generate QR absensi. Hanya perangkat
            terdaftar yang dapat digunakan.
          </Text>
        </View>
      </View>

      {/* Device Count */}
      <View style={styles.countCard}>
        <Text style={styles.countNumber}>{devices.length}</Text>
        <Text style={styles.countLabel}>Perangkat Terdaftar</Text>
      </View>

      {/* Device List */}
      {devices.length > 0 ? (
        devices.map(device => {
          const status = statusConfig[device.status] || statusConfig.inactive;
          return (
            <View
              key={device.id}
              style={[
                styles.deviceCard,
                device.is_current && styles.currentDevice,
              ]}>
              <View style={styles.deviceHeader}>
                <View style={styles.deviceIcon}>
                  <Text style={styles.deviceIconText}>📱</Text>
                </View>
                <View style={styles.deviceInfo}>
                  <View style={styles.deviceNameRow}>
                    <Text style={styles.deviceName}>{device.device_name}</Text>
                    {device.is_current && (
                      <View style={styles.currentBadge}>
                        <Text style={styles.currentBadgeText}>
                          Perangkat Ini
                        </Text>
                      </View>
                    )}
                  </View>
                  <Text style={styles.deviceModel}>{device.device_model}</Text>
                </View>
                <View
                  style={[styles.statusBadge, {backgroundColor: status.bg}]}>
                  <Text style={[styles.statusText, {color: status.color}]}>
                    {status.label}
                  </Text>
                </View>
              </View>

              <View style={styles.deviceDetails}>
                <View style={styles.detailRow}>
                  <Text style={styles.detailLabel}>OS</Text>
                  <Text style={styles.detailValue}>{device.os_version}</Text>
                </View>
                <View style={styles.detailRow}>
                  <Text style={styles.detailLabel}>Versi App</Text>
                  <Text style={styles.detailValue}>{device.app_version}</Text>
                </View>
                <View style={styles.detailRow}>
                  <Text style={styles.detailLabel}>Terdaftar</Text>
                  <Text style={styles.detailValue}>
                    {formatDate(device.registered_at)}
                  </Text>
                </View>
                <View style={styles.detailRow}>
                  <Text style={styles.detailLabel}>Terakhir Aktif</Text>
                  <Text style={styles.detailValue}>
                    {formatDate(device.last_active_at)}
                  </Text>
                </View>
              </View>

              {!device.is_current && (
                <TouchableOpacity
                  style={styles.removeButton}
                  onPress={() => handleRemoveDevice(device)}>
                  <Text style={styles.removeText}>Hapus Perangkat</Text>
                </TouchableOpacity>
              )}
            </View>
          );
        })
      ) : (
        <View style={styles.emptyContainer}>
          <Text style={styles.emptyIcon}>📭</Text>
          <Text style={styles.emptyText}>Belum ada perangkat terdaftar</Text>
          <Text style={styles.emptySubtext}>
            Perangkat akan terdaftar otomatis saat Anda pertama kali generate QR
          </Text>
        </View>
      )}

      <View style={{height: 32}} />
    </ScrollView>
  );
};

const styles = StyleSheet.create({
  container: {flex: 1, backgroundColor: '#F3F4F6'},
  center: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    padding: 40,
  },
  loadingText: {fontSize: 14, color: '#6B7280', marginTop: 12},

  infoCard: {
    flexDirection: 'row',
    backgroundColor: '#EFF6FF',
    margin: 16,
    marginBottom: 8,
    borderRadius: 12,
    padding: 14,
    borderWidth: 1,
    borderColor: '#BFDBFE',
  },
  infoIcon: {fontSize: 24, marginRight: 12},
  infoContent: {flex: 1},
  infoTitle: {fontSize: 14, fontWeight: '600', color: '#1E40AF'},
  infoDesc: {fontSize: 12, color: '#3B82F6', marginTop: 4, lineHeight: 18},

  countCard: {
    backgroundColor: '#10B981',
    marginHorizontal: 16,
    marginBottom: 16,
    borderRadius: 12,
    padding: 16,
    alignItems: 'center',
  },
  countNumber: {fontSize: 28, fontWeight: 'bold', color: '#fff'},
  countLabel: {fontSize: 12, color: '#A7F3D0', marginTop: 2},

  deviceCard: {
    backgroundColor: '#fff',
    borderRadius: 12,
    marginHorizontal: 16,
    marginBottom: 10,
    padding: 14,
    shadowColor: '#000',
    shadowOffset: {width: 0, height: 1},
    shadowOpacity: 0.05,
    shadowRadius: 2,
    elevation: 1,
  },
  currentDevice: {borderWidth: 2, borderColor: '#10B981'},

  deviceHeader: {flexDirection: 'row', alignItems: 'center'},
  deviceIcon: {
    width: 40,
    height: 40,
    borderRadius: 20,
    backgroundColor: '#F3F4F6',
    justifyContent: 'center',
    alignItems: 'center',
    marginRight: 12,
  },
  deviceIconText: {fontSize: 20},
  deviceInfo: {flex: 1},
  deviceNameRow: {flexDirection: 'row', alignItems: 'center', gap: 6},
  deviceName: {fontSize: 15, fontWeight: '600', color: '#111827'},
  deviceModel: {fontSize: 12, color: '#6B7280', marginTop: 2},

  currentBadge: {
    backgroundColor: '#D1FAE5',
    paddingHorizontal: 6,
    paddingVertical: 1,
    borderRadius: 4,
  },
  currentBadgeText: {fontSize: 10, color: '#065F46', fontWeight: '600'},

  statusBadge: {paddingHorizontal: 8, paddingVertical: 3, borderRadius: 6},
  statusText: {fontSize: 11, fontWeight: '600'},

  deviceDetails: {
    marginTop: 12,
    paddingTop: 12,
    borderTopWidth: 1,
    borderTopColor: '#F3F4F6',
  },
  detailRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingVertical: 4,
  },
  detailLabel: {fontSize: 13, color: '#6B7280'},
  detailValue: {fontSize: 13, color: '#111827', fontWeight: '500'},

  removeButton: {
    marginTop: 12,
    paddingVertical: 10,
    borderRadius: 8,
    borderWidth: 1,
    borderColor: '#FCA5A5',
    alignItems: 'center',
    backgroundColor: '#FEF2F2',
  },
  removeText: {fontSize: 13, color: '#DC2626', fontWeight: '600'},

  emptyContainer: {
    alignItems: 'center',
    paddingVertical: 40,
    marginHorizontal: 16,
  },
  emptyIcon: {fontSize: 48, marginBottom: 12},
  emptyText: {fontSize: 16, color: '#6B7280', fontWeight: '600'},
  emptySubtext: {
    fontSize: 13,
    color: '#9CA3AF',
    marginTop: 4,
    textAlign: 'center',
  },
});
