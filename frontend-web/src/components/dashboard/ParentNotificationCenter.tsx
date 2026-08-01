import React, { useEffect, useState, useRef, useCallback } from 'react';
import { Bell, X, CheckCheck, Loader2, Volume2, UserCheck, Clock, AlertTriangle, XCircle, ChevronRight } from 'lucide-react';
import { apiClient } from '../../lib/api';
import { useRealtimeChannel, useRealtime } from '../../hooks/useRealtime';
import { useAuthStore } from '../../modules/auth/stores/useAuthStore';

interface Notification {
    id: string;
    type: string;
    title: string;
    message: string;
    read_at: string | null;
    created_at: string;
    data?: Record<string, unknown>;
    student_name?: string;
    student_id?: string;
    time_ago?: string;
}

interface NotificationEvent {
    id: string;
    type: string;
    title: string;
    message: string;
    student_id: string;
    student_name: string;
    timestamp: string;
    time_ago: string;
    read: boolean;
    data: Record<string, unknown>;
}

export const ParentNotificationCenter: React.FC = () => {
    const [notifications, setNotifications] = useState<Notification[]>([]);
    const [unreadCount, setUnreadCount] = useState(0);
    const [isOpen, setIsOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const dropdownRef = useRef<HTMLDivElement>(null);
    const { user } = useAuthStore();
    const parentId = user?.id;

    // Get Echo connection status
    const { isConnected } = useRealtime({ enabled: true });

    // Subscribe to parent's private channel for real-time notifications
    // Subscribe to parent channel for real-time badge updates (always active)
    useRealtimeChannel({
        channelName: parentId ? `parent.${parentId}` : '',
        eventName: 'parent.notification',
        enabled: !!parentId,
        handler: (data: NotificationEvent) => {
            // Add new notification to top of list
            const newNotif: Notification = {
                id: data.id,
                type: data.type,
                title: data.title,
                message: data.message,
                read_at: null,
                created_at: data.timestamp,
                student_name: data.student_name,
                student_id: data.student_id,
                time_ago: 'Baru saja',
            };
            setNotifications(prev => [newNotif, ...prev]);
            setUnreadCount(prev => prev + 1);

            // Play notification sound if available (feature-flagged)
            if (import.meta.env.VITE_ENABLE_NOTIFICATION_SOUND === 'true') {
                try {
                    const audio = new Audio('/sounds/notification.mp3');
                    audio.volume = 0.3;
                    audio.play().catch(() => {});
                } catch {
                    // Audio not available, skip
                }
            }
        },
    });

    const fetchNotifications = useCallback(async () => {
        try {
            setLoading(true);
            const response = await apiClient.get('/notifications', { params: { per_page: 20 } });
            const data = response.data?.data || response.data || [];
            const items = Array.isArray(data) ? data : data.data || [];
            setNotifications(items);
            setUnreadCount(items.filter((n: Notification) => !n.read_at).length);
        } catch (error) {
            console.error('Failed to fetch notifications:', error);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        fetchNotifications();
        const interval = setInterval(fetchNotifications, 60000); // Poll every 60s
        return () => clearInterval(interval);
    }, [fetchNotifications]);

    // Close dropdown when clicking outside
    useEffect(() => {
        const handleClickOutside = (e: MouseEvent) => {
            if (dropdownRef.current && !dropdownRef.current.contains(e.target as Node)) {
                setIsOpen(false);
            }
        };
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    const markAsRead = async (id: string) => {
        try {
            await apiClient.post(`/notifications/${id}/read`);
            setNotifications(prev =>
                prev.map(n => n.id === id ? { ...n, read_at: new Date().toISOString() } : n)
            );
            setUnreadCount(prev => Math.max(0, prev - 1));
        } catch (error) {
            console.error('Failed to mark as read:', error);
        }
    };

    const markAllAsRead = async () => {
        try {
            await apiClient.post('/notifications/read-all');
            setNotifications(prev => prev.map(n => ({ ...n, read_at: n.read_at || new Date().toISOString() })));
            setUnreadCount(0);
        } catch (error) {
            console.error('Failed to mark all as read:', error);
        }
    };

    const formatTime = (dateStr: string) => {
        const date = new Date(dateStr);
        const now = new Date();
        const diffMs = now.getTime() - date.getTime();
        const diffMins = Math.floor(diffMs / 60000);
        const diffHours = Math.floor(diffMs / 3600000);
        const diffDays = Math.floor(diffMs / 86400000);

        if (diffMins < 1) return 'Baru saja';
        if (diffMins < 60) return `${diffMins} menit lalu`;
        if (diffHours < 24) return `${diffHours} jam lalu`;
        if (diffDays < 7) return `${diffDays} hari lalu`;
        return date.toLocaleDateString('id-ID');
    };

    const getNotifStyle = (type: string) => {
        switch (type) {
            case 'check_in':
                return { bg: 'bg-green-50', icon: <UserCheck className="w-5 h-5 text-green-600" />, dot: 'bg-green-500' };
            case 'late':
                return { bg: 'bg-yellow-50', icon: <Clock className="w-5 h-5 text-yellow-600" />, dot: 'bg-yellow-500' };
            case 'absent':
                return { bg: 'bg-red-50', icon: <XCircle className="w-5 h-5 text-red-600" />, dot: 'bg-red-500' };
            case 'alert':
                return { bg: 'bg-orange-50', icon: <AlertTriangle className="w-5 h-5 text-orange-600" />, dot: 'bg-orange-500' };
            default:
                return { bg: 'bg-blue-50', icon: <Bell className="w-5 h-5 text-blue-600" />, dot: 'bg-blue-500' };
        }
    };

    return (
        <div className="relative" ref={dropdownRef}>
            {/* Bell Button with connection indicator */}
            <button
                onClick={() => {
                    setIsOpen(!isOpen);
                    if (!isOpen) fetchNotifications();
                }}
                className="relative p-2 text-slate-600 hover:text-slate-900 hover:bg-slate-100 rounded-lg transition-colors group"
                title="Notifikasi"
            >
                <Bell className="w-5 h-5" />
                {/* Real-time connection dot */}
                {isConnected && (
                    <span className="absolute -top-0.5 -right-0.5 w-2 h-2 bg-green-400 rounded-full animate-pulse" />
                )}
                {/* Unread badge */}
                {unreadCount > 0 && (
                    <span className="absolute -top-0.5 -right-0.5 w-5 h-5 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center ring-2 ring-white">
                        {unreadCount > 99 ? '99+' : unreadCount}
                    </span>
                )}
            </button>

            {/* Dropdown */}
            {isOpen && (
                <div className="absolute right-0 top-full mt-2 w-[28rem] max-w-[calc(100vw-2rem)] bg-white rounded-xl shadow-xl border border-slate-200 z-50 max-h-[520px] flex flex-col">
                    {/* Header */}
                    <div className="px-4 py-3 border-b border-slate-200 flex items-center justify-between bg-gradient-to-r from-blue-50 to-white rounded-t-xl">
                        <div className="flex items-center gap-2">
                            <Volume2 className="w-4 h-4 text-blue-500" />
                            <h3 className="font-semibold text-slate-900">Notifikasi</h3>
                            {unreadCount > 0 && (
                                <span className="px-2 py-0.5 bg-blue-100 text-blue-700 text-xs font-medium rounded-full animate-pulse">
                                    {unreadCount} baru
                                </span>
                            )}
                        </div>
                        <div className="flex items-center gap-1">
                            {isConnected && (
                                <span className="flex items-center gap-1 px-2 py-0.5 bg-green-50 text-green-700 text-[10px] rounded-full">
                                    <span className="w-1.5 h-1.5 bg-green-400 rounded-full animate-pulse" />
                                    LIVE
                                </span>
                            )}
                            {unreadCount > 0 && (
                                <button
                                    onClick={markAllAsRead}
                                    className="p-1.5 text-slate-500 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                                    title="Tandai semua sudah dibaca"
                                >
                                    <CheckCheck className="w-4 h-4" />
                                </button>
                            )}
                            <button
                                onClick={() => setIsOpen(false)}
                                className="p-1.5 text-slate-500 hover:text-slate-700 hover:bg-slate-100 rounded-lg"
                            >
                                <X className="w-4 h-4" />
                            </button>
                        </div>
                    </div>

                    {/* Notification List */}
                    <div className="overflow-y-auto flex-1">
                        {loading && notifications.length === 0 ? (
                            <div className="flex items-center justify-center py-8">
                                <Loader2 className="w-6 h-6 text-blue-600 animate-spin" />
                            </div>
                        ) : notifications.length === 0 ? (
                            <div className="py-12 text-center">
                                <Bell className="w-10 h-10 text-slate-300 mx-auto mb-3" />
                                <p className="text-sm text-slate-500">Belum ada notifikasi</p>
                                <p className="text-xs text-slate-400 mt-1">
                                    Notifikasi akan muncul saat anak Anda melakukan check-in
                                </p>
                            </div>
                        ) : (
                            notifications.map((notif, index) => {
                                const style = getNotifStyle(notif.type || (notif.data as any)?.type || 'info');
                                return (
                                    <div
                                        key={notif.id || index}
                                        className={`px-4 py-3 hover:bg-slate-50 border-b border-slate-100 last:border-0 cursor-pointer transition-all ${
                                            !notif.read_at ? 'bg-gradient-to-r from-blue-50/80 to-white' : ''
                                        }`}
                                        onClick={() => {
                                            if (!notif.read_at) markAsRead(notif.id);
                                        }}
                                    >
                                        <div className="flex gap-3">
                                            {/* Icon */}
                                            <div className={`w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0 ${
                                                !notif.read_at ? style.bg : 'bg-slate-100'
                                            }`}>
                                                {style.icon}
                                            </div>

                                            {/* Content */}
                                            <div className="flex-1 min-w-0">
                                                <div className="flex items-start justify-between gap-2">
                                                    <p className={`text-sm ${
                                                        !notif.read_at ? 'font-semibold text-slate-900' : 'text-slate-700'
                                                    }`}>
                                                        {notif.title}
                                                    </p>
                                                    {!notif.read_at && (
                                                        <span className={`w-2 h-2 ${style.dot} rounded-full flex-shrink-0 mt-1.5`} />
                                                    )}
                                                </div>
                                                <p className="text-xs text-slate-500 mt-0.5 line-clamp-2">
                                                    {notif.message}
                                                </p>
                                                <div className="flex items-center gap-2 mt-1.5">
                                                    <p className="text-[11px] text-slate-400">
                                                        {notif.time_ago || formatTime(notif.created_at)}
                                                    </p>
                                                    {notif.student_name && (
                                                        <>
                                                            <span className="text-[11px] text-slate-300">•</span>
                                                            <span className="text-[11px] text-blue-500 font-medium">
                                                                {notif.student_name}
                                                            </span>
                                                        </>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                );
                            })
                        )}
                    </div>

                    {/* Footer */}
                    {notifications.length > 0 && (
                        <div className="px-4 py-2.5 border-t border-slate-200 bg-slate-50 rounded-b-xl">
                            <button
                                onClick={() => {
                                    setIsOpen(false);
                                    // Navigate to full notification page (if available)
                                }}
                                className="w-full text-center text-xs font-medium text-blue-600 hover:text-blue-700 py-1 rounded-lg hover:bg-blue-50 transition-colors flex items-center justify-center gap-1"
                            >
                                Lihat Semua Notifikasi
                                <ChevronRight className="w-3.5 h-3.5" />
                            </button>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
};

export default ParentNotificationCenter;
