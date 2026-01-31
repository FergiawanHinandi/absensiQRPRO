import React from 'react';
import { BarChart, Download, Filter } from 'lucide-react';
import { useClassAttendanceSummary } from '../../modules/admin/hooks';
import Loading from '../../components/common/Loading';
import ErrorMessage from '../../components/common/ErrorMessage';

const AdminClassAttendance: React.FC = () => {
    const { data, isLoading, error, refetch } = useClassAttendanceSummary();

    if (isLoading) return <Loading text="Memuat data absensi per kelas..." />;
    if (error) return <ErrorMessage message="Gagal memuat data" onRetry={refetch} />;

    const calculatePercentage = (value: number, total: number) => {
        return total > 0 ? Math.round((value / total) * 100) : 0;
    };

    return (
        <div className="p-6 space-y-6">
            {/* Header */}
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Absensi per Kelas</h1>
                    <p className="text-sm text-gray-600 mt-1">
                        Data absensi hari ini: {data?.date}
                    </p>
                </div>
                <div className="flex gap-3">
                    <button className="flex items-center gap-2 px-4 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                        <Filter className="w-4 h-4" />
                        <span>Filter</span>
                    </button>
                    <button className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                        <Download className="w-4 h-4" />
                        <span>Export</span>
                    </button>
                </div>
            </div>

            {/* Summary Cards */}
            <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                {[
                    { label: 'Total Kelas', value: data?.classes?.length || 0, color: 'blue' },
                    {
                        label: 'Total Siswa',
                        value: data?.classes?.reduce((sum, c) => sum + c.total_students, 0) || 0,
                        color: 'purple'
                    },
                    {
                        label: 'Total Hadir',
                        value: data?.classes?.reduce((sum, c) => sum + c.present, 0) || 0,
                        color: 'green'
                    },
                    {
                        label: 'Total Alfa',
                        value: data?.classes?.reduce((sum, c) => sum + c.alpha, 0) || 0,
                        color: 'red'
                    },
                ].map((stat, idx) => (
                    <div key={idx} className="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                        <p className="text-sm text-gray-600 mb-1">{stat.label}</p>
                        <p className={`text-3xl font-bold text-${stat.color}-600`}>{stat.value}</p>
                    </div>
                ))}
            </div>

            {/* Classes Table */}
            <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full">
                        <thead className="bg-gray-50 border-b border-gray-200">
                            <tr>
                                <th className="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                    Kelas
                                </th>
                                <th className="px-6 py-4 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                    Total Siswa
                                </th>
                                <th className="px-6 py-4 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                    Hadir
                                </th>
                                <th className="px-6 py-4 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                    Terlambat
                                </th>
                                <th className="px-6 py-4 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                    Sakit
                                </th>
                                <th className="px-6 py-4 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                    Izin
                                </th>
                                <th className="px-6 py-4 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                    Alfa
                                </th>
                                <th className="px-6 py-4 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                    % Hadir
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-200">
                            {data?.classes && data.classes.length > 0 ? (
                                data.classes.map((classData) => {
                                    const attendanceRate = calculatePercentage(
                                        classData.present,
                                        classData.total_students
                                    );
                                    return (
                                        <tr key={classData.class_id} className="hover:bg-gray-50 transition-colors">
                                            <td className="px-6 py-4">
                                                <div className="flex items-center gap-3">
                                                    <div className="w-10 h-10 rounded-lg bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white font-bold">
                                                        {classData.class_name.substring(0, 2)}
                                                    </div>
                                                    <span className="font-semibold text-gray-900">
                                                        {classData.class_name}
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="px-6 py-4 text-center font-medium text-gray-900">
                                                {classData.total_students}
                                            </td>
                                            <td className="px-6 py-4 text-center">
                                                <span className="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold bg-green-100 text-green-800">
                                                    {classData.present}
                                                </span>
                                            </td>
                                            <td className="px-6 py-4 text-center">
                                                <span className="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold bg-yellow-100 text-yellow-800">
                                                    {classData.late}
                                                </span>
                                            </td>
                                            <td className="px-6 py-4 text-center">
                                                <span className="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold bg-blue-100 text-blue-800">
                                                    {classData.sick}
                                                </span>
                                            </td>
                                            <td className="px-6 py-4 text-center">
                                                <span className="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold bg-purple-100 text-purple-800">
                                                    {classData.permission}
                                                </span>
                                            </td>
                                            <td className="px-6 py-4 text-center">
                                                <span className="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold bg-red-100 text-red-800">
                                                    {classData.alpha}
                                                </span>
                                            </td>
                                            <td className="px-6 py-4 text-center">
                                                <div className="flex items-center justify-center gap-2">
                                                    <div className="w-20 bg-gray-200 rounded-full h-2">
                                                        <div
                                                            className={`h-2 rounded-full ${attendanceRate >= 80
                                                                    ? 'bg-green-500'
                                                                    : attendanceRate >= 60
                                                                        ? 'bg-yellow-500'
                                                                        : 'bg-red-500'
                                                                }`}
                                                            style={{ width: `${attendanceRate}%` }}
                                                        ></div>
                                                    </div>
                                                    <span className="text-sm font-semibold text-gray-700">
                                                        {attendanceRate}%
                                                    </span>
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })
                            ) : (
                                <tr>
                                    <td colSpan={8} className="px-6 py-12 text-center text-gray-500">
                                        Belum ada data absensi hari ini
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Chart Section */}
            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <div className="flex items-center gap-2 mb-4">
                    <BarChart className="w-5 h-5 text-blue-600" />
                    <h2 className="text-lg font-bold text-gray-900">Grafik Kehadiran per Kelas</h2>
                </div>
                <div className="h-64 flex items-center justify-center text-gray-500">
                    <p>Chart visualization akan ditambahkan di sini</p>
                </div>
            </div>
        </div>
    );
};

export default AdminClassAttendance;
