/**
 * Security Guard Component
 *
 * Higher-Order Component (HOC) that wraps screens with security checks.
 * Blocks access to sensitive features if device is compromised.
 *
 * Usage:
 * - Wrap sensitive screens (QR scan, login) with withSecurityGuard
 * - Use SecurityGuard component for inline security checks
 */

import React, {useEffect, useState, useCallback, ComponentType} from 'react';
import {
  View,
  Text,
  StyleSheet,
  ActivityIndicator,
  TouchableOpacity,
  Alert,
  BackHandler,
} from 'react-native';
import deviceSecurityService, {
  SecurityCheckResult,
  SecurityViolation,
} from '../services/DeviceSecurityService';
import {mobileSecurityApi} from '../api/mobileSecurityApi';

// Security check modes
export type SecurityMode = 'strict' | 'warn' | 'log-only';

export interface SecurityGuardProps {
  children: React.ReactNode;
  mode?: SecurityMode;
  requiredFeatures?: ('attendance' | 'login' | 'qr_scan')[];
  onSecurityFailure?: (violations: SecurityViolation[]) => void;
  allowDebugMode?: boolean;
  showBlockingScreen?: boolean;
}

interface SecurityState {
  isChecking: boolean;
  checkResult: SecurityCheckResult | null;
  isAllowed: boolean;
  blockingViolations: SecurityViolation[];
}

/**
 * SecurityGuard Component
 *
 * Wraps children with security checks. Blocks rendering if device is compromised.
 */
export const SecurityGuard: React.FC<SecurityGuardProps> = ({
  children,
  mode = 'strict',
  requiredFeatures = [],
  onSecurityFailure,
  allowDebugMode = false,
  showBlockingScreen = true,
}) => {
  const [state, setState] = useState<SecurityState>({
    isChecking: true,
    checkResult: null,
    isAllowed: false,
    blockingViolations: [],
  });

  const runSecurityCheck = useCallback(async () => {
    setState(prev => ({...prev, isChecking: true}));

    try {
      const result = await deviceSecurityService.runSecurityChecks();

      // Filter violations based on mode and settings
      let blockingViolations = result.violations.filter(
        v => v.severity === 'critical' || v.severity === 'high',
      );

      // Allow debug mode in development if configured
      if (allowDebugMode && __DEV__) {
        blockingViolations = blockingViolations.filter(
          v => v.type !== 'debug_mode',
        );
      }

      // Report violations to backend
      if (result.violations.length > 0) {
        await mobileSecurityApi.reportViolations(
          result.violations,
          result.deviceFingerprint,
        );
      }

      // Determine if access is allowed based on mode
      let isAllowed = true;
      if (mode === 'strict') {
        isAllowed = blockingViolations.length === 0;
      } else if (mode === 'warn') {
        isAllowed = true; // Allow but warn
        if (blockingViolations.length > 0) {
          showSecurityWarning(blockingViolations);
        }
      }
      // 'log-only' mode always allows access

      if (!isAllowed && onSecurityFailure) {
        onSecurityFailure(blockingViolations);
      }

      setState({
        isChecking: false,
        checkResult: result,
        isAllowed,
        blockingViolations,
      });
    } catch (error) {
      console.error('Security check failed:', error);
      // Fail closed in strict mode, open in others
      setState({
        isChecking: false,
        checkResult: null,
        isAllowed: mode !== 'strict',
        blockingViolations: [],
      });
    }
  }, [mode, allowDebugMode, onSecurityFailure]);

  useEffect(() => {
    runSecurityCheck();

    // Recheck when app comes to foreground
    const backHandler = BackHandler.addEventListener(
      'hardwareBackPress',
      () => {
        runSecurityCheck();
        return false;
      },
    );

    return () => backHandler.remove();
  }, [runSecurityCheck]);

  // Show loading state
  if (state.isChecking) {
    return (
      <View style={styles.loadingContainer}>
        <ActivityIndicator size="large" color="#2563EB" />
        <Text style={styles.loadingText}>Memeriksa keamanan perangkat...</Text>
      </View>
    );
  }

  // Show blocking screen if not allowed
  if (!state.isAllowed && showBlockingScreen) {
    return (
      <SecurityBlockedScreen
        violations={state.blockingViolations}
        onRetry={runSecurityCheck}
      />
    );
  }

  return <>{children}</>;
};

/**
 * Security Blocked Screen Component
 */
interface SecurityBlockedScreenProps {
  violations: SecurityViolation[];
  onRetry: () => void;
}

