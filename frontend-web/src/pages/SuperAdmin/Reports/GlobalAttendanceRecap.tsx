import React, { useEffect, useState } from 'react';
import { BarChart3, TrendingUp, TrendingDown, CheckCircle } from 'lucide-react';
import { apiClient } from '../../../lib/api';

interface DailyRecap {
    date: string;
    present: number;
    late: number;
    sick: number;
    permission: number;
    alpha: number;
}

interface SchoolRanking {
    name: string;
    rate: number;
}

interface ReportData {
    daily_recap: DailyRecap[];
    rankings: {
        top: SchoolRanking[];
        bottom: SchoolRanking[];
    };
    today_summary: {
        total_checked_in: number;
        total_students: number;
        attendance_rate: number;
    };
}

export const GlobalAttendanceRecap: React.FC = () => {
    const [data, setData] = useState<ReportData | null>(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        fetchData();
    }, []);

    const fetchData = async () => {
        try {
            setLoading(true);
            const response = await apiClient.get('/super-admin/reports/attendance-recap');
            if (response.data.success) {
                setData(response.data.data);
            }
        } catch (error) {
            console.error('Failed to fetch attendance recap:', error);
        } finally {
            setLoading(false);
        }
    };

    if (loading) {
        return (
            <div className="flex justify-center py-12">
                <div className="w-8 h-8 border-4 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
            </div>
        );
    }

    if (!data) return null;

    return (
        <div className="min-h-screen bg-slate-50 p-6">
            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 mb-2">Global Attendance Recap</h1>
                <p className="text-slate-600">Overview kehadiran harian seluruh sekolah di platform.</p>
            </div>

            {/* Today Summary */}
            <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <div className="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
                    <div className="flex justify-between items-start mb-4">
                        <div>
                            <p className="text-slate-500 text-sm mb-1">Kehadiran Hari Ini</p>
                            <h3 className="text-3xl font-bold text-slate-900">{data.today_summary.attendance_rate}%</h3>
                        </div>
                        <div className="p-2 bg-green-50 text-green-600 rounded-lg">
                            <CheckCircle className="w-6 h-6" />
                        </div>
                    </div>
                    <div className="w-full bg-slate-100 rounded-full h-2 mb-2">
                        <div className="bg-green-500 h-2 rounded-full" style={{ width: `${data.today_summary.attendance_rate}%` }}></div>
                    </div>
                    <p className="text-xs text-slate-500">
                        {data.today_summary.total_checked_in} dari {data.today_summary.total_students} siswa check-in
                    </p>
                </div>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* Daily Trend Chart (Visual Simulation) */}
                <div className="lg:col-span-2 bg-white p-6 rounded-xl shadow-sm border border-slate-200">
                    <div className="flex justify-between items-center mb-6">
                        <h3 className="font-bold text-slate-800 flex items-center gap-2">
                            <BarChart3 className="w-5 h-5 text-slate-500" />
                            Tren 7 Hari Terakhir
                        </h3>
                    </div>
                    <div className="h-64 flex items-end justify-between gap-2">
                        {data.daily_recap.map((day, idx) => {
                            const total = day.present + day.late + day.sick + day.permission + day.alpha;
                            const heightPercentage = Math.min(((day.present + day.late) / total) * 100, 100);

                            return (
                                <div key={idx} className="flex-1 flex flex-col items-center group relative">
                                    <div className="w-full bg-slate-100 rounded-t-sm h-full relative overflow-hidden">
                                        <div
                                            className="absolute bottom-0 w-full bg-blue-500 rounded-t-sm transition-all duration-500 hover:bg-blue-600"
                                            style={{ height: `${heightPercentage}%` }}
                                        ></div>
                                    </div>
                                    <div className="text-xs text-slate-400 mt-2 truncate w-full text-center">
                                        {new Date(day.date).toLocaleDateString('id-ID', { weekday: 'short' })}
                                    </div>

                                    {/* Tooltip */}
                                    <div className="absolute bottom-full mb-2 bg-slate-800 text-white text-xs rounded p-2 opacity-0 group-hover:opacity-100 transition-opacity z-10 w-32 left-1/2 -translate-x-1/2 pointer-events-none">
                                        <p>Hadir: {day.present}</p>
                                        <p>Terlambat: {day.late}</p>
                                        <p>Sakit: {day.sick}</p>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>

                {/* Top/Bottom Rankings */}
                <div className="bg-white p-6 rounded-xl shadow-sm border border-slate-200 flex flex-col gap-6">
                    <div>
                        <h3 className="font-bold text-slate-800 mb-4 flex items-center gap-2">
                            <TrendingUp className="w-5 h-5 text-green-500" />
                            Sekolah Terdisiplin
                        </h3>
                        <div className="space-y-3">
                            {data.rankings.top.map((school, idx) => (
                                <div key={idx} className="flex justify-between items-center p-3 bg-green-50 rounded-lg">
                                    <span className="font-medium text-slate-700 text-sm truncate max-w-[180px]">{school.name}</span>
                                    <span className="font-bold text-green-700">{school.rate}%</span>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="border-t border-slate-100 pt-6">
                        <h3 className="font-bold text-slate-800 mb-4 flex items-center gap-2">
                            <TrendingDown className="w-5 h-5 text-red-500" />
                            Perlu Perhatian
                        </h3>
                        <div className="space-y-3">
                            {data.rankings.bottom.map((school, idx) => (
                                <div key={idx} className="flex justify-between items-center p-3 bg-red-50 rounded-lg">
                                    <span className="font-medium text-slate-700 text-sm truncate max-w-[180px]">{school.name}</span>
                                    <span className="font-bold text-red-700">{school.rate}%</span>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
};
