import React, { useState, useCallback } from 'react';
import { FileText, Download, Calendar, FileSpreadsheet, Loader2, CheckCircle, AlertCircle, RefreshCw } from 'lucide-react';
import Loading from '../../components/common/Loading';
import ErrorMessage from '../../components/common/ErrorMessage';
import { useExportJob } from '../../hooks/useExportJob';
import { useDailyReport } from '../../modules/admin/hooks';
import { useMonthlyReport, useAdminClasses } from '../../modules/admin/hooks/useAdminService';
import showToast from '../../utils/toast';

const AdminReports: React.FC = () => {
    const today = new Date().toISOString().split('T')[0];
    const currentMonth = new Date().toISOString().slice(0, 7);

    const [selectedDate, setSelectedDate] = useState(today);
    const [selectedMonth, setSelectedMonth] = useState(currentMonth);

    const [selectedClassId, setSelectedClassId] = useState<string>('');
    const [exportType, setExportType] = useState<'daily' | 'monthly'>('daily');

    const { exportJob, isPolling, startExport, downloadExport, resetExport } = useExportJob({
        onComplete: () => {},
        onError: (msg) => console.error('Export failed:', msg),
    });

    const { data: dailyData, isLoading: dailyLoading, error: dailyError } = useDailyReport(selectedDate);
    const { data: monthlyData, isLoading: monthlyLoading } = useMonthlyReport(
        selectedMonth.split('-')[1],
        selectedMonth.split('-')[0]
    );
    const { data: classData } = useAdminClasses();

    const getExportParams = useCallback(() => {
        const params: any = {
            format: 'pdf' as const,
        };

        if (exportType === 'daily') {
            params.start_date = selectedDate;
            params.end_date = selectedDate;
        } else {
            const [year, month] = selectedMonth.split('-');
            const lastDay = new Date(parseInt(year), parseInt(month), 0).getDate();
            params.start_date = `${year}-${month}-01`;
            params.end_date = `${year}-${month}-${lastDay}`;
        }

        if (selectedClassId) {
            params.class_id = parseInt(selectedClassId);
        }

        return params;
    }, [exportType, selectedDate, selectedMonth, selectedClassId]);

    const handleExportPDF = async () => {
        const params = getExportParams();
        params.format = 'pdf';
        await startExport(params);
    };

    const handleExportExcel = async () => {
        const params = getExportParams();
        params.format = 'excel';
        await startExport(params);
    };

    if (dailyLoading && !dailyData) {
        return <Loading text="Memuat laporan..." />;
    }

    if (dailyError) {
        return (
            <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
                <ErrorMessage message="Gagal memuat laporan" onRetry={() => window.location.reload()} />
            </div>
        );
    }

    return (
        <div className="space-y-6">
            {/* Header */}
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
                        <FileText className="w-6 h-6 text-blue-600" />
                        Laporan & Export
                    </h1>
                    <p className="text-sm text-gray-500 mt-1">
                        Lihat ringkasan dan unduh laporan absensi
                    </p>
                </div>
            </div>

            {/* Export Section */}
            <div className="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
                <h2 className="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
                    <Download className="w-5 h-5 text-blue-600" />
                    Export Laporan
                </h2>

                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                    {/* Report Type */}
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-2">
                            Jenis Laporan
                        </label>
                        <select
                            value={exportType}
                            onChange={(e) => setExportType(e.target.value as 'daily' | 'monthly')}
                            className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        >
                            <option value="daily">Harian</option>
                            <option value="monthly">Bulanan</option>
                        </select>
                    </div>

                    {/* Date/Month Selector */}
                    {exportType === 'daily' ? (
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-2">
                                Tanggal
                            </label>
                            <input
                                type="date"
                                value={selectedDate}
                                onChange={(e) => setSelectedDate(e.target.value)}
                                className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                            />
                        </div>
                    ) : (
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-2">
                                Bulan
                            </label>
                            <input
                                type="month"
                                value={selectedMonth}
                                onChange={(e) => setSelectedMonth(e.target.value)}
                                className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                            />
                        </div>
                    )}

                    {/* Class Filter */}
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-2">
                            Kelas (Opsional)
                        </label>
                        <select
                            value={selectedClassId}
                            onChange={(e) => setSelectedClassId(e.target.value)}
                            className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        >
                            <option value="">Semua Kelas</option>
                            {classData?.classes?.map((cls: any) => (
                                <option key={cls.id} value={cls.id}>
                                    {cls.name}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>

                {/* Export Buttons */}
                <div className="flex flex-wrap gap-3">
                    <button
                        onClick={exportJob?.status === 'completed' ? downloadExport : handleExportPDF}
                        disabled={isPolling}
                        className={`px-6 py-3 rounded-lg font-medium flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed transition-all ${
                            exportJob?.status === 'completed'
                                ? 'bg-green-600 text-white hover:bg-green-700'
                                : 'bg-red-600 text-white hover:bg-red-700'
                        }`}
                    >
                        {isPolling ? (
                            <>
                                <Loader2 className="w-5 h-5 animate-spin" />
                                {exportJob?.statusLabel || 'Memproses...'}
                            </>
                        ) : exportJob?.status === 'completed' ? (
                            <>
                                <Download className="w-5 h-5" />
                                Unduh PDF
                            </>
                        ) : (
                            <>
                                <FileText className="w-5 h-5" />
                                Export PDF
                            </>
                        )}
                    </button>
                    <button
                        onClick={exportJob?.status === 'completed' ? downloadExport : handleExportExcel}
                        disabled={isPolling}
                        className={`px-6 py-3 rounded-lg font-medium flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed transition-all ${
                            exportJob?.status === 'completed'
                                ? 'bg-green-600 text-white hover:bg-green-700'
                                : 'bg-green-600 text-white hover:bg-green-700'
                        }`}
                    >
                        {isPolling ? (
                            <>
                                <Loader2 className="w-5 h-5 animate-spin" />
                                {exportJob?.statusLabel || 'Memproses...'}
                            </>
                        ) : exportJob?.status === 'completed' ? (
                            <>
                                <Download className="w-5 h-5" />
                                Unduh Excel
                            </>
                        ) : (
                            <>
                                <FileSpreadsheet className="w-5 h-5" />
                                Export Excel
                            </>
                        )}
                    </button>

                    {/* Reset button when completed */}
                    {exportJob?.status === 'completed' && (
                        <button
                            onClick={resetExport}
                            className="px-4 py-3 border border-slate-200 text-slate-600 rounded-lg hover:bg-slate-50 font-medium flex items-center gap-2"
                        >
                            <RefreshCw className="w-4 h-4" />
                            Export Baru
                        </button>
                    )}

                    {/* Failed state */}
                    {exportJob?.status === 'failed' && (
                        <div className="flex items-center gap-3 w-full">
                            <div className="flex items-center gap-2 text-red-600 text-sm">
                                <AlertCircle className="w-4 h-4" />
                                {exportJob.errorMessage}
                            </div>
                            <button
                                onClick={resetExport}
                                className="px-3 py-1.5 bg-red-50 text-red-600 rounded-lg hover:bg-red-100 text-sm font-medium"
                            >
                                Coba Lagi
                            </button>
                        </div>
                    )}
                </div>

                {/* Progress bar when processing */}
                {isPolling && exportJob && (
                    <div className="mt-4">
                        <div className="flex items-center justify-between text-sm mb-1">
                            <span className="text-slate-600">{exportJob.statusLabel}</span>
                            <span className="text-slate-500 font-mono">{exportJob.progress}%</span>
                        </div>
                        <div className="w-full bg-slate-100 rounded-full h-2 overflow-hidden">
                            <div
                                className="bg-blue-600 h-full rounded-full transition-all duration-500 ease-out"
                                style={{ width: `${exportJob.progress}%` }}
                            />
                        </div>
                    </div>
                )}

                {/* Success confirmation */}
                {exportJob?.status === 'completed' && (
                    <div className="mt-4 p-3 bg-green-50 border border-green-200 rounded-lg flex items-center gap-2 text-green-700 text-sm">
                        <CheckCircle className="w-4 h-4 flex-shrink-0" />
                        <span>
                            Laporan siap diunduh! {exportJob.fileSize && `(${exportJob.fileSize})`}
                        </span>
                    </div>
                )}
            </div>

            {/* Daily Report Preview */}
            <div className="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
                <h2 className="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
                    <Calendar className="w-5 h-5 text-blue-600" />
                    Ringkasan Harian - {selectedDate}
                </h2>

                <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
                        <p className="text-sm text-blue-600 font-medium mb-1">Total Siswa</p>
                        <p className="text-2xl font-bold text-blue-900">{dailyData?.total_students || 0}</p>
                    </div>
                    <div className="bg-green-50 border border-green-200 rounded-lg p-4">
                        <p className="text-sm text-green-600 font-medium mb-1">Hadir</p>
                        <p className="text-2xl font-bold text-green-900">{dailyData?.present || 0}</p>
                    </div>
                    <div className="bg-amber-50 border border-amber-200 rounded-lg p-4">
                        <p className="text-sm text-amber-600 font-medium mb-1">Terlambat</p>
                        <p className="text-2xl font-bold text-amber-900">{dailyData?.late || 0}</p>
                    </div>
                    <div className="bg-red-50 border border-red-200 rounded-lg p-4">
                        <p className="text-sm text-red-600 font-medium mb-1">Alpha</p>
                        <p className="text-2xl font-bold text-red-900">{dailyData?.alpha || 0}</p>
                    </div>
                </div>

                <div className="mt-4 bg-slate-50 border border-slate-200 rounded-lg p-4">
                    <p className="text-sm text-slate-600 font-medium">Tingkat Kehadiran</p>
                    <div className="flex items-center gap-3 mt-2">
                        <div className="flex-1 bg-slate-200 rounded-full h-3 overflow-hidden">
                            <div
                                className="bg-green-500 h-full rounded-full transition-all"
                                style={{ width: `${dailyData?.attendance_rate || 0}%` }}
                            />
                        </div>
                        <span className="text-lg font-bold text-slate-900">
                            {dailyData?.attendance_rate || 0}%
                        </span>
                    </div>
                </div>
            </div>

            {/* Monthly Report Preview */}
            {monthlyLoading ? (
                <div className="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
                    <Loading text="Memuat ringkasan bulanan..." />
                </div>
            ) : monthlyData && (
                <div className="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
                    <h2 className="text-lg font-semibold text-gray-900 mb-4">
                        Ringkasan Bulanan - {selectedMonth}
                    </h2>
                    <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
                            <p className="text-sm text-blue-600 font-medium mb-1">Rata-rata Kehadiran</p>
                            <p className="text-2xl font-bold text-blue-900">{monthlyData?.average_attendance_rate || 0}%</p>
                        </div>
                        <div className="bg-green-50 border border-green-200 rounded-lg p-4">
                            <p className="text-sm text-green-600 font-medium mb-1">Total Hadir</p>
                            <p className="text-2xl font-bold text-green-900">{monthlyData?.total_present || 0}</p>
                        </div>
                        <div className="bg-amber-50 border border-amber-200 rounded-lg p-4">
                            <p className="text-sm text-amber-600 font-medium mb-1">Total Terlambat</p>
                            <p className="text-2xl font-bold text-amber-900">{monthlyData?.total_late || 0}</p>
                        </div>
                        <div className="bg-red-50 border border-red-200 rounded-lg p-4">
                            <p className="text-sm text-red-600 font-medium mb-1">Total Alpha</p>
                            <p className="text-2xl font-bold text-red-900">{monthlyData?.total_alpha || 0}</p>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default AdminReports;
