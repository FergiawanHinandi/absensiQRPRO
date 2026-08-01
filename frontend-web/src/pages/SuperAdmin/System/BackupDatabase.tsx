import React, { useState } from 'react';
import {
    Database,
    Download,
    HardDrive,
    Activity,
    Loader2,
    AlertTriangle,
    Clock,
    CheckCircle
} from 'lucide-react';
import { apiClient } from '../../../lib/api';
import showToast from '../../../utils/toast';

export const BackupDatabase: React.FC = () => {
    const [backupLoading, setBackupLoading] = useState(false);
    const [lastBackup, setLastBackup] = useState<string | null>(null);

    const handleDownloadBackup = async () => {
        if (!window.confirm('Download backup database sekarang? Proses ini mungkin memakan waktu beberapa menit.')) {
            return;
        }

        try {
            setBackupLoading(true);
            const response = await apiClient.get('/super-admin/system/backup', {
                responseType: 'blob',
            });

            const url = window.URL.createObjectURL(new Blob([response.data]));
            const link = document.createElement('a');
            link.href = url;
            link.setAttribute('download', `backup_${new Date().toISOString().split('T')[0]}.sql`);
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.URL.revokeObjectURL(url);

            setLastBackup(new Date().toLocaleString('id-ID'));
            showToast.success('Backup berhasil didownload!');
        } catch (error: any) {
            console.error('Backup failed:', error);
            showToast.error(error.response?.data?.message || 'Gagal download backup. Pastikan pg_dump tersedia di server.');
        } finally {
            setBackupLoading(false);
        }
    };

    return (
        <div className="min-h-screen bg-slate-50 p-6">
            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 mb-2">Backup Database</h1>
                <p className="text-slate-600">Download backup PostgreSQL untuk penyimpanan eksternal</p>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Download Backup Card */}
                <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                    <div className="flex items-center gap-3 mb-6">
                        <div className="p-3 bg-purple-50 rounded-lg">
                            <Database className="w-6 h-6 text-purple-600" />
                        </div>
                        <div>
                            <h2 className="text-lg font-bold text-slate-900">Download Backup</h2>
                            <p className="text-sm text-slate-600">Buat dan download file SQL dump</p>
                        </div>
                    </div>

                    <div className="space-y-4 mb-6">
                        <div className="flex items-start gap-3 p-3 bg-slate-50 rounded-lg">
                            <HardDrive className="w-5 h-5 text-slate-600 mt-0.5" />
                            <div>
                                <p className="text-sm font-semibold text-slate-900">Format: SQL Dump (pg_dump)</p>
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
                        {lastBackup && (
                            <div className="flex items-start gap-3 p-3 bg-green-50 rounded-lg">
                                <CheckCircle className="w-5 h-5 text-green-600 mt-0.5" />
                                <div>
                                    <p className="text-sm font-semibold text-green-900">Backup terakhir</p>
                                    <p className="text-xs text-green-700">{lastBackup}</p>
                                </div>
                            </div>
                        )}
                    </div>

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

                {/* Info & Recommendations Card */}
                <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                    <div className="flex items-center gap-3 mb-6">
                        <div className="p-3 bg-amber-50 rounded-lg">
                            <AlertTriangle className="w-6 h-6 text-amber-600" />
                        </div>
                        <div>
                            <h2 className="text-lg font-bold text-slate-900">Rekomendasi Backup</h2>
                            <p className="text-sm text-slate-600">Best practices untuk keamanan data</p>
                        </div>
                    </div>

                    <div className="space-y-4">
                        <div className="flex gap-3 p-3 bg-amber-50 border border-amber-200 rounded-lg">
                            <Clock className="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5" />
                            <div className="text-sm">
                                <p className="font-semibold text-amber-900">Jadwal Backup</p>
                                <p className="text-amber-800">Backup minimal 1x per hari untuk data penting</p>
                            </div>
                        </div>
                        <div className="flex gap-3 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                            <HardDrive className="w-5 h-5 text-blue-600 flex-shrink-0 mt-0.5" />
                            <div className="text-sm">
                                <p className="font-semibold text-blue-900">Penyimpanan</p>
                                <p className="text-blue-800">Simpan di cloud storage (S3/Google Cloud) untuk redundansi</p>
                            </div>
                        </div>
                        <div className="flex gap-3 p-3 bg-green-50 border border-green-200 rounded-lg">
                            <CheckCircle className="w-5 h-5 text-green-600 flex-shrink-0 mt-0.5" />
                            <div className="text-sm">
                                <p className="font-semibold text-green-900">Verifikasi</p>
                                <p className="text-green-800">Test restore secara berkala untuk memastikan integritas</p>
                            </div>
                        </div>
                        <div className="flex gap-3 p-3 bg-purple-50 border border-purple-200 rounded-lg">
                            <Database className="w-5 h-5 text-purple-600 flex-shrink-0 mt-0.5" />
                            <div className="text-sm">
                                <p className="font-semibold text-purple-900">Keamanan</p>
                                <p className="text-purple-800">Enkripsi file backup sebelum disimpan</p>
                            </div>
                        </div>
                    </div>

                    <div className="mt-6 p-4 bg-slate-50 rounded-lg">
                        <p className="text-sm font-semibold text-slate-700 mb-2">Restore Command:</p>
                        <code className="text-xs text-slate-600 bg-slate-100 p-2 rounded block">
                            psql -U username -d database_name &lt; backup_file.sql
                        </code>
                    </div>
                </div>
            </div>
        </div>
    );
};