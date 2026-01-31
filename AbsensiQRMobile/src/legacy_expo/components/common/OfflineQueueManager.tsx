import { useEffect, useRef } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import Toast from 'react-native-toast-message';

import { useNetworkStatus } from '../../hooks/useNetworkStatus';
import { getAttendanceQueue, removeFromQueue, QueuedAttendance } from '../../services/offlineService';
import api from '../../services/api';

const syncAttendance = async (attendance: QueuedAttendance) => {
  const payload = {
    qr_token: attendance.qr_token, // Backend expects 'qr_token', not 'token'
    lat: attendance.latitude,      // Backend expects 'lat', not 'latitude'
    lng: attendance.longitude,     // Backend expects 'lng', not 'longitude'
  };
  const { data } = await api.post('/v1/attendance/scan', payload);
  return data;
};

export const OfflineQueueManager = () => {
  const isOnline = useNetworkStatus();
  const queryClient = useQueryClient();
  const isSyncing = useRef(false);

  const mutation = useMutation({
    mutationFn: syncAttendance,
    onSuccess: async (_, variables) => {
        // Remove only the successfully synced item
        await removeFromQueue(variables.id);
        queryClient.invalidateQueries({ queryKey: ['attendances'] });
    },
    onError: (error: any) => {
      console.error('Sync failed for item:', error);
      // Item remains in queue to retry later
    },
  });

  useEffect(() => {
    const processQueue = async () => {
      if (!isOnline || isSyncing.current) return;
      
      const queue = await getAttendanceQueue();
      if (queue.length === 0) return;

      isSyncing.current = true;
      console.log(`Starting sync for ${queue.length} items...`);

      if (queue.length > 0) {
          Toast.show({
            type: 'info',
            text1: 'Sinkronisasi Data...',
            text2: `Mengirim ${queue.length} data absensi pending.`,
          });
      }

      // Process items sequentially to prevent server overload
      for (const item of queue) {
        if (!isOnline) break; // Stop if network drops during sync
        try {
            await mutation.mutateAsync(item);
        } catch (e) {
            // Error handled in mutation.onError
        }
      }

      isSyncing.current = false;
      
      // Check if queue is fully empty
      const remaining = await getAttendanceQueue();
      if (remaining.length === 0) {
        Toast.show({
            type: 'success',
            text1: 'Sinkronisasi Selesai',
            text2: 'Semua data offline berhasil dikirim.',
        });
      }
    };

    // Initial check when coming online
    if (isOnline) {
        processQueue();
    }

    // Interval check every 30 seconds
    const intervalId = setInterval(() => {
        if (isOnline) {
            processQueue();
        }
    }, 30000);

    return () => clearInterval(intervalId);
  }, [isOnline]);

  return null;
};
