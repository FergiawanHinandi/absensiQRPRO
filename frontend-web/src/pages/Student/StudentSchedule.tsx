import React from 'react';
import Loading from '../../components/common/Loading';
import { EmptyState } from '../../components/ui/EmptyStates';
import { useStudentSchedule } from '../../modules/student/hooks';
import { BookOpen, Clock, MapPin, CheckCircle2, PlayCircle, CircleDot } from 'lucide-react';

const getStatusConfig = (status: string) => {
  switch (status) {
    case 'completed':
      return { icon: CheckCircle2, label: 'Selesai', cls: 'bg-green-100 text-green-700 border-green-200', line: 'bg-green-400' };
    case 'ongoing':
      return { icon: PlayCircle, label: 'Berlangsung', cls: 'bg-blue-100 text-blue-700 border-blue-300 ring-2 ring-blue-200', line: 'bg-blue-500' };
    case 'upcoming':
    default:
      return { icon: CircleDot, label: 'Akan Datang', cls: 'bg-slate-50 text-slate-500 border-slate-200', line: 'bg-slate-300' };
  }
};

const StudentSchedule: React.FC = () => {
  const { data, isLoading } = useStudentSchedule();

  if (isLoading) return <Loading text="Memuat jadwal..." />;

  const schedule = data?.schedule || [];

  return (
    <div className="p-6 max-w-3xl mx-auto space-y-6">
      {/* Header */}
      <div>
        <h1 className="text-2xl font-bold text-slate-900 flex items-center gap-2">
          <BookOpen className="w-6 h-6 text-blue-600" />
          Jadwal Hari Ini
        </h1>
        <p className="text-sm text-slate-500 mt-1">
          {data?.day_name}, {data?.date} — {data?.total_subjects || 0} mata pelajaran
        </p>
      </div>

      {/* Timeline */}
      {schedule.length === 0 ? (
        <EmptyState
          icon={BookOpen}
          preset="no-schedules"
          title="Tidak Ada Jadwal Hari Ini"
          description="Tidak ada jadwal pelajaran untuk hari ini. Nikmati hari libur Anda!"
          size="md"
        />
      ) : (
        <div className="relative">
          {/* Vertical Line */}
          <div className="absolute left-[23px] top-4 bottom-4 w-0.5 bg-slate-200" />

          <div className="space-y-4">
            {schedule.map((item) => {
              const config = getStatusConfig(item.status);
              const Icon = config.icon;

              return (
                <div key={item.id} className="relative flex items-start gap-4 pl-2">
                  {/* Timeline Dot */}
                  <div className={`relative z-10 w-3 h-3 rounded-full mt-5 ${config.line} ring-2 ring-white`} />

                  {/* Card */}
                  <div className={`flex-1 rounded-xl border p-4 ${config.cls} transition-all`}>
                    <div className="flex items-start justify-between">
                      <div>
                        <h3 className="font-semibold text-slate-900">{item.subject.name}</h3>
                        <p className="text-xs text-slate-500">{item.subject.code}</p>
                      </div>
                      <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium ${config.cls}`}>
                        <Icon className="w-3 h-3" />
                        {config.label}
                      </span>
                    </div>
                    <div className="mt-3 flex flex-wrap gap-4 text-sm text-slate-600">
                      <span className="inline-flex items-center gap-1">
                        <Clock className="w-3.5 h-3.5" />
                        {item.time.start} - {item.time.end}
                      </span>
                      <span className="inline-flex items-center gap-1">
                        <MapPin className="w-3.5 h-3.5" />
                        {item.room || 'TBA'}
                      </span>
                    </div>
                    <p className="mt-2 text-xs text-slate-400">Guru: {item.teacher}</p>
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
};

export default StudentSchedule;
