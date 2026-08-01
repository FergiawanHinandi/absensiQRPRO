import React, { useState, useEffect } from 'react';
import { BarChart3, Download, Users, CheckCircle2, XCircle, Clock } from 'lucide-react';
import { apiClient } from '../../../lib/api';
import showToast from '../../../utils/toast';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { SkeletonTable } from '../../../components/ui/LoadingStates';
import { EmptyState } from '../../../components/ui/EmptyStates';

interface StudentRecap {
    student_id: number;
    student_name: string;
    total_days: number;
    present: number;
    late: number;
    absent: number;
    sick: number;
    permit: number;
    attendance_rate: number;
}

const HomeroomClassRecap: React.FC = () => {
    const [recap, setRecap] = useState<StudentRecap[]>([]);
    const [loading, setLoading] = useState(true);
    const [period, setPeriod] = useState<'month' | 'semester'>('month');

    useEffect(() => {
        fetchRecap();
    }, [period]);

    const fetchRecap = async () => {
        try {
            setLoading(true);
            const response = await apiClient.get('/teacher/class-attendance/recap', {
                params: { period }
            });
            setRecap(response.data.data || []);
        } catch (error) {
            if (import.meta.env.DEV) {
                console.error('Failed to fetch recap:', error);
            }
            showToast.error('Gagal memuat rekap kelas');
        } finally {
            setLoading(false);
        }
    };

    const getRateColor = (rate: number) => {
        if (rate >= 90) return 'text-green-600 bg-green-50';
        if (rate >= 75) return 'text-yellow-600 bg-yellow-50';
        return 'text-red-600 bg-red-50';
    };

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between">
                <div className="flex items-center gap-3">
                    <div className="w-10 h-10 rounded-full bg-indigo-100 flex items-center justify-center">
                        <BarChart3 className="w-5 h-5 text-indigo-600" />
                    </div>
                    <div>
                        <h1 className="text-lg font-bold text-slate-900">Rekap Kelas</h1>
                        <p className="text-sm text-slate-500">Ringkasan kehadiran seluruh siswa</p>
                    </div>
                </div>
                <div className="flex items-center gap-2">
                    <select
                        value={period}
                        onChange={(e) => setPeriod(e.target.value as typeof period)}
                        className="px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                        <option value="month">Bulan Ini</option>
                        <option value="semester">Semester Ini</option>
                    </select>
                </div>
            </div>

            {loading ? (
                <SkeletonTable rows={6} cols={8} />
            ) : recap.length === 0 ? (
                <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
                    <EmptyState
                        preset="no-attendance"
                        title="Belum Ada Data Rekap"
                        description="Data rekap kehadiran akan muncul setelah absensi tercatat."
                        size="md"
                    />
                </div>
            ) : (
                <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-slate-50 border-b border-slate-200">
                                <tr>
                                    <th className="text-left px-4 py-3 font-semibold text-slate-600">No</th>
                                    <th className="text-left px-4 py-3 font-semibold text-slate-600">Nama Siswa</th>
                                    <th className="text-center px-4 py-3 font-semibold text-slate-600">Hadir</th>
                                    <th className="text-center px-4 py-3 font-semibold text-slate-600">Terlambat</th>
                                    <th className="text-center px-4 py-3 font-semibold text-slate-600">Alpha</th>
                                    <th className="text-center px-4 py-3 font-semibold text-slate-600">Sakit</th>
                                    <th className="text-center px-4 py-3 font-semibold text-slate-600">Izin</th>
                                    <th className="text-center px-4 py-3 font-semibold text-slate-600">Tingkat Kehadiran</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {recap.map((student, index) => (
                                    <tr key={student.student_id} className="hover:bg-slate-50 transition-colors">
                                        <td className="px-4 py-3 text-slate-500">{index + 1}</td>
                                        <td className="px-4 py-3 font-medium text-slate-900">{student.student_name}</td>
                                        <td className="px-4 py-3 text-center">
                                            <span className="inline-flex items-center justify-center w-6 h-6 rounded-full bg-green-100 text-green-700 text-xs font-bold">{student.present}</span>
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <span className="inline-flex items-center justify-center w-6 h-6 rounded-full bg-yellow-100 text-yellow-700 text-xs font-bold">{student.late}</span>
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <span className="inline-flex items-center justify-center w-6 h-6 rounded-full bg-red-100 text-red-700 text-xs font-bold">{student.absent}</span>
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <span className="inline-flex items-center justify-center w-6 h-6 rounded-full bg-blue-100 text-blue-700 text-xs font-bold">{student.sick}</span>
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <span className="inline-flex items-center justify-center w-6 h-6 rounded-full bg-slate-100 text-slate-700 text-xs font-bold">{student.permit}</span>
                                        </td>
                                        <td className="px-4 py-3 text-center">
                                            <span className={`inline-block px-2 py-1 rounded text-xs font-bold ${getRateColor(student.attendance_rate)}`}>
                                                {student.attendance_rate}%
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </div>
    );
};

export default HomeroomClassRecap;
