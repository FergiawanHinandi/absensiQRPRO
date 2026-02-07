import React, { useState } from 'react';
import { CreditCard, Download, Users, CheckCircle, Clock, AlertCircle } from 'lucide-react';
import Loading from '../../components/common/Loading';
import ErrorMessage from '../../components/common/ErrorMessage';
import { useStudentCardProgress, useBulkGenerateStudentCards } from '../../modules/admin/hooks/useAdminService';
import { useAdminClasses } from '../../modules/admin/hooks';
import { PieChart, Pie, Cell, ResponsiveContainer, Legend, Tooltip } from 'recharts';
import showToast from '../../utils/toast';

const AdminStudentCards: React.FC = () => {
    const { data: progress, isLoading, error, refetch } = useStudentCardProgress();
    const { data: classData } = useAdminClasses();
    const bulkGenerateMutation = useBulkGenerateStudentCards();
    const [selectedClassId, setSelectedClassId] = useState<string>('');

    const handleBulkGenerate = async () => {
        if (!selectedClassId) {
            showToast.error('Pilih kelas terlebih dahulu');
            return;
        }

        if (!window.confirm('Generate kartu untuk semua siswa di kelas ini?')) {
            return;
        }

        try {
            await bulkGenerateMutation.mutateAsync(parseInt(selectedClassId));
            setSelectedClassId('');
            refetch();
        } catch (error) {
            console.error('Bulk generation failed:', error);
        }
    };

    if (isLoading) {
        return <Loading text="Memuat data kartu siswa..." />;
    }

    if (error) {
        return (
            <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
                <ErrorMessage message="Gagal memuat data kartu siswa" onRetry={() => refetch()} />
            </div>
        );
    }

    // Prepare chart data
    const chartData = [
        { name: 'Aktif', value: progress?.active_count || 0, color: '#22c55e' },
        { name: 'Pending', value: progress?.pending_count || 0, color: '#eab308' },
        { name: 'Belum Dibuat', value: progress?.not_generated_count || 0, color: '#ef4444' },
    ];

    const totalStudents = progress?.total_students || 0;
    const completionRate = totalStudents > 0
        ? Math.round(((progress?.active_count || 0) / totalStudents) * 100)
        : 0;

    return (
        <div className="space-y-6">
            {/* Header */}
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
                        <CreditCard className="w-6 h-6 text-blue-600" />
                        Manajemen Kartu Siswa
                    </h1>
                    <p className="text-sm text-gray-500 mt-1">
                        Monitor dan kelola kartu QR siswa untuk absensi
                    </p>
                </div>
            </div>

            {/* Stats Cards */}
            <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div className="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
                    <div className="flex items-center justify-between mb-3">
                        <div className="p-2 bg-blue-50 text-blue-600 rounded-lg">
                            <Users className="w-5 h-5" />
                        </div>
                    </div>
                    <h3 className="text-2xl font-bold text-slate-900">{totalStudents}</h3>
                    <p className="text-sm text-slate-500 font-medium">Total Siswa</p>
                </div>

                <div className="bg-white p-6 rounded-xl border border-green-200 shadow-sm">
                    <div className="flex items-center justify-between mb-3">
                        <div className="p-2 bg-green-50 text-green-600 rounded-lg">
                            <CheckCircle className="w-5 h-5" />
                        </div>
                        <span className="text-xs font-semibold bg-green-100 text-green-700 px-2 py-1 rounded-full">
                            {completionRate}%
                        </span>
                    </div>
                    <h3 className="text-2xl font-bold text-slate-900">{progress?.active_count || 0}</h3>
                    <p className="text-sm text-slate-500 font-medium">Kartu Aktif</p>
                </div>

                <div className="bg-white p-6 rounded-xl border border-amber-200 shadow-sm">
                    <div className="flex items-center justify-between mb-3">
                        <div className="p-2 bg-amber-50 text-amber-600 rounded-lg">
                            <Clock className="w-5 h-5" />
                        </div>
                    </div>
                    <h3 className="text-2xl font-bold text-slate-900">{progress?.pending_count || 0}</h3>
                    <p className="text-sm text-slate-500 font-medium">Pending</p>
                </div>

                <div className="bg-white p-6 rounded-xl border border-red-200 shadow-sm">
                    <div className="flex items-center justify-between mb-3">
                        <div className="p-2 bg-red-50 text-red-600 rounded-lg">
                            <AlertCircle className="w-5 h-5" />
                        </div>
                    </div>
                    <h3 className="text-2xl font-bold text-slate-900">{progress?.not_generated_count || 0}</h3>
                    <p className="text-sm text-slate-500 font-medium">Belum Dibuat</p>
                </div>
            </div>

            {/* Chart and Bulk Generation */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Distribution Chart */}
                <div className="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
                    <h2 className="text-lg font-semibold text-gray-900 mb-4">Distribusi Status Kartu</h2>
                    <div className="h-[300px]">
                        <ResponsiveContainer width="100%" height="100%">
                            <PieChart>
                                <Pie
                                    data={chartData}
                                    cx="50%"
                                    cy="50%"
                                    innerRadius={60}
                                    outerRadius={100}
                                    paddingAngle={5}
                                    dataKey="value"
                                >
                                    {chartData.map((entry, index) => (
                                        <Cell key={`cell-${index}`} fill={entry.color} strokeWidth={0} />
                                    ))}
                                </Pie>
                                <Tooltip />
                                <Legend verticalAlign="bottom" height={36} />
                            </PieChart>
                        </ResponsiveContainer>
                    </div>
                </div>

                {/* Bulk Generation */}
                <div className="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
                    <h2 className="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
                        <Download className="w-5 h-5 text-blue-600" />
                        Generate Kartu Massal
                    </h2>
                    <p className="text-sm text-gray-600 mb-4">
                        Buat kartu QR untuk semua siswa dalam satu kelas sekaligus
                    </p>

                    <div className="space-y-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-2">
                                Pilih Kelas
                            </label>
                            <select
                                value={selectedClassId}
                                onChange={(e) => setSelectedClassId(e.target.value)}
                                className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                            >
                                <option value="">-- Pilih Kelas --</option>
                                {classData?.classes?.map((cls) => (
                                    <option key={cls.id} value={cls.id}>
                                        {cls.name} ({cls.total_students} siswa)
                                    </option>
                                ))}
                            </select>
                        </div>

                        <button
                            onClick={handleBulkGenerate}
                            disabled={!selectedClassId || bulkGenerateMutation.isPending}
                            className="w-full px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <Download className="w-5 h-5" />
                            {bulkGenerateMutation.isPending ? 'Memproses...' : 'Generate Kartu Kelas'}
                        </button>

                        <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
                            <h3 className="text-sm font-semibold text-blue-900 mb-2">ℹ️ Informasi</h3>
                            <ul className="text-xs text-blue-800 space-y-1">
                                <li>• Kartu akan dibuat untuk siswa yang belum memiliki kartu aktif</li>
                                <li>• Proses mungkin memakan waktu untuk kelas besar</li>
                                <li>• Kartu dapat diunduh dari halaman detail siswa</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            {/* Class-wise Progress Table */}
            <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                <div className="px-6 py-4 border-b border-slate-200">
                    <h2 className="text-lg font-semibold text-gray-900">Progress Per Kelas</h2>
                </div>
                <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead>
                            <tr className="text-left text-gray-500 border-b bg-slate-50">
                                <th className="py-3 px-6">Kelas</th>
                                <th className="py-3 px-6">Total Siswa</th>
                                <th className="py-3 px-6">Aktif</th>
                                <th className="py-3 px-6">Pending</th>
                                <th className="py-3 px-6">Belum Dibuat</th>
                                <th className="py-3 px-6">Progress</th>
                            </tr>
                        </thead>
                        <tbody>
                            {progress?.by_class?.length ? (
                                progress.by_class.map((cls: any) => {
                                    const classProgress = cls.total > 0
                                        ? Math.round((cls.active / cls.total) * 100)
                                        : 0;

                                    return (
                                        <tr key={cls.class_id} className="border-b last:border-0 hover:bg-slate-50">
                                            <td className="py-3 px-6 font-medium text-gray-900">{cls.class_name}</td>
                                            <td className="py-3 px-6 text-gray-600">{cls.total}</td>
                                            <td className="py-3 px-6">
                                                <span className="text-green-700 font-semibold">{cls.active}</span>
                                            </td>
                                            <td className="py-3 px-6">
                                                <span className="text-amber-700 font-semibold">{cls.pending}</span>
                                            </td>
                                            <td className="py-3 px-6">
                                                <span className="text-red-700 font-semibold">{cls.not_generated}</span>
                                            </td>
                                            <td className="py-3 px-6">
                                                <div className="flex items-center gap-2">
                                                    <div className="flex-1 bg-gray-200 rounded-full h-2 overflow-hidden">
                                                        <div
                                                            className="bg-green-500 h-full rounded-full transition-all"
                                                            style={{ width: `${classProgress}%` }}
                                                        />
                                                    </div>
                                                    <span className="text-xs font-semibold text-gray-600 w-12 text-right">
                                                        {classProgress}%
                                                    </span>
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })
                            ) : (
                                <tr>
                                    <td colSpan={6} className="py-10 text-center text-sm text-gray-500">
                                        Belum ada data progress kartu siswa
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
};

export default AdminStudentCards;
