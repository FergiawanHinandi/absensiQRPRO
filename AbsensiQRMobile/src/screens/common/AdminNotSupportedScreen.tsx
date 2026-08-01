import React from 'react';
import {View, Text, StyleSheet, TouchableOpacity, Linking} from 'react-native';
import {useAuth} from '../../contexts/AuthContext';

/**
 * Layar khusus untuk role admin/school_admin/super_admin
 * yang mencoba login via aplikasi mobile.
 *
 * Role admin hanya bisa diakses melalui web dashboard.
 */
export const AdminNotSupportedScreen = () => {
  const {logout} = useAuth();

  return (
    <View style={styles.container}>
      <View style={styles.iconContainer}>
        <Text style={styles.icon}>🖥️</Text>
      </View>
      <Text style={styles.title}>Gunakan Web Dashboard</Text>
      <Text style={styles.description}>
        Akun admin hanya dapat diakses melalui web dashboard. Aplikasi mobile
        ini ditujukan untuk guru, siswa, dan orang tua.
      </Text>
      <Text style={styles.hint}>
        Silakan buka browser dan akses dashboard admin melalui alamat web yang
        telah disediakan.
      </Text>
      <TouchableOpacity style={styles.logoutButton} onPress={logout}>
        <Text style={styles.logoutText}>Keluar</Text>
      </TouchableOpacity>
    </View>
  );
};

const styles = StyleSheet.create({
  container: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    backgroundColor: '#F3F4F6',
    padding: 32,
  },
  iconContainer: {
    width: 80,
    height: 80,
    borderRadius: 40,
    backgroundColor: '#EFF6FF',
    justifyContent: 'center',
    alignItems: 'center',
    marginBottom: 24,
  },
  icon: {
    fontSize: 40,
  },
  title: {
    fontSize: 22,
    fontWeight: '700',
    color: '#111827',
    marginBottom: 12,
    textAlign: 'center',
  },
  description: {
    fontSize: 15,
    color: '#6B7280',
    textAlign: 'center',
    lineHeight: 22,
    marginBottom: 12,
  },
  hint: {
    fontSize: 13,
    color: '#9CA3AF',
    textAlign: 'center',
    lineHeight: 20,
    marginBottom: 32,
  },
  logoutButton: {
    backgroundColor: '#EF4444',
    paddingHorizontal: 32,
    paddingVertical: 14,
    borderRadius: 10,
  },
  logoutText: {
    color: '#fff',
    fontSize: 15,
    fontWeight: '600',
  },
});
