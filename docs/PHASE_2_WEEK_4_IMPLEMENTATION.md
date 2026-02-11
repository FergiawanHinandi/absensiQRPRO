# PHASE 2: MINGGU 4-6 - COMPLETE CORE FUNCTIONALITY
## Implementation Guide dengan Contoh Kode

**Timeline**: Week 4-6 (15 hari kerja)  
**Goal**: Melengkapi 50% fitur yang hilang di mobile app dan web dashboard  
**Status Target**: 70% → 90% production ready

---

## 🗓️ WEEK 4: MOBILE APP CORE FEATURES

### **DAY 16: [P1] GPS Accuracy Improvement**

#### 🔍 **Problem Analysis**
- **Root Cause**: Default GPS settings tidak optimal, tidak ada filtering untuk bad readings
- **Impact**: Attendance rejected karena lokasi tidak akurat (user complaint tinggi)
- **Priority**: HIGH (blocking core feature)

#### 🛠️ **Step-by-Step Solution**

**Step 1: Install Better Location Library**
```bash
npm install react-native-geolocation-service
npm install @react-native-community/geolocation
```

**Step 2: Request High Accuracy Permission** (`android/app/src/main/AndroidManifest.xml`):
```xml
<uses-permission android:name="android.permission.ACCESS_FINE_LOCATION" />
<uses-permission android:name="android.permission.ACCESS_COARSE_LOCATION" />
<!-- For Android 10+ background location -->
<uses-permission android:name="android.permission.ACCESS_BACKGROUND_LOCATION" />
```

**Step 3: Implement Smart Location Service** (`src/services/locationService.ts`):
```typescript
import Geolocation from 'react-native-geolocation-service';
import { PermissionsAndroid, Platform } from 'react-native';

interface LocationData {
  latitude: number;
  longitude: number;
  accuracy: number;
  timestamp: number;
}

export class LocationService {
  private static readonly MAX_ACCURACY = 50; // meters
  private static readonly TIMEOUT = 15000; // 15 seconds
  private static readonly MAX_AGE = 5000; // 5 seconds

  /**
   * Request location permissions
   */
  static async requestPermission(): Promise<boolean> {
    if (Platform.OS === 'android') {
      const granted = await PermissionsAndroid.request(
        PermissionsAndroid.PERMISSIONS.ACCESS_FINE_LOCATION,
        {
          title: 'Izin Akses Lokasi',
          message: 'Aplikasi membutuhkan akses lokasi untuk absensi',
          buttonPositive: 'OK',
          buttonNegative: 'Batal',
        }
      );
      return granted === PermissionsAndroid.RESULTS.GRANTED;
    }
    return true; // iOS handles via Info.plist
  }

  /**
   * Get current location with high accuracy
   * Retries up to 3 times if accuracy is poor
   */
  static async getCurrentLocation(): Promise<LocationData> {
    const hasPermission = await this.requestPermission();
    if (!hasPermission) {
      throw new Error('PERMISSION_DENIED');
    }

    let attempts = 0;
    const MAX_ATTEMPTS = 3;

    while (attempts < MAX_ATTEMPTS) {
      try {
        const location = await this.getLocationOnce();
        
        // Check accuracy
        if (location.accuracy <= this.MAX_ACCURACY) {
          console.log(`✅ Good location (accuracy: ${location.accuracy}m)`);
          return location;
        }
        
        console.log(`⚠️ Poor accuracy (${location.accuracy}m), retrying... (${attempts + 1}/${MAX_ATTEMPTS})`);
        attempts++;
        
        // Wait 2 seconds before retry
        await new Promise(resolve => setTimeout(resolve, 2000));
      } catch (error) {
        attempts++;
        if (attempts >= MAX_ATTEMPTS) {
          throw error;
        }
      }
    }

    throw new Error('LOCATION_ACCURACY_TOO_LOW');
  }

  /**
   * Get location once (internal method)
   */
  private static getLocationOnce(): Promise<LocationData> {
    return new Promise((resolve, reject) => {
      Geolocation.getCurrentPosition(
        (position) => {
          resolve({
            latitude: position.coords.latitude,
            longitude: position.coords.longitude,
            accuracy: position.coords.accuracy,
            timestamp: position.timestamp,
          });
        },
        (error) => {
          console.error('Location error:', error);
          reject(new Error(`LOCATION_ERROR: ${error.message}`));
        },
        {
          accuracy: {
            android: 'high',
            ios: 'best',
          },
          enableHighAccuracy: true,
          timeout: this.TIMEOUT,
          maximumAge: this.MAX_AGE,
          distanceFilter: 0,
        }
      );
    });
  }

  /**
   * Calculate distance between two points (Haversine formula)
   */
  static calculateDistance(
    lat1: number,
    lon1: number,
    lat2: number,
    lon2: number
  ): number {
    const R = 6371e3; // Earth radius in meters
    const φ1 = (lat1 * Math.PI) / 180;
    const φ2 = (lat2 * Math.PI) / 180;
    const Δφ = ((lat2 - lat1) * Math.PI) / 180;
    const Δλ = ((lon2 - lon1) * Math.PI) / 180;

    const a =
      Math.sin(Δφ / 2) * Math.sin(Δφ / 2) +
      Math.cos(φ1) * Math.cos(φ2) * Math.sin(Δλ / 2) * Math.sin(Δλ / 2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));

    return R * c; // Distance in meters
  }

  /**
   * Validate if user is within school radius
   */
  static isWithinSchoolRadius(
    userLat: number,
    userLon: number,
    schoolLat: number,
    schoolLon: number,
    radiusMeters: number = 100
  ): { valid: boolean; distance: number } {
    const distance = this.calculateDistance(userLat, userLon, schoolLat, schoolLon);
    return {
      valid: distance <= radiusMeters,
      distance: Math.round(distance),
    };
  }
}
```