const SecurityBlockedScreen: React.FC<SecurityBlockedScreenProps> = ({
  violations,
  onRetry,
}) => {
  const primaryViolation = violations[0];

  return (
    <View style={styles.blockedContainer}>
      <View style={styles.blockedCard}>
        <Text style={styles.blockedIcon}>🛡️</Text>
        <Text style={styles.blockedTitle}>Perangkat Tidak Aman</Text>
        <Text style={styles.blockedMessage}>
          {primaryViolation?.message ||
            'Perangkat tidak memenuhi persyaratan keamanan untuk absensi'}
        </Text>

        {violations.length > 1 && (
          <View style={styles.violationList}>
            <Text style={styles.violationListTitle}>Masalah terdeteksi:</Text>
            {violations.map((v, index) => (
              <Text key={index} style={styles.violationItem}>
                • {v.message}
              </Text>
            ))}
          </View>
        )}

        <View style={styles.blockedActions}>
          <TouchableOpacity style={styles.retryButton} onPress={onRetry}>
            <Text style={styles.retryButtonText}>Periksa Ulang</Text>
          </TouchableOpacity>
        </View>

        <Text style={styles.helpText}>
          Hubungi administrator jika Anda yakin ini adalah kesalahan.
        </Text>
      </View>
    </View>
  );
};

/**
 * Show security warning alert (for warn mode)
 */
const showSecurityWarning = (violations: SecurityViolation[]) => {
  const messages = violations.map(v => v.message).join('\n');
  Alert.alert(
    '⚠️ Peringatan Keamanan',
    `Terdeteksi masalah keamanan pada perangkat:\n\n${messages}\n\nAbsensi mungkin tidak tercatat dengan benar.`,
    [{text: 'Mengerti', style: 'default'}],
  );
};

/**
 * Higher-Order Component for wrapping screens with security
 */
export function withSecurityGuard<P extends object>(
  WrappedComponent: ComponentType<P>,
  options: Omit<SecurityGuardProps, 'children'> = {},
): ComponentType<P> {
  const displayName =
    WrappedComponent.displayName || WrappedComponent.name || 'Component';

  const WithSecurityGuard: React.FC<P> = props => {
    return (
      <SecurityGuard {...options}>
        <WrappedComponent {...props} />
      </SecurityGuard>
    );
  };

  WithSecurityGuard.displayName = `withSecurityGuard(${displayName})`;

  return WithSecurityGuard;
}

/**
 * Hook for accessing security state
 */
export function useDeviceSecurity() {
  const [securityState, setSecurityState] =
    useState<SecurityCheckResult | null>(null);
  const [isChecking, setIsChecking] = useState(false);

  const checkSecurity = useCallback(async (forceRefresh = false) => {
    setIsChecking(true);
    try {
      const result = await deviceSecurityService.runSecurityChecks(
        forceRefresh,
      );
      setSecurityState(result);
      return result;
    } finally {
      setIsChecking(false);
    }
  }, []);

  useEffect(() => {
    checkSecurity();
  }, [checkSecurity]);

  return {
    securityState,
    isChecking,
    checkSecurity,
    isSecure: securityState?.isSecure ?? false,
    riskLevel: securityState?.riskLevel ?? 'none',
    violations: securityState?.violations ?? [],
    deviceFingerprint: securityState?.deviceFingerprint ?? '',
  };
}

const styles = StyleSheet.create({
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
  blockedContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    backgroundColor: '#FEE2E2',
    padding: 20,
  },
  blockedCard: {
    backgroundColor: 'white',
    borderRadius: 16,
    padding: 24,
    width: '100%',
    maxWidth: 400,
    alignItems: 'center',
    shadowColor: '#000',
    shadowOffset: {width: 0, height: 2},
    shadowOpacity: 0.1,
    shadowRadius: 8,
    elevation: 4,
  },
  blockedIcon: {
    fontSize: 48,
    marginBottom: 16,
  },
  blockedTitle: {
    fontSize: 20,
    fontWeight: 'bold',
    color: '#DC2626',
    marginBottom: 12,
    textAlign: 'center',
  },
  blockedMessage: {
    fontSize: 16,
    color: '#4B5563',
    textAlign: 'center',
    lineHeight: 24,
    marginBottom: 16,
  },
  violationList: {
    width: '100%',
    backgroundColor: '#FEF2F2',
    borderRadius: 8,
    padding: 12,
    marginBottom: 16,
  },
  violationListTitle: {
    fontSize: 14,
    fontWeight: '600',
    color: '#991B1B',
    marginBottom: 8,
  },
  violationItem: {
    fontSize: 14,
    color: '#7F1D1D',
    marginBottom: 4,
  },
  blockedActions: {
    width: '100%',
    marginTop: 8,
  },
  retryButton: {
    backgroundColor: '#2563EB',
    borderRadius: 8,
    padding: 14,
    alignItems: 'center',
  },
  retryButtonText: {
    color: 'white',
    fontWeight: 'bold',
    fontSize: 16,
  },
  helpText: {
    marginTop: 16,
    fontSize: 12,
    color: '#9CA3AF',
    textAlign: 'center',
  },
});

export default SecurityGuard;
