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
  nip?: string;
  school_name?: string;
  subject_specialization?: string;
}

export const TeacherProfileScreen = () => {
  const {user, logout} = useAuth();
  const [profile, setProfile] = useState<ProfileData | null>(null);

  useEffect(() => {
    const fetchProfile = async () => {
      try {
        const response = await apiClient.get('/teacher/profile');
        if (response.data?.success) {
          setProfile(response.data.data);
        }
      } catch {
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
        <Text>Memuat profil...</Text>
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
      <View style={styles.avatarSection}>
        <View style={styles.avatar}>
          <Text style={styles.avatarText}>
            {displayData.name.charAt(0).toUpperCase()}
          </Text>
        </View>
        <Text style={styles.name}>{displayData.name}</Text>
        <Text style={styles.role}>
          {user?.role_type === 'homeroom_teacher' ? 'Wali Kelas' : 'Guru'}
        </Text>
      </View>

      <View style={styles.card}>
        <Text style={styles.cardTitle}>Informasi Pribadi</Text>
        <InfoRow label="NIP" value={profile?.nip} />
        <InfoRow label="Username" value={displayData.username} />
        <InfoRow label="Email" value={displayData.email} />
        <InfoRow label="Telepon" value={profile?.phone} />
        <InfoRow label="Bidang Studi" value={profile?.subject_specialization} />
        <InfoRow label="Sekolah" value={displayData.school_name} />
      </View>

      <TouchableOpacity style={styles.logoutButton} onPress={logout}>
        <Text style={styles.logoutText}>Keluar</Text>
      </TouchableOpacity>
    </ScrollView>
  );
};

const styles = StyleSheet.create({
  container: {flex: 1, backgroundColor: '#F3F4F6'},
  center: {flex: 1, justifyContent: 'center', alignItems: 'center'},
  avatarSection: {
    alignItems: 'center',
    paddingVertical: 30,
    backgroundColor: '#10B981',
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
  avatarText: {fontSize: 28, fontWeight: 'bold', color: '#10B981'},
  name: {fontSize: 20, fontWeight: 'bold', color: '#fff'},
  role: {fontSize: 14, color: '#A7F3D0', marginTop: 4},
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
