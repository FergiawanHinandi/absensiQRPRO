import React, { useState } from 'react';
import { FileText, Download, Calendar, FileSpreadsheet } from 'lucide-react';
import Loading from '../../components/common/Loading';
import ErrorMessage from '../../components/common/ErrorMessage';
import { useDailyReport } from '../../modules/admin/hooks';
import { useMonthlyReport, useAdminClasses } from '../../modules/admin/hooks/useAdminService';
import * as adminService from '../../services/adminService';
import showToast from '../../utils/toast';

const AdminReports: React.FC = () => {
    const today = new Date().toISOString().split('T')[0];
    const currentMonth = new Date().toISOString().slice(0, 7);

    const [selectedDate, setSelectedDate] = useState(today);
    const [selectedMonth, setSelectedMonth] = useState(currentMonth);

    const [selectedClassId, setSelectedClassId] = useState<string>('');
    const [exportType, setExportType] = useState<'daily' | 'monthly'>('daily');
    const [isExporting, setIsExporting] = useState(false);

    const { data: dailyData, isLoading: dailyLoading, error: dailyError } = useDailyReport(selectedDate);
    const { data: monthlyData, isLoading: monthlyLoading } = useMonthlyReport(
        selectedMonth.split('-')[1],
        selectedMonth.split('-')[0]
    );
    const { data: classData } = useAdminClasses();

    const handleExportPDF = async () => {
        setIsExporting(true);
        try {
            const params: any = {
                report_type: exportType,
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

            const blob = await adminService.exportReportPDF(params);

            // Download file
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `laporan-${exportType}-${exportType === 'daily' ? selectedDate : selectedMonth}.pdf`;
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            document.body.removeChild(a);

            showToast.success('Laporan PDF berhasil diunduh');
        } catch (error: any) {
            showToast.error(error?.response?.data?.message || 'Gagal mengunduh laporan PDF');
        } finally {
            setIsExporting(false);
        }
    };

    const handleExportExcel = async () => {
        setIsExporting(true);
        try {
            const params: any = {
                report_type: exportType,
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

            const blob = await adminService.exportReportExcel(params);

            // Download file
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `laporan-${exportType}-${exportType === 'daily' ? selectedDate : selectedMonth}.xlsx`;
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            document.body.removeChild(a);

            showToast.success('Laporan Excel berhasil diunduh');
        } catch (error: any) {
            showToast.error(error?.response?.data?.message || 'Gagal mengunduh laporan Excel');
        } finally {
            setIsExporting(false);
        }
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
                        onClick={handleExportPDF}
                        disabled={isExporting}
                        className="px-6 py-3 bg-red-600 text-white rounded-lg hover:bg-red-700 font-medium flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        <FileText className="w-5 h-5" />
                        {isExporting ? 'Memproses...' : 'Export PDF'}
                    </button>
                    <button
                        onClick={handleExportExcel}
                        disabled={isExporting}
                        className="px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        <FileSpreadsheet className="w-5 h-5" />
                        {isExporting ? 'Memproses...' : 'Export Excel'}
                    </button>
                </div>
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
