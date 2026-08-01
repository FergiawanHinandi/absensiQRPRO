import React, { useState } from 'react';
import { Skeleton, SkeletonTable, SkeletonCard } from '../../components/ui/LoadingStates';
import { useAttendanceOverview } from '../../modules/principal/hooks';
import { Eye, Users, CheckCircle2, Clock, XCircle, TrendingUp, TrendingDown, Minus, AlertTriangle, RefreshCw } from 'lucide-react';

// A3-L1 FIX: Tambahkan error state agar jika API gagal user tahu dan bisa retry
const PrincipalMonitoring: React.FC = () => {
  const [range, setRange] = useState<'7d' | '30d'>('30d');
  const { data, isLoading, error, refetch } = useAttendanceOverview(range);

  if (isLoading) return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <Skeleton variant="rounded" width={320} height={36} />
        <Skeleton variant="rounded" width={150} height={36} />
      </div>
      <SkeletonTable rows={7} cols={6} />
      <SkeletonCard className="h-64" />
    </div>
  );

  // A3-L1 FIX: Tampilkan error state yang informatif
  if (error) {
    return (
      <div className="p-6">
        <div className="bg-red-50 border border-red-200 rounded-xl p-8 text-center">
          <AlertTriangle className="w-12 h-12 text-red-400 mx-auto mb-3" />
          <h2 className="text-lg font-semibold text-red-700 mb-2">Gagal Memuat Data Monitoring</h2>
          <p className="text-sm text-red-500 mb-4">
            {(error as any)?.message ?? 'Terjadi kesalahan saat mengambil data dari server.'}
          </p>
          <button
            onClick={() => refetch?.()}
            className="inline-flex items-center gap-2 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors text-sm"
          >
            <RefreshCw className="w-4 h-4" />
            Coba Lagi
          </button>
        </div>
      </div>
    );
  }

  const trend = data?.monthly_trend || [];
  const classes = data?.class_breakdown || [];

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-900 flex items-center gap-2">
            <Eye className="w-6 h-6 text-blue-600" />
            Monitoring Absensi
          </h1>
          <p className="text-sm text-slate-500">Pantau kehadiran seluruh siswa secara real-time</p>
        </div>
        <select
          value={range}
          onChange={(e) => setRange(e.target.value as '7d' | '30d')}
          className="border border-slate-200 rounded-lg px-3 py-2 text-sm"
        >
          <option value="7d">7 Hari Terakhir</option>
          <option value="30d">30 Hari Terakhir</option>
        </select>
      </div>

      {/* Tidak ada data sama sekali */}
      {trend.length === 0 && classes.length === 0 && (
        <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-8 text-center text-slate-400">
          <Eye className="w-12 h-12 mx-auto mb-3 text-slate-200" />
          <p className="font-medium">Tidak ada data tersedia</p>
          <p className="text-sm mt-1">Belum ada data absensi untuk periode ini.</p>
        </div>
      )}

      {/* Trend Table */}
      {trend.length > 0 && (
        <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
          <h2 className="text-lg font-semibold text-slate-800 mb-4">Tren Harian</h2>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-slate-400 text-xs uppercase border-b border-slate-100">
                  <th className="pb-3 pr-4">Tanggal</th>
                  <th className="pb-3 text-center">Kehadiran</th>
                  <th className="pb-3 text-center">Hadir</th>
                  <th className="pb-3 text-center">Terlambat</th>
                  <th className="pb-3 text-center">Alpha</th>
                  <th className="pb-3 text-center">Tren</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-50">
                {trend.map((day, idx) => {
                  const prev = trend[idx + 1];
                  const trendDir = prev ? (day.attendance_rate > prev.attendance_rate ? 'up' : day.attendance_rate < prev.attendance_rate ? 'down' : 'flat') : 'flat';
                  return (
                    <tr key={day.date} className="hover:bg-slate-50">
                      <td className="py-2.5 pr-4 font-medium text-slate-700">
                        {new Date(day.date).toLocaleDateString('id-ID', { weekday: 'short', day: 'numeric', month: 'short' })}
                      </td>
                      <td className="py-2.5 text-center">
                        <span className={`font-mono font-semibold ${day.attendance_rate >= 90 ? 'text-green-600' : day.attendance_rate >= 75 ? 'text-yellow-600' : 'text-red-600'}`}>
                          {day.attendance_rate}%
                        </span>
                      </td>
                      <td className="py-2.5 text-center text-green-600">{day.present}</td>
                      <td className="py-2.5 text-center text-yellow-600">{day.late}</td>
                      <td className="py-2.5 text-center text-red-600">{day.absent}</td>
                      <td className="py-2.5 text-center">
                        {trendDir === 'up' && <TrendingUp className="w-4 h-4 text-green-500 mx-auto" />}
                        {trendDir === 'down' && <TrendingDown className="w-4 h-4 text-red-500 mx-auto" />}
                        {trendDir === 'flat' && <Minus className="w-4 h-4 text-slate-300 mx-auto" />}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Per-Class Breakdown */}
      <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
        <h2 className="text-lg font-semibold text-slate-800 mb-4">Detail Per Kelas</h2>
        {classes.length === 0 ? (
          <p className="text-sm text-slate-400 text-center py-8">Tidak ada data kelas.</p>
        ) : (
          <div className="grid gap-3">
            {classes.map((cls) => (
              <div key={cls.class_id} className="flex items-center gap-4 p-3 bg-slate-50 rounded-lg">
                <div className="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                  <Users className="w-5 h-5 text-blue-600" />
                </div>
                <div className="flex-1">
                  <p className="font-medium text-slate-800">{cls.class_name}</p>
                  <p className="text-xs text-slate-400">{cls.total_students} siswa</p>
                </div>
                <div className="flex items-center gap-4 text-sm">
                  <span className="flex items-center gap-1 text-green-600">
                    <CheckCircle2 className="w-3.5 h-3.5" /> {cls.present}
                  </span>
                  <span className="flex items-center gap-1 text-yellow-600">
                    <Clock className="w-3.5 h-3.5" /> {cls.late}
                  </span>
                  <span className="flex items-center gap-1 text-red-600">
                    <XCircle className="w-3.5 h-3.5" /> {cls.absent}
                  </span>
                  <span className={`font-mono font-semibold min-w-[48px] text-right ${cls.attendance_rate >= 90 ? 'text-green-600' : cls.attendance_rate >= 75 ? 'text-yellow-600' : 'text-red-600'}`}>
                    {cls.attendance_rate}%
                  </span>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
};

export default PrincipalMonitoring;
