import React from 'react';
import { FileText } from 'lucide-react';
import { useLocation } from 'react-router-dom';
import Loading from '../../components/common/Loading';
import ErrorMessage from '../../components/common/ErrorMessage';
import { useAdminReports } from '../../modules/admin/hooks';

const titleMap: Record<string, string> = {
    '/admin/reports': 'Laporan & Rekap',
    '/admin/reports/class': 'Absensi per Kelas',
    '/admin/reports/teacher': 'Absensi per Guru',
    '/admin/reports/monthly': 'Rekap Bulanan',
    '/admin/reports/semester': 'Rekap Semester',
    '/admin/reports/export': 'Export PDF / Excel',
};

const AdminReports: React.FC = () => {
    const location = useLocation();
    const { data, isLoading, error, refetch } = useAdminReports();
    const title = titleMap[location.pathname] ?? 'Laporan & Rekap';
    const isClass = location.pathname.includes('/reports/class');
    const isTeacher = location.pathname.includes('/reports/teacher');
    const isMonthly = location.pathname.includes('/reports/monthly');
    const isSemester = location.pathname.includes('/reports/semester');
    const isExport = location.pathname.includes('/reports/export');

    const filteredReports = (data?.reports ?? []).filter((report) => {
        const type = report.report_type?.toLowerCase() ?? '';
        if (isClass) return type.includes('class') || type.includes('kelas');
        if (isTeacher) return type.includes('teacher') || type.includes('guru');
        if (isMonthly) return type.includes('month') || type.includes('bulanan');
        if (isSemester) return type.includes('semester');
        return true;
    });

    if (isLoading) {
        return <Loading text="Memuat laporan..." />;
    }

    if (error) {
        return (
            <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
                <ErrorMessage message="Gagal memuat laporan" onRetry={() => refetch()} />
            </div>
        );
    }

    if (isExport) {
        return (
            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
                            <FileText className="w-6 h-6 text-blue-600" />
                            {title}
                        </h1>
                        <p className="text-sm text-gray-500 mt-1">Unduh laporan yang tersedia.</p>
                    </div>
                </div>

                <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead>
                            <tr className="text-left text-gray-500 border-b">
                                <th className="py-3 px-6">Jenis</th>
                                <th className="py-3 px-6">Tanggal</th>
                                <th className="py-3 px-6">Periode</th>
                                <th className="py-3 px-6">File</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data?.reports?.length ? (
                                data.reports.map((report) => (
                                    <tr key={report.id} className="border-b last:border-0">
                                        <td className="py-3 px-6 text-gray-600 capitalize">{report.report_type}</td>
                                        <td className="py-3 px-6 text-gray-600">{report.report_date}</td>
                                        <td className="py-3 px-6 text-gray-600">{report.period_start} - {report.period_end}</td>
                                        <td className="py-3 px-6 text-blue-600">
                                            {report.file_url ? (
                                                <a href={report.file_url} className="underline">Unduh</a>
                                            ) : (
                                                '-'
                                            )}
                                        </td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td colSpan={4} className="py-10 text-center text-sm text-gray-500">
                                        Belum ada laporan untuk diexport.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
                        <FileText className="w-6 h-6 text-blue-600" />
                        {title}
                    </h1>
                    <p className="text-sm text-gray-500 mt-1">Daftar laporan absensi yang tersedia.</p>
                </div>
                <div className="text-sm text-gray-600">Total: {filteredReports.length}</div>
            </div>

            <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto">
                <table className="min-w-full text-sm">
                    <thead>
                        <tr className="text-left text-gray-500 border-b">
                            <th className="py-3 px-6">Jenis</th>
                            <th className="py-3 px-6">Tanggal</th>
                            <th className="py-3 px-6">Periode</th>
                            <th className="py-3 px-6">Kehadiran</th>
                            <th className="py-3 px-6">File</th>
                        </tr>
                    </thead>
                    <tbody>
                        {filteredReports.length ? (
                            filteredReports.map((report) => (
                                <tr key={report.id} className="border-b last:border-0">
                                    <td className="py-3 px-6 text-gray-600 capitalize">{report.report_type}</td>
                                    <td className="py-3 px-6 text-gray-600">{report.report_date}</td>
                                    <td className="py-3 px-6 text-gray-600">{report.period_start} - {report.period_end}</td>
                                    <td className="py-3 px-6 text-gray-600">{report.attendance_rate}%</td>
                                    <td className="py-3 px-6 text-blue-600">
                                        {report.file_url ? (
                                            <a href={report.file_url} className="underline">Unduh</a>
                                        ) : (
                                            '-'
                                        )}
                                    </td>
                                </tr>
                            ))
                        ) : (
                            <tr>
                                <td colSpan={5} className="py-10 text-center text-sm text-gray-500">
                                    Belum ada laporan yang tersedia.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
};

export default AdminReports;
