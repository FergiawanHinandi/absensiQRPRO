/**
 * Offline Sync Service
 * 
 * Handles offline attendance queue synchronization.
 * Automatically syncs queued attendance records when network is restored.
 * 
 * REQUIRED PACKAGES:
 * npm install @react-native-async-storage/async-storage @react-native-community/netinfo
 * 
 * SECURITY NOTES:
 * - Uses request_id for idempotency (safe to retry)
 * - Server handles duplicate detection via 409 responses
 * - Exponential backoff prevents server overload
 */

// @ts-ignore - Install package: npm install @react-native-async-storage/async-storage
import AsyncStorage from '@react-native-async-storage/async-storage';
// @ts-ignore - Install package: npm install @react-native-community/netinfo
import NetInfo from '@react-native-community/netinfo';
import { attendanceApi, ScanPayload } from '../api/attendance';

const ATTENDANCE_QUEUE_KEY = 'attendance_offline_queue';
const SYNC_IN_PROGRESS_KEY = 'attendance_sync_in_progress';
const MAX_RETRY_ATTEMPTS = 3;
const BASE_RETRY_DELAY_MS = 1000;

export interface QueuedAttendance {
    id: string;                   // request_id for idempotency
    qr_token: string;
    latitude: number;
    longitude: number;
    accuracy?: number;
    is_mocked?: boolean;
    device_fingerprint?: string;
    queued_at: string;            // ISO timestamp
    retry_count: number;
    last_error?: string;
}

/**
 * Generate a simple UUID
 */
const generateId = (): string => {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        const v = c === 'x' ? r : (r & 0x3) | 0x8;
        return v.toString(16);
    });
};

/**
 * Get the current offline queue
 */
export const getQueue = async (): Promise<QueuedAttendance[]> => {
    try {
        const json = await AsyncStorage.getItem(ATTENDANCE_QUEUE_KEY);
        return json ? JSON.parse(json) : [];
    } catch (error) {
        console.error('OfflineSync: Failed to get queue', error);
        return [];
    }
};

/**
 * Save the queue
 */
const saveQueue = async (queue: QueuedAttendance[]): Promise<void> => {
    try {
        await AsyncStorage.setItem(ATTENDANCE_QUEUE_KEY, JSON.stringify(queue));
    } catch (error) {
        console.error('OfflineSync: Failed to save queue', error);
    }
};

/**
 * Add attendance to offline queue
 * Called when network is unavailable during scan
 */
export const addToQueue = async (attendance: Omit<QueuedAttendance, 'id' | 'queued_at' | 'retry_count'>): Promise<string> => {
    const queue = await getQueue();
    
    // Check for duplicate (same QR token within 60 seconds)
    const isDuplicate = queue.some(item => 
        item.qr_token === attendance.qr_token &&
        (new Date().getTime() - new Date(item.queued_at).getTime()) < 60000
    );
    
    if (isDuplicate) {
        console.log('OfflineSync: Duplicate scan detected, skipping');
        throw new Error('Duplicate scan detected');
    }
    
    const requestId = generateId();
    const queuedItem: QueuedAttendance = {
        ...attendance,
        id: requestId,
        queued_at: new Date().toISOString(),
        retry_count: 0,
    };
    
    queue.push(queuedItem);
    await saveQueue(queue);
    
    console.log(`OfflineSync: Added to queue (${queue.length} items pending)`);
    return requestId;
};

/**
 * Remove item from queue
 */
export const removeFromQueue = async (id: string): Promise<void> => {
    const queue = await getQueue();
    const filtered = queue.filter(item => item.id !== id);
    await saveQueue(filtered);
};

/**
 * Clear the entire queue (for testing/reset)
 */
export const clearQueue = async (): Promise<void> => {
    await AsyncStorage.removeItem(ATTENDANCE_QUEUE_KEY);
};

/**
 * Sync a single queued attendance
 */
const syncItem = async (item: QueuedAttendance): Promise<{ success: boolean; shouldRemove: boolean }> => {
    try {
        const payload: ScanPayload = {
            qr_token: item.qr_token,
            lat: item.latitude,
            lng: item.longitude,
            accuracy: item.accuracy,
            is_mocked: item.is_mocked,
            device_fingerprint: item.device_fingerprint,
            request_id: item.id, // Idempotency key
        };
        
        await attendanceApi.scan(payload);
        console.log(`OfflineSync: Successfully synced ${item.id}`);
        return { success: true, shouldRemove: true };
        
    } catch (error: any) {
        const status = error.response?.status;
        
        // 409 Conflict = Already recorded (idempotent success)
        if (status === 409) {
            console.log(`OfflineSync: Already recorded ${item.id}`);
            return { success: true, shouldRemove: true };
        }
        
        // 422 Unprocessable = Invalid data (permanent failure)
        if (status === 422 || status === 400) {
            console.warn(`OfflineSync: Invalid data for ${item.id}, removing`);
            return { success: false, shouldRemove: true };
        }
        
        // 401/403 = Auth issue (permanent until re-login)
        if (status === 401 || status === 403) {
            console.warn(`OfflineSync: Auth failed for ${item.id}, keeping for later`);
            return { success: false, shouldRemove: false };
        }
        
        // Network/server errors = retry later
        console.warn(`OfflineSync: Failed ${item.id}, will retry`, error.message);
        return { success: false, shouldRemove: false };
    }
};

