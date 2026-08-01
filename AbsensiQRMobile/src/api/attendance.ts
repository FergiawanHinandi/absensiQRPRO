/**
 * Attendance API - Refactored (Server-Side Logic Only)
 *
 * PRINCIPLES:
 * 1. Client sends RAW DATA only (no computed status, no business logic)
 * 2. Server determines ALL business logic (status, validation, etc.)
 * 3. GPS validation ONLY on server
 * 4. Device fingerprint verification server-side
 * 5. Offline queue encrypted
 *
 * @version 2.0.0 - Refactored for server-side logic
 */

import {secureApi} from './secureClient';
import {
  encryptOfflineData,
  decryptOfflineData,
  removeEncryptedData,
} from '../utils/encryption';

/**
 * Scan Payload - RAW DATA ONLY
 *
 * REMOVED:
 * - status (server determines)
 * - computed fields
 * - client-side validations
 *
 * ADDED:
 * - device_fingerprint (server validates)
 * - security_context (server logs)
 */
export interface ScanPayload {
  // QR Token (encrypted by server)
  qr_token: string;

  // Location Data (RAW - server validates radius & speed)
  latitude: number;
  longitude: number;
  accuracy?: number;
  altitude?: number | null;
  speed?: number | null;
  heading?: number | null;

  // Security Metadata (server validates & logs)
  is_mocked?: boolean; // Mock location flag
  device_fingerprint: string; // Device identity
  security_context?: {
    is_secure: boolean; // Device security status
    risk_level: 'low' | 'medium' | 'high' | 'critical' | 'unknown';
    violation_count: number; // Security violations detected
    violations?: string[]; // List of violation types
  };

  // Idempotency & Retry Support
  request_id: string; // UUID for idempotency

  // Timestamps (for server validation, NOT for record)
  client_timestamp?: number; // Client time (for clock skew detection)
  scanned_at?: string; // ISO timestamp when QR was scanned
}

/**
 * Attendance Record Response
 */
export interface AttendanceRecord {
  id: number;
  student_id: number;
  student_name: string;
  schedule_id: number;
  class: string;
  subject: string;
  teacher: string;

  // SERVER-DETERMINED STATUS (not from client)
  status: 'present' | 'late' | 'sick' | 'permit' | 'alpha';

  // SERVER TIMESTAMPS (source of truth)
  attendance_date: string; // YYYY-MM-DD
  check_in_time: string; // HH:mm:ss

  // Metadata
  is_manual: boolean;
  attendance_type: 'qr_scan' | 'manual' | 'teacher_scan';
  recorded_by?: number;

  // Location (as recorded by server)
  lat_in?: number;
  lng_in?: number;

  // Idempotency
  is_retry?: boolean; // True if this was an idempotent replay
}

/**
 * API Response Wrapper
 */
export interface AttendanceApiResponse<T> {
  success: boolean;
  message: string;
  data: T;

  // Idempotency metadata
  _idempotent_replay?: boolean;
  _original_timestamp?: string;
}

/**
 * Offline Queue Item (ENCRYPTED)
 */
interface OfflineQueueItem {
  id: string;
  payload: ScanPayload;
  created_at: number;
  retry_count: number;
}

const OFFLINE_QUEUE_KEY = '@attendance_offline_queue';
const MAX_RETRY_COUNT = 3;

/**
 * Attendance API
 */
