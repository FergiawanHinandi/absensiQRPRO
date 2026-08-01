import React, { useState, useEffect } from 'react';
import { History, Monitor, MapPin, Clock } from 'lucide-react';
import { apiClient } from '../../../lib/api';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';

interface LoginEntry {
    id: number;
    ip_address: string;
    device: string;
    browser: string;
    location: string;
    login_at: string;
    is_current: boolean;
}

const TeacherLoginHistory: React.FC = () => {
    const [logins, setLogins] = useState<LoginEntry[]>([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        fetchLoginHistory();
    }, []);

    const fetchLoginHistory = async () => {
        try {
            const response = await apiClient.get('/teacher/profile/login-history');
            setLogins(response.data.data || []);
        } catch (error) {
            if (import.meta.env.DEV) {
                console.error('Failed to fetch login history:', error);
            }
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="max-w-2xl mx-auto">
            <div className="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
                <div className="flex items-center gap-3 mb-6">
                    <div className="w-10 h-10 rounded-full bg-purple-100 flex items-center justify-center">
                        <History className="w-5 h-5 text-purple-600" />
                    </div>
                    <div>
                        <h1 className="text-lg font-bold text-slate-900">Riwayat Login</h1>
                        <p className="text-sm text-slate-500">Aktivitas login akun Anda</p>
                    </div>
                </div>

                {loading ? (
                    <div className="text-center py-8 text-slate-500">Memuat data...</div>
                ) : logins.length === 0 ? (
                    <div className="text-center py-8 text-slate-500">Belum ada riwayat login</div>
                ) : (
                    <div className="space-y-3">
                        {logins.map((login) => (
                            <div key={login.id} className={`p-4 rounded-xl border ${login.is_current ? 'bg-blue-50 border-blue-200' : 'bg-white border-slate-200'}`}>
                                <div className="flex items-start justify-between">
                                    <div className="flex items-start gap-3">
                                        <div className={`w-10 h-10 rounded-lg flex items-center justify-center ${login.is_current ? 'bg-blue-100 text-blue-600' : 'bg-slate-100 text-slate-500'}`}>
                                            <Monitor className="w-5 h-5" />
                                        </div>
                                        <div>
                                            <p className="font-medium text-slate-900 text-sm">{login.browser}</p>
                                            <p className="text-xs text-slate-500">{login.device}</p>
                                            <div className="flex items-center gap-3 mt-1.5 text-xs text-slate-400">
                                                <span className="flex items-center gap-1"><MapPin className="w-3 h-3" /> {login.location || 'Unknown'}</span>
                                                <span className="flex items-center gap-1"><Clock className="w-3 h-3" /> {format(new Date(login.login_at), 'dd MMM yyyy, HH:mm', { locale: id })}</span>
                                            </div>
                                        </div>
                                    </div>
                                    {login.is_current && (
                                        <span className="px-2 py-0.5 bg-blue-100 text-blue-700 rounded text-xs font-medium">Sekarang</span>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
};

export default TeacherLoginHistory;
