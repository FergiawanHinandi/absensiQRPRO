import React from 'react';
import { Users, AlertTriangle, Download, Phone, Mail } from 'lucide-react';
import { useTeacherAbsence } from '../../modules/admin/hooks';
import Loading from '../../components/common/Loading';
import ErrorMessage from '../../components/common/ErrorMessage';

const AdminTeacherAbsent: React.FC = () => {
    const { data, isLoading, error, refetch } = useTeacherAbsence();

    if (isLoading) return <Loading text="Memuat data guru tidak hadir..." />;
    if (error) return <ErrorMessage message="Gagal memuat data" onRetry={refetch} />;

    return (
        <div className="p-6 space-y-6">
            {/* Header */}
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Guru Tidak Hadir</h1>
                    <p className="text-sm text-gray-600 mt-1">
                        Monitoring guru yang terindikasi tidak hadir - {data?.date}
                    </p>
                </div>
                <button className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                    <Download className="w-4 h-4" />
                    <span>Export Laporan</span>
                </button>
            </div>

            {/* Summary Cards */}
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div className="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <div className="flex items-center gap-3 mb-2">
                        <div className="p-3 bg-blue-50 rounded-lg">
                            <Users className="w-6 h-6 text-blue-600" />
                        </div>
                        <div>
                            <p className="text-sm text-gray-600">Total Guru Dijadwalkan</p>
                            <p className="text-2xl font-bold text-gray-900">{data?.total_teachers_scheduled || 0}</p>
                        </div>
                    </div>
                </div>

                <div className="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <div className="flex items-center gap-3 mb-2">
                        <div className="p-3 bg-red-50 rounded-lg">
                            <AlertTriangle className="w-6 h-6 text-red-600" />
                        </div>
                        <div>
                            <p className="text-sm text-gray-600">Terindikasi Tidak Hadir</p>
                            <p className="text-2xl font-bold text-red-600">{data?.total_teachers_absent || 0}</p>
                        </div>
                    </div>
                </div>

                <div className="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                    <div className="flex items-center gap-3 mb-2">
                        <div className="p-3 bg-green-50 rounded-lg">
                            <Users className="w-6 h-6 text-green-600" />
                        </div>
                        <div>
                            <p className="text-sm text-gray-600">Persentase Kehadiran</p>
                            <p className="text-2xl font-bold text-green-600">
                                {data?.total_teachers_scheduled
                                    ? Math.round(
                                        ((data.total_teachers_scheduled - (data.total_teachers_absent || 0)) /
                                            data.total_teachers_scheduled) *
                                        100
                                    )
                                    : 0}
                                %
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            {/* Teachers List */}
            <div className="bg-white rounded-xl shadow-sm border border-gray-100">
                <div className="p-6 border-b border-gray-200">
                    <h2 className="text-lg font-bold text-gray-900">Daftar Guru Tidak Hadir</h2>
                    <p className="text-sm text-gray-600 mt-1">
                        Guru yang belum generate QR untuk jadwal mengajar hari ini
                    </p>
                </div>

                <div className="divide-y divide-gray-200">
                    {data?.teachers && data.teachers.length > 0 ? (
                        data.teachers.map((teacher) => (
                            <div
                                key={teacher.teacher_id}
                                className="p-6 hover:bg-gray-50 transition-colors"
                            >
                                <div className="flex items-start justify-between">
                                    <div className="flex items-start gap-4 flex-1">
                                        {/* Avatar */}
                                        <div className="w-12 h-12 rounded-full bg-gradient-to-br from-red-500 to-orange-600 flex items-center justify-center text-white font-bold text-lg flex-shrink-0">
                                            {teacher.teacher_name.substring(0, 2).toUpperCase()}
                                        </div>

                                        {/* Info */}
                                        <div className="flex-1">
                                            <h3 className="font-semibold text-gray-900 text-lg mb-1">
                                                {teacher.teacher_name}
                                            </h3>

                                            {/* Stats */}
                                            <div className="flex flex-wrap gap-4 mb-3">
                                                <div className="flex items-center gap-2 text-sm">
                                                    <span className="text-gray-600">Total Jadwal:</span>
                                                    <span className="font-semibold text-gray-900">
                                                        {teacher.total_schedules}
                                                    </span>
                                                </div>
                                                <div className="flex items-center gap-2 text-sm">
                                                    <span className="text-gray-600">QR Dibuat:</span>
                                                    <span className="font-semibold text-green-600">
                                                        {teacher.qr_generated}
                                                    </span>
                                                </div>
                                                <div className="flex items-center gap-2 text-sm">
                                                    <span className="text-gray-600">Belum Dibuat:</span>
                                                    <span className="font-semibold text-red-600">
                                                        {teacher.missing_qr}
                                                    </span>
                                                </div>
                                            </div>

                                            {/* Progress Bar */}
                                            <div className="mb-3">
                                                <div className="flex items-center justify-between text-xs text-gray-600 mb-1">
                                                    <span>Progress QR Generation</span>
                                                    <span>
                                                        {Math.round((teacher.qr_generated / teacher.total_schedules) * 100)}%
                                                    </span>
                                                </div>
                                                <div className="w-full bg-gray-200 rounded-full h-2">
                                                    <div
                                                        className="bg-gradient-to-r from-red-500 to-orange-500 h-2 rounded-full transition-all"
                                                        style={{
                                                            width: `${(teacher.qr_generated / teacher.total_schedules) * 100}%`,
                                                        }}
                                                    ></div>
                                                </div>
                                            </div>

                                            {/* Alert */}
                                            <div className="flex items-start gap-2 p-3 bg-red-50 border border-red-200 rounded-lg">
                                                <AlertTriangle className="w-4 h-4 text-red-600 mt-0.5 flex-shrink-0" />
                                                <p className="text-sm text-red-800">
                                                    Guru ini memiliki {teacher.missing_qr} jadwal yang belum di-generate QR.
                                                    Segera hubungi untuk konfirmasi kehadiran.
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Actions */}
                                    <div className="flex flex-col gap-2 ml-4">
                                        <button className="flex items-center gap-2 px-4 py-2 bg-blue-50 text-blue-700 rounded-lg hover:bg-blue-100 transition-colors text-sm font-medium">
                                            <Phone className="w-4 h-4" />
                                            <span>Hubungi</span>
                                        </button>
                                        <button className="flex items-center gap-2 px-4 py-2 bg-slate-50 text-slate-700 rounded-lg hover:bg-slate-100 transition-colors text-sm font-medium">
                                            <Mail className="w-4 h-4" />
                                            <span>Email</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        ))
                    ) : (
                        <div className="p-12 text-center">
                            <div className="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                <Users className="w-8 h-8 text-green-600" />
                            </div>
                            <h3 className="text-lg font-semibold text-gray-900 mb-2">
                                Semua Guru Hadir
                            </h3>
                            <p className="text-gray-600">
                                Tidak ada guru yang terindikasi tidak hadir hari ini
                            </p>
                        </div>
                    )}
                </div>
            </div>

            {/* Info Box */}
            <div className="bg-blue-50 border border-blue-200 rounded-xl p-6">
                <div className="flex gap-3">
                    <AlertTriangle className="w-5 h-5 text-blue-600 flex-shrink-0 mt-0.5" />
                    <div>
                        <h3 className="font-semibold text-blue-900 mb-2">Catatan Penting</h3>
                        <ul className="text-sm text-blue-800 space-y-1">
                            <li>• Guru dianggap tidak hadir jika belum generate QR untuk jadwal mengajar</li>
                            <li>• Data ini bersifat indikasi dan perlu konfirmasi lebih lanjut</li>
                            <li>• Segera hubungi guru yang terindikasi tidak hadir untuk klarifikasi</li>
                            <li>• Sistem akan otomatis update ketika guru generate QR</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default AdminTeacherAbsent;
