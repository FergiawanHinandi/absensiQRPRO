import React, { useState, useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
    startOfWeek,
    endOfWeek,
    format,
    addDays,
    addWeeks,
    subWeeks,
    isSameDay
} from 'date-fns';
import { id } from 'date-fns/locale';
import { teacherService, type TeacherSchedule } from '../../services/teacherService';
import { ChevronLeft, ChevronRight, Calendar, Clock, MapPin, Users, AlertCircle } from 'lucide-react';
import { Skeleton, SkeletonCard } from '../../components/ui/LoadingStates';

const TeacherSchedulePage: React.FC = () => {
    // State: Current Week Focus (Defaults to Today)
    const [currentDate, setCurrentDate] = useState(new Date());

    // Compute Week Range
    const weekStart = useMemo(() => startOfWeek(currentDate, { weekStartsOn: 1 }), [currentDate]);
    const weekEnd = useMemo(() => endOfWeek(currentDate, { weekStartsOn: 1 }), [currentDate]);

    // API Call
    const { data: schedules, isLoading, isError, error } = useQuery({
        queryKey: ['teacher-schedules', format(weekStart, 'yyyy-MM-dd')],
        queryFn: () => teacherService.getSchedules(format(weekStart, 'yyyy-MM-dd')),
        // Default to empty array to avoid undefined errors
        initialData: [] as TeacherSchedule[],
    });

    // Navigation
    const nextWeek = () => setCurrentDate(addWeeks(currentDate, 1));
    const prevWeek = () => setCurrentDate(subWeeks(currentDate, 1));
    const goToToday = () => setCurrentDate(new Date());

    // Grid Construction
    const weekDays = useMemo(() => {
        // Monday to Friday (5 days)
        return Array.from({ length: 5 }).map((_, i) => addDays(weekStart, i));
    }, [weekStart]);

    // Group Schedules by Day
    const schedulesByDay = useMemo(() => {
        const grouped: Record<string, TeacherSchedule[]> = {};

        // Initialize empty arrays
        weekDays.forEach(day => {
            const dayKey = format(day, 'EEEE').toLowerCase(); // 'monday', 'tuesday' etc.
            grouped[dayKey] = [];
        });

        if (schedules) {
            schedules.forEach(schedule => {
                // Backend returns 'monday', 'tuesday' etc.
                // Map local 'senin' -> 'monday' IF needed, based on Locale?
                // Assuming backend returns standard English lowercase days as per interface
                // But date-fns 'EEEE' with 'id' locale returns 'Senin', 'Selasa'.
                // Need robust mapping or standardized keys.
                // Approach: We use the backend key directly if it matches our day index?
                // Better: Map 'monday' -> 0, 'tuesday' -> 1...
                // Or simply use English keys for grouping.

                const key = schedule.day_of_week.toLowerCase();
                if (!grouped[key]) grouped[key] = [];
                grouped[key].push(schedule);
            });
        }

        // Sort by time within day
        Object.keys(grouped).forEach(key => {
            grouped[key].sort((a, b) => a.start_time.localeCompare(b.start_time));
        });

        return grouped;
    }, [schedules, weekDays]);

    // Helper to get day key from Date object for lookup
    const getDayKey = (date: Date) => {
        // We know weekDays are Mon-Fri
        const dayIndex = date.getDay(); // 0=Sun, 1=Mon...
        const map = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        return map[dayIndex];
    };

    if (isLoading) return (
        <div className="space-y-6">
            <Skeleton variant="rounded" width="100%" height={72} />
            <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
                {[1,2,3,4,5].map((i) => (
                    <SkeletonCard key={i} className="h-80" />
                ))}
            </div>
        </div>
    );

    if (isError) {
        return (
            <div className="flex flex-col items-center justify-center min-h-[50vh] text-slate-500">
                <AlertCircle className="w-12 h-12 text-red-400 mb-2" />
                <p>Gagal memuat jadwal ({error instanceof Error ? error.message : 'Unknown error'})</p>
                <button onClick={() => window.location.reload()} className="mt-4 px-4 py-2 bg-blue-50 text-blue-600 rounded-lg hover:bg-blue-100 transition-colors">
                    Coba Lagi
                </button>
            </div>
        );
    }

    return (
        <div className="space-y-6">
            {/* Header / Navigation */}
            <div className="bg-white rounded-xl border border-slate-200 p-4 flex flex-col md:flex-row items-center justify-between gap-4 shadow-sm">
                <div className="flex items-center gap-2">
                    <div className="p-2 bg-blue-50 text-blue-600 rounded-lg">
                        <Calendar className="w-5 h-5" />
                    </div>
                    <div>
                        <h1 className="font-bold text-lg text-slate-800">Jadwal Mengajar</h1>
                        <p className="text-sm text-slate-500">
                            {format(weekStart, 'd MMMM', { locale: id })} - {format(weekEnd, 'd MMMM yyyy', { locale: id })}
                        </p>
                    </div>
                </div>

                <div className="flex items-center gap-2 bg-slate-50 p-1 rounded-lg border border-slate-200">
                    <button onClick={prevWeek} className="p-2 hover:bg-white hover:shadow-sm rounded-md transition-all text-slate-600">
                        <ChevronLeft className="w-4 h-4" />
                    </button>
                    <button onClick={goToToday} className="px-3 py-1.5 text-sm font-medium hover:bg-white hover:text-blue-600 hover:shadow-sm rounded-md transition-all text-slate-600">
                        Hari Ini
                    </button>
                    <button onClick={nextWeek} className="p-2 hover:bg-white hover:shadow-sm rounded-md transition-all text-slate-600">
                        <ChevronRight className="w-4 h-4" />
                    </button>
                </div>
            </div>

            {/* Weekly Grid */}
            <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
                {weekDays.map((date) => {
                    const dayKey = getDayKey(date);
                    const daySchedules = schedulesByDay[dayKey] || [];
                    const isToday = isSameDay(new Date(), date);

                    return (
                        <div key={dayKey} className={`flex flex-col h-full bg-white rounded-xl border ${isToday ? 'border-blue-300 ring-4 ring-blue-50/50 shadow-md' : 'border-slate-200 shadow-sm'} overflow-hidden transition-all hover:shadow-md`}>
                            {/* Day Header */}
                            <div className={`p-4 border-b ${isToday ? 'bg-blue-50 border-blue-100' : 'bg-slate-50 border-slate-100'}`}>
                                <p className={`font-bold text-lg ${isToday ? 'text-blue-700' : 'text-slate-800'}`}>
                                    {format(date, 'EEEE', { locale: id })}
                                </p>
                                <p className={`text-sm font-medium ${isToday ? 'text-blue-600' : 'text-slate-500'}`}>
                                    {format(date, 'd MMM yyyy', { locale: id })}
                                </p>
                            </div>

                            {/* Schedule Slot List */}
                            <div className="flex-1 p-3 space-y-3 min-h-[200px] bg-slate-50/30">
                                {daySchedules.length > 0 ? (
                                    daySchedules.map((schedule) => (
                                        <div key={schedule.id} className="bg-white p-3 rounded-lg border border-slate-200 shadow-sm hover:border-blue-300 hover:shadow-md transition-all group cursor-default relative">

                                            {/* Time Tag */}
                                            <div className="inline-flex items-center gap-1.5 px-2 py-1 bg-slate-100 text-slate-600 rounded text-xs font-semibold mb-2 group-hover:bg-blue-50 group-hover:text-blue-600 transition-colors">
                                                <Clock className="w-3 h-3" />
                                                {schedule.start_time} - {schedule.end_time}
                                            </div>

                                            {/* Subject */}
                                            <h4 className="font-bold text-slate-800 text-sm mb-1 line-clamp-2">
                                                {schedule.subject_name}
                                            </h4>

                                            {/* Metadata */}
                                            <div className="space-y-1">
                                                <div className="flex items-center gap-1.5 text-xs text-slate-500">
                                                    <Users className="w-3 h-3" />
                                                    <span className="font-medium">{schedule.class_name}</span>
                                                </div>
                                                <div className="flex items-center gap-1.5 text-xs text-slate-500">
                                                    <MapPin className="w-3 h-3" />
                                                    <span>{schedule.room || 'Ruang Kelas'}</span>
                                                </div>
                                            </div>

                                            {/* Accent Bar */}
                                            <div className="absolute left-0 top-3 bottom-3 w-1 bg-blue-500 rounded-r opacity-0 group-hover:opacity-100 transition-opacity" />
                                        </div>
                                    ))
                                ) : (
                                    <div className="h-full flex flex-col items-center justify-center text-slate-400 py-8">
                                        <div className="w-12 h-12 bg-slate-100 rounded-full flex items-center justify-center mb-2">
                                            <Calendar className="w-5 h-5 text-slate-300" />
                                        </div>
                                        <span className="text-xs font-medium">Kosong</span>
                                    </div>
                                )}
                            </div>
                        </div>
                    );
                })}
            </div>

            {/* Pagination / Limit Warning */}
            {schedules && schedules.length > 20 && (
                <div className="flex justify-center mt-6">
                    <span className="text-xs text-slate-400 bg-slate-100 px-3 py-1 rounded-full border border-slate-200">
                        Menampilkan {schedules.length} jadwal minggu ini
                    </span>
                </div>
            )}
        </div>
    );
};

export default TeacherSchedulePage;
