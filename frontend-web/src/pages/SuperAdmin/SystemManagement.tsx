import React, { useEffect, useState } from 'react';
import {
    Database,
    Download,
    Power,
    PowerOff,
    Activity,
    HardDrive,
    Loader2,
    CheckCircle,
    XCircle,
    AlertTriangle
} from 'lucide-react';
import { apiClient } from '../../lib/api';
import showToast from '../../utils/toast';

interface SystemStatus {
    maintenance_mode: boolean;
    status: 'up' | 'down';
}

export const SystemManagement: React.FC = () => {
    const [maintenanceStatus, setMaintenanceStatus] = useState<SystemStatus | null>(null);
    const [loading, setLoading] = useState(true);
    const [actionLoading, setActionLoading] = useState(false);
    const [backupLoading, setBackupLoading] = useState(false);

    useEffect(() => {
        fetchMaintenanceStatus();
    }, []);

    const fetchMaintenanceStatus = async () => {
        try {
            const response = await apiClient.get('/super-admin/system/maintenance/status');
            if (response.data.success) {
                setMaintenanceStatus(response.data.data);
            }
        } catch (error) {
            console.error('Failed to fetch maintenance status:', error);
        } finally {
            setLoading(false);
        }
    };

    const handleDownloadBackup = async () => {
        if (!window.confirm('Download backup database sekarang? Proses ini mungkin memakan waktu beberapa menit.')) {
            return;
        }

        try {
            setBackupLoading(true);
            const response = await apiClient.get('/super-admin/system/backup', {
                responseType: 'blob'
            });

            // Create download link
            const url = window.URL.createObjectURL(new Blob([response.data]));
            const link = document.createElement('a');
            link.href = url;
            link.setAttribute('download', `backup_${new Date().toISOString().split('T')[0]}.sql`);
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.URL.revokeObjectURL(url);

            showToast.success('Backup berhasil didownload!');
        } catch (error: any) {
            console.error('Backup failed:', error);
            showToast.error(error.response?.data?.message || 'Gagal download backup. Silakan coba lagi.');
        } finally {
            setBackupLoading(false);
        }
    };

    const handleToggleMaintenance = async () => {
        const enable = !maintenanceStatus?.maintenance_mode;
        const message = enable
            ? 'Aktifkan Maintenance Mode? Semua user (kecuali Super Admin) tidak akan bisa mengakses aplikasi.'
            : 'Nonaktifkan Maintenance Mode? User akan kembali bisa mengakses aplikasi.';

        if (!window.confirm(message)) {
            return;
        }

        try {
            setActionLoading(true);
            const response = await apiClient.post('/super-admin/system/maintenance', { enable });

            if (response.data.success) {
                setMaintenanceStatus(response.data.data);
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
            <div className="flex items-center justify-center h-screen">
                <div className="text-center">
                    <div className="w-16 h-16 border-4 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto mb-4"></div>
                    <p className="text-slate-600">Loading...</p>
                </div>
            </div>
        );
    }

    return (
        <div className="min-h-screen bg-slate-50 p-6">
            {/* Header */}
            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 mb-2">System Management</h1>
                <p className="text-slate-600">Kelola backup database dan maintenance mode</p>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Maintenance Mode Card */}
                <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                    <div className="flex items-center gap-3 mb-6">
                        <div className={`p-3 rounded-lg ${maintenanceStatus?.maintenance_mode ? 'bg-red-50' : 'bg-green-50'}`}>
                            {maintenanceStatus?.maintenance_mode ? (
                                <PowerOff className="w-6 h-6 text-red-600" />
                            ) : (
                                <Power className="w-6 h-6 text-green-600" />
                            )}
                        </div>
                        <div>
                            <h2 className="text-lg font-bold text-slate-900">Maintenance Mode</h2>
                            <p className="text-sm text-slate-600">Kontrol akses aplikasi</p>
                        </div>
                    </div>

                    {/* Status Indicator */}
                    <div className={`p-4 rounded-lg mb-6 ${maintenanceStatus?.maintenance_mode ? 'bg-red-50 border border-red-200' : 'bg-green-50 border border-green-200'}`}>
                        <div className="flex items-center gap-3">
                            {maintenanceStatus?.maintenance_mode ? (
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

                    {/* Info Box */}
                    <div className="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                        <div className="flex gap-3">
                            <AlertTriangle className="w-5 h-5 text-blue-600 flex-shrink-0 mt-0.5" />
                            <div className="text-sm text-blue-900">
                                <p className="font-semibold mb-1">Catatan Penting:</p>
                                <ul className="list-disc list-inside space-y-1 text-blue-800">
                                    <li>Super Admin tetap bisa akses dengan bypass token</li>
                                    <li>Gunakan saat update sistem atau perbaikan critical</li>
                                    <li>Pastikan informasikan ke user sebelumnya</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    {/* Toggle Button */}
                    <button
                        onClick={handleToggleMaintenance}
                        disabled={actionLoading}
                        className={`w-full px-6 py-3 rounded-lg font-semibold flex items-center justify-center gap-2 transition-colors ${maintenanceStatus?.maintenance_mode
                            ? 'bg-green-600 hover:bg-green-700 text-white'
                            : 'bg-red-600 hover:bg-red-700 text-white'
                            } disabled:opacity-50 disabled:cursor-not-allowed`}
                    >
                        {actionLoading ? (
                            <>
                                <Loader2 className="w-5 h-5 animate-spin" />
                                Processing...
                            </>
                        ) : maintenanceStatus?.maintenance_mode ? (
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

                {/* Database Backup Card */}
                <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                    <div className="flex items-center gap-3 mb-6">
                        <div className="p-3 bg-purple-50 rounded-lg">
                            <Database className="w-6 h-6 text-purple-600" />
                        </div>
                        <div>
                            <h2 className="text-lg font-bold text-slate-900">Database Backup</h2>
                            <p className="text-sm text-slate-600">Download backup PostgreSQL</p>
                        </div>
                    </div>

                    {/* Info */}
                    <div className="space-y-4 mb-6">
                        <div className="flex items-start gap-3 p-3 bg-slate-50 rounded-lg">
                            <HardDrive className="w-5 h-5 text-slate-600 mt-0.5" />
                            <div>
                                <p className="text-sm font-semibold text-slate-900">Format: SQL Dump</p>
                                <p className="text-xs text-slate-600">File .sql yang dapat di-restore ke PostgreSQL</p>
                            </div>
                        </div>

                        <div className="flex items-start gap-3 p-3 bg-slate-50 rounded-lg">
                            <Activity className="w-5 h-5 text-slate-600 mt-0.5" />
                            <div>
                                <p className="text-sm font-semibold text-slate-900">Proses: Real-time</p>
                                <p className="text-xs text-slate-600">Backup dibuat saat Anda klik download</p>
                            </div>
                        </div>
                    </div>

                    {/* Warning Box */}
                    <div className="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6">
                        <div className="flex gap-3">
                            <AlertTriangle className="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5" />
                            <div className="text-sm text-amber-900">
                                <p className="font-semibold mb-1">Rekomendasi:</p>
                                <ul className="list-disc list-inside space-y-1 text-amber-800">
                                    <li>Backup minimal 1x per hari</li>
                                    <li>Simpan di cloud storage (S3/Google Cloud)</li>
                                    <li>Test restore secara berkala</li>
                                    <li>Enkripsi file backup untuk keamanan</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    {/* Download Button */}
                    <button
                        onClick={handleDownloadBackup}
                        disabled={backupLoading}
                        className="w-full px-6 py-3 bg-purple-600 hover:bg-purple-700 text-white rounded-lg font-semibold flex items-center justify-center gap-2 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        {backupLoading ? (
                            <>
                                <Loader2 className="w-5 h-5 animate-spin" />
                                Membuat Backup...
                            </>
                        ) : (
                            <>
                                <Download className="w-5 h-5" />
                                Download Backup Sekarang
                            </>
                        )}
                    </button>

                    <p className="text-xs text-slate-500 text-center mt-3">
                        File akan otomatis terdownload ke folder Downloads Anda
                    </p>
                </div>
            </div>

            {/* System Health Dashboard (Future Enhancement) */}
            <div className="mt-6 bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                <h2 className="text-lg font-bold text-slate-900 mb-4">System Health (Coming Soon)</h2>
                <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div className="p-4 bg-slate-50 rounded-lg">
                        <p className="text-sm text-slate-600 mb-1">CPU Usage</p>
                        <p className="text-2xl font-bold text-slate-900">--</p>
                    </div>
                    <div className="p-4 bg-slate-50 rounded-lg">
                        <p className="text-sm text-slate-600 mb-1">Memory</p>
                        <p className="text-2xl font-bold text-slate-900">--</p>
                    </div>
                    <div className="p-4 bg-slate-50 rounded-lg">
                        <p className="text-sm text-slate-600 mb-1">Disk Space</p>
                        <p className="text-2xl font-bold text-slate-900">--</p>
                    </div>
                    <div className="p-4 bg-slate-50 rounded-lg">
                        <p className="text-sm text-slate-600 mb-1">Uptime</p>
                        <p className="text-2xl font-bold text-slate-900">--</p>
                    </div>
                </div>
            </div>
        </div>
    );
};
