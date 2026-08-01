/**
 * Sample React Native App
 * https://github.com/facebook/react-native
 *
 * @format
 */

import React, { useEffect } from 'react';
import { Alert } from 'react-native';
import { AuthProvider } from './src/contexts/AuthContext';
import { RootNavigator } from './src/navigation/RootNavigator';
import { NotificationService } from './src/services/NotificationService';
import { offlineSyncService } from './src/services/OfflineSyncService';

function App(): React.JSX.Element {
  useEffect(() => {
    // Initialize offline sync network listener
    offlineSyncService.init();

    // Setup push notifications
    NotificationService.registerDevice();
    const cleanupNotifications = NotificationService.setupListeners(
      (remoteMessage) => {
        // Handle foreground message
        Alert.alert(
          remoteMessage.notification?.title || 'Notifikasi Baru',
          remoteMessage.notification?.body || ''
        );
      },
      (remoteMessage) => {
        // Handle notification tap
        console.log('App: User tapped on notification', remoteMessage.messageId);
      }
    );

    return () => {
      offlineSyncService.destroy();
      cleanupNotifications();
    };
  }, []);

  return (
    <AuthProvider>
      <RootNavigator />
    </AuthProvider>
  );
}

export default App;
