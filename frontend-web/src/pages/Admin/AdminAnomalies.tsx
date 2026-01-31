import React from 'react';
import { AlertTriangle, Activity, User, Clock } from 'lucide-react';
import Loading from '../../components/common/Loading';
import ErrorMessage from '../../components/common/ErrorMessage';
import { useAttendanceAnomalies } from '../../modules/admin/hooks';

const AdminAnomalies: React.FC = () => {
    const { data, isLoading, error, refetch } = useAttendanceAnomalies();

    if (isLoading) {
        return <Loading text="Memuat notifikasi anomali..." />;
    }

    if (error) {
        return (
            <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
                <ErrorMessage message="Gagal memuat notifikasi anomali" onRetry={() => refetch()} />
            </div>
        );
    }

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
                        <AlertTriangle className="w-6 h-6 text-purple-600" />
                        Notifikasi Anomali Absensi
                    </h1>
                    <p className="text-sm text-gray-500 mt-1">Ringkasan anomali absensi hari ini.</p>
                </div>
                <div className="text-sm text-gray-600">Tanggal: {data?.date || '-'}</div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div className="bg-white p-5 rounded-xl shadow-sm border border-gray-100">
                    <div className="flex items-center gap-3">
                        <div className="p-2 bg-purple-50 rounded-lg">
                            <Activity className="w-5 h-5 text-purple-600" />
                        </div>
                        <div>
                            <p className="text-xs text-gray-500">Total Anomali</p>
                            <p className="text-xl font-semibold text-gray-900">{data?.total ?? 0}</p>
                        </div>
                    </div>
                </div>
                <div className="bg-white p-5 rounded-xl shadow-sm border border-gray-100">
                    <div className="flex items-center gap-3">
                        <div className="p-2 bg-blue-50 rounded-lg">
                            <User className="w-5 h-5 text-blue-600" />
                        </div>
                        <div>
                            <p className="text-xs text-gray-500">Jenis Utama</p>
                            <p className="text-sm font-semibold text-gray-900">Manual Override / Multiple Scan</p>
                        </div>
                    </div>
                </div>
                <div className="bg-white p-5 rounded-xl shadow-sm border border-gray-100">
                    <div className="flex items-center gap-3">
                        <div className="p-2 bg-amber-50 rounded-lg">
                            <Clock className="w-5 h-5 text-amber-600" />
                        </div>
                        <div>
                            <p className="text-xs text-gray-500">Update Terakhir</p>
                            <p className="text-sm font-semibold text-gray-900">Hari ini</p>
                        </div>
                    </div>
                </div>
            </div>

            <div className="bg-white rounded-xl shadow-sm border border-gray-100">
                <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 className="text-lg font-semibold text-gray-900">Daftar Anomali</h2>
                    <span className="text-xs text-gray-500">Menampilkan maksimal 20 data terbaru</span>
                </div>
                <div className="divide-y">
                    {data?.items?.length ? (
                        data.items.map((item, index) => (
                            <div key={`${item.type}-${index}`} className="px-6 py-4 flex items-start gap-4">
                                <div className="mt-1">
                                    <Activity className="w-5 h-5 text-purple-600" />
                                </div>
                                <div className="flex-1">
                                    <div className="flex items-center gap-2">
                                        <span className="text-sm font-semibold text-gray-900">{item.message}</span>
                                        <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${item.type === 'manual_override'
                                                ? 'bg-blue-50 text-blue-700'
                                                : 'bg-amber-50 text-amber-700'
                                            }`}>
                                            {item.type === 'manual_override' ? 'Manual Override' : 'Multiple Scan'}
                                        </span>
                                    </div>
                                    <div className="mt-2 text-xs text-gray-500 space-y-1">
                                        {item.recorded_by && (
                                            <div>Dicatat oleh: {item.recorded_by}</div>
                                        )}
                                        {item.status && (
                                            <div>Status: {item.status}</div>
                                        )}
                                        {item.time && (
                                            <div>Waktu: {new Date(item.time).toLocaleTimeString('id-ID')}</div>
                                        )}
                                        {item.scan_count && (
                                            <div>Jumlah scan: {item.scan_count}</div>
                                        )}
                                    </div>
                                </div>
                            </div>
                        ))
                    ) : (
                        <div className="px-6 py-10 text-center text-sm text-gray-500">
                            Tidak ada anomali absensi hari ini.
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
};

export default AdminAnomalies;
