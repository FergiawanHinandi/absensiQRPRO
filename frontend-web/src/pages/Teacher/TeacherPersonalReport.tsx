import React, { useState, useEffect } from 'react';
import { BarChart3, Calendar, Clock, Download, FileText, Loader2 } from 'lucide-react';
import { apiClient } from '../../lib/api';
import { useExportJob } from '../../hooks/useExportJob';
import showToast from '../../utils/toast';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { SkeletonTable } from '../../components/ui/LoadingStates';
import { EmptyState } from '../../components/ui/EmptyStates';

interface AttendanceSummary {
    total_hadir: number;
    total_terlambat: number;
    total_sakit: number;
    total_izin: number;
    total_alpha: number;
    persentase_kehadiran: number;
}

interface AttendanceHistory {
    id: number;
    attendance_date: string;
    check_in_time: string | null;
    check_out_time: string | null;
    status: string;
    notes: string | null;
}

const TeacherPersonalReport: React.FC = () => {
    const [summary, setSummary] = useState<AttendanceSummary | null>(null);
    const [history, setHistory] = useState<AttendanceHistory[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    const [selectedMonth, setSelectedMonth] = useState(format(new Date(), 'yyyy-MM'));

    const { exportJob, isPolling, startExport, downloadExport, resetExport } = useExportJob({
        onComplete: () => {},
        onError: (msg) => console.error('Export failed:', msg),
    });

    useEffect(() => {
        loadData();
    }, [selectedMonth]);

    const loadData = async () => {
        setIsLoading(true);
        try {
            // Adjust to your actual backend endpoint format
            const [summaryRes, historyRes] = await Promise.all([
                apiClient.get('/teacher/attendance/summary', { params: { month: selectedMonth } }),
                apiClient.get('/teacher/attendance/history', { params: { month: selectedMonth } })
            ]);

            setSummary(summaryRes.data?.data || summaryRes.data || null);
            setHistory(historyRes.data?.data || historyRes.data || []);
        } catch (error) {
            console.error("Gagal memuat laporan", error);
            showToast.error("Gagal memuat laporan absensi Anda");
        } finally {
            setIsLoading(false);
        }
    };

    const handleExport = async (fmt: 'pdf' | 'excel') => {
        const [year, month] = selectedMonth.split('-');
        const lastDay = new Date(parseInt(year), parseInt(month), 0).getDate();

        await startExport({
            start_date: `${year}-${month}-01`,
            end_date: `${year}-${month}-${lastDay}`,
            format: fmt,
            report_type: 'teacher_attendance',
        });
    };

    return (
        <div className="space-y-6">
            <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
                        <BarChart3 className="w-6 h-6 text-blue-600" />
                        Laporan Absensi Pribadi
                    </h1>
                    <p className="text-sm text-gray-500 mt-1">Pantau rekap kehadiran dan riwayat absensi Anda</p>
                </div>
                <div className="flex items-center gap-3 w-full sm:w-auto">
                    <input
                        type="month"
                        value={selectedMonth}
                        onChange={(e) => setSelectedMonth(e.target.value)}
                        className="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 bg-white"
                    />
                    <button
                        onClick={exportJob?.status === 'completed' ? downloadExport : () => handleExport('pdf')}
                        disabled={isPolling}
                        className={`p-2 border rounded-lg flex items-center gap-2 transition-all ${
                            exportJob?.status === 'completed'
                                ? 'bg-green-50 border-green-300 text-green-700 hover:bg-green-100'
                                : isPolling
                                    ? 'bg-gray-50 border-gray-200 text-gray-400 cursor-not-allowed'
                                    : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50'
                        }`}
                        title={exportJob?.status === 'completed' ? 'Unduh PDF' : 'Export PDF'}
                    >
                        {isPolling ? (
                            <Loader2 className="w-4 h-4 animate-spin" />
                        ) : exportJob?.status === 'completed' ? (
                            <Download className="w-4 h-4" />
                        ) : (
                            <Download className="w-4 h-4" />
                        )}
                        <span className="hidden sm:inline">{isPolling ? '...' : exportJob?.status === 'completed' ? 'Unduh' : 'PDF'}</span>
                    </button>
                    <button
                        onClick={exportJob?.status === 'completed' ? downloadExport : () => handleExport('excel')}
                        disabled={isPolling}
                        className={`p-2 border rounded-lg flex items-center gap-2 transition-all ${
                            exportJob?.status === 'completed'
                                ? 'bg-green-50 border-green-300 text-green-700 hover:bg-green-100'
                                : isPolling
                                    ? 'bg-gray-50 border-gray-200 text-gray-400 cursor-not-allowed'
                                    : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50'
                        }`}
                        title={exportJob?.status === 'completed' ? 'Unduh Excel' : 'Export Excel'}
                    >
                        {isPolling ? (
                            <Loader2 className="w-4 h-4 animate-spin" />
                        ) : exportJob?.status === 'completed' ? (
                            <Download className="w-4 h-4" />
                        ) : (
                            <FileText className="w-4 h-4" />
                        )}
                        <span className="hidden sm:inline">{isPolling ? '...' : exportJob?.status === 'completed' ? 'Unduh' : 'Excel'}</span>
                    </button>
                    {/* Reset button when completed */}
                    {exportJob?.status === 'completed' && (
                        <button
                            onClick={resetExport}
                            className="p-2 border border-gray-300 bg-white rounded-lg text-gray-700 hover:bg-gray-50 flex items-center gap-2"
                            title="Export Baru"
                        >
                            <Download className="w-4 h-4" />
                            <span className="hidden sm:inline">Baru</span>
                        </button>
                    )}
                </div>
            </div>

            {isLoading ? (
                <SkeletonTable rows={6} cols={5} />
            ) : (
                <>
                    {/* Stats Grid */}
                    <div className="grid grid-cols-2 md:grid-cols-6 gap-4">
                        <div className="bg-white p-4 rounded-xl border border-gray-100 shadow-sm col-span-2 md:col-span-1 border-l-4 border-l-blue-500">
                            <div className="text-sm text-gray-500 font-medium">Kehadiran</div>
                            <div className="text-2xl font-bold text-gray-900 mt-1">{summary?.persentase_kehadiran || 0}%</div>
                        </div>
                        <div className="bg-white p-4 rounded-xl border border-gray-100 shadow-sm border-l-4 border-l-green-500">
                            <div className="text-sm text-gray-500 font-medium">Hadir</div>
                            <div className="text-2xl font-bold text-gray-900 mt-1">{summary?.total_hadir || 0}</div>
                        </div>
                        <div className="bg-white p-4 rounded-xl border border-gray-100 shadow-sm border-l-4 border-l-orange-500">
                            <div className="text-sm text-gray-500 font-medium">Terlambat</div>
                            <div className="text-2xl font-bold text-gray-900 mt-1">{summary?.total_terlambat || 0}</div>
                        </div>
                        <div className="bg-white p-4 rounded-xl border border-gray-100 shadow-sm border-l-4 border-l-teal-500">
                            <div className="text-sm text-gray-500 font-medium">Sakit</div>
                            <div className="text-2xl font-bold text-gray-900 mt-1">{summary?.total_sakit || 0}</div>
                        </div>
                        <div className="bg-white p-4 rounded-xl border border-gray-100 shadow-sm border-l-4 border-l-purple-500">
                            <div className="text-sm text-gray-500 font-medium">Izin</div>
                            <div className="text-2xl font-bold text-gray-900 mt-1">{summary?.total_izin || 0}</div>
                        </div>
                        <div className="bg-white p-4 rounded-xl border border-gray-100 shadow-sm border-l-4 border-l-red-500">
                            <div className="text-sm text-gray-500 font-medium">Alpha</div>
                            <div className="text-2xl font-bold text-gray-900 mt-1">{summary?.total_alpha || 0}</div>
                        </div>
                    </div>

                    {/* History Table */}
                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div className="px-6 py-4 border-b border-gray-100">
                            <h2 className="text-lg font-semibold text-gray-900 flex items-center gap-2">
                                <Calendar className="w-5 h-5 text-gray-400" />
                                Riwayat Harian
                            </h2>
                        </div>

                        {history.length === 0 ? (
                            <EmptyState
                                preset="no-attendance"
                                title="Tidak Ada Data Absensi"
                                description="Belum ada catatan absensi pada bulan ini."
                                size="sm"
                            />
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm text-left">
                                    <thead className="bg-gray-50 text-gray-600 font-medium border-b border-gray-200">
                                        <tr>
                                            <th className="px-6 py-4">Tanggal</th>
                                            <th className="px-6 py-4">Check In</th>
                                            <th className="px-6 py-4">Check Out</th>
                                            <th className="px-6 py-4">Status</th>
                                            <th className="px-6 py-4">Catatan</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-100">
                                        {history.map((record) => (
                                            <tr key={record.id} className="hover:bg-gray-50">
                                                <td className="px-6 py-3 font-medium text-gray-900">
                                                    {format(new Date(record.attendance_date), 'dd MMM yyyy', { locale: id })}
                                                </td>
                                                <td className="px-6 py-3 text-gray-600">
                                                    {record.check_in_time ? (
                                                        <div className="flex items-center gap-1">
                                                            <Clock className="w-3 h-3 text-gray-400" />
                                                            {record.check_in_time.substring(0, 5)}
                                                        </div>
                                                    ) : '-'}
                                                </td>
                                                <td className="px-6 py-3 text-gray-600">
                                                    {record.check_out_time ? (
                                                        <div className="flex items-center gap-1">
                                                            <Clock className="w-3 h-3 text-gray-400" />
                                                            {record.check_out_time.substring(0, 5)}
                                                        </div>
                                                    ) : '-'}
                                                </td>
                                                <td className="px-6 py-3">
                                                    {record.status === 'present' ? (
                                                        <span className="px-2.5 py-1 bg-green-100 text-green-700 rounded-full text-xs font-medium">Hadir</span>
                                                    ) : record.status === 'late' ? (
                                                        <span className="px-2.5 py-1 bg-orange-100 text-orange-700 rounded-full text-xs font-medium">Terlambat</span>
                                                    ) : record.status === 'sick' ? (
                                                        <span className="px-2.5 py-1 bg-teal-100 text-teal-700 rounded-full text-xs font-medium">Sakit</span>
                                                    ) : record.status === 'permit' ? (
                                                        <span className="px-2.5 py-1 bg-purple-100 text-purple-700 rounded-full text-xs font-medium">Izin</span>
                                                    ) : (
                                                        <span className="px-2.5 py-1 bg-red-100 text-red-700 rounded-full text-xs font-medium">Alpha</span>
                                                    )}
                                                </td>
                                                <td className="px-6 py-3 text-gray-500 italic max-w-xs truncate">
                                                    {record.notes || '-'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                </>
            )}
        </div>
    );
};

export default TeacherPersonalReport;
