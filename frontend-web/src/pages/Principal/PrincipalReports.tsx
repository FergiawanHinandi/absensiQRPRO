import React from 'react';
import { Skeleton, SkeletonTable, SkeletonCard } from '../../components/ui/LoadingStates';
import { useClassPerformance } from '../../modules/principal/hooks';
import { FileText, Trophy, BarChart3, TrendingUp, TrendingDown, Minus, AlertTriangle, RefreshCw } from 'lucide-react';

// A3-L2 AUDIT: PrincipalReports.tsx sudah memanggil API via useClassPerformance hook (BENAR).
// Hanya menambahkan error state yang sebelumnya tidak ada.
const PrincipalReports: React.FC = () => {
  const { data, isLoading, error, refetch } = useClassPerformance();

  if (isLoading) return (
    <div className="p-6 space-y-6">
      <Skeleton variant="rounded" width={320} height={36} />
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        {[1,2,3,4].map((i) => (
          <SkeletonCard key={i} className="h-32" />
        ))}
      </div>
      <SkeletonTable rows={8} cols={6} />
    </div>
  );

  // A3-L2 FIX: Tampilkan error state
  if (error) {
    return (
      <div className="p-6">
        <div className="bg-red-50 border border-red-200 rounded-xl p-8 text-center">
          <AlertTriangle className="w-12 h-12 text-red-400 mx-auto mb-3" />
          <h2 className="text-lg font-semibold text-red-700 mb-2">Gagal Memuat Laporan</h2>
          <p className="text-sm text-red-500 mb-4">
            {(error as any)?.message ?? 'Terjadi kesalahan saat mengambil data laporan.'}
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

  const ranking = data?.performance_ranking || [];
  const gradeComparison = data?.grade_comparison || [];

  return (
    <div className="p-6 space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-slate-900 flex items-center gap-2">
          <FileText className="w-6 h-6 text-blue-600" />
          Laporan Sekolah
        </h1>
        <p className="text-sm text-slate-500">Performa kelas dan perbandingan jenjang</p>
      </div>

      {/* Grade Comparison */}
      {gradeComparison.length > 0 && (
        <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
          <h2 className="text-lg font-semibold text-slate-800 mb-4 flex items-center gap-2">
            <BarChart3 className="w-5 h-5 text-blue-600" />
            Perbandingan Jenjang
          </h2>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
            {gradeComparison.map((grade) => (
              <div key={grade.grade_level} className="text-center p-4 bg-slate-50 rounded-xl">
                <p className="text-sm text-slate-500">{grade.grade_name}</p>
                <p className={`text-3xl font-bold mt-1 ${grade.avg_attendance_rate >= 90 ? 'text-green-600' : grade.avg_attendance_rate >= 75 ? 'text-yellow-600' : 'text-red-600'}`}>
                  {grade.avg_attendance_rate}%
                </p>
                <p className="text-xs text-slate-400 mt-1">{grade.total_classes} kelas</p>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* No data state */}
      {ranking.length === 0 && gradeComparison.length === 0 && (
        <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-8 text-center text-slate-400">
          <FileText className="w-12 h-12 mx-auto mb-3 text-slate-200" />
          <p className="font-medium">Belum Ada Data Laporan</p>
          <p className="text-sm mt-1">Data laporan performa kelas belum tersedia.</p>
        </div>
      )}

      {/* Class Performance Ranking */}
      <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
        <h2 className="text-lg font-semibold text-slate-800 mb-4 flex items-center gap-2">
          <Trophy className="w-5 h-5 text-amber-500" />
          Peringkat Kelas
        </h2>
        {ranking.length === 0 ? (
          <p className="text-sm text-slate-400 text-center py-8">Tidak ada data peringkat.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-slate-400 text-xs uppercase border-b border-slate-100">
                  <th className="pb-3 w-12">#</th>
                  <th className="pb-3">Kelas</th>
                  <th className="pb-3">Jenjang</th>
                  <th className="pb-3 text-center">Siswa</th>
                  <th className="pb-3 text-center">Kehadiran</th>
                  <th className="pb-3 text-center">Tren</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-50">
                {ranking.map((cls) => (
                  <tr key={cls.class_id} className="hover:bg-slate-50">
                    <td className="py-3">
                      <span className={`inline-flex items-center justify-center w-7 h-7 rounded-full text-xs font-bold ${cls.rank <= 3 ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-500'}`}>
                        {cls.rank}
                      </span>
                    </td>
                    <td className="py-3 font-medium text-slate-800">{cls.class_name}</td>
                    <td className="py-3 text-slate-500">{cls.grade_level}</td>
                    <td className="py-3 text-center text-slate-500">{cls.total_students}</td>
                    <td className="py-3 text-center">
                      <span className={`font-mono font-semibold ${cls.attendance_rate >= 90 ? 'text-green-600' : cls.attendance_rate >= 75 ? 'text-yellow-600' : 'text-red-600'}`}>
                        {cls.attendance_rate}%
                      </span>
                    </td>
                    <td className="py-3 text-center">
                      {cls.trend === 'up' && <TrendingUp className="w-4 h-4 text-green-500 mx-auto" />}
                      {cls.trend === 'down' && <TrendingDown className="w-4 h-4 text-red-500 mx-auto" />}
                      {(!cls.trend || cls.trend === 'stable') && <Minus className="w-4 h-4 text-slate-300 mx-auto" />}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
};

export default PrincipalReports;
