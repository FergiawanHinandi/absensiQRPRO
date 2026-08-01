import React, {useEffect, useState} from 'react';
import {
  View,
  Text,
  StyleSheet,
  ScrollView,
  TouchableOpacity,
  ActivityIndicator,
} from 'react-native';
import {useAuth} from '../../contexts/AuthContext';
import apiClient from '../../api/client';

interface ProfileData {
  name?: string;
  username?: string;
  email?: string;
  phone?: string;
  school_name?: string;
}

export const ParentProfileScreen = () => {
  const {user, logout} = useAuth();
  const [profile, setProfile] = useState<ProfileData | null>(null);
  const [loadingProfile, setLoadingProfile] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const fetchProfile = async () => {
      try {
        setError(null);
        const response = await apiClient.get('/parent/profile');
        if (response.data?.success) {
          setProfile(response.data.data);
        }
      } catch (err: any) {
        const message =
          err?.response?.data?.message || err?.message || 'Gagal memuat profil';
        setError(message);
        console.log('Failed to fetch parent profile:', err);
      } finally {
        setLoadingProfile(false);
      }
    };
    fetchProfile();
  }, []);

  // Merge API data with auth context, API data takes priority
  const displayData = {
    name: profile?.name || user?.name || '-',
    username: profile?.username || user?.username || '-',
    email: profile?.email || user?.email || '-',
    phone: profile?.phone || '-',
    school_name: profile?.school_name || user?.school_name || '-',
  };

  if (!user) {
    return (
      <View style={styles.center}>
        <ActivityIndicator size="large" color="#8B5CF6" />
        <Text style={{marginTop: 8}}>Memuat profil...</Text>
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
        <Text style={styles.role}>Orang Tua/Wali</Text>
      </View>

      {error && (
        <Text
          style={{
            color: '#DC2626',
            textAlign: 'center',
            padding: 10,
            backgroundColor: '#FEE2E2',
            borderRadius: 8,
            margin: 16,
            marginBottom: 0,
          }}>
          {error}
        </Text>
      )}

      <View style={styles.card}>
        <Text style={styles.cardTitle}>Informasi Pribadi</Text>
        {loadingProfile ? (
          <ActivityIndicator
            size="small"
            color="#8B5CF6"
            style={{paddingVertical: 20}}
          />
        ) : (
          <>
            <InfoRow label="Username" value={displayData.username} />
            <InfoRow label="Email" value={displayData.email} />
            <InfoRow label="Telepon" value={displayData.phone} />
            <InfoRow label="Sekolah" value={displayData.school_name} />
          </>
        )}
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
    backgroundColor: '#8B5CF6',
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
  avatarText: {fontSize: 28, fontWeight: 'bold', color: '#8B5CF6'},
  name: {fontSize: 20, fontWeight: 'bold', color: '#fff'},
  role: {fontSize: 14, color: '#DDD6FE', marginTop: 4},
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
