import { useState, useCallback } from 'react';
import { useRealtime, useRealtimeChannel } from '../../hooks/useRealtime';
import { useAuthStore } from '../../modules/auth/stores/useAuthStore';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../../lib/api';
import { Clock, CheckCircle2, AlertCircle, XCircle, UserCheck, Activity, Loader2, Wifi, WifiOff } from 'lucide-react';

interface AttendanceEvent {
    id: number;
    student_id: number;
    student_name: string;
    class_name: string;
    status: 'present' | 'late' | 'sick' | 'permit' | 'alpha';
    check_in_time: string;
}

export const LiveAttendanceFeed = () => {
    const { user } = useAuthStore();
    const schoolId = user?.school_id;
    const { isConnected } = useRealtime({ enabled: !!schoolId });
    const [feeds, setFeeds] = useState<AttendanceEvent[]>([]);

    // Fetch recent attendances on mount
    const { isLoading } = useQuery({
        queryKey: ['live-feed-initial', schoolId],
        queryFn: async () => {
            const response = await apiClient.get<AttendanceEvent[]>('/attendance/recent', {
                params: { limit: 8 }
            });
            const data = response.data ?? response;
            if (Array.isArray(data)) {
                setFeeds(data.slice(0, 8));
            }
            return data;
        },
        enabled: !!schoolId,
        staleTime: 30000,
    });

    // Real-time subscription via WebSocket
    useRealtimeChannel<AttendanceEvent>({
        channelName: schoolId ? `school.${schoolId}` : '',
        eventName: 'StudentAttended',
        enabled: !!schoolId,
        handler: useCallback((event) => {
            if (import.meta.env.DEV) {
                console.debug('[LiveFeed] Real-time attendance:', event);
            }
            setFeeds(prev => {
                if (prev.some(item => item.id === event.id)) return prev;
                return [event, ...prev].slice(0, 8);
            });
        }, []),
    });

    const getStatusStyle = (status: string) => {
        switch (status) {
            case 'present':
                return { bg: 'bg-emerald-100', text: 'text-emerald-700', icon: <CheckCircle2 className="w-4 h-4" /> };
            case 'late':
                return { bg: 'bg-amber-100', text: 'text-amber-700', icon: <AlertCircle className="w-4 h-4" /> };
            case 'sick':
            case 'permit':
                return { bg: 'bg-blue-100', text: 'text-blue-700', icon: <Activity className="w-4 h-4" /> };
            default:
                return { bg: 'bg-red-100', text: 'text-red-700', icon: <XCircle className="w-4 h-4" /> };
        }
    };

    return (
        <div className="bg-white p-5 rounded-2xl shadow-sm border border-slate-200 h-full flex flex-col">
            <div className="flex justify-between items-center mb-4">
                <h3 className="font-bold text-slate-800 flex items-center gap-2">
                    <UserCheck className="w-5 h-5 text-indigo-600" />
                    Live Feed Absensi
                </h3>
                <div className="flex items-center gap-1.5">
                    {isConnected ? (
                        <>
                            <span className="relative flex h-2.5 w-2.5">
                                <span className="animate-ping absolute inline-flex h-full w-full rounded-full opacity-75 bg-green-400" />
                                <span className="relative inline-flex rounded-full h-2.5 w-2.5 bg-green-500" />
                            </span>
                            <span className="text-xs font-medium text-green-600">LIVE</span>
                        </>
                    ) : (
                        <>
                            <WifiOff className="w-3 h-3 text-slate-400" />
                            <span className="text-xs font-medium text-slate-400">Polling</span>
                        </>
                    )}
                </div>
            </div>

            <div className="flex-1 overflow-y-auto space-y-3 pr-1 custom-scrollbar">
                {isLoading && feeds.length === 0 ? (
                    <div className="text-center py-10 text-slate-400 flex flex-col items-center">
                        <Loader2 className="w-6 h-6 animate-spin mb-3 text-blue-400" />
                        <p className="text-sm">Memuat data terbaru...</p>
                    </div>
                ) : feeds.length === 0 ? (
                    <div className="text-center py-10 text-slate-400 flex flex-col items-center">
                        <div className="bg-slate-50 p-3 rounded-full mb-3">
                            <Clock className="w-6 h-6 text-slate-300" />
                        </div>
                        <p className="text-sm">Menunggu data absensi masuk...</p>
                        <p className="text-xs text-slate-300 mt-1">Data akan muncul secara real-time</p>
                    </div>
                ) : (
                    feeds.map((item) => {
                        const style = getStatusStyle(item.status);
                        return (
                            <div
                                key={item.id}
                                className="flex items-center gap-3 p-3 bg-white hover:bg-slate-50 rounded-xl border border-slate-100 shadow-sm transition-all duration-300 animate-in slide-in-from-top-2 fade-in fill-mode-backwards"
                            >
                                <div className={`p-2.5 rounded-full ${style.bg} ${style.text} shrink-0`}>
                                    {style.icon}
                                </div>

                                <div className="flex-1 min-w-0">
                                    <h4 className="font-bold text-slate-800 text-sm truncate">{item.student_name}</h4>
                                    <p className="text-xs text-slate-500">{item.class_name}</p>
                                </div>

                                <div className="text-right shrink-0">
                                    <div className="font-mono font-bold text-slate-700 text-sm">{item.check_in_time}</div>
                                    <span className={`text-[10px] uppercase font-bold px-1.5 py-0.5 rounded ${style.bg} ${style.text}`}>
                                        {item.status === 'present' ? 'Hadir' : 
                                         item.status === 'late' ? 'Telat' : 
                                         item.status === 'sick' ? 'Sakit' : 
                                         item.status === 'permit' ? 'Izin' : 'Alpha'}
                                    </span>
                                </div>
                            </div>
                        );
                    })
                )}
            </div>
        </div>
    );
};