**Step 4: Use in QR Scan Screen** (`src/screens/QRScanScreen.tsx`):
```typescript
import { LocationService } from '../services/locationService';
import { Alert } from 'react-native';

const QRScanScreen = () => {
  const [scanning, setScanning] = useState(false);
  const [location, setLocation] = useState<any>(null);

  const handleScan = async (qrData: string) => {
    setScanning(true);
    
    try {
      // 1. Get accurate location
      const userLocation = await LocationService.getCurrentLocation();
      setLocation(userLocation);
      
      // 2. Validate against school location
      const schoolLocation = {
        latitude: -6.200000, // From QR data or API
        longitude: 106.816666,
      };
      
      const validation = LocationService.isWithinSchoolRadius(
        userLocation.latitude,
        userLocation.longitude,
        schoolLocation.latitude,
        schoolLocation.longitude,
        100 // 100 meters radius
      );
      
      if (!validation.valid) {
        Alert.alert(
          'Lokasi Tidak Valid',
          `Anda berada ${validation.distance}m dari sekolah. Maksimal jarak: 100m.`,
          [{ text: 'OK' }]
        );
        return;
      }
      
      // 3. Submit attendance
      const response = await api.post('/attendance/scan', {
        qr_token: qrData,
        latitude: userLocation.latitude,
        longitude: userLocation.longitude,
        accuracy: userLocation.accuracy,
      });
      
      Alert.alert('Sukses', 'Absensi berhasil dicatat!');
      
    } catch (error: any) {
      if (error.message === 'PERMISSION_DENIED') {
        Alert.alert('Izin Ditolak', 'Aplikasi membutuhkan akses lokasi untuk absensi.');
      } else if (error.message === 'LOCATION_ACCURACY_TOO_LOW') {
        Alert.alert('GPS Tidak Akurat', 'Pastikan GPS aktif dan Anda berada di luar ruangan.');
      } else {
        Alert.alert('Error', 'Gagal mendapatkan lokasi. Coba lagi.');
      }
    } finally {
      setScanning(false);
    }
  };

  return (
    <View style={styles.container}>
      {/* QR Scanner UI */}
      
      {/* Location Debug Info */}
      {location && (
        <View style={styles.debugInfo}>
          <Text>Akurasi: {location.accuracy.toFixed(1)}m</Text>
          <Text>Lat: {location.latitude.toFixed(6)}</Text>
          <Text>Lon: {location.longitude.toFixed(6)}</Text>
        </View>
      )}
    </View>
  );
};
```

