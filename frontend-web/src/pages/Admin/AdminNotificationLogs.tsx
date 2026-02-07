import React, { useState } from 'react';
import { Bell, TrendingDown, AlertTriangle, Clock } from 'lucide-react';
import Loading from '../../components/common/Loading';
import ErrorMessage from '../../components/common/ErrorMessage';
import { useNotificationLogs, useRiskChanges } from '../../modules/admin/hooks/useAdminService';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';

const AdminNotificationLogs: React.FC = () => {
    const [currentPage, setCurrentPage] = useState(1);
    const [riskDays, setRiskDays] = useState(7);

    const { data: notifications, isLoading: notifLoading, error: notifError } = useNotificationLogs(currentPage, 20);
    const { data: riskChanges, isLoading: riskLoading } = useRiskChanges(riskDays);

    if (notifLoading && !notifications) {
        return <Loading text="Memuat log notifikasi..." />;
    }

    if (notifError) {
        return (
            <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
                <ErrorMessage message="Gagal memuat log notifikasi" onRetry={() => window.location.reload()} />
            </div>
        );
    }

    const notificationList = notifications?.notifications || [];
    const totalPages = notifications?.total_pages || 1;

    return (
        <div className="space-y-6">
            {/* Header */}
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
                        <Bell className="w-6 h-6 text-blue-600" />
                        Log Notifikasi & Perubahan Risiko
                    </h1>
                    <p className="text-sm text-gray-500 mt-1">
                        Monitor notifikasi sistem dan perubahan status risiko siswa
                    </p>
                </div>
            </div>

            {/* Risk Changes Section */}
            <div className="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
                <div className="flex items-center justify-between mb-4">
                    <h2 className="text-lg font-semibold text-gray-900 flex items-center gap-2">
                        <TrendingDown className="w-5 h-5 text-orange-600" />
                        Perubahan Status Risiko
                    </h2>
                    <select
                        value={riskDays}
                        onChange={(e) => setRiskDays(parseInt(e.target.value))}
                        className="px-3 py-1 text-sm border border-gray-300 rounded-lg"
                    >
                        <option value={7}>7 Hari Terakhir</option>
                        <option value={14}>14 Hari Terakhir</option>
                        <option value={30}>30 Hari Terakhir</option>
                    </select>
                </div>

                {riskLoading ? (
                    <div className="py-8">
                        <Loading text="Memuat perubahan risiko..." />
                    </div>
                ) : (
                    <div className="space-y-3">
                        {riskChanges?.changes?.length ? (
                            riskChanges.changes.map((change: any, index: number) => (
                                <div
                                    key={index}
                                    className="flex items-start gap-3 p-4 bg-orange-50 border border-orange-200 rounded-lg"
                                >
                                    <div className="p-2 bg-orange-100 rounded-lg">
                                        <AlertTriangle className="w-5 h-5 text-orange-600" />
                                    </div>
                                    <div className="flex-1">
                                        <div className="flex items-center justify-between mb-1">
                                            <h3 className="font-semibold text-gray-900">{change.student_name}</h3>
                                            <span className="text-xs text-gray-500">
                                                {change.class_name}
                                            </span>
                                        </div>
                                        <p className="text-sm text-gray-700 mb-2">{change.description}</p>
                                        <div className="flex items-center gap-4 text-xs text-gray-600">
                                            <span className="flex items-center gap-1">
                                                <span className="font-medium">Status Lama:</span>
                                                <span className={`px-2 py-0.5 rounded ${change.old_status === 'high' ? 'bg-red-100 text-red-700' :
                                                        change.old_status === 'medium' ? 'bg-amber-100 text-amber-700' :
                                                            'bg-green-100 text-green-700'
                                                    }`}>
                                                    {change.old_status}
                                                </span>
                                            </span>
                                            <span className="flex items-center gap-1">
                                                <span className="font-medium">Status Baru:</span>
                                                <span className={`px-2 py-0.5 rounded ${change.new_status === 'high' ? 'bg-red-100 text-red-700' :
                                                        change.new_status === 'medium' ? 'bg-amber-100 text-amber-700' :
                                                            'bg-green-100 text-green-700'
                                                    }`}>
                                                    {change.new_status}
                                                </span>
                                            </span>
                                            <span className="flex items-center gap-1">
                                                <Clock className="w-3 h-3" />
                                                {change.changed_at && format(new Date(change.changed_at), 'dd MMM yyyy HH:mm', { locale: id })}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            ))
                        ) : (
                            <div className="py-8 text-center text-sm text-gray-500">
                                Tidak ada perubahan status risiko dalam {riskDays} hari terakhir
                            </div>
                        )}
                    </div>
                )}
            </div>

            {/* Notification Logs Section */}
            <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                <div className="px-6 py-4 border-b border-slate-200">
                    <h2 className="text-lg font-semibold text-gray-900 flex items-center gap-2">
                        <Bell className="w-5 h-5 text-blue-600" />
                        Log Notifikasi Sistem
                    </h2>
                </div>

                <div className="divide-y divide-slate-100">
                    {notificationList.length ? (
                        notificationList.map((notif: any) => (
                            <div key={notif.id} className="p-4 hover:bg-slate-50 transition-colors">
                                <div className="flex items-start gap-3">
                                    <div className={`p-2 rounded-lg ${notif.type === 'success' ? 'bg-green-100' :
                                            notif.type === 'warning' ? 'bg-amber-100' :
                                                notif.type === 'error' ? 'bg-red-100' :
                                                    'bg-blue-100'
                                        }`}>
                                        <Bell className={`w-4 h-4 ${notif.type === 'success' ? 'text-green-600' :
                                                notif.type === 'warning' ? 'text-amber-600' :
                                                    notif.type === 'error' ? 'text-red-600' :
                                                        'text-blue-600'
                                            }`} />
                                    </div>
                                    <div className="flex-1">
                                        <div className="flex items-center justify-between mb-1">
                                            <h3 className="font-semibold text-gray-900">{notif.title}</h3>
                                            <span className="text-xs text-gray-500">
                                                {notif.created_at && format(new Date(notif.created_at), 'dd MMM yyyy HH:mm', { locale: id })}
                                            </span>
                                        </div>
                                        <p className="text-sm text-gray-700 mb-2">{notif.message}</p>
                                        <div className="flex items-center gap-3 text-xs text-gray-600">
                                            {notif.recipient_type && (
                                                <span className="px-2 py-0.5 bg-slate-100 rounded">
                                                    {notif.recipient_type}
                                                </span>
                                            )}
                                            {notif.recipient_name && (
                                                <span>{notif.recipient_name}</span>
                                            )}
                                            {notif.status && (
                                                <span className={`px-2 py-0.5 rounded ${notif.status === 'sent' ? 'bg-green-100 text-green-700' :
                                                        notif.status === 'failed' ? 'bg-red-100 text-red-700' :
                                                            'bg-amber-100 text-amber-700'
                                                    }`}>
                                                    {notif.status}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        ))
                    ) : (
                        <div className="py-12 text-center text-sm text-gray-500">
                            Belum ada log notifikasi
                        </div>
                    )}
                </div>

                {/* Pagination */}
                {totalPages > 1 && (
                    <div className="px-6 py-4 border-t border-slate-200 flex items-center justify-between">
                        <button
                            onClick={() => setCurrentPage(prev => Math.max(1, prev - 1))}
                            disabled={currentPage === 1}
                            className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            Sebelumnya
                        </button>
                        <span className="text-sm text-gray-600">
                            Halaman {currentPage} dari {totalPages}
                        </span>
                        <button
                            onClick={() => setCurrentPage(prev => Math.min(totalPages, prev + 1))}
                            disabled={currentPage === totalPages}
                            className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            Selanjutnya
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
};

export default AdminNotificationLogs;
