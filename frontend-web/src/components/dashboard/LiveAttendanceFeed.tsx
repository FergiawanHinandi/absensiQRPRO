import { useEffect, useState } from 'react';
import echo from '../../lib/echo';
import { useAuthStore } from '../../modules/auth/stores/useAuthStore';
import { Clock, CheckCircle2, AlertCircle, XCircle, UserCheck, Activity } from 'lucide-react';

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
    const [feeds, setFeeds] = useState<AttendanceEvent[]>([]);
    const [isConnected, setIsConnected] = useState(false);

    useEffect(() => {
        if (!user?.school_id) return;

        // Subscribe to channel
        const channel = echo.private(`school.${user.school_id}`);

        channel.listen('StudentAttended', (e: AttendanceEvent) => {
            console.log('Real-time Attendance:', e);
            setFeeds(prev => {
                // Prevent duplicates based on ID
                if (prev.some(item => item.id === e.id)) return prev;
                return [e, ...prev].slice(0, 8); // Keep last 8 items
            });
        });

        // Monitoring connection status (basic)
        echo.connector.pusher.connection.bind('connected', () => setIsConnected(true));
        echo.connector.pusher.connection.bind('disconnected', () => setIsConnected(false));
        // Initial state
        if (echo.connector.pusher.connection.state === 'connected') {
            setIsConnected(true);
        }

        return () => {
            channel.stopListening('StudentAttended');
            echo.leave(`school.${user.school_id}`);
        };
    }, [user?.school_id]);

    const getStatusStyle = (status: string) => {
        switch (status) {
            case 'present':
                return { bg: 'bg-green-100', text: 'text-green-700', icon: <CheckCircle2 className="w-4 h-4" /> };
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
                    <span className={`relative flex h-2.5 w-2.5`}>
                        <span className={`animate-ping absolute inline-flex h-full w-full rounded-full opacity-75 ${isConnected ? 'bg-green-400' : 'bg-red-400'}`}></span>
                        <span className={`relative inline-flex rounded-full h-2.5 w-2.5 ${isConnected ? 'bg-green-500' : 'bg-red-500'}`}></span>
                    </span>
                    <span className="text-xs font-medium text-slate-500">
                        {isConnected ? 'LIVE' : 'OFFLINE'}
                    </span>
                </div>
            </div>

            <div className="flex-1 overflow-y-auto space-y-3 pr-1 custom-scrollbar">
                {feeds.length === 0 ? (
                    <div className="text-center py-10 text-slate-400 flex flex-col items-center">
                        <div className="bg-slate-50 p-3 rounded-full mb-3">
                            <Clock className="w-6 h-6 text-slate-300" />
                        </div>
                        <p className="text-sm">Menunggu data absensi masuk...</p>
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
                                        {item.status}
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
