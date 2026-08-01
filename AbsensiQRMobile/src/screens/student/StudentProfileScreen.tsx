import React, {useEffect, useState} from 'react';
import {
  View,
  Text,
  StyleSheet,
  ScrollView,
  TouchableOpacity,
} from 'react-native';
import {useAuth} from '../../contexts/AuthContext';
import apiClient from '../../api/client';

interface ProfileData {
  id: number;
  name: string;
  username: string;
  email: string;
  phone?: string;
  class_name?: string;
  school_name?: string;
  nis?: string;
}

export const StudentProfileScreen = () => {
  const {user, logout} = useAuth();
  const [profile, setProfile] = useState<ProfileData | null>(null);

  useEffect(() => {
    const fetchProfile = async () => {
      try {
        const response = await apiClient.get('/student/profile');
        if (response.data?.success) {
          setProfile(response.data.data);
        }
      } catch (error) {
        // Use user data from context as fallback
        if (user) {
          setProfile({
            id: user.id,
            name: user.name,
            username: user.username,
            email: user.email,
            school_name: user.school_name,
          });
        }
      }
    };
    fetchProfile();
  }, [user]);

  const displayData =
    profile ||
    (user
      ? {
          id: user.id,
          name: user.name,
          username: user.username,
          email: user.email,
          school_name: user.school_name,
        }
      : null);

  if (!displayData) {
    return (
      <View style={styles.center}>
        <Text style={styles.loadingText}>Memuat profil...</Text>
      </View>
    );
  }

  const InfoRow = ({label, value}: {label: string; value?: string}) => (
    <View style={styles.infoRow}>
      <Text style={styles.infoLabel}>{label}</Text>
      <Text style={styles.infoValue}>{value || '-'}</Text>
    </View>
  );

  return (
    <ScrollView style={styles.container}>
      {/* Avatar Header */}
      <View style={styles.avatarSection}>
        <View style={styles.avatar}>
          <Text style={styles.avatarText}>
            {displayData.name.charAt(0).toUpperCase()}
          </Text>
        </View>
        <Text style={styles.name}>{displayData.name}</Text>
        <Text style={styles.role}>Siswa</Text>
      </View>

      {/* Info Card */}
      <View style={styles.card}>
        <Text style={styles.cardTitle}>Informasi Pribadi</Text>
        <InfoRow label="NIS" value={profile?.nis} />
        <InfoRow label="Username" value={displayData.username} />
        <InfoRow label="Email" value={displayData.email} />
        <InfoRow label="Telepon" value={profile?.phone} />
        <InfoRow label="Kelas" value={profile?.class_name} />
        <InfoRow label="Sekolah" value={displayData.school_name} />
      </View>

      {/* Logout */}
      <TouchableOpacity style={styles.logoutButton} onPress={logout}>
        <Text style={styles.logoutText}>Keluar</Text>
      </TouchableOpacity>
    </ScrollView>
  );
};

const styles = StyleSheet.create({
  container: {flex: 1, backgroundColor: '#F3F4F6'},
  center: {flex: 1, justifyContent: 'center', alignItems: 'center'},
  loadingText: {fontSize: 16, color: '#6B7280'},
  avatarSection: {
    alignItems: 'center',
    paddingVertical: 30,
    backgroundColor: '#3B82F6',
  },
  avatar: {
    width: 72,
    height: 72,
    borderRadius: 36,
    backgroundColor: '#fff',
    justifyContent: 'center',
    alignItems: 'center',
    marginBottom: 12,
  },
  avatarText: {fontSize: 28, fontWeight: 'bold', color: '#3B82F6'},
  name: {fontSize: 20, fontWeight: 'bold', color: '#fff'},
  role: {fontSize: 14, color: '#BFDBFE', marginTop: 4},
  card: {
    backgroundColor: '#fff',
    borderRadius: 12,
    padding: 16,
    margin: 16,
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
  infoRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingVertical: 10,
    borderBottomWidth: 1,
    borderBottomColor: '#F3F4F6',
  },
  infoLabel: {fontSize: 14, color: '#6B7280'},
  infoValue: {fontSize: 14, color: '#111827', fontWeight: '500'},
  logoutButton: {
    backgroundColor: '#EF4444',
    borderRadius: 10,
    padding: 14,
    margin: 16,
    alignItems: 'center',
  },
  logoutText: {color: '#fff', fontSize: 15, fontWeight: '600'},
});
