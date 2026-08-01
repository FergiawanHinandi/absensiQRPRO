import React from 'react';
import { useNavigate } from 'react-router-dom';
import { SkeletonDashboard } from '../../components/ui/LoadingStates';
import ErrorMessage from '../../components/common/ErrorMessage';
import { getErrorMessage } from '../../utils/errorHandler';
import { useStudentDashboard } from '../../modules/student/hooks';
import { useAuthStore } from '../../modules/auth/stores/useAuthStore';
import {
  CheckCircle2,
  Clock,
  XCircle,
  AlertTriangle,
  CalendarDays,
  TrendingUp,
  BookOpen,
  History,
  User as UserIcon,
} from 'lucide-react';

const getStatusConfig = (status: string) => {
  switch (status) {
    case 'present':
      return { color: 'bg-green-500', text: 'Hadir', icon: CheckCircle2, bg: 'bg-green-50 text-green-700' };
    case 'late':
      return { color: 'bg-yellow-500', text: 'Terlambat', icon: Clock, bg: 'bg-yellow-50 text-yellow-700' };
    case 'sick':
    case 'permission':
      return { color: 'bg-blue-500', text: 'Izin/Sakit', icon: AlertTriangle, bg: 'bg-blue-50 text-blue-700' };
    case 'alpha':
    case 'absent':
      return { color: 'bg-red-500', text: 'Alpha', icon: XCircle, bg: 'bg-red-50 text-red-700' };
    default:
      return { color: 'bg-gray-400', text: 'Belum Absen', icon: AlertTriangle, bg: 'bg-gray-50 text-gray-500' };
  }
};

const StudentDashboard: React.FC = () => {
  const navigate = useNavigate();
  const { user } = useAuthStore();
  const { data, isLoading, error, refetch } = useStudentDashboard();

  if (isLoading) return (
    <div className="p-6 max-w-4xl mx-auto">
      <SkeletonDashboard />
    </div>
  );

  if (error) {
    return (
      <div className="p-6 max-w-4xl mx-auto">
        <ErrorMessage message={getErrorMessage(error)} onRetry={() => refetch()} />
      </div>
    );
  }

  const todayStatus = data?.today_status;
  const monthly = data?.monthly_summary;
  const statusConfig = getStatusConfig(todayStatus?.attendance?.status || '');
  const StatusIcon = statusConfig.icon;

  return (
    <div className="p-6 space-y-6 max-w-4xl mx-auto">
      {/* Header */}
      <div className="bg-gradient-to-r from-blue-600 to-indigo-600 rounded-2xl p-6 text-white">
        <p className="text-blue-100 text-sm">Selamat datang,</p>
        <h1 className="text-2xl font-bold">{user?.name || 'Siswa'}</h1>
        <p className="text-blue-200 text-sm mt-1">
          {todayStatus?.date || new Date().toLocaleDateString('id-ID', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}
        </p>
      </div>

      {/* Status Hari Ini */}
      <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
        <h2 className="text-lg font-semibold text-slate-800 mb-4 flex items-center gap-2">
          <CalendarDays className="w-5 h-5 text-blue-600" />
          Status Hari Ini
        </h2>
        <div className="flex items-center gap-4">
          <div className={`p-3 rounded-full ${statusConfig.bg}`}>
            <StatusIcon className="w-8 h-8" />
          </div>
          <div>
            <p className="text-xl font-bold text-slate-900">{statusConfig.text}</p>
            {todayStatus?.attendance?.time && (
              <p className="text-sm text-slate-500">Jam: {todayStatus.attendance.time}</p>
            )}
            <p className="text-sm text-slate-500">{todayStatus?.total_subjects || 0} mata pelajaran hari ini</p>
          </div>
        </div>
      </div>

      {/* Ringkasan Bulanan */}
      {monthly && (
        <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
          <h2 className="text-lg font-semibold text-slate-800 mb-4 flex items-center gap-2">
            <TrendingUp className="w-5 h-5 text-blue-600" />
            Ringkasan Bulan {monthly.month}
          </h2>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div className="text-center p-3 bg-blue-50 rounded-lg">
              <p className="text-2xl font-bold text-blue-600">{monthly.attendance_rate}%</p>
              <p className="text-xs text-slate-500">Kehadiran</p>
            </div>
            <div className="text-center p-3 bg-green-50 rounded-lg">
              <p className="text-2xl font-bold text-green-600">{monthly.present_days}</p>
              <p className="text-xs text-slate-500">Hadir</p>
            </div>
            <div className="text-center p-3 bg-yellow-50 rounded-lg">
              <p className="text-2xl font-bold text-yellow-600">{monthly.late_days}</p>
              <p className="text-xs text-slate-500">Terlambat</p>
            </div>
            <div className="text-center p-3 bg-red-50 rounded-lg">
              <p className="text-2xl font-bold text-red-600">{monthly.absent_days}</p>
              <p className="text-xs text-slate-500">Alpha</p>
            </div>
          </div>
        </div>
      )}

      {/* Quick Links */}
      <div className="grid grid-cols-3 gap-4">
        <button
          onClick={() => navigate('/student/history')}
          className="flex flex-col items-center gap-2 p-4 bg-white rounded-xl shadow-sm border border-slate-200 hover:shadow-md transition-shadow"
        >
          <History className="w-8 h-8 text-indigo-600" />
          <span className="text-sm font-medium text-slate-700">Riwayat</span>
        </button>
        <button
          onClick={() => navigate('/student/schedule')}
          className="flex flex-col items-center gap-2 p-4 bg-white rounded-xl shadow-sm border border-slate-200 hover:shadow-md transition-shadow"
        >
          <BookOpen className="w-8 h-8 text-emerald-600" />
          <span className="text-sm font-medium text-slate-700">Jadwal</span>
        </button>
        <button
          onClick={() => navigate('/student/profile')}
          className="flex flex-col items-center gap-2 p-4 bg-white rounded-xl shadow-sm border border-slate-200 hover:shadow-md transition-shadow"
        >
          <UserIcon className="w-8 h-8 text-orange-600" />
          <span className="text-sm font-medium text-slate-700">Profil</span>
        </button>
      </div>
    </div>
  );
};

export default StudentDashboard;