/**
 * Process the entire offline queue
 */
export const syncQueue = async (): Promise<{ synced: number; failed: number; remaining: number }> => {
    // Prevent concurrent syncs
    const inProgress = await AsyncStorage.getItem(SYNC_IN_PROGRESS_KEY);
    if (inProgress === 'true') {
        console.log('OfflineSync: Sync already in progress');
        return { synced: 0, failed: 0, remaining: 0 };
    }
    
    await AsyncStorage.setItem(SYNC_IN_PROGRESS_KEY, 'true');
    
    let synced = 0;
    let failed = 0;
    
    try {
        const queue = await getQueue();
        
        if (queue.length === 0) {
            console.log('OfflineSync: Queue is empty');
            return { synced: 0, failed: 0, remaining: 0 };
        }
        
        console.log(`OfflineSync: Starting sync of ${queue.length} items`);
        
        for (const item of queue) {
            // Skip items that have exceeded retry limit
            if (item.retry_count >= MAX_RETRY_ATTEMPTS) {
                console.warn(`OfflineSync: Max retries exceeded for ${item.id}`);
                await removeFromQueue(item.id);
                failed++;
                continue;
            }
            
            const result = await syncItem(item);
            
            if (result.shouldRemove) {
                await removeFromQueue(item.id);
                if (result.success) {
                    synced++;
                } else {
                    failed++;
                }
            } else {
                // Update retry count
                const currentQueue = await getQueue();
                const updated = currentQueue.map(q => 
                    q.id === item.id 
                        ? { ...q, retry_count: q.retry_count + 1, last_error: 'Network error' }
                        : q
                );
                await saveQueue(updated);
            }
            
            // Small delay between requests to avoid rate limiting
            await new Promise(resolve => setTimeout(resolve, 200));
        }
        
        const remaining = (await getQueue()).length;
        console.log(`OfflineSync: Completed - synced: ${synced}, failed: ${failed}, remaining: ${remaining}`);
        
        return { synced, failed, remaining };
        
    } finally {
        await AsyncStorage.setItem(SYNC_IN_PROGRESS_KEY, 'false');
    }
};

/**
 * OfflineSyncService class for initialization and management
 */
class OfflineSyncService {
    private unsubscribe: (() => void) | null = null;
    private isInitialized = false;
    
    /**
     * Initialize network listener for automatic sync
     */
    init(): void {
        if (this.isInitialized) {
            console.log('OfflineSync: Already initialized');
            return;
        }
        
        this.unsubscribe = NetInfo.addEventListener(this.handleNetworkChange.bind(this));
        this.isInitialized = true;
        console.log('OfflineSync: Initialized network listener');
        
        // Check immediately on init
        NetInfo.fetch().then((state: any) => {
            if (state.isConnected && state.isInternetReachable) {
                this.syncQueue();
            }
        });
    }
    
    /**
     * Handle network state changes
     */
    private async handleNetworkChange(state: any): Promise<void> {
        if (state.isConnected && state.isInternetReachable) {
            console.log('OfflineSync: Network restored, starting sync');
            await this.syncQueue();
        }
    }
    
    /**
     * Manually trigger sync
     */
    async syncQueue(): Promise<{ synced: number; failed: number; remaining: number }> {
        return syncQueue();
    }
    
    /**
     * Get current queue status
     */
    async getStatus(): Promise<{ count: number; items: QueuedAttendance[] }> {
        const items = await getQueue();
        return { count: items.length, items };
    }
    
    /**
     * Add to queue
     */
    async addToQueue(attendance: Omit<QueuedAttendance, 'id' | 'queued_at' | 'retry_count'>): Promise<string> {
        return addToQueue(attendance);
    }
    
    /**
     * Cleanup
     */
    destroy(): void {
        if (this.unsubscribe) {
            this.unsubscribe();
            this.unsubscribe = null;
        }
        this.isInitialized = false;
    }
}

// Export singleton instance
export const offlineSyncService = new OfflineSyncService();
export default offlineSyncService;
