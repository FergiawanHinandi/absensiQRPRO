import React, {useState, useEffect} from 'react';
import {
  View,
  Text,
  TextInput,
  TouchableOpacity,
  StyleSheet,
  Alert,
  ActivityIndicator,
} from 'react-native';
import {useAuth} from '../../contexts/AuthContext';
import {useDeviceSecurity} from '../../components/SecurityGuard';
import {mobileSecurityApi} from '../../api/mobileSecurityApi';
import deviceSecurityService from '../../services/DeviceSecurityService';

export const LoginScreen = () => {
  const {login} = useAuth();
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [securityChecking, setSecurityChecking] = useState(true);
  const {securityState, isSecure, riskLevel, deviceFingerprint} =
    useDeviceSecurity();

  // Run security check on mount
  useEffect(() => {
    const checkSecurity = async () => {
      try {
        const result = await deviceSecurityService.runSecurityChecks(true);

        // Block login on critical security issues
        if (result.riskLevel === 'critical') {
          Alert.alert(
            'Perangkat Tidak Aman',
            result.violations[0]?.message ||
              'Perangkat tidak memenuhi persyaratan keamanan',
            [{text: 'OK'}],
          );
        }
      } finally {
        setSecurityChecking(false);
      }
    };

    checkSecurity();
  }, []);

  const handleLogin = async () => {
    if (!username || !password) {
      Alert.alert('Error', 'Username dan password harus diisi');
      return;
    }

    // Block login on critical security issues
    if (riskLevel === 'critical') {
      Alert.alert(
        'Login Diblokir',
        'Perangkat tidak aman untuk login. Silakan gunakan perangkat lain.',
        [{text: 'OK'}],
      );
      return;
    }

    setLoading(true);
    try {
      const result = await login(username, password);

      if (result.success && result.user) {
        // Report device integrity to backend after successful login
        if (securityState) {
          try {
            await mobileSecurityApi.reportDeviceIntegrityCheck(
              deviceFingerprint,
              {
                isSecure,
                riskLevel,
                violationCount: securityState.violations.length,
              },
            );
          } catch {
            // Non-critical - don't block login for security reporting
          }
        }

        Alert.alert('Sukses', `Selamat datang ${result.user.name}`);
        // Navigation is handled automatically by AuthContext → RootNavigator
      } else {
        Alert.alert('Gagal', result.error || 'Login gagal');
      }
    } catch (error: any) {
      console.error(error);
      const msg =
        error.response?.data?.message || 'Gagal login (Cek koneksi/server)';
      Alert.alert('Error', msg);
    } finally {
      setLoading(false);
    }
  };

  // Show loading while checking security
  if (securityChecking) {
    return (
      <View style={styles.loadingContainer}>
        <ActivityIndicator size="large" color="#2563EB" />
        <Text style={styles.loadingText}>Memeriksa keamanan perangkat...</Text>
      </View>
    );
  }

  return (
    <View style={styles.container}>
      <View style={styles.card}>
        <Text style={styles.title}>AbsensiQR Pro</Text>
        <Text style={styles.subtitle}>Masuk ke Akun Anda</Text>

        {/* Security status indicator */}
        {!isSecure && (
          <View style={styles.securityWarning}>
            <Text style={styles.securityWarningText}>
              ⚠️ Peringatan keamanan terdeteksi
            </Text>
          </View>
        )}

        <TextInput
          style={styles.input}
          placeholder="Username / NIS"
          value={username}
          onChangeText={setUsername}
          autoCapitalize="none"
          editable={riskLevel !== 'critical'}
        />

        <TextInput
          style={styles.input}
          placeholder="Password"
          value={password}
          onChangeText={setPassword}
          secureTextEntry
          editable={riskLevel !== 'critical'}
        />

        <TouchableOpacity
          style={[
            styles.button,
            riskLevel === 'critical' && styles.buttonDisabled,
          ]}
          onPress={handleLogin}
          disabled={loading || riskLevel === 'critical'}>
          {loading ? (
            <ActivityIndicator color="#fff" />
          ) : (
            <Text style={styles.buttonText}>MASUK</Text>
          )}
        </TouchableOpacity>

        {riskLevel === 'critical' && (
          <Text style={styles.blockedText}>
            Login diblokir karena masalah keamanan perangkat
          </Text>
        )}
      </View>
    </View>
  );
};

const styles = StyleSheet.create({
  container: {
    flex: 1,
    justifyContent: 'center',
    padding: 20,
    backgroundColor: '#F3F4F6',
  },
  loadingContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    backgroundColor: '#F3F4F6',
  },
  loadingText: {
    marginTop: 16,
    fontSize: 16,
    color: '#6B7280',
  },
  card: {
    backgroundColor: 'white',
    padding: 24,
    borderRadius: 16,
    shadowColor: '#000',
    shadowOffset: {width: 0, height: 2},
    shadowOpacity: 0.1,
    shadowRadius: 4,
    elevation: 3,
  },
  title: {
    fontSize: 24,
    fontWeight: 'bold',
    textAlign: 'center',
    color: '#111827',
    marginBottom: 8,
  },
  subtitle: {
    fontSize: 16,
    textAlign: 'center',
    color: '#6B7280',
    marginBottom: 24,
  },
  securityWarning: {
    backgroundColor: '#FEF3C7',
    borderRadius: 8,
    padding: 12,
    marginBottom: 16,
    borderLeftWidth: 4,
    borderLeftColor: '#F59E0B',
  },
  securityWarningText: {
    color: '#92400E',
    fontSize: 14,
    fontWeight: '500',
  },
  input: {
    backgroundColor: '#F9FAFB',
    borderWidth: 1,
    borderColor: '#E5E7EB',
    borderRadius: 8,
    padding: 12,
    marginBottom: 16,
    fontSize: 16,
  },
  button: {
    backgroundColor: '#2563EB',
    borderRadius: 8,
    padding: 14,
    alignItems: 'center',
  },
  buttonDisabled: {
    backgroundColor: '#9CA3AF',
  },
  buttonText: {
    color: 'white',
    fontWeight: 'bold',
    fontSize: 16,
  },
  blockedText: {
    marginTop: 12,
    color: '#DC2626',
    fontSize: 12,
    textAlign: 'center',
  },
});
