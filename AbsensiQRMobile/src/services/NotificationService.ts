import messaging, {
  FirebaseMessagingTypes,
} from '@react-native-firebase/messaging';
import {Platform, PermissionsAndroid} from 'react-native';
import {apiClient} from '../api';
import {SecureStorage} from './SecureStorage';

const FCM_TOKEN_KEY = 'fcm_token';

export class NotificationService {
  /**
   * Request notification permission
   */
  static async requestPermission(): Promise<boolean> {
    if (Platform.OS === 'android' && Platform.Version >= 33) {
      const granted = await PermissionsAndroid.request(
        PermissionsAndroid.PERMISSIONS.POST_NOTIFICATIONS,
      );
      return granted === PermissionsAndroid.RESULTS.GRANTED;
    }

    const authStatus = await messaging().requestPermission();
    return (
      authStatus === messaging.AuthorizationStatus.AUTHORIZED ||
      authStatus === messaging.AuthorizationStatus.PROVISIONAL
    );
  }

  /**
   * Get FCM token and save to server
   */
  static async registerDevice(): Promise<string | null> {
    try {
      const hasPermission = await this.requestPermission();
      if (!hasPermission) {
        console.log('[NotificationService] Notification permission denied');
        return null;
      }

      // Get FCM token
      const token = await messaging().getToken();
      console.log('[NotificationService] FCM Token obtained');

      // Save to SecureStorage (encrypted)
      await SecureStorage.setItem(FCM_TOKEN_KEY, token);

      // Send to backend
      await apiClient.post('/user/device-token', {
        device_token: token,
        platform: Platform.OS,
      });

      console.log(
        '✅ [NotificationService] Device registered for notifications',
      );
      return token;
    } catch (error) {
      console.error('[NotificationService] Failed to register device:', error);
      return null;
    }
  }

  /**
   * Setup notification listeners
   */
  static setupListeners(
    onNotificationReceived: (
      notification: FirebaseMessagingTypes.RemoteMessage,
    ) => void,
    onNotificationOpened: (
      notification: FirebaseMessagingTypes.RemoteMessage,
    ) => void,
  ): () => void {
    // Foreground notifications
    const unsubscribeForeground = messaging().onMessage(async remoteMessage => {
      console.log(
        '📬 [NotificationService] Foreground notification:',
        remoteMessage.messageId,
      );
      onNotificationReceived(remoteMessage);
    });

    // Background/Quit state notifications
    messaging().onNotificationOpenedApp(remoteMessage => {
      console.log(
        '📬 [NotificationService] Notification opened app:',
        remoteMessage.messageId,
      );
      onNotificationOpened(remoteMessage);
    });

    // Check if app was opened from notification (quit state)
    messaging()
      .getInitialNotification()
      .then(remoteMessage => {
        if (remoteMessage) {
          console.log(
            '📬 [NotificationService] App opened from notification:',
            remoteMessage.messageId,
          );
          onNotificationOpened(remoteMessage);
        }
      });

    // Token refresh listener
    const unsubscribeTokenRefresh = messaging().onTokenRefresh(async token => {
      console.log('🔄 [NotificationService] FCM token refreshed');
      await SecureStorage.setItem(FCM_TOKEN_KEY, token);
      try {
        await apiClient.post('/user/device-token', {
          device_token: token,
          platform: Platform.OS,
        });
      } catch (err) {
        console.error(
          '[NotificationService] Failed to refresh token on server',
          err,
        );
      }
    });

    // Return cleanup function
    return () => {
      unsubscribeForeground();
      unsubscribeTokenRefresh();
    };
  }
}
