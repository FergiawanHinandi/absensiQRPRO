import React, { useEffect, useState } from 'react';
import {
    Activity,
    Search,
    Filter,
    Calendar,
    User,
    MapPin,
    Clock,
    Download,
} from 'lucide-react';
import { apiClient } from '../../lib/api';

interface ActivityLog {
    id: number;
    user_id: number;
    user_name?: string;
    school_name?: string;
    action: string;
    description: string;
    ip_address: string;
    created_at: string;
}

export const ActivityLogs: React.FC = () => {
    const [logs, setLogs] = useState<ActivityLog[]>([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [filterAction, setFilterAction] = useState<string>('all');

    useEffect(() => {
        fetchLogs();
    }, [search, filterAction]);

    const fetchLogs = async () => {
        try {
            setLoading(true);
            const response = await apiClient.get('/super-admin/users/activity-logs', {
                params: {
                    search: search || undefined,
                    action: filterAction !== 'all' ? filterAction : undefined,
                },
            });
            if (response.data.success) {
                setLogs(response.data.data.data);
            }
        } catch (error) {
            console.error('Failed to fetch logs:', error);
        } finally {
            setLoading(false);
        }
    };

    const getActionBadge = (action: string) => {
        const colors: Record<string, string> = {
            login: 'bg-green-100 text-green-800',
            logout: 'bg-slate-100 text-slate-800',
            update_profile: 'bg-blue-100 text-blue-800',
            create: 'bg-purple-100 text-purple-800',
            delete: 'bg-red-100 text-red-800',
            update: 'bg-yellow-100 text-yellow-800',
        };

        return (
            <span className={`px-3 py-1 rounded-full text-xs font-semibold ${colors[action] || 'bg-gray-100 text-gray-800'}`}>
                {action.replace(/_/g, ' ').toUpperCase()}
            </span>
        );
    };

    const getActionIcon = (action: string) => {
        const iconClass = "w-5 h-5";

        switch (action) {
            case 'login':
                return <User className={`${iconClass} text-green-600`} />;
            case 'logout':
                return <User className={`${iconClass} text-slate-600`} />;
            case 'update_profile':
            case 'update':
                return <Activity className={`${iconClass} text-blue-600`} />;
            case 'create':
                return <Activity className={`${iconClass} text-purple-600`} />;
            case 'delete':
                return <Activity className={`${iconClass} text-red-600`} />;
            default:
                return <Activity className={`${iconClass} text-slate-600`} />;
        }
    };

    return (
        <div className="min-h-screen bg-slate-50 p-6">
            {/* Header */}
            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 mb-2">Log Aktivitas</h1>
                <p className="text-slate-600">Monitor semua aktivitas admin sekolah dalam platform</p>
            </div>

            {/* Filters */}
            <div className="bg-white rounded-lg shadow-sm border border-slate-200 p-4 mb-6">
                <div className="flex flex-col md:flex-row gap-4">
                    {/* Search */}
                    <div className="flex-1 relative">
                        <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-slate-400" />
                        <input
                            type="text"
                            placeholder="Cari user, aksi, atau deskripsi..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="w-full pl-10 pr-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        />
                    </div>

                    {/* Action Filter */}
                    <div className="flex items-center gap-2">
                        <Filter className="w-5 h-5 text-slate-500" />
                        <select
                            value={filterAction}
                            onChange={(e) => setFilterAction(e.target.value)}
                            className="px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        >
                            <option value="all">Semua Aksi</option>
                            <option value="login">Login</option>
                            <option value="logout">Logout</option>
                            <option value="update_profile">Update Profile</option>
                            <option value="create">Create</option>
                            <option value="update">Update</option>
                            <option value="delete">Delete</option>
                        </select>
                    </div>

                    {/* Export Button */}
                    <button className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                        <Download className="w-4 h-4" />
                        <span>Export</span>
                    </button>
                </div>
            </div>

            {/* Stats Cards */}
            <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div className="bg-white p-4 rounded-xl shadow-sm border border-slate-200">
                    <div className="flex items-center gap-3">
                        <div className="p-3 bg-green-50 rounded-lg">
                            <User className="w-5 h-5 text-green-600" />
                        </div>
                        <div>
                            <p className="text-sm text-slate-600">Total Login</p>
                            <p className="text-xl font-bold text-slate-900">
                                {logs.filter(l => l.action === 'login').length}
                            </p>
                        </div>
                    </div>
                </div>

                <div className="bg-white p-4 rounded-xl shadow-sm border border-slate-200">
                    <div className="flex items-center gap-3">
                        <div className="p-3 bg-blue-50 rounded-lg">
                            <Activity className="w-5 h-5 text-blue-600" />
                        </div>
                        <div>
                            <p className="text-sm text-slate-600">Total Aktivitas</p>
                            <p className="text-xl font-bold text-slate-900">{logs.length}</p>
                        </div>
                    </div>
                </div>

                <div className="bg-white p-4 rounded-xl shadow-sm border border-slate-200">
                    <div className="flex items-center gap-3">
                        <div className="p-3 bg-purple-50 rounded-lg">
                            <User className="w-5 h-5 text-purple-600" />
                        </div>
                        <div>
                            <p className="text-sm text-slate-600">User Aktif</p>
                            <p className="text-xl font-bold text-slate-900">
                                {new Set(logs.map(l => l.user_id)).size}
                            </p>
                        </div>
                    </div>
                </div>

                <div className="bg-white p-4 rounded-xl shadow-sm border border-slate-200">
                    <div className="flex items-center gap-3">
                        <div className="p-3 bg-amber-50 rounded-lg">
                            <Clock className="w-5 h-5 text-amber-600" />
                        </div>
                        <div>
                            <p className="text-sm text-slate-600">Hari Ini</p>
                            <p className="text-xl font-bold text-slate-900">
                                {logs.filter(l =>
                                    new Date(l.created_at).toDateString() === new Date().toDateString()
                                ).length}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            {/* Activity Timeline */}
            <div className="bg-white rounded-lg shadow-sm border border-slate-200">
                <div className="p-6 border-b border-slate-200">
                    <h2 className="text-lg font-bold text-slate-900">Timeline Aktivitas</h2>
                </div>

                <div className="divide-y divide-slate-200">
                    {loading ? (
                        <div className="flex justify-center py-12">
                            <div className="w-8 h-8 border-4 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
                        </div>
                    ) : logs.length === 0 ? (
                        <div className="p-12 text-center text-slate-500">
                            Tidak ada log aktivitas
                        </div>
                    ) : (
                        logs.map((log) => (
                            <div key={log.id} className="p-6 hover:bg-slate-50 transition-colors">
                                <div className="flex items-start gap-4">
                                    {/* Icon */}
                                    <div className="flex-shrink-0 w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center">
                                        {getActionIcon(log.action)}
                                    </div>

                                    {/* Content */}
                                    <div className="flex-1 min-w-0">
                                        <div className="flex items-start justify-between gap-4 mb-2">
                                            <div className="flex-1">
                                                <div className="flex items-center gap-2 mb-1">
                                                    <h3 className="font-semibold text-slate-900">
                                                        {log.user_name || `User #${log.user_id}`}
                                                    </h3>
                                                    {getActionBadge(log.action)}
                                                </div>
                                                <p className="text-sm text-slate-600">{log.description}</p>
                                            </div>
                                            <span className="text-xs text-slate-500 whitespace-nowrap">
                                                {new Date(log.created_at).toLocaleString('id-ID')}
                                            </span>
                                        </div>

                                        {/* Meta Info */}
                                        <div className="flex flex-wrap gap-4 text-xs text-slate-500">
                                            {log.school_name && (
                                                <div className="flex items-center gap-1">
                                                    <User className="w-3 h-3" />
                                                    <span>{log.school_name}</span>
                                                </div>
                                            )}
                                            <div className="flex items-center gap-1">
                                                <MapPin className="w-3 h-3" />
                                                <span>IP: {log.ip_address}</span>
                                            </div>
                                            <div className="flex items-center gap-1">
                                                <Calendar className="w-3 h-3" />
                                                <span>{new Date(log.created_at).toLocaleDateString('id-ID')}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        ))
                    )}
                </div>
            </div>
        </div>
    );
};