export const attendanceApi = {
  /**
   * Submit attendance via QR code scan
   *
   * SECURITY:
   * - SSL pinning in production
   * - Idempotency key for retry safety
   * - Offline queue with encryption
   *
   * SERVER DETERMINES:
   * - Attendance status (present/late)
   * - Radius validation
   * - Speed validation (anti-spoofing)
   * - Time window validation
   * - Device fingerprint verification
   */
  scan: async (
    payload: ScanPayload,
  ): Promise<AttendanceApiResponse<AttendanceRecord>> => {
    try {
      const response = await secureApi.post<
        AttendanceApiResponse<AttendanceRecord>
      >('/v1/attendance/scan', payload, {
        headers: {
          'X-Idempotency-Key': payload.request_id,
          'X-Device-ID': payload.device_fingerprint,
        },
      });

      return response.data;
    } catch (error: any) {
      // If network error, queue for offline sync
      if (error.code === 'NETWORK_ERROR' || error.code === 'ECONNABORTED') {
        await attendanceApi.queueOffline(payload);
        throw {
          code: 'QUEUED_OFFLINE',
          message:
            'Tidak ada koneksi. Absensi akan dikirim otomatis saat online.',
        };
      }

      throw error;
    }
  },

  /**
   * Queue attendance for offline sync (ENCRYPTED)
   *
   * SECURITY:
   * - Payload encrypted before storage
   * - Decrypted only when syncing
   * - Auto-cleanup after max retries
   */
  queueOffline: async (payload: ScanPayload): Promise<void> => {
    try {
      // Get existing queue
      const queue = await attendanceApi.getOfflineQueue();

      // Create queue item
      const item: OfflineQueueItem = {
        id: payload.request_id,
        payload,
        created_at: Date.now(),
        retry_count: 0,
      };

      // Add to queue
      queue.push(item);

      // Encrypt and save
      await encryptOfflineData(OFFLINE_QUEUE_KEY, JSON.stringify(queue));

      console.log('Attendance queued for offline sync:', item.id);
    } catch (error) {
      console.error('Failed to queue offline attendance:', error);
      throw error;
    }
  },

  /**
   * Get offline queue (DECRYPTED)
   */
  getOfflineQueue: async (): Promise<OfflineQueueItem[]> => {
    try {
      const decrypted = await decryptOfflineData(OFFLINE_QUEUE_KEY);
      if (!decrypted) {
        return [];
      }

      return JSON.parse(decrypted);
    } catch (error) {
      console.error('Failed to get offline queue:', error);
      return [];
    }
  },

  /**
   * Sync offline queue
   *
   * PROCESS:
   * 1. Get encrypted queue
   * 2. Decrypt items
   * 3. Send each to server
   * 4. Remove successful items
   * 5. Increment retry count for failed items
   * 6. Remove items exceeding max retries
   * 7. Re-encrypt and save
   */
  syncOfflineQueue: async (): Promise<{
    synced: number;
    failed: number;
    removed: number;
  }> => {
    const queue = await attendanceApi.getOfflineQueue();

    if (queue.length === 0) {
      return {synced: 0, failed: 0, removed: 0};
    }

    let synced = 0;
    let failed = 0;
    let removed = 0;
    const remainingQueue: OfflineQueueItem[] = [];

    for (const item of queue) {
      try {
        // Try to sync
        await attendanceApi.scan(item.payload);
        synced++;
        console.log('Synced offline attendance:', item.id);
      } catch (error: any) {
        // If still network error, keep in queue
        if (error.code === 'NETWORK_ERROR' || error.code === 'ECONNABORTED') {
          item.retry_count++;

          // Remove if exceeded max retries
          if (item.retry_count >= MAX_RETRY_COUNT) {
            removed++;
            console.log('Removed offline attendance (max retries):', item.id);
          } else {
            remainingQueue.push(item);
            failed++;
          }
        } else {
          // Other errors (validation, etc.) - remove from queue
          removed++;
          console.log(
            'Removed offline attendance (validation error):',
            item.id,
            error.message,
          );
        }
      }
    }

    // Save remaining queue (encrypted)
    if (remainingQueue.length > 0) {
      await encryptOfflineData(
        OFFLINE_QUEUE_KEY,
        JSON.stringify(remainingQueue),
      );
    } else {
      await removeEncryptedData(OFFLINE_QUEUE_KEY);
    }

    return {synced, failed, removed};
  },

  /**
   * Clear offline queue (for testing/debugging)
   */
  clearOfflineQueue: async (): Promise<void> => {
    await removeEncryptedData(OFFLINE_QUEUE_KEY);
  },

  /**
   * Get offline queue count
   */
  getOfflineQueueCount: async (): Promise<number> => {
    const queue = await attendanceApi.getOfflineQueue();
    return queue.length;
  },
};

export default attendanceApi;
