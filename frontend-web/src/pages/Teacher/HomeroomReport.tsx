import React, { useState, useEffect } from 'react';
import { Users, School, PieChart, Activity } from 'lucide-react';
import { apiClient } from '../../lib/api';
import showToast from '../../utils/toast';
import { Skeleton, SkeletonCard } from '../../components/ui/LoadingStates';

interface HomeroomSummary {
    class_info: { id: number; name: string };
    summary: {
        total_students: number;
        present_today: number;
        late_today: number;
        absent_today: number;
        not_checked_in: number;
        attendance_rate: number;
    };
    details: {
        present: number;
        late: number;
        sick: number;
        permission: number;
        alpha: number;
    };
}

const HomeroomReport: React.FC = () => {
    const [data, setData] = useState<HomeroomSummary | null>(null);
    const [isLoading, setIsLoading] = useState(true);

    useEffect(() => {
        loadSummary();
    }, []);

    const loadSummary = async () => {
        setIsLoading(true);
        try {
            const response = await apiClient.get('/teacher/classes');
            setData(response.data?.data || response.data);
        } catch (error: any) {
            if (error?.response?.status !== 403) {
                showToast.error("Gagal memuat rekap kelas wali");
            }
        } finally {
            setIsLoading(false);
        }
    };

    if (isLoading) {
        return (
            <div className="space-y-6">
                <Skeleton variant="rounded" width={320} height={36} />
                <div className="grid grid-cols-2 lg:grid-cols-4 gap-6">
                    {[1, 2, 3, 4].map((i) => (
                        <SkeletonCard key={i} className="h-28" />
                    ))}
                </div>
                <SkeletonCard className="h-64" />
            </div>
        );
    }

    if (!data) {
        return (
            <div className="flex flex-col items-center justify-center py-20 bg-gray-50 rounded-xl border border-dashed border-gray-300">
                <School className="w-12 h-12 text-gray-400 mb-4" />
                <h3 className="text-lg font-medium text-gray-900">Bukan Wali Kelas</h3>
                <p className="text-gray-500 max-w-sm text-center mt-2">
                    Anda tidak terdaftar sebagai wali kelas untuk tahun ajaran yang sedang aktif.
                    Laporan ini khusus untuk guru yang menjabat sebagai wali kelas.
                </p>
            </div>
        );
    }

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
                        <Users className="w-6 h-6 text-blue-600" />
                        Rekap Kelas Wali: {data.class_info.name}
                    </h1>
                    <p className="text-sm text-gray-500 mt-1">Pantau ringkasan kehadiran siswa di kelas yang Anda pimpin hari ini</p>
                </div>
                <div className="text-right">
                    <div className="text-sm text-gray-500 mb-1">Tingkat Kehadiran</div>
                    <div className="text-3xl font-bold text-green-600 bg-green-50 px-4 py-1 rounded-lg">
                        {data.summary.attendance_rate}%
                    </div>
                </div>
            </div>

            <div className="grid grid-cols-2 lg:grid-cols-4 gap-6">
                <div className="bg-white p-6 rounded-xl border border-gray-100 shadow-sm flex items-center gap-4">
                    <div className="p-3 bg-blue-50 text-blue-600 rounded-lg">
                        <Users className="w-6 h-6" />
                    </div>
                    <div>
                        <div className="text-sm font-medium text-gray-500">Total Siswa</div>
                        <div className="text-2xl font-bold text-gray-900 mt-1">{data.summary.total_students}</div>
                    </div>
                </div>
                <div className="bg-white p-6 rounded-xl border border-gray-100 shadow-sm flex items-center gap-4">
                    <div className="p-3 bg-green-50 text-green-600 rounded-lg">
                        <Activity className="w-6 h-6" />
                    </div>
                    <div>
                        <div className="text-sm font-medium text-gray-500">Hadir / Terlambat</div>
                        <div className="text-2xl font-bold text-gray-900 mt-1">{data.summary.present_today}</div>
                    </div>
                </div>
                <div className="bg-white p-6 rounded-xl border border-gray-100 shadow-sm flex items-center gap-4">
                    <div className="p-3 bg-red-50 text-red-600 rounded-lg">
                        <PieChart className="w-6 h-6" />
                    </div>
                    <div>
                        <div className="text-sm font-medium text-gray-500">Tidak Hadir (S/I/A)</div>
                        <div className="text-2xl font-bold text-gray-900 mt-1">{data.summary.absent_today}</div>
                    </div>
                </div>
                <div className="bg-white p-6 rounded-xl border border-gray-100 shadow-sm flex items-center gap-4">
                    <div className="p-3 bg-gray-50 text-gray-600 rounded-lg">
                        <School className="w-6 h-6" />
                    </div>
                    <div>
                        <div className="text-sm font-medium text-gray-500">Belum Ada Status</div>
                        <div className="text-2xl font-bold text-gray-900 mt-1">{data.summary.not_checked_in}</div>
                    </div>
                </div>
            </div>

            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h2 className="text-lg font-semibold text-gray-900 mb-6">Rincian Status Harian</h2>

                <div className="grid grid-cols-2 sm:grid-cols-5 gap-4">
                    <div className="text-center p-4 border rounded-lg border-green-200 bg-green-50">
                        <div className="text-sm font-medium text-green-800 mb-1">Hadir Tepat Waktu</div>
                        <div className="text-3xl font-bold text-green-600">{data.details.present}</div>
                    </div>
                    <div className="text-center p-4 border rounded-lg border-orange-200 bg-orange-50">
                        <div className="text-sm font-medium text-orange-800 mb-1">Terlambat</div>
                        <div className="text-3xl font-bold text-orange-600">{data.details.late}</div>
                    </div>
                    <div className="text-center p-4 border rounded-lg border-teal-200 bg-teal-50">
                        <div className="text-sm font-medium text-teal-800 mb-1">Sakit</div>
                        <div className="text-3xl font-bold text-teal-600">{data.details.sick}</div>
                    </div>
                    <div className="text-center p-4 border rounded-lg border-purple-200 bg-purple-50">
                        <div className="text-sm font-medium text-purple-800 mb-1">Izin</div>
                        <div className="text-3xl font-bold text-purple-600">{data.details.permission}</div>
                    </div>
                    <div className="text-center p-4 border rounded-lg border-red-200 bg-red-50">
                        <div className="text-sm font-medium text-red-800 mb-1">Alpha</div>
                        <div className="text-3xl font-bold text-red-600">{data.details.alpha}</div>
                    </div>
                </div>
            </div>

            <div className="bg-blue-50 text-blue-800 p-4 rounded-lg flex items-start gap-3">
                <School className="w-5 h-5 flex-shrink-0 mt-0.5" />
                <p className="text-sm">
                    Laporan ini menunjukkan hasil rekapitulasi real-time absensi siswa di kelas perwalian Anda untuk hari ini.
                    Untuk memasukkan izin surat dari orang tua secara manual, gunakan menu <strong>Absensi Kelas &gt; Input Izin/Sakit</strong>.
                </p>
            </div>
        </div>
    );
};

export default HomeroomReport;
