import { useState, useRef, useCallback, useEffect } from 'react';
import { apiClient } from '../lib/api';
import showToast from '../utils/toast';

export type ExportStatus = 'idle' | 'pending' | 'processing' | 'completed' | 'failed';

export interface ExportJob {
  jobId: string;
  status: ExportStatus;
  progress: number;
  statusLabel: string;
  errorMessage?: string;
  downloadUrl?: string;
  fileName?: string;
  fileSize?: string;
}

interface UseExportJobOptions {
  onComplete?: (job: ExportJob) => void;
  onError?: (error: string) => void;
  pollInterval?: number; // ms between status polls
  maxPolls?: number; // max number of polls before giving up
}

/**
 * useExportJob Hook
 *
 * Manages async export lifecycle:
 * 1. Create export job (POST)
 * 2. Poll status (GET) until complete/failed
 * 3. Trigger download when ready
 *
 * Usage:
 *   const { exportJob, startExport, downloadExport, isPolling } = useExportJob();
 *   await startExport({ start_date: '...', end_date: '...', format: 'pdf' });
 */
export function useExportJob(options: UseExportJobOptions = {}) {
  const {
    onComplete,
    onError,
    pollInterval = 3000,
    maxPolls = 100, // 5 minutes max at 3s interval
  } = options;

  const [exportJob, setExportJob] = useState<ExportJob | null>(null);
  const [isPolling, setIsPolling] = useState(false);
  const pollCountRef = useRef(0);
  const pollTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const initialPollRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  // Auto-cleanup on unmount
  useEffect(() => {
    return () => {
      if (pollTimerRef.current) clearInterval(pollTimerRef.current);
      if (initialPollRef.current) clearTimeout(initialPollRef.current);
    };
  }, []);

  /**
   * Start a new export job
   */
  const startExport = useCallback(async (params: {
    start_date: string;
    end_date: string;
    format: 'pdf' | 'excel';
    class_id?: number;
    subject_id?: number;
    include_summary?: boolean;
    report_type?: string;
  }) => {
    // Clear previous job and timers
    if (pollTimerRef.current) {
      clearInterval(pollTimerRef.current);
      pollTimerRef.current = null;
    }
    if (initialPollRef.current) {
      clearTimeout(initialPollRef.current);
      initialPollRef.current = null;
    }

    setExportJob({
      jobId: '',
      status: 'pending',
      progress: 0,
      statusLabel: 'Mengirim permintaan...',
    });

    try {
      const response = await apiClient.post('/reports/export', {
        format: params.format,
        start_date: params.start_date,
        end_date: params.end_date,
        class_id: params.class_id,
        subject_id: params.subject_id,
        include_summary: params.include_summary ?? false,
        report_type: params.report_type || 'attendance',
      });

      const jobId = response.data?.job_id || response.data?.data?.job_id;

      if (!jobId) {
        throw new Error('Server tidak mengembalikan job ID');
      }

      setExportJob(prev => prev ? { ...prev, jobId, statusLabel: 'Memproses...' } : null);

      // Start polling
      pollCountRef.current = 0;
      setIsPolling(true);

      const poll = async () => {
        pollCountRef.current++;

        try {
          const statusRes = await apiClient.get(`/reports/export/${jobId}/status`);
          const data = statusRes.data?.data || statusRes.data;

          setExportJob(prev => {
            if (!prev) return null;
            return {
              ...prev,
              status: data.status || prev.status,
              progress: data.progress || prev.progress,
              statusLabel: data.status_label || prev.statusLabel,
              downloadUrl: data.download_url,
              fileName: data.file_name,
              fileSize: data.file_size,
              errorMessage: data.error_message,
            };
          });

          if (data.status === 'completed') {
            setIsPolling(false);
            if (pollTimerRef.current) {
              clearInterval(pollTimerRef.current);
              pollTimerRef.current = null;
            }

            const completedJob: ExportJob = {
              jobId,
              status: 'completed',
              progress: 100,
              statusLabel: 'Selesai',
              downloadUrl: data.download_url,
              fileName: data.file_name,
              fileSize: data.file_size,
            };

            setExportJob(completedJob);
            showToast.success('Laporan siap diunduh!');
            onComplete?.(completedJob);
          } else if (data.status === 'failed') {
            setIsPolling(false);
            if (pollTimerRef.current) {
              clearInterval(pollTimerRef.current);
              pollTimerRef.current = null;
            }

            const errorMsg = data.error_message || 'Export gagal diproses';
            setExportJob(prev => prev ? { ...prev, status: 'failed', errorMessage: errorMsg } : null);
            showToast.error(errorMsg);
            onError?.(errorMsg);
          } else if (pollCountRef.current >= maxPolls) {
            // Timeout
            setIsPolling(false);
            if (pollTimerRef.current) {
              clearInterval(pollTimerRef.current);
              pollTimerRef.current = null;
            }
            const timeoutMsg = 'Waktu permintaan export habis. Silakan coba lagi.';
            setExportJob(prev => prev ? { ...prev, status: 'failed', errorMessage: timeoutMsg } : null);
            showToast.error(timeoutMsg);
            onError?.(timeoutMsg);
          }
        } catch (err: any) {
          // Silently retry on network errors
          if (import.meta.env.DEV) {
              console.warn('Export status poll failed, retrying...', err);
          }
        }
      };

      // Initial poll after 1 second
      initialPollRef.current = setTimeout(poll, 1000);

      // Then poll every pollInterval
      pollTimerRef.current = setInterval(poll, pollInterval);

      return jobId;
    } catch (err: any) {
      const errorMsg = err.response?.data?.message || err.message || 'Gagal memulai export';
      setExportJob({
        jobId: '',
        status: 'failed',
        progress: 0,
        statusLabel: 'Gagal',
        errorMessage: errorMsg,
      });
      showToast.error(errorMsg);
      onError?.(errorMsg);
      return null;
    }
  }, [pollInterval, maxPolls, onComplete, onError]);

  /**
   * Download the completed export file
   */
  const downloadExport = useCallback(async () => {
    if (!exportJob?.jobId || exportJob.status !== 'completed') {
      showToast.error('Export belum siap diunduh');
      return;
    }

    try {
      // Try to get download URL from status first
      if (exportJob.downloadUrl) {
        // If it's a full URL, open it
        if (exportJob.downloadUrl.startsWith('http')) {
          window.open(exportJob.downloadUrl, '_blank');
          return;
        }

        // If it's a route name, download via API
        const downloadRes = await apiClient.get(`/reports/export/${exportJob.jobId}/download`, {
          responseType: 'blob',
        });

        const blob = downloadRes.data;
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = exportJob.fileName || `laporan_${exportJob.jobId}.pdf`;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
        showToast.success('File berhasil diunduh');
      }
    } catch (err: any) {
      showToast.error('Gagal mengunduh file');
    }
  }, [exportJob]);

  /**
   * Reset export job state
   */
  const resetExport = useCallback(() => {
    if (pollTimerRef.current) {
      clearInterval(pollTimerRef.current);
      pollTimerRef.current = null;
    }
    setIsPolling(false);
    setExportJob(null);
  }, []);

  return {
    exportJob,
    isPolling,
    startExport,
    downloadExport,
    resetExport,
  };
}

export default useExportJob;