#### ✅ **Success Criteria**
- [ ] GPS accuracy consistently <50 meters
- [ ] Location obtained within 15 seconds
- [ ] Retry mechanism works (3 attempts)
- [ ] Distance validation accurate
- [ ] User-friendly error messages

#### ⏱️ **Time Estimate**
- Preparation: 1 jam (install libraries, setup permissions)
- Implementation: 4 jam (location service + integration)
- Testing: 3 jam (test di berbagai kondisi GPS)
- **Total**: 8 jam (1 hari kerja)

#### 🚨 **Common Pitfalls & Solutions**

**Pitfall 1**: GPS tidak berfungsi di emulator
- **Solution**: Test di real device, atau gunakan mock location di emulator

**Pitfall 2**: Accuracy selalu >100m di dalam gedung
- **Solution**: Tambahkan fallback mode "Manual Check-in" untuk kasus khusus

**Pitfall 3**: Permission denied di Android 11+
- **Solution**: Request `ACCESS_BACKGROUND_LOCATION` secara terpisah setelah `ACCESS_FINE_LOCATION`

#### 📚 **Learning Resources**
- [React Native Geolocation](https://github.com/Agontuk/react-native-geolocation-service)
- [Android Location Best Practices](https://developer.android.com/training/location/permissions)
- [Haversine Formula Explained](https://www.movable-type.co.uk/scripts/latlong.html)

---

### **DAY 17-18: [P1] Offline Attendance Queue (Advanced)**

#### 🔍 **Problem Analysis**
- **Root Cause**: No offline capability, users lose data when network unavailable
- **Impact**: Attendance data loss, user frustration
- **Priority**: HIGH (core feature missing)

#### 🛠️ **Step-by-Step Solution**

**Step 1: Install Dependencies**
```bash
npm install @react-native-async-storage/async-storage
npm install @react-native-community/netinfo
npm install react-native-background-fetch
```

**Step 2: Create Advanced Queue Manager** (`src/services/offlineQueueManager.ts`):
```typescript
import AsyncStorage from '@react-native-async-storage/async-storage';
import NetInfo from '@react-native-community/netinfo';
import BackgroundFetch from 'react-native-background-fetch';
import { api } from './api';

const QUEUE_KEY = '@attendance_queue';
const MAX_RETRY_ATTEMPTS = 5;

export interface QueuedAttendance {
  id: string;
  qr_token: string;
  latitude: number;
  longitude: number;
  accuracy: number;
  timestamp: string;
  retry_count: number;
  status: 'pending' | 'syncing' | 'failed' | 'synced';
  error_message?: string;
}

export class OfflineQueueManager {
  private static syncInProgress = false;

  /**
   * Add attendance to queue
   */
  static async enqueue(attendance: Omit<QueuedAttendance, 'id' | 'retry_count' | 'status'>): Promise<string> {
    const queue = await this.getQueue();
    
    const newItem: QueuedAttendance = {
      ...attendance,
      id: `${Date.now()}_${Math.random().toString(36).substr(2, 9)}`,
      retry_count: 0,
      status: 'pending',
    };
    
    queue.push(newItem);
    await this.saveQueue(queue);
    
    console.log(`✅ Queued attendance: ${newItem.id}`);
    
    // Try immediate sync if online
    this.syncQueue();
    
    return newItem.id;
  }

  /**
   * Get all queued items
   */
  static async getQueue(): Promise<QueuedAttendance[]> {
    try {
      const data = await AsyncStorage.getItem(QUEUE_KEY);
      return data ? JSON.parse(data) : [];
    } catch (error) {
      console.error('Failed to get queue:', error);
      return [];
    }
  }

  /**
   * Save queue to storage
   */
  private static async saveQueue(queue: QueuedAttendance[]): Promise<void> {
    await AsyncStorage.setItem(QUEUE_KEY, JSON.stringify(queue));
  }

  /**
   * Sync queue with server
   */
  static async syncQueue(): Promise<{ success: number; failed: number }> {
    // Prevent concurrent syncs
    if (this.syncInProgress) {
      console.log('⏳ Sync already in progress, skipping...');
      return { success: 0, failed: 0 };
    }

    this.syncInProgress = true;
    let successCount = 0;
    let failedCount = 0;

    try {
      // Check network
      const netInfo = await NetInfo.fetch();
      if (!netInfo.isConnected) {
        console.log('📡 No internet connection, skipping sync');
        return { success: 0, failed: 0 };
      }

      const queue = await this.getQueue();
      const pendingItems = queue.filter(item => item.status === 'pending' || item.status === 'failed');

      console.log(`🔄 Syncing ${pendingItems.length} items...`);

      for (const item of pendingItems) {
        try {
          // Update status to syncing
          item.status = 'syncing';
          await this.saveQueue(queue);

          // Send to server
          await api.post('/attendance/scan', {
            qr_token: item.qr_token,
            latitude: item.latitude,
            longitude: item.longitude,
            accuracy: item.accuracy,
            offline_timestamp: item.timestamp,
          });

          // Success - mark as synced
          item.status = 'synced';
          successCount++;
          console.log(`✅ Synced: ${item.id}`);

        } catch (error: any) {
          // Failed - increment retry count
          item.retry_count++;
          item.error_message = error.message;

          if (item.retry_count >= MAX_RETRY_ATTEMPTS) {
            item.status = 'failed';
            console.error(`❌ Max retries reached for ${item.id}`);
          } else {
            item.status = 'pending';
            console.warn(`⚠️ Retry ${item.retry_count}/${MAX_RETRY_ATTEMPTS} for ${item.id}`);
          }
          
          failedCount++;
        }
      }

      // Save updated queue
      await this.saveQueue(queue);

      // Clean up synced items older than 7 days
      await this.cleanupOldItems();

      console.log(`✅ Sync complete: ${successCount} success, ${failedCount} failed`);
      
    } finally {
      this.syncInProgress = false;
    }

    return { success: successCount, failed: failedCount };
  }

  /**
   * Remove synced items older than 7 days
   */
  private static async cleanupOldItems(): Promise<void> {
    const queue = await this.getQueue();
    const sevenDaysAgo = Date.now() - (7 * 24 * 60 * 60 * 1000);
    
    const filtered = queue.filter(item => {
      if (item.status === 'synced') {
        const itemTime = new Date(item.timestamp).getTime();
        return itemTime > sevenDaysAgo;
      }
      return true; // Keep pending/failed items
    });

    if (filtered.length < queue.length) {
      await this.saveQueue(filtered);
      console.log(`🗑️ Cleaned up ${queue.length - filtered.length} old items`);
    }
  }

  /**
   * Get queue statistics
   */
  static async getStats(): Promise<{
    total: number;
    pending: number;
    syncing: number;
    synced: number;
    failed: number;
  }> {
    const queue = await this.getQueue();
    
    return {
      total: queue.length,
      pending: queue.filter(i => i.status === 'pending').length,
      syncing: queue.filter(i => i.status === 'syncing').length,
      synced: queue.filter(i => i.status === 'synced').length,
      failed: queue.filter(i => i.status === 'failed').length,
    };
  }

  /**
   * Setup background sync
   */
  static async setupBackgroundSync(): Promise<void> {
    await BackgroundFetch.configure(
      {
        minimumFetchInterval: 15, // minutes
        stopOnTerminate: false,
        startOnBoot: true,
        enableHeadless: true,
      },
      async (taskId) => {
        console.log('[BackgroundFetch] Task started:', taskId);
        
        try {
          await this.syncQueue();
        } catch (error) {
          console.error('[BackgroundFetch] Sync failed:', error);
        }
        
        BackgroundFetch.finish(taskId);
      },
      (taskId) => {
        console.log('[BackgroundFetch] Task timeout:', taskId);
        BackgroundFetch.finish(taskId);
      }
    );

    console.log('✅ Background sync configured');
  }
}
```

**Step 3: Integrate in App** (`App.tsx`):
```typescript
import { OfflineQueueManager } from './services/offlineQueueManager';
import NetInfo from '@react-native-community/netinfo';

useEffect(() => {
  // Setup background sync
  OfflineQueueManager.setupBackgroundSync();

  // Sync when app comes to foreground
  const appStateSubscription = AppState.addEventListener('change', (nextAppState) => {
    if (nextAppState === 'active') {
      OfflineQueueManager.syncQueue();
    }
  });

  // Sync when network becomes available
  const netInfoSubscription = NetInfo.addEventListener(state => {
    if (state.isConnected) {
      console.log('📡 Network available, syncing...');
      OfflineQueueManager.syncQueue();
    }
  });

  return () => {
    appStateSubscription.remove();
    netInfoSubscription();
  };
}, []);
```

**Step 4: Update QR Scan Screen**:
```typescript
const handleScan = async (qrData: string) => {
  try {
    const location = await LocationService.getCurrentLocation();
    const netInfo = await NetInfo.fetch();

    const attendanceData = {
      qr_token: qrData,
      latitude: location.latitude,
      longitude: location.longitude,
      accuracy: location.accuracy,
      timestamp: new Date().toISOString(),
    };

    if (netInfo.isConnected) {
      // Try online first
      try {
        await api.post('/attendance/scan', attendanceData);
        Alert.alert('✅ Sukses', 'Absensi berhasil dicatat');
      } catch (error) {
        // Online failed, queue it
        await OfflineQueueManager.enqueue(attendanceData);
        Alert.alert('📦 Disimpan Offline', 'Absensi akan dikirim saat online');
      }
    } else {
      // Offline mode
      await OfflineQueueManager.enqueue(attendanceData);
      Alert.alert('📦 Mode Offline', 'Absensi disimpan, akan dikirim saat online');
    }
  } catch (error) {
    Alert.alert('❌ Error', 'Gagal mencatat absensi');
  }
};
```

**Step 5: Create Queue Status Screen** (`src/screens/QueueStatusScreen.tsx`):
```typescript
import { OfflineQueueManager } from '../services/offlineQueueManager';

const QueueStatusScreen = () => {
  const [stats, setStats] = useState<any>(null);
  const [syncing, setSyncing] = useState(false);

  useEffect(() => {
    loadStats();
  }, []);

  const loadStats = async () => {
    const data = await OfflineQueueManager.getStats();
    setStats(data);
  };

  const handleManualSync = async () => {
    setSyncing(true);
    const result = await OfflineQueueManager.syncQueue();
    setSyncing(false);
    
    Alert.alert(
      'Sync Selesai',
      `Berhasil: ${result.success}\nGagal: ${result.failed}`
    );
    
    loadStats();
  };

  if (!stats) return <LoadingSpinner />;

  return (
    <View style={styles.container}>
      <Text style={styles.title}>Status Antrian Offline</Text>
      
      <View style={styles.statsCard}>
        <StatItem label="Total" value={stats.total} color="#3b82f6" />
        <StatItem label="Pending" value={stats.pending} color="#f59e0b" />
        <StatItem label="Synced" value={stats.synced} color="#10b981" />
        <StatItem label="Failed" value={stats.failed} color="#ef4444" />
      </View>

      <TouchableOpacity
        style={styles.syncButton}
        onPress={handleManualSync}
        disabled={syncing}
      >
        <Text style={styles.syncButtonText}>
          {syncing ? 'Syncing...' : 'Sync Sekarang'}
        </Text>
      </TouchableOpacity>
    </View>
  );
};
```

#### ✅ **Success Criteria**
- [ ] Attendance saved offline when no internet
- [ ] Auto-sync when network available
- [ ] Background sync works every 15 minutes
- [ ] Retry mechanism (max 5 attempts)
- [ ] Queue status screen shows accurate data
- [ ] Old synced items cleaned up after 7 days

#### ⏱️ **Time Estimate**
- Preparation: 2 jam
- Implementation: 10 jam (2 hari)
- Testing: 4 jam
- **Total**: 16 jam (2 hari kerja)

#### 🚨 **Common Pitfalls & Solutions**

**Pitfall 1**: Background sync not working on iOS
- **Solution**: Add background modes in `Info.plist`: `fetch`, `processing`

**Pitfall 2**: Queue grows too large (>1000 items)
- **Solution**: Implement pagination in queue display, limit max queue size

**Pitfall 3**: Duplicate submissions after sync
- **Solution**: Backend should implement idempotency check using `offline_timestamp`

---

### **DAY 19-20: [P1] Push Notifications**

#### 🔍 **Problem Analysis**
- **Root Cause**: No real-time alerts for attendance events
- **Impact**: Users miss important notifications (late, absent, etc.)
- **Priority**: HIGH (user engagement feature)

#### 🛠️ **Step-by-Step Solution**

**Step 1: Setup Firebase Cloud Messaging**
```bash
npm install @react-native-firebase/app
npm install @react-native-firebase/messaging
```

**Step 2: Configure Firebase** (`android/app/google-services.json`):
```json
// Download from Firebase Console
// Place in android/app/google-services.json
```

**Step 3: Create Notification Service** (`src/services/notificationService.ts`):
```typescript
import messaging from '@react-native-firebase/messaging';
import { Platform, PermissionsAndroid } from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { api } from './api';

const FCM_TOKEN_KEY = '@fcm_token';

export class NotificationService {
  /**
   * Request notification permission
   */
  static async requestPermission(): Promise<boolean> {
    if (Platform.OS === 'android' && Platform.Version >= 33) {
      const granted = await PermissionsAndroid.request(
        PermissionsAndroid.PERMISSIONS.POST_NOTIFICATIONS
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
        console.log('Notification permission denied');
        return null;
      }

      // Get FCM token
      const token = await messaging().getToken();
      console.log('FCM Token:', token);

      // Save to AsyncStorage
      await AsyncStorage.setItem(FCM_TOKEN_KEY, token);

      // Send to backend
      await api.post('/user/device-token', {
        device_token: token,
        platform: Platform.OS,
      });

      console.log('✅ Device registered for notifications');
      return token;
    } catch (error) {
      console.error('Failed to register device:', error);
      return null;
    }
  }

  /**
   * Setup notification listeners
   */
  static setupListeners(
    onNotificationReceived: (notification: any) => void,
    onNotificationOpened: (notification: any) => void
  ): () => void {
    // Foreground notifications
    const unsubscribeForeground = messaging().onMessage(async (remoteMessage) => {
      console.log('📬 Foreground notification:', remoteMessage);
      onNotificationReceived(remoteMessage);
    });

    // Background/Quit state notifications
    messaging().onNotificationOpenedApp((remoteMessage) => {
      console.log('📬 Notification opened app:', remoteMessage);
      onNotificationOpened(remoteMessage);
    });

    // Check if app was opened from notification (quit state)
    messaging()
      .getInitialNotification()
      .then((remoteMessage) => {
        if (remoteMessage) {
          console.log('📬 App opened from notification:', remoteMessage);
          onNotificationOpened(remoteMessage);
        }
      });

    // Token refresh
    const unsubscribeTokenRefresh = messaging().onTokenRefresh(async (token) => {
      console.log('🔄 FCM token refreshed:', token);
      await AsyncStorage.setItem(FCM_TOKEN_KEY, token);
      await api.post('/user/device-token', {
        device_token: token,
        platform: Platform.OS,
      });
    });

    // Return cleanup function
    return () => {
      unsubscribeForeground();
      unsubscribeTokenRefresh();
    };
  }

  /**
   * Display local notification (for foreground)
   */
  static async showLocalNotification(title: string, body: string, data?: any): Promise<void> {
    // Use react-native-push-notification or similar
    // For simplicity, using Alert here
    Alert.alert(title, body);
  }
}
```

**Step 4: Integrate in App** (`App.tsx`):
```typescript
import { NotificationService } from './services/notificationService';

useEffect(() => {
  // Register device for notifications
  NotificationService.registerDevice();

  // Setup listeners
  const unsubscribe = NotificationService.setupListeners(
    // Foreground notification
    (notification) => {
      NotificationService.showLocalNotification(
        notification.notification.title,
        notification.notification.body,
        notification.data
      );
    },
    // Notification opened
    (notification) => {
      // Navigate based on notification type
      if (notification.data?.type === 'attendance_recorded') {
        navigation.navigate('AttendanceHistory');
      } else if (notification.data?.type === 'late_warning') {
        navigation.navigate('Dashboard');
      }
    }
  );

  return unsubscribe;
}, []);
```

**Step 5: Backend Implementation** (`app/Notifications/AttendanceRecordedNotification.php`):
```php
<?php

namespace App\Notifications;

use App\Models\Attendance;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as FcmNotification;

class AttendanceRecordedNotification extends Notification
{
    use Queueable;

    protected $attendance;

    public function __construct(Attendance $attendance)
    {
        $this->attendance = $attendance;
    }

    public function via($notifiable)
    {
        return [FcmChannel::class, 'database'];
    }

    public function toFcm($notifiable)
    {
        $status = $this->attendance->status;
        $title = $this->getTitleByStatus($status);
        $body = $this->getBodyByStatus($status);

        return FcmMessage::create()
            ->setNotification(
                FcmNotification::create()
                    ->setTitle($title)
                    ->setBody($body)
                    ->setImage(asset('images/notification-icon.png'))
            )
            ->setData([
                'type' => 'attendance_recorded',
                'attendance_id' => $this->attendance->id,
                'status' => $status,
                'timestamp' => now()->toIso8601String(),
            ]);
    }

    public function toArray($notifiable)
    {
        return [
            'attendance_id' => $this->attendance->id,
            'status' => $this->attendance->status,
            'schedule' => $this->attendance->schedule->subject->name,
            'timestamp' => $this->attendance->check_in_time,
        ];
    }

    private function getTitleByStatus($status)
    {
        return match($status) {
            'present' => '✅ Absensi Berhasil',
            'late' => '⏰ Terlambat',
            'absent' => '❌ Tidak Hadir',
            default => 'Absensi Dicatat',
        };
    }

    private function getBodyByStatus($status)
    {
        $schedule = $this->attendance->schedule;
        $subject = $schedule->subject->name;
        
        return match($status) {
            'present' => "Anda hadir di kelas {$subject}",
            'late' => "Anda terlambat di kelas {$subject}",
            'absent' => "Anda tidak hadir di kelas {$subject}",
            default => "Status absensi Anda: {$status}",
        };
    }
}
```

**Step 6: Send Notification** (`app/Services/AttendanceService.php`):
```php
use App\Notifications\AttendanceRecordedNotification;

public function recordAttendance($data)
{
    // ... existing code ...
    
    $attendance = Attendance::create([...]);
    
    // Send notification to student
    $student = $attendance->student;
    $student->notify(new AttendanceRecordedNotification($attendance));
    
    // Also notify parent if exists
    $parents = $student->parents;
    foreach ($parents as $parent) {
        $parent->notify(new AttendanceRecordedNotification($attendance));
    }
    
    return $attendance;
}
```

#### ✅ **Success Criteria**
- [ ] Push notifications received on Android/iOS
- [ ] Foreground notifications show alert
- [ ] Background notifications appear in tray
- [ ] Tapping notification navigates to correct screen
- [ ] Token saved to backend
- [ ] Notifications sent for: present, late, absent

#### ⏱️ **Time Estimate**
- Preparation: 2 jam (Firebase setup)
- Implementation: 8 jam (2 hari)
- Testing: 2 jam
- **Total**: 12 jam (1.5 hari kerja)

---

## 📊 **WEEK 4 SUMMARY**

**Completed Features**:
- ✅ GPS Accuracy Improvement (Day 16)
- ✅ Offline Queue System (Day 17-18)
- ✅ Push Notifications (Day 19-20)

**Next Week Preview**:
- Week 5: Complete Web Dashboard (Charts, Reports, Admin Features)
- Week 6: Integration Testing & Bug Fixes

**Progress**: 70% → 80% production ready

---

*Lanjut ke Week 5-6 di file terpisah untuk menghindari file terlalu panjang.*
