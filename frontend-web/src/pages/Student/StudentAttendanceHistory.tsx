import React, { useState } from 'react';
import Loading from '../../components/common/Loading';
import { EmptyState } from '../../components/ui/EmptyStates';
import { useAttendanceHistory } from '../../modules/student/hooks';
import {
  CheckCircle2,
  Clock,
  XCircle,
  AlertTriangle,
  Calendar,
  ChevronDown,
  ChevronUp,
} from 'lucide-react';

const getStatusBadge = (status: string) => {
  switch (status) {
    case 'present':
      return { icon: CheckCircle2, label: 'Hadir', cls: 'bg-green-100 text-green-700' };
    case 'late':
      return { icon: Clock, label: 'Terlambat', cls: 'bg-yellow-100 text-yellow-700' };
    case 'sick':
    case 'permission':
      return { icon: AlertTriangle, label: 'Izin/Sakit', cls: 'bg-blue-100 text-blue-700' };
    case 'alpha':
    case 'absent':
      return { icon: XCircle, label: 'Alpha', cls: 'bg-red-100 text-red-700' };
    default:
      return { icon: AlertTriangle, label: status, cls: 'bg-gray-100 text-gray-600' };
  }
};

const StudentAttendanceHistory: React.FC = () => {
  const { data, isLoading } = useAttendanceHistory();
  const [expandedDate, setExpandedDate] = useState<string | null>(null);

  if (isLoading) return <Loading text="Memuat riwayat absensi..." />;

  const _history = data?.history || [];

  // Group history by date
  const grouped = _history.reduce<Record<string, typeof _history>>((acc, record) => {
    if (!acc[record.date]) acc[record.date] = [];
    acc[record.date].push(record);
    return acc;
  }, {});

  const dates = Object.keys(grouped).sort((a, b) => b.localeCompare(a));

  return (
    <div className="p-6 max-w-3xl mx-auto space-y-6">
      {/* Header */}
      <div>
        <h1 className="text-2xl font-bold text-slate-900 flex items-center gap-2">
          <Calendar className="w-6 h-6 text-blue-600" />
          Riwayat Absensi
        </h1>
        {data?.period && (
          <p className="text-sm text-slate-500 mt-1">
            Periode: {data.period.from} — {data.period.to} ({data.total_records} catatan)
          </p>
        )}
      </div>

      {/* Timeline */}
      {dates.length === 0 ? (
        <EmptyState
          icon={Calendar}
          preset="no-attendance"
          title="Belum Ada Riwayat Absensi"
          description="Riwayat kehadiran akan muncul setelah Anda melakukan absensi. Mulai absen sekarang untuk mencatat kehadiran."
          size="md"
        />
      ) : (
        <div className="space-y-3">
          {dates.map((date) => {
            const records = grouped[date];
            const isExpanded = expandedDate === date;
            const formattedDate = new Date(date).toLocaleDateString('id-ID', {
              weekday: 'long',
              year: 'numeric',
              month: 'long',
              day: 'numeric',
            });

            return (
              <div key={date} className="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <button
                  onClick={() => setExpandedDate(isExpanded ? null : date)}
                  className="w-full flex items-center justify-between p-4 hover:bg-slate-50 transition-colors"
                >
                  <div className="flex items-center gap-3">
                    <span className="text-sm font-semibold text-slate-800">{formattedDate}</span>
                    <span className="text-xs text-slate-400">{records.length} mapel</span>
                  </div>
                  <div className="flex items-center gap-2">
                    {/* Mini status badges */}
                    {records.map((r, i) => {
                      const badge = getStatusBadge(r.status);
                      return (
                        <span key={i} className={`w-2 h-2 rounded-full ${badge.cls.includes('green') ? 'bg-green-500' : badge.cls.includes('yellow') ? 'bg-yellow-500' : badge.cls.includes('red') ? 'bg-red-500' : 'bg-blue-500'}`} />
                      );
                    })}
                    {isExpanded ? <ChevronUp className="w-4 h-4 text-slate-400" /> : <ChevronDown className="w-4 h-4 text-slate-400" />}
                  </div>
                </button>

                {isExpanded && (
                  <div className="border-t border-slate-100 divide-y divide-slate-100">
                    {records.map((record, idx) => {
                      const badge = getStatusBadge(record.status);
                      const Icon = badge.icon;
                      return (
                        <div key={idx} className="px-4 py-3 flex items-center gap-4">
                          <div className="text-sm text-slate-400 w-20 text-center font-mono">
                            {record.schedule?.start_time} - {record.schedule?.end_time}
                          </div>
                          <div className="flex-1">
                            <p className="text-sm font-medium text-slate-800">{record.subject?.name}</p>
                            <p className="text-xs text-slate-400">{record.teacher}</p>
                          </div>
                          <span className={`inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium ${badge.cls}`}>
                            <Icon className="w-3 h-3" />
                            {badge.label}
                          </span>
                        </div>
                      );
                    })}
                  </div>
                )}
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
};

export default StudentAttendanceHistory;
