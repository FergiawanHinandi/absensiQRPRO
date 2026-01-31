import React, { useEffect, useState } from 'react';
import { X, Info, AlertTriangle, AlertCircle, CheckCircle } from 'lucide-react';
import { apiClient } from '../lib/api';

interface Announcement {
    id: number;
    title: string;
    content: string;
    type: 'info' | 'warning' | 'critical' | 'success';
    created_at: string;
}

const typeConfig = {
    info: {
        icon: Info,
        bgColor: 'bg-blue-50',
        borderColor: 'border-blue-200',
        textColor: 'text-blue-700',
        iconColor: 'text-blue-500'
    },
    warning: {
        icon: AlertTriangle,
        bgColor: 'bg-amber-50',
        borderColor: 'border-amber-200',
        textColor: 'text-amber-700',
        iconColor: 'text-amber-500'
    },
    critical: {
        icon: AlertCircle,
        bgColor: 'bg-red-50',
        borderColor: 'border-red-200',
        textColor: 'text-red-700',
        iconColor: 'text-red-500'
    },
    success: {
        icon: CheckCircle,
        bgColor: 'bg-green-50',
        borderColor: 'border-green-200',
        textColor: 'text-green-700',
        iconColor: 'text-green-500'
    }
};

export const AnnouncementWidget: React.FC = () => {
    const [announcements, setAnnouncements] = useState<Announcement[]>([]);
    const [dismissedIds, setDismissedIds] = useState<number[]>([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        fetchAnnouncements();
        // Load dismissed IDs from localStorage
        const dismissed = localStorage.getItem('dismissedAnnouncements');
        if (dismissed) {
            setDismissedIds(JSON.parse(dismissed));
        }
    }, []);

    const fetchAnnouncements = async () => {
        try {
            const response = await apiClient.get('/broadcasts');
            if (response.data.success) {
                setAnnouncements(response.data.data);
            }
        } catch (error) {
            console.error('Failed to fetch announcements:', error);
        } finally {
            setLoading(false);
        }
    };

    const handleDismiss = (id: number) => {
        const newDismissed = [...dismissedIds, id];
        setDismissedIds(newDismissed);
        localStorage.setItem('dismissedAnnouncements', JSON.stringify(newDismissed));
    };

    const visibleAnnouncements = announcements.filter(a => !dismissedIds.includes(a.id));

    if (loading || visibleAnnouncements.length === 0) {
        return null;
    }

    return (
        <div className="space-y-3 mb-6">
            {visibleAnnouncements.map((announcement) => {
                const config = typeConfig[announcement.type];
                const Icon = config.icon;

                return (
                    <div
                        key={announcement.id}
                        className={`${config.bgColor} ${config.borderColor} border rounded-lg p-4 shadow-sm animate-in fade-in slide-in-from-top duration-300`}
                    >
                        <div className="flex items-start gap-3">
                            <div className={`${config.iconColor} mt-0.5`}>
                                <Icon className="w-5 h-5" />
                            </div>
                            <div className="flex-1 min-w-0">
                                <h4 className={`font-semibold ${config.textColor} mb-1`}>
                                    {announcement.title}
                                </h4>
                                <p className={`text-sm ${config.textColor} opacity-90`}>
                                    {announcement.content}
                                </p>
                                <p className="text-xs text-slate-500 mt-2">
                                    {new Date(announcement.created_at).toLocaleString('id-ID', {
                                        day: 'numeric',
                                        month: 'long',
                                        year: 'numeric',
                                        hour: '2-digit',
                                        minute: '2-digit'
                                    })}
                                </p>
                            </div>
                            <button
                                onClick={() => handleDismiss(announcement.id)}
                                className={`${config.textColor} hover:opacity-70 transition-opacity`}
                                title="Tutup"
                            >
                                <X className="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                );
            })}
        </div>
    );
};
