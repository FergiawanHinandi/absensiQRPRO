import { useState, useEffect, useRef } from 'react';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../../lib/api';
import { useRealtimeChannel } from '../../hooks/useRealtime';
import { useAuthStore } from '../../modules/auth/stores/useAuthStore';
import { Users, CheckCircle2, Clock, XCircle, Loader2 } from 'lucide-react';

// ─── Types ────────────────────────────────────────────────────────────────

interface TodayStats {
  present: number;
  late: number;
  absent: number;
  alpha: number;
  total_students: number;
  attendance_rate: number;
}

interface StatsEvent {
  type: 'attendance_update';
  stats?: TodayStats;
  attendance?: {
    status: string;
  };
}

// ─── Component ────────────────────────────────────────────────────────────

interface LiveAttendanceCounterProps {
  /** Optional title override */
  title?: string;
  /** Show loading skeleton while fetching */
  showSkeleton?: boolean;
  /** Class name override */
  className?: string;
  /** Show compact mode (smaller cards) */
  compact?: boolean;
}

const LiveAttendanceCounter: React.FC<LiveAttendanceCounterProps> = ({
  title = 'Absensi Hari Ini',
  showSkeleton = true,
  className = '',
  compact = false,
}) => {
  const { user } = useAuthStore();
  const schoolId = user?.school_id;

  // Fetch initial stats
  const { data: stats, isLoading, refetch } = useQuery({
    queryKey: ['today-stats'],
    queryFn: async () => {
      const response = await apiClient.get<TodayStats>('/admin/reports/daily');
      return response.data ?? response;
    },
    refetchInterval: 30000, // Poll every 30s as fallback
    enabled: true,
  });

  // Real-time updates via WebSocket
  const [liveStats, setLiveStats] = useState<TodayStats | null>(null);
  const [lastEvent, setLastEvent] = useState<{ status: string; time: string } | null>(null);
  const lastEventTimeout = useRef<ReturnType<typeof setTimeout> | null>(null);

  // Cleanup timeout on unmount
  useEffect(() => {
    return () => {
      if (lastEventTimeout.current) {
        clearTimeout(lastEventTimeout.current);
      }
    };
  }, []);

  useRealtimeChannel<StatsEvent>({
    channelName: schoolId ? `school.${schoolId}` : '',
    eventName: 'StudentAttended',
    enabled: !!schoolId,
    handler: (data) => {
      // Create smooth visual update
      setLastEvent({
        status: data.attendance?.status || 'present',
        time: new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }),
      });

      // If we have stats from the event, update directly
      if (data.stats) {
        setLiveStats(data.stats);
      } else {
        // Otherwise refetch to get updated counts
        refetch();
      }

      // Clear last event indicator after 3s
      if (lastEventTimeout.current) clearTimeout(lastEventTimeout.current);
      lastEventTimeout.current = setTimeout(() => setLastEvent(null), 3000);
    },
  });

  // Merge live stats with fetched stats
  const displayStats = liveStats || stats;

  if (isLoading && showSkeleton) {
    return (
      <div className={`bg-white rounded-2xl border border-slate-200 shadow-sm p-5 ${className}`}>
        <div className="animate-pulse space-y-4">
          <div className="h-5 bg-slate-200 rounded w-36" />
          <div className="grid grid-cols-3 gap-3">
            {[...Array(3)].map((_, i) => (
              <div key={i} className="h-20 bg-slate-100 rounded-xl" />
            ))}
          </div>
        </div>
      </div>
    );
  }

  const present = displayStats?.present ?? 0;
  const late = displayStats?.late ?? 0;
  const absent = (displayStats?.absent ?? 0) + (displayStats?.alpha ?? 0);
  const total = displayStats?.total_students ?? 0;
  const rate = displayStats?.attendance_rate ?? (total > 0 ? Math.round(((present + late) / total) * 100) : 0);

  return (
    <div className={`bg-white rounded-2xl border border-slate-200 shadow-sm ${className}`}>
      {/* Header */}
      <div className="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
        <div className="flex items-center gap-2">
          <div className="w-8 h-8 rounded-lg bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-sm">
            <Users className="w-4 h-4 text-white" />
          </div>
          <h3 className="font-bold text-slate-900 text-sm">{title}</h3>
        </div>

        {/* Last event flash */}
        {lastEvent && (
          <div className="flex items-center gap-1.5 animate-in slide-in-from-right-2 fade-in duration-300">
            <span className="w-2 h-2 rounded-full bg-green-500 animate-pulse" />
            <span className="text-[10px] font-medium text-green-600 bg-green-50 px-1.5 py-0.5 rounded-full">
              {lastEvent.time}
            </span>
          </div>
        )}
      </div>

      {/* Stats Cards */}
      <div className={`p-5 ${compact ? 'space-y-2' : 'space-y-3'}`}>
        <div className="flex items-center justify-between p-3 bg-emerald-50 rounded-xl border border-emerald-100 transition-all hover:bg-emerald-100/50">
          <div className="flex items-center gap-3">
            <div className="w-9 h-9 rounded-lg bg-emerald-100 flex items-center justify-center">
              <CheckCircle2 className="w-5 h-5 text-emerald-600" />
            </div>
            <div>
              <p className="text-xs font-medium text-emerald-700">Hadir</p>
              {!compact && <p className="text-[10px] text-emerald-500">Tepat waktu</p>}
            </div>
          </div>
          <div className="text-right">
            <p className={`font-bold text-emerald-700 ${compact ? 'text-lg' : 'text-2xl'}`}>
              {present}
            </p>
          </div>
        </div>

        <div className="flex items-center justify-between p-3 bg-amber-50 rounded-xl border border-amber-100 transition-all hover:bg-amber-100/50">
          <div className="flex items-center gap-3">
            <div className="w-9 h-9 rounded-lg bg-amber-100 flex items-center justify-center">
              <Clock className="w-5 h-5 text-amber-600" />
            </div>
            <div>
              <p className="text-xs font-medium text-amber-700">Terlambat</p>
              {!compact && <p className="text-[10px] text-amber-500">&gt; Toleransi waktu</p>}
            </div>
          </div>
          <p className={`font-bold text-amber-700 ${compact ? 'text-lg' : 'text-2xl'}`}>
            {late}
          </p>
        </div>

        <div className="flex items-center justify-between p-3 bg-red-50 rounded-xl border border-red-100 transition-all hover:bg-red-100/50">
          <div className="flex items-center gap-3">
            <div className="w-9 h-9 rounded-lg bg-red-100 flex items-center justify-center">
              <XCircle className="w-5 h-5 text-red-600" />
            </div>
            <div>
              <p className="text-xs font-medium text-red-700">Alpha / Absen</p>
              {!compact && <p className="text-[10px] text-red-500">Tanpa keterangan</p>}
            </div>
          </div>
          <p className={`font-bold text-red-700 ${compact ? 'text-lg' : 'text-2xl'}`}>
            {absent}
          </p>
        </div>

        {/* Attendance Rate */}
        <div className="pt-2 border-t border-slate-100">
          <div className="flex items-center justify-between">
            <span className="text-xs font-medium text-slate-500">Tingkat Kehadiran</span>
            <span className={`text-sm font-bold ${
              rate >= 90 ? 'text-emerald-600' : rate >= 75 ? 'text-amber-600' : 'text-red-600'
            }`}>
              {rate}%
            </span>
          </div>
          <div className="mt-1.5 w-full h-2 bg-slate-100 rounded-full overflow-hidden">
            <div
              className={`h-full rounded-full transition-all duration-700 ease-out ${
                rate >= 90 ? 'bg-emerald-500' : rate >= 75 ? 'bg-amber-500' : 'bg-red-500'
              }`}
              style={{ width: `${Math.min(rate, 100)}%` }}
            />
          </div>
        </div>
      </div>
    </div>
  );
};

export { LiveAttendanceCounter };
export type { TodayStats, StatsEvent };
