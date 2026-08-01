import React from 'react';
import { useRealtime, useRealtimeChannel } from '../../hooks/useRealtime';
import { useAuthStore } from '../../modules/auth/stores/useAuthStore';
import {
  Wifi,
  WifiOff,
  RefreshCw,
  Activity,
  Users,
  CheckCircle2,
  Clock,
  XCircle,
  TrendingUp,
  TrendingDown,
} from 'lucide-react';

// ─── Types ────────────────────────────────────────────────────────────────

interface MetricsEvent {
  type: 'dashboard_update';
  attendance_rate?: number;
  total_students?: number;
  present?: number;
  late?: number;
  absent?: number;
  classes_active?: number;
  timestamp?: string;
}

interface RealTimeDashboardStatsProps {
  className?: string;
}

// ─── Component ────────────────────────────────────────────────────────────

const RealTimeDashboardStats: React.FC<RealTimeDashboardStatsProps> = ({
  className = '',
}) => {
  const { user } = useAuthStore();
  const schoolId = user?.school_id;

  const { isConnected, status, isAvailable } = useRealtime({
    enabled: !!schoolId,
  });

  const [metrics, setMetrics] = React.useState<MetricsEvent | null>(null);
  const [lastUpdate, setLastUpdate] = React.useState<string>('--:--:--');

  useRealtimeChannel<MetricsEvent>({
    channelName: schoolId ? `school.${schoolId}` : '',
    eventName: 'metrics.updated',
    enabled: !!schoolId,
    handler: (data) => {
      setMetrics(data);
      setLastUpdate(new Date().toLocaleTimeString('id-ID'));
    },
  });

  if (!isAvailable) {
    return (
      <div className={`bg-white rounded-2xl border border-slate-200 shadow-sm p-5 ${className}`}>
        <div className="flex items-center gap-3 text-slate-400">
          <WifiOff className="w-5 h-5" />
          <div>
            <p className="text-sm font-medium text-slate-500">Real-time tidak tersedia</p>
            <p className="text-xs text-slate-400">Konfigurasikan Reverb untuk live updates</p>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className={`bg-white rounded-2xl border border-slate-200 shadow-sm ${className}`}>
      {/* Header */}
      <div className="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
        <div className="flex items-center gap-2">
          <Activity className="w-4 h-4 text-blue-600" />
          <h3 className="font-bold text-slate-900 text-sm">Live Dashboard</h3>
        </div>
        <div className="flex items-center gap-2">
          <span className="text-[10px] text-slate-400 font-mono">{lastUpdate}</span>
          <div className="flex items-center gap-1">
            <span className={`relative flex h-2 w-2`}>
              <span className={`animate-ping absolute inline-flex h-full w-full rounded-full opacity-75 ${
                isConnected ? 'bg-green-400' : 'bg-red-400'
              }`} />
              <span className={`relative inline-flex rounded-full h-2 w-2 ${
                isConnected ? 'bg-green-500' : 'bg-red-500'
              }`} />
            </span>
            <span className={`text-[10px] font-medium ${
              isConnected ? 'text-green-600' : 'text-red-500'
            }`}>
              {isConnected ? 'LIVE' : 'OFFLINE'}
            </span>
          </div>
          {!isConnected && (
            <RefreshCw className="w-3 h-3 text-slate-400 animate-spin" />
          )}
        </div>
      </div>

      {/* Metrics Content */}
      <div className="p-5 space-y-4">
        {metrics ? (
          <>
            {/* Main stat */}
            <div className="text-center p-4 bg-gradient-to-br from-blue-50 to-indigo-50 rounded-xl border border-blue-100">
              <p className="text-3xl font-bold text-blue-700">
                {metrics.attendance_rate ?? '--'}%
              </p>
              <p className="text-xs font-medium text-blue-500 mt-1">Tingkat Kehadiran Real-time</p>
            </div>

            {/* Stats grid */}
            <div className="grid grid-cols-3 gap-3">
              <div className="text-center p-3 bg-emerald-50 rounded-xl border border-emerald-100">
                <CheckCircle2 className="w-5 h-5 text-emerald-600 mx-auto mb-1" />
                <p className="text-lg font-bold text-emerald-700">{metrics.present ?? 0}</p>
                <p className="text-[10px] text-emerald-500 font-medium">Hadir</p>
              </div>
              <div className="text-center p-3 bg-amber-50 rounded-xl border border-amber-100">
                <Clock className="w-5 h-5 text-amber-600 mx-auto mb-1" />
                <p className="text-lg font-bold text-amber-700">{metrics.late ?? 0}</p>
                <p className="text-[10px] text-amber-500 font-medium">Telat</p>
              </div>
              <div className="text-center p-3 bg-red-50 rounded-xl border border-red-100">
                <XCircle className="w-5 h-5 text-red-600 mx-auto mb-1" />
                <p className="text-lg font-bold text-red-700">{metrics.absent ?? 0}</p>
                <p className="text-[10px] text-red-500 font-medium">Absen</p>
              </div>
            </div>

            {/* Secondary info */}
            <div className="flex items-center justify-between text-xs text-slate-400 pt-2 border-t border-slate-100">
              <span className="flex items-center gap-1">
                <Users className="w-3 h-3" />
                {metrics.total_students ?? 0} siswa
              </span>
              <span className="flex items-center gap-1">
                <Activity className="w-3 h-3" />
                {metrics.classes_active ?? 0} kelas aktif
              </span>
            </div>
          </>
        ) : (
          <div className="text-center py-8 text-slate-400">
            <div className="animate-pulse space-y-3">
              <div className="w-20 h-4 bg-slate-200 rounded mx-auto" />
              <div className="w-32 h-4 bg-slate-200 rounded mx-auto" />
              <div className="grid grid-cols-3 gap-2 pt-2">
                {[...Array(3)].map((_, i) => (
                  <div key={i} className="h-16 bg-slate-100 rounded-lg" />
                ))}
              </div>
            </div>
            <p className="text-xs mt-3 text-slate-300">Menunggu data pertama...</p>
          </div>
        )}
      </div>
    </div>
  );
};

export { RealTimeDashboardStats };
