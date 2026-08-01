import React, { useState, useMemo } from 'react';
import { SkeletonDashboard } from '../../components/ui/LoadingStates';
import ErrorMessage from '../../components/common/ErrorMessage';
import {
    Calendar as CalendarIcon,
    Clock,
    MapPin,
    Users,
    CheckCircle2,
    AlertTriangle,
    QrCode,
    FileEdit,
    BookOpen,
    GraduationCap,
} from 'lucide-react';
import {
    useTeacherSchedule,
    useHomeroomStats
} from '../../modules/teacher/hooks';
import { AnnouncementWidget } from '../../components/AnnouncementWidget';
import { LiveAttendanceFeed } from '../../components/dashboard/LiveAttendanceFeed';
import { AttendanceTrendChart } from '../../components/dashboard/AttendanceTrendChart';
import { useNavigate } from 'react-router-dom';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';

// Helper types
interface ScheduleSession {
    start_time: string;
    end_time: string;
    subject_name: string;
    class_name: string;
    room_name: string;
}

// Helper component for schedule timeline
const ScheduleTimelineItem = ({ session, isNext, isPast }: { session: ScheduleSession, isNext: boolean, isPast: boolean }) => {
    return (
        <div className={`relative pl-8 pb-8 border-l-2 ${isNext ? 'border-blue-500' : isPast ? 'border-slate-300' : 'border-slate-200'} last:pb-0`}>
            {/* Timeline Dot */}
            <div className={`absolute -left-[9px] top-0 w-4 h-4 rounded-full border-2 ${isNext ? 'bg-blue-500 border-blue-100 ring-4 ring-blue-50' :
                isPast ? 'bg-slate-400 border-slate-50' :
                    'bg-white border-slate-300'
                }`}></div>

            <div className={`p-4 rounded-xl border transition-all ${isNext
                ? 'bg-blue-50 border-blue-200 shadow-sm'
                : isPast
                    ? 'bg-slate-50 border-slate-100 opacity-70'
                    : 'bg-white border-slate-200 hover:border-blue-200 hover:shadow-sm'
                }`}>
                <div className="flex justify-between items-start mb-2">
                    <div>
                        <span className={`inline-block px-2 py-0.5 rounded text-xs font-semibold mb-1 ${isNext ? 'bg-blue-100 text-blue-700' : 'bg-slate-100 text-slate-600'
                            }`}>
                            {session.start_time} - {session.end_time}
                        </span>
                        <h4 className={`font-bold text-lg ${isNext ? 'text-blue-900' : 'text-slate-900'}`}>
                            {session.subject_name || 'Mata Pelajaran'}
                        </h4>
                        <p className="text-sm text-slate-600 flex items-center gap-1.5 mt-0.5">
                            <Users className="w-3.5 h-3.5" /> Kelas {session.class_name}
                            <span className="mx-1 text-slate-300">|</span>
                            <MapPin className="w-3.5 h-3.5" /> {session.room_name || 'Ruang Kelas'}
                        </p>
                    </div>
                    {isNext && (
                        <div className="animate-pulse">
                            <span className="flex items-center gap-1 text-xs font-bold text-blue-600 bg-white px-2 py-1 rounded-full shadow-sm">
                                <Clock className="w-3 h-3" /> NEXT
                            </span>
                        </div>
                    )}
                </div>

                {/* Action Buttons for this session */}
                <div className="flex gap-2 mt-4">
                    <button className="flex-1 py-1.5 px-3 bg-white border border-slate-200 rounded-lg text-sm font-medium text-slate-700 hover:bg-slate-50 hover:text-blue-600 transition-colors flex items-center justify-center gap-2">
                        <QrCode className="w-4 h-4" /> QR Absen
                    </button>
                    <button className="flex-1 py-1.5 px-3 bg-white border border-slate-200 rounded-lg text-sm font-medium text-slate-700 hover:bg-slate-50 hover:text-blue-600 transition-colors flex items-center justify-center gap-2">
                        <FileEdit className="w-4 h-4" /> Manual
                    </button>
                </div>
            </div>
        </div>
    );
};

