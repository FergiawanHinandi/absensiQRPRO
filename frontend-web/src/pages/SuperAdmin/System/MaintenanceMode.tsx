import React, { useEffect, useState } from 'react';
import {
    Power,
    PowerOff,
    Loader2,
    CheckCircle,
    XCircle,
    AlertTriangle,
    Shield,
    Clock,
    RefreshCw
} from 'lucide-react';
import { apiClient } from '../../../lib/api';
import showToast from '../../../utils/toast';

interface SystemStatus {
    maintenance_mode: boolean;
    status: 'up' | 'down';
    message?: string;
    since?: string;
}

export const MaintenanceMode: React.FC = () => {
    const [systemStatus, setSystemStatus] = useState<SystemStatus | null>(null);
    const [loading, setLoading] = useState(true);
    const [actionLoading, setActionLoading] = useState(false);
    const [message, setMessage] = useState('Sedang dalam pemeliharaan sistem. Silakan coba beberapa saat lagi.');

    useEffect(() => {
        fetchStatus();
    }, []);

    const fetchStatus = async () => {
        try {
            setLoading(true);
            const response = await apiClient.get('/super-admin/system/maintenance/status');
            if (response.data?.success !== false) {
                setSystemStatus(response.data?.data || response.data);
            }
        } catch (error) {
            console.error('Failed to fetch maintenance status:', error);
            showToast.error('Gagal memuat status maintenance');
        } finally {
            setLoading(false);
        }
    };

    const handleToggleMaintenance = async () => {
        const enable = !systemStatus?.maintenance_mode;
        const confirmMsg = enable
            ? 'Aktifkan Maintenance Mode? Semua user (kecuali Super Admin) tidak akan bisa mengakses aplikasi.'
            : 'Nonaktifkan Maintenance Mode? User akan kembali bisa mengakses aplikasi.';

        if (!window.confirm(confirmMsg)) return;

        try {
            setActionLoading(true);
            const response = await apiClient.post('/super-admin/system/maintenance', {
                enable,
                message: enable ? message : undefined,
            });

            if (response.data?.success !== false) {
                setSystemStatus(response.data?.data || response.data);
                showToast.success(`Maintenance mode berhasil ${enable ? 'diaktifkan' : 'dinonaktifkan'}!`);
            }
        } catch (error: any) {
            console.error('Toggle maintenance failed:', error);
            showToast.error(error.response?.data?.message || 'Gagal mengubah maintenance mode.');
        } finally {
            setActionLoading(false);
        }
    };

    if (loading) {
        return (
            <div className="flex items-center justify-center h-64">
                <div className="text-center">
                    <Loader2 className="w-10 h-10 text-blue-600 animate-spin mx-auto mb-3" />
                    <p className="text-slate-600">Memuat status...</p>
                </div>
            </div>
        );
    }

    return (
        <div className="min-h-screen bg-slate-50 p-6">
            <div className="mb-6 flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 mb-2">Maintenance Mode</h1>
                    <p className="text-slate-600">Kontrol akses aplikasi saat pemeliharaan sistem</p>
                </div>
                <button
                    onClick={fetchStatus}
                    className="flex items-center gap-2 px-4 py-2 text-sm text-slate-600 bg-white border border-slate-300 rounded-lg hover:bg-slate-50"
                >
                    <RefreshCw className="w-4 h-4" />
                    Refresh
                </button>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Status & Toggle Card */}
                <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                    <div className="flex items-center gap-3 mb-6">
                        <div className={`p-3 rounded-lg ${systemStatus?.maintenance_mode ? 'bg-red-50' : 'bg-green-50'}`}>
                            {systemStatus?.maintenance_mode ? (
                                <PowerOff className="w-6 h-6 text-red-600" />
                            ) : (
                                <Power className="w-6 h-6 text-green-600" />
                            )}
                        </div>
                        <div>
                            <h2 className="text-lg font-bold text-slate-900">Status Aplikasi</h2>
                            <p className="text-sm text-slate-600">Kontrol akses user</p>
                        </div>
                    </div>

                    {/* Status Indicator */}
                    <div className={`p-4 rounded-lg mb-6 ${systemStatus?.maintenance_mode
                        ? 'bg-red-50 border border-red-200'
                        : 'bg-green-50 border border-green-200'
                        }`}>
                        <div className="flex items-center gap-3">
                            {systemStatus?.maintenance_mode ? (
                                <>
                                    <XCircle className="w-5 h-5 text-red-600" />
                                    <div>
                                        <p className="font-semibold text-red-900">Maintenance Mode AKTIF</p>
                                        <p className="text-sm text-red-700">Aplikasi tidak dapat diakses oleh user biasa</p>
                                    </div>
                                </>
                            ) : (
                                <>
                                    <CheckCircle className="w-5 h-5 text-green-600" />
                                    <div>
                                        <p className="font-semibold text-green-900">Aplikasi ONLINE</p>
                                        <p className="text-sm text-green-700">Semua user dapat mengakses aplikasi</p>
                                    </div>
                                </>
                            )}
                        </div>
                    </div>

                    {/* Custom Message (shown when enabling maintenance) */}
                    {!systemStatus?.maintenance_mode && (
                        <div className="mb-6">
                            <label className="block text-sm font-medium text-slate-700 mb-2">
                                Pesan Maintenance (opsional)
                            </label>
                            <textarea
                                value={message}
                                onChange={(e) => setMessage(e.target.value)}
                                rows={3}
                                className="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                placeholder="Pesan yang akan ditampilkan ke user..."
                            />
                        </div>
                    )}

                    {/* Toggle Button */}
                    <button
                        onClick={handleToggleMaintenance}
                        disabled={actionLoading}
                        className={`w-full px-6 py-3 rounded-lg font-semibold flex items-center justify-center gap-2 transition-colors ${systemStatus?.maintenance_mode
                            ? 'bg-green-600 hover:bg-green-700 text-white'
                            : 'bg-red-600 hover:bg-red-700 text-white'
                            } disabled:opacity-50 disabled:cursor-not-allowed`}
                    >
                        {actionLoading ? (
                            <>
                                <Loader2 className="w-5 h-5 animate-spin" />
                                Processing...
                            </>
                        ) : systemStatus?.maintenance_mode ? (
                            <>
                                <Power className="w-5 h-5" />
                                Nonaktifkan Maintenance Mode
                            </>
                        ) : (
                            <>
                                <PowerOff className="w-5 h-5" />
                                Aktifkan Maintenance Mode
                            </>
                        )}
                    </button>
                </div>

                {/* Info Card */}
                <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                    <div className="flex items-center gap-3 mb-6">
                        <div className="p-3 bg-blue-50 rounded-lg">
                            <Shield className="w-6 h-6 text-blue-600" />
                        </div>
                        <div>
                            <h2 className="text-lg font-bold text-slate-900">Informasi</h2>
                            <p className="text-sm text-slate-600">Hal penting tentang maintenance mode</p>
                        </div>
                    </div>

                    <div className="space-y-4">
                        <div className="flex gap-3 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                            <Shield className="w-5 h-5 text-blue-600 flex-shrink-0 mt-0.5" />
                            <div className="text-sm">
                                <p className="font-semibold text-blue-900">Super Admin Bypass</p>
                                <p className="text-blue-800">Super Admin tetap bisa mengakses aplikasi saat maintenance</p>
                            </div>
                        </div>
                        <div className="flex gap-3 p-3 bg-amber-50 border border-amber-200 rounded-lg">
                            <Clock className="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5" />
                            <div className="text-sm">
                                <p className="font-semibold text-amber-900">Kapan Digunakan</p>
                                <p className="text-amber-800">Saat update sistem, migrasi database, atau perbaikan critical</p>
                            </div>
                        </div>
                        <div className="flex gap-3 p-3 bg-red-50 border border-red-200 rounded-lg">
                            <AlertTriangle className="w-5 h-5 text-red-600 flex-shrink-0 mt-0.5" />
                            <div className="text-sm">
                                <p className="font-semibold text-red-900">Peringatan</p>
                                <p className="text-red-800">API akan mengembalikan HTTP 503 untuk semua request dari user non-super-admin</p>
                            </div>
                        </div>
                        <div className="flex gap-3 p-3 bg-green-50 border border-green-200 rounded-lg">
                            <CheckCircle className="w-5 h-5 text-green-600 flex-shrink-0 mt-0.5" />
                            <div className="text-sm">
                                <p className="font-semibold text-green-900">Best Practice</p>
                                <p className="text-green-800">Informasikan jadwal maintenance ke user melalui pengumuman terlebih dahulu</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
};