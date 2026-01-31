import AsyncStorage from '@react-native-async-storage/async-storage';
import Toast from 'react-native-toast-message';

const ATTENDANCE_QUEUE_KEY = 'attendance_queue';

export interface QueuedAttendance {
  id: string; // Unique ID for deduplication
  qr_token: string;
  scanned_at: string;
  latitude?: number;
  longitude?: number;
}

/**
 * Retrieves the current attendance queue from AsyncStorage.
 * @returns {Promise<QueuedAttendance[]>} A promise that resolves to an array of queued attendance records.
 */
export const getAttendanceQueue = async (): Promise<QueuedAttendance[]> => {
  try {
    const queueJson = await AsyncStorage.getItem(ATTENDANCE_QUEUE_KEY);
    return queueJson ? JSON.parse(queueJson) : [];
  } catch (error) {
    console.error('Failed to get attendance queue:', error);
    return [];
  }
};

/**
 * Adds a new attendance record to the queue.
 * @param {Omit<QueuedAttendance, 'id'>} attendance - The attendance record to add.
 * @returns {Promise<void>}
 */
export const addAttendanceToQueue = async (attendance: Omit<QueuedAttendance, 'id'>): Promise<void> => {
  try {
    const currentQueue = await getAttendanceQueue();
    
    // Simple deduplication check: check if same token scanned within last minute
    const isDuplicate = currentQueue.some(item => 
      item.qr_token === attendance.qr_token && 
      (new Date(attendance.scanned_at).getTime() - new Date(item.scanned_at).getTime()) < 60000
    );

    if (isDuplicate) {
        console.log('Duplicate offline scan detected, skipping queue.');
        return;
    }

    const newRecord: QueuedAttendance = {
        ...attendance,
        id: Math.random().toString(36).substr(2, 9) + Date.now().toString()
    };

    const updatedQueue = [...currentQueue, newRecord];
    await AsyncStorage.setItem(ATTENDANCE_QUEUE_KEY, JSON.stringify(updatedQueue));
    Toast.show({
      type: 'info',
      text1: 'Offline Mode',
      text2: 'Absensi tersimpan di antrian lokal.',
    });
  } catch (error) {
    console.error('Failed to add attendance to queue:', error);
    Toast.show({
      type: 'error',
      text1: 'Save Failed',
      text2: 'Gagal menyimpan absensi offline.',
    });
  }
};

/**
 * Removes a specific item from the queue
 */
export const removeFromQueue = async (id: string): Promise<void> => {
    try {
        const currentQueue = await getAttendanceQueue();
        const updatedQueue = currentQueue.filter(item => item.id !== id);
        await AsyncStorage.setItem(ATTENDANCE_QUEUE_KEY, JSON.stringify(updatedQueue));
    } catch (error) {
        console.error('Failed to remove item from queue:', error);
    }
};

/**
 * Clears the entire attendance queue from AsyncStorage.
 * Typically called after a successful sync.
 * @returns {Promise<void>}
 */
export const clearAttendanceQueue = async (): Promise<void> => {
  try {
    await AsyncStorage.removeItem(ATTENDANCE_QUEUE_KEY);
  } catch (error) {
    console.error('Failed to clear attendance queue:', error);
  }
};