const TeacherDashboard: React.FC = () => {
    const navigate = useNavigate();
    const todayStr = new Date().toISOString().split('T')[0];
    const [selectedDate] = useState<string>(todayStr);

    // Fetch Data
    const { data: scheduleData, isLoading: scheduleLoading, error: scheduleError, refetch: refetchSchedule } = useTeacherSchedule(selectedDate);
    const { data: homeroomStats } = useHomeroomStats();

    // Logic to find "Next Class" based on current time
    const nextClass = useMemo(() => {
        if (!scheduleData?.schedules || scheduleData.schedules.length === 0) return null;

        const now = new Date();
        const currentMinutes = now.getHours() * 60 + now.getMinutes();

        // Find the next class that hasn't ended yet
        for (const schedule of scheduleData.schedules) {
            const [endHour, endMin] = schedule.end_time.split(':').map(Number);
            const endMinutes = endHour * 60 + endMin;
            if (endMinutes > currentMinutes) {
                return schedule;
            }
        }
        // All classes have ended - return last class
        return scheduleData.schedules[scheduleData.schedules.length - 1];
    }, [scheduleData]);

    // Determine which classes are past and which is next
    const scheduleStatus = useMemo(() => {
        if (!scheduleData?.schedules) return { pastIndices: new Set<number>(), nextIndex: -1 };

        const now = new Date();
        const currentMinutes = now.getHours() * 60 + now.getMinutes();
        const pastIndices = new Set<number>();
        let nextIndex = -1;

        scheduleData.schedules.forEach((schedule: ScheduleSession, index: number) => {
            const [endHour, endMin] = schedule.end_time.split(':').map(Number);
            const endMinutes = endHour * 60 + endMin;
            if (endMinutes <= currentMinutes) {
                pastIndices.add(index);
            } else if (nextIndex === -1) {
                nextIndex = index;
            }
        });

        return { pastIndices, nextIndex };
    }, [scheduleData]);

    const formattedDate = format(new Date(selectedDate), 'EEEE, d MMMM yyyy', { locale: id });

    // Handle Loading State
    if (scheduleLoading && !scheduleData) return (
        <div className="p-6 max-w-6xl mx-auto">
            <SkeletonDashboard />
        </div>
    );

    // Handle Error State
    if (scheduleError) {
        return (
            <div className="min-h-screen bg-slate-50 flex items-center justify-center p-4">
                <ErrorMessage message="Gagal memuat jadwal mengajar" onRetry={() => refetchSchedule()} />
            </div>
        );
    }

    return (
        <div className="min-h-screen bg-slate-50 pb-12 font-sans text-slate-900">
            {/* Header Section */}
            <div className="bg-white border-b border-slate-200 sticky top-0 z-30 shadow-sm backdrop-blur-md bg-white/90">
                <div className="px-6 py-4 flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-3 mb-1">
                            <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Dashboard Guru</h1>
                            {homeroomStats?.is_homeroom ? (
                                <span className="px-2.5 py-0.5 rounded-full text-xs font-bold bg-purple-100 text-purple-700 border border-purple-200 uppercase tracking-wide">
                                    Mode Wali Kelas
                                </span>
                            ) : (
                                <span className="px-2.5 py-0.5 rounded-full text-xs font-bold bg-blue-100 text-blue-700 border border-blue-200 uppercase tracking-wide">
                                    Mode Guru Mapel
                                </span>
                            )}
                        </div>
                        <p className="text-sm text-slate-500">
                            Selamat Datang, Bapak/Ibu Guru.
                        </p>
                    </div>
                    <div className="flex items-center gap-3">
                        <div className="bg-slate-100 px-3 py-1.5 rounded-lg text-sm font-medium text-slate-700 flex items-center gap-2">
                            <CalendarIcon className="w-4 h-4 text-slate-500" />
                            {formattedDate}
                        </div>
                    </div>
                </div>
            </div>

            <div className="p-6 max-w-6xl mx-auto space-y-6">

                {/* 1. Hero Section: Next Class */}
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {/* Next Class Card (Priority) */}
                    <div className="lg:col-span-2 bg-gradient-to-br from-blue-600 to-indigo-700 rounded-2xl p-6 text-white shadow-lg relative overflow-hidden">
                        <div className="relative z-10 flex flex-col h-full justify-between">
                            <div>
                                <div className="flex items-center gap-2 text-blue-100 mb-1">
                                    <Clock className="w-4 h-4" />
                                    <span className="text-sm font-medium">Jadwal Sebelumnya / Berikutnya</span>
                                </div>
                                <h2 className="text-3xl font-bold mb-2">
                                    {nextClass ? nextClass.subject_name : 'Tidak ada jadwal aktif'}
                                </h2>
                                {nextClass && (
                                    <div className="flex flex-wrap gap-4 text-sm mt-2">
                                        <div className="bg-white/20 backdrop-blur-sm px-3 py-1.5 rounded-lg flex items-center gap-2">
                                            <Users className="w-4 h-4" /> Route: {nextClass.class_name}
                                        </div>
                                        <div className="bg-white/20 backdrop-blur-sm px-3 py-1.5 rounded-lg flex items-center gap-2">
                                            <MapPin className="w-4 h-4" /> {nextClass.room_name || 'Ruang Regular'}
                                        </div>
                                        <div className="bg-white/20 backdrop-blur-sm px-3 py-1.5 rounded-lg flex items-center gap-2">
                                            <Clock className="w-4 h-4" /> {nextClass.start_time} - {nextClass.end_time}
                                        </div>
                                    </div>
                                )}
                            </div>

                            <div className="mt-8 flex gap-3">
                                <button onClick={() => navigate('/teacher/attendance/qr')} className="bg-white text-blue-700 px-5 py-2.5 rounded-xl font-bold text-sm shadow hover:bg-blue-50 transition-colors flex items-center gap-2">
                                    <QrCode className="w-4 h-4" /> Tampilkan QR
                                </button>
                                <button onClick={() => navigate('/teacher/attendance/manual')} className="bg-blue-500/30 hover:bg-blue-500/40 text-white border border-blue-400/50 px-5 py-2.5 rounded-xl font-bold text-sm backdrop-blur-sm transition-colors flex items-center gap-2">
                                    <FileEdit className="w-4 h-4" /> Input Manual
                                </button>
                            </div>
                        </div>

                        {/* Decorative BG Icon */}
                        <div className="absolute top-0 right-0 -mt-8 -mr-8 opacity-10">
                            <BookOpen className="w-64 h-64 text-white" />
                        </div>
                    </div>

                    {/* Quick Stats / Homeroom Summary */}
                    <div className="space-y-4">
                        {homeroomStats?.is_homeroom ? (
                            <div className="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm h-full flex flex-col">
                                <h3 className="font-bold text-slate-800 flex items-center gap-2 mb-4">
                                    <GraduationCap className="w-5 h-5 text-emerald-600" />
                                    Wali Kelas {homeroomStats.class_name}
                                </h3>

                                <div className="space-y-4 flex-1">
                                    <div className="flex justify-between items-center p-3 bg-slate-50 rounded-xl">
                                        <div className="flex items-center gap-3">
                                            <div className="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center text-blue-600">
                                                <Users className="w-4 h-4" />
                                            </div>
                                            <span className="text-sm font-medium text-slate-600">Total Siswa</span>
                                        </div>
                                        <span className="text-lg font-bold text-slate-900">{homeroomStats.total_students}</span>
                                    </div>

                                    <div className="flex justify-between items-center p-3 bg-slate-50 rounded-xl">
                                        <div className="flex items-center gap-3">
                                            <div className="w-8 h-8 rounded-full bg-green-100 flex items-center justify-center text-green-600">
                                                <CheckCircle2 className="w-4 h-4" />
                                            </div>
                                            <span className="text-sm font-medium text-slate-600">Hadir Hari Ini</span>
                                        </div>
                                        <div className="text-right">
                                            <span className="block text-lg font-bold text-slate-900">{homeroomStats.present_today}</span>
                                            <span className="text-xs text-slate-400">dari {homeroomStats.total_students}</span>
                                        </div>
                                    </div>

                                    <div className="flex justify-between items-center p-3 bg-slate-50 rounded-xl">
                                        <div className="flex items-center gap-3">
                                            <div className="w-8 h-8 rounded-full bg-red-100 flex items-center justify-center text-red-600">
                                                <AlertTriangle className="w-4 h-4" />
                                            </div>
                                            <span className="text-sm font-medium text-slate-600">Absen / Masalah</span>
                                        </div>
                                        <span className="text-lg font-bold text-red-600">{homeroomStats.absent_today}</span>
                                    </div>
                                </div>

                                <button onClick={() => navigate('/teacher/class-attendance/daily')} className="w-full mt-4 py-2 text-sm text-blue-600 font-medium hover:bg-blue-50 rounded-lg transition-colors border border-dashed border-blue-200">
                                    Lihat Detail Kelas
                                </button>
                            </div>
                        ) : (
                            // Fallback if not homeroom teacher: Simple teaching stats
                            <div className="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm h-full flex flex-col justify-center items-center text-center">
                                <div className="w-16 h-16 bg-blue-50 text-blue-600 rounded-full flex items-center justify-center mb-3">
                                    <BookOpen className="w-8 h-8" />
                                </div>
                                <h3 className="font-bold text-slate-900 text-lg">Jadwal Mengajar</h3>
                                <p className="text-slate-500 text-sm mt-1">Anda memiliki {scheduleData?.schedules?.length || 0} sesi kelas hari ini.</p>
                            </div>
                        )}
                    </div>
                </div>

                {/* 2. Attendance Trend Chart */}
                <AttendanceTrendChart title="Tren Kehadiran Saya" className="mb-6" />

                {/* 3. Main Content: Schedule Timeline & Quick Tools */}
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {/* Left: Schedule Timeline */}
                    <div className="lg:col-span-2">
                        <div className="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
                            <h3 className="font-bold text-slate-900 text-lg mb-6 flex items-center gap-2">
                                <CalendarIcon className="w-5 h-5 text-blue-600" />
                                Timeline Hari Ini
                            </h3>

                            <div className="mt-2 pl-2">
                                {scheduleData?.schedules && scheduleData.schedules.length > 0 ? (
                                    scheduleData.schedules.map((session: ScheduleSession, index: number) => {
                                        const isNext = index === scheduleStatus.nextIndex;
                                        const isPast = scheduleStatus.pastIndices.has(index);
                                        return (
                                            <ScheduleTimelineItem
                                                key={index}
                                                session={session}
                                                isNext={isNext}
                                                isPast={isPast}
                                            />
                                        );
                                    })
                                ) : (
                                    <div className="text-center py-12 text-slate-500 bg-slate-50 rounded-xl border border-dashed border-slate-200">
                                        <CalendarIcon className="w-12 h-12 mx-auto text-slate-300 mb-2" />
                                        <p>Tidak ada jadwal mengajar untuk tanggal ini.</p>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Right: Quick Tools & Announcements */}
                    <div className="space-y-6">
                        {/* Quick Tools */}
                        <div className="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                            <h3 className="font-bold text-slate-900 mb-4 text-sm uppercase tracking-wide text-slate-500">Aksi Cepat</h3>
                            <div className="grid grid-cols-2 gap-3">
                                <button onClick={() => navigate('/teacher/attendance/manual')} className="p-3 bg-slate-50 hover:bg-slate-100 rounded-xl border border-slate-200 transition-all text-center group">
                                    <FileEdit className="w-6 h-6 mx-auto text-blue-600 mb-2 group-hover:scale-110 transition-transform" />
                                    <span className="text-xs font-semibold text-slate-700">Input Izin</span>
                                </button>
                                <button onClick={() => navigate('/teacher/reports')} className="p-3 bg-slate-50 hover:bg-slate-100 rounded-xl border border-slate-200 transition-all text-center group">
                                    <BookOpen className="w-6 h-6 mx-auto text-purple-600 mb-2 group-hover:scale-110 transition-transform" />
                                    <span className="text-xs font-semibold text-slate-700">Laporan</span>
                                </button>
                            </div>
                        </div>


                        {/* Live Attendance Feed */}
                        <div className="h-[400px]">
                            <LiveAttendanceFeed />
                        </div>

                        {/* Announcement Widget */}
                        <AnnouncementWidget />
                    </div>
                </div>

            </div>
        </div>
    );
};

export default TeacherDashboard;
