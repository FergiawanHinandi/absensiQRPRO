import React, { useState } from 'react';
import { SkeletonDashboard } from '../../components/ui/LoadingStates';
import ErrorMessage from '../../components/common/ErrorMessage';
import { getErrorMessage } from '../../utils/errorHandler';
import { AttendanceTrendChart } from '../../components/dashboard/AttendanceTrendChart';
import { LiveAttendanceCounter } from '../../components/dashboard/LiveAttendanceCounter';
import { ExportTrendButton } from '../../components/dashboard/ExportTrendButton';
import { useAttendanceOverview, useRiskStudents } from '../../modules/principal/hooks';
import { useNavigate } from 'react-router-dom';
import {
  Users, TrendingUp, AlertTriangle, BarChart3,
  CheckCircle2, Clock, XCircle, ChevronRight,
  Eye, FileText, ShieldCheck,
} from 'lucide-react';

const PrincipalDashboard: React.FC = () => {
  const navigate = useNavigate();
  const [range, setRange] = useState<'7d' | '30d'>('7d');
  const [chartPeriod, setChartPeriod] = useState<'7d' | '30d' | '90d'>('7d');
  const { data: overview, isLoading, error: overviewError, refetch: refetchOverview } = useAttendanceOverview(range);
  const { data: risk, error: riskError, refetch: refetchRisk } = useRiskStudents(10);

  if (isLoading) return (
    <div className="p-6">
      <SkeletonDashboard />
    </div>
  );

  if (overviewError || riskError) {
    return (
      <div className="p-6">
        <ErrorMessage
          message={getErrorMessage(overviewError || riskError)}
          onRetry={() => {
            refetchOverview();
            refetchRisk();
          }}
        />
      </div>
    );
  }

  const summary = overview?.school_summary;

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Dashboard Kepala Sekolah</h1>
          <p className="text-sm text-slate-500">Ringkasan kehadiran dan performa sekolah</p>
        </div>
        <div className="flex gap-2">
          <button
            onClick={() => setRange('7d')}
            className={`px-3 py-1.5 rounded-lg text-sm font-medium transition-colors ${range === '7d' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}
          >
            7 Hari
          </button>
          <button
            onClick={() => setRange('30d')}
            className={`px-3 py-1.5 rounded-lg text-sm font-medium transition-colors ${range === '30d' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}
          >
            30 Hari
          </button>
        </div>
      </div>

      {/* Summary Cards */}
      {summary && (
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <SummaryCard icon={Users} label="Total Siswa" value={summary.total_students} color="blue" />
          <SummaryCard icon={CheckCircle2} label="Hadir Hari Ini" value={summary.present_today} color="green" />
          <SummaryCard icon={Clock} label="Terlambat" value={summary.late_today} color="yellow" />
          <SummaryCard icon={XCircle} label="Tidak Hadir" value={summary.absent_today} color="red" />
        </div>
      )}

      {/* Attendance Rate Card */}
      {summary && (
        <div className="bg-gradient-to-r from-blue-600 to-indigo-600 rounded-2xl p-6 text-white">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-blue-100 text-sm">Tingkat Kehadiran</p>
              <p className="text-4xl font-bold mt-1">{summary.attendance_rate}%</p>
              <p className="text-blue-200 text-sm mt-1">{summary.total_classes} kelas aktif</p>
            </div>
            <TrendingUp className="w-16 h-16 text-white/20" />
          </div>
        </div>
      )}

      {/* Middle section: Class Breakdown + Live Counter side by side */}
      <div className="grid grid-cols-1 lg:grid-cols-4 gap-6">
        {/* Class Breakdown - wider */}
        <div className="lg:col-span-3">
          {overview?.class_breakdown && overview.class_breakdown.length > 0 && (
            <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
              <div className="flex items-center justify-between mb-4">
                <h2 className="text-lg font-semibold text-slate-800 flex items-center gap-2">
                  <BarChart3 className="w-5 h-5 text-blue-600" />
                  Kehadiran Per Kelas
                </h2>
                <button onClick={() => navigate('/principal/monitoring')} className="text-sm text-blue-600 hover:text-blue-800 flex items-center gap-1">
                  Detail <ChevronRight className="w-4 h-4" />
                </button>
              </div>
              <div className="space-y-3">
                {overview.class_breakdown.slice(0, 8).map((cls) => (
                  <div key={cls.class_id} className="flex items-center gap-3">
                    <span className="text-sm font-medium text-slate-700 w-24 truncate">{cls.class_name}</span>
                    <div className="flex-1 h-3 bg-slate-100 rounded-full overflow-hidden">
                      <div
                        className={`h-full rounded-full transition-all ${cls.attendance_rate >= 90 ? 'bg-green-500' : cls.attendance_rate >= 75 ? 'bg-yellow-500' : 'bg-red-500'}`}
                        style={{ width: `${cls.attendance_rate}%` }}
                      />
                    </div>
                    <span className="text-sm font-mono text-slate-500 w-14 text-right">{cls.attendance_rate}%</span>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>

        {/* Live Attendance Counter - sidebar */}
        <div className="lg:col-span-1">
          <LiveAttendanceCounter title="Absensi Hari Ini" compact={true} />
        </div>
      </div>

      {/* Risk Students Preview */}
      {risk?.risk_summary && (
        <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-lg font-semibold text-slate-800 flex items-center gap-2">
              <AlertTriangle className="w-5 h-5 text-amber-500" />
              Siswa Berisiko
            </h2>
            <div className="flex gap-2">
              <span className="px-2 py-0.5 bg-red-100 text-red-700 text-xs rounded-full font-medium">
                Tinggi: {risk.risk_summary.high_risk_count}
              </span>
              <span className="px-2 py-0.5 bg-yellow-100 text-yellow-700 text-xs rounded-full font-medium">
                Sedang: {risk.risk_summary.medium_risk_count}
              </span>
            </div>
          </div>
          {risk.high_risk_students.length > 0 ? (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-slate-400 text-xs uppercase">
                    <th className="pb-2">Siswa</th>
                    <th className="pb-2">Kelas</th>
                    <th className="pb-2 text-center">Kehadiran</th>
                    <th className="pb-2 text-center">Alpha</th>
                    <th className="pb-2 text-center">Risiko</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {risk.high_risk_students.slice(0, 5).map((s) => (
                    <tr key={s.student_id}>
                      <td className="py-2 font-medium text-slate-800">{s.student_name}</td>
                      <td className="py-2 text-slate-500">{s.class_name}</td>
                      <td className="py-2 text-center">
                        <span className={`font-mono ${s.attendance_rate < 60 ? 'text-red-600' : 'text-yellow-600'}`}>
                          {s.attendance_rate}%
                        </span>
                      </td>
                      <td className="py-2 text-center text-red-500 font-mono">{s.absent_days}</td>
                      <td className="py-2 text-center">
                        <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${s.risk_level === 'high' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700'}`}>
                          {s.risk_level === 'high' ? 'Tinggi' : 'Sedang'}
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <p className="text-sm text-slate-400 text-center py-4">Tidak ada siswa berisiko tinggi saat ini.</p>
          )}
        </div>
      )}

      {/* Attendance Trend Chart */}
      <div className="relative">
        <AttendanceTrendChart
          title="Tren Kehadiran Sekolah"
          showComparison={true}
          period={chartPeriod}
          onPeriodChange={setChartPeriod}
        />
        <div className="absolute top-5 right-5 z-10">
          <ExportTrendButton period={chartPeriod} variant="icon" />
        </div>
      </div>

      {/* Quick Links */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        <QuickLink icon={Eye} label="Monitoring" onClick={() => navigate('/principal/monitoring')} />
        <QuickLink icon={FileText} label="Laporan" onClick={() => navigate('/principal/reports')} />
        <QuickLink icon={ShieldCheck} label="Approval" onClick={() => navigate('/principal/approvals')} />
        <QuickLink icon={BarChart3} label="Performa Kelas" onClick={() => navigate('/principal/class-performance')} />
      </div>
    </div>
  );
};

const SummaryCard: React.FC<{
  icon: React.ElementType;
  label: string;
  value: number;
  color: 'blue' | 'green' | 'yellow' | 'red';
}> = ({ icon: Icon, label, value, color }) => {
  const colorMap = {
    blue: 'bg-blue-50 text-blue-600',
    green: 'bg-green-50 text-green-600',
    yellow: 'bg-yellow-50 text-yellow-600',
    red: 'bg-red-50 text-red-600',
  };
  return (
    <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
      <div className={`w-10 h-10 rounded-lg ${colorMap[color]} flex items-center justify-center mb-3`}>
        <Icon className="w-5 h-5" />
      </div>
      <p className="text-2xl font-bold text-slate-900">{value}</p>
      <p className="text-xs text-slate-500">{label}</p>
    </div>
  );
};

const QuickLink: React.FC<{
  icon: React.ElementType;
  label: string;
  onClick: () => void;
}> = ({ icon: Icon, label, onClick }) => (
  <button
    onClick={onClick}
    className="flex items-center gap-3 p-4 bg-white rounded-xl shadow-sm border border-slate-200 hover:shadow-md transition-shadow text-left"
  >
    <Icon className="w-5 h-5 text-blue-600" />
    <span className="text-sm font-medium text-slate-700">{label}</span>
  </button>
);

export default PrincipalDashboard;
