import React from 'react';
import { Clock, ShieldCheck } from 'lucide-react';
import { useLocation } from 'react-router-dom';
import Loading from '../../components/common/Loading';
import ErrorMessage from '../../components/common/ErrorMessage';
import { useSchoolProfile } from '../../modules/admin/hooks';

const titleMap: Record<string, string> = {
    '/admin/attendance': 'Manajemen Absensi',
    '/admin/attendance/settings': 'Pengaturan Jam Absensi',
    '/admin/attendance/tolerance': 'Toleransi Keterlambatan',
    '/admin/attendance/location': 'Lokasi Valid (GPS)',
    '/admin/attendance/qr-mode': 'Mode QR (Per Sesi/Hari)',
    '/admin/attendance/override': 'Override Absensi (Izin/Sakit)',
};

const AdminAttendanceSettings: React.FC = () => {
    const location = useLocation();
    const title = titleMap[location.pathname] ?? 'Manajemen Absensi';
    const { data, isLoading, error, refetch } = useSchoolProfile();
    const isTolerance = location.pathname.includes('/attendance/tolerance');
    const isLocation = location.pathname.includes('/attendance/location');
    const isQrMode = location.pathname.includes('/attendance/qr-mode');
    const isOverride = location.pathname.includes('/attendance/override');

    const settings = data?.settings ?? {};
    const toleranceValue = settings?.late_tolerance_minutes
        ?? settings?.attendance?.late_tolerance_minutes
        ?? settings?.tolerance_minutes
        ?? '-';
    const qrModeValue = settings?.qr_mode
        ?? settings?.attendance?.qr_mode
        ?? '-';
    const overrideValue = settings?.override
        ?? settings?.attendance?.override
        ?? null;

    if (isLoading) {
        return <Loading text="Memuat pengaturan absensi..." />;
    }

    if (error) {
        return (
            <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
                <ErrorMessage message="Gagal memuat pengaturan absensi" onRetry={() => refetch()} />
            </div>
        );
    }

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
                        <Clock className="w-6 h-6 text-blue-600" />
                        {title}
                    </h1>
                    <p className="text-sm text-gray-500 mt-1">Pengaturan absensi berbasis konfigurasi sekolah.</p>
                </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                {(isLocation || (!isTolerance && !isQrMode && !isOverride)) && (
                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-3">
                        <h2 className="text-lg font-semibold text-gray-900 flex items-center gap-2">
                            <ShieldCheck className="w-5 h-5 text-blue-600" />
                            Lokasi & Keamanan
                        </h2>
                        <div className="text-sm text-gray-600 space-y-2">
                            <div><span className="font-medium text-gray-900">Radius GPS:</span> {data?.radius_meters ?? '-'} m</div>
                            <div><span className="font-medium text-gray-900">Latitude:</span> {data?.latitude ?? '-'}</div>
                            <div><span className="font-medium text-gray-900">Longitude:</span> {data?.longitude ?? '-'}</div>
                        </div>
                    </div>
                )}
                {isTolerance && (
                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-3">
                        <h2 className="text-lg font-semibold text-gray-900">Toleransi Keterlambatan</h2>
                        <p className="text-sm text-gray-500">Nilai toleransi diambil dari pengaturan sekolah.</p>
                        <div className="text-2xl font-semibold text-gray-900">{toleranceValue} menit</div>
                    </div>
                )}
                {isQrMode && (
                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-3">
                        <h2 className="text-lg font-semibold text-gray-900">Mode QR</h2>
                        <p className="text-sm text-gray-500">Mode QR yang digunakan untuk absensi.</p>
                        <div className="text-sm text-gray-700">{qrModeValue}</div>
                    </div>
                )}
                {isOverride && (
                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-3">
                        <h2 className="text-lg font-semibold text-gray-900">Override Absensi</h2>
                        <p className="text-sm text-gray-500">Informasi izin/sakit yang diperbolehkan.</p>
                        <pre className="bg-slate-50 rounded-lg p-4 text-xs text-slate-600 overflow-x-auto">
                            {JSON.stringify(overrideValue ?? {}, null, 2)}
                        </pre>
                    </div>
                )}
                <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-3">
                    <h2 className="text-lg font-semibold text-gray-900">Konfigurasi Absensi</h2>
                    <p className="text-sm text-gray-500">Nilai ini dapat diatur di pengaturan sekolah.</p>
                    <pre className="bg-slate-50 rounded-lg p-4 text-xs text-slate-600 overflow-x-auto">
                        {JSON.stringify(data?.settings ?? {}, null, 2)}
                    </pre>
                </div>
            </div>
        </div>
    );
};

export default AdminAttendanceSettings;
