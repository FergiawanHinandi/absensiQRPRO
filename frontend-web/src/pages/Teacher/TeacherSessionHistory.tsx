import React, { useState, useEffect } from 'react';
import { History, Search, Calendar, CheckSquare, XSquare } from 'lucide-react';
import { apiClient } from '../../lib/api';
import showToast from '../../utils/toast';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { SkeletonTable } from '../../components/ui/LoadingStates';
import { EmptyState } from '../../components/ui/EmptyStates';

interface SessionRecord {
    id: number;
    subject_name: string;
    class_name: string;
    day_of_week: string;
    start_time: string;
    end_time: string;
    attendance_count: number;
    total_students: number;
    status: 'completed' | 'ongoing' | 'not_started';
}

const TeacherSessionHistory: React.FC = () => {
    const [sessions, setSessions] = useState<SessionRecord[]>([]);
    const [isLoading, setIsLoading] = useState(true);

    useEffect(() => {
        loadSessions();
    }, []);

    const loadSessions = async () => {
        setIsLoading(true);
        try {
            // Adjust endpoint if needed; reusing monitoring endpoint to list past sessions
            const response = await apiClient.get('/teacher/teaching/monitoring');
            // Mocking array format for safety if monitoring returns differently
            const data = response.data?.data || response.data || [];
            if (Array.isArray(data)) {
                setSessions(data);
            } else if (data.sessions) {
                setSessions(data.sessions);
            }
        } catch (error) {
            showToast.error("Gagal memuat riwayat sesi mengajar");
        } finally {
            setIsLoading(false);
        }
    };

    return (
        <div className="space-y-6">
            <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
                        <History className="w-6 h-6 text-blue-600" />
                        Riwayat Sesi Mengajar
                    </h1>
                    <p className="text-sm text-gray-500 mt-1">Laporan pelaksanaan sesi kelas dan kelengkapan absensi</p>
                </div>
                <div className="relative w-full sm:w-64">
                    <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 w-5 h-5" />
                    <input
                        type="text"
                        placeholder="Cari mata pelajaran atau kelas..."
                        className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                    />
                </div>
            </div>

            <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div className="px-6 py-4 border-b border-gray-100 bg-gray-50">
                    <div className="flex items-center gap-2 text-gray-700 font-medium">
                        <Calendar className="w-5 h-5" />
                        {format(new Date(), 'EEEE, dd MMMM yyyy', { locale: id })}
                    </div>
                </div>

                {isLoading ? (
                    <div className="p-4">
                        <SkeletonTable rows={5} cols={5} />
                    </div>
                ) : sessions.length === 0 ? (
                    <div className="p-4">
                        <EmptyState
                            preset="no-reports"
                            title="Belum Ada Riwayat Sesi Mengajar"
                            description="Riwayat sesi mengajar Anda akan muncul di sini setelah Anda menyelesaikan kelas."
                            size="md"
                        />
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm text-left">
                            <thead className="bg-gray-50 text-gray-600 font-medium border-b border-gray-200">
                                <tr>
                                    <th className="px-6 py-4">Mata Pelajaran</th>
                                    <th className="px-6 py-4">Kelas</th>
                                    <th className="px-6 py-4">Waktu</th>
                                    <th className="px-6 py-4 text-center">Kehadiran Direkam</th>
                                    <th className="px-6 py-4 text-center">Status Sesi</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {sessions.map((session, index) => (
                                    <tr key={session.id || index} className="hover:bg-gray-50 transition-colors">
                                        <td className="px-6 py-4 font-medium text-gray-900">{session.subject_name}</td>
                                        <td className="px-6 py-4 text-gray-600">{session.class_name}</td>
                                        <td className="px-6 py-4 text-gray-600">
                                            {session.start_time.substring(0, 5)} - {session.end_time.substring(0, 5)}
                                        </td>
                                        <td className="px-6 py-4">
                                            <div className="flex flex-col items-center">
                                                <div className="text-lg font-semibold text-gray-900">
                                                    {session.attendance_count || 0} / {session.total_students || 0}
                                                </div>
                                                <div className="w-24 bg-gray-200 rounded-full h-1.5 mt-2">
                                                    <div className="bg-blue-600 h-1.5 rounded-full" style={{ width: `${((session.attendance_count || 0) / (session.total_students || 1)) * 100}%` }}></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td className="px-6 py-4 text-center">
                                            {(session.attendance_count || 0) > 0 ? (
                                                <span className="inline-flex items-center gap-1.5 px-2.5 py-1 bg-green-100 text-green-700 rounded-full text-xs font-medium">
                                                    <CheckSquare className="w-3.5 h-3.5" /> Selesai
                                                </span>
                                            ) : (
                                                <span className="inline-flex items-center gap-1.5 px-2.5 py-1 bg-orange-100 text-orange-700 rounded-full text-xs font-medium">
                                                    <XSquare className="w-3.5 h-3.5" /> Tersisa
                                                </span>
                                            )}
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

export default TeacherSessionHistory;
