import React, { useEffect, useState, useCallback } from 'react';
import {
    Activity,
    Cpu,
    HardDrive,
    Clock,
    Database,
    Wifi,
    RefreshCw,
    CheckCircle,
    XCircle,
    AlertTriangle,
    Loader2,
    Server,
    Layers
} from 'lucide-react';
import { apiClient } from '../../../lib/api';
import showToast from '../../../utils/toast';

interface HealthCheck {
    status: string;
    details?: Record<string, unknown>;
}

interface SystemHealthData {
    status: 'healthy' | 'degraded' | 'down';
    checks: {
        database?: HealthCheck;
        cache?: HealthCheck;
        queue?: HealthCheck;
        storage?: HealthCheck;
    };
    metrics?: {
        uptime?: string;
        memory_usage?: string;
        cpu_load?: number[];
        disk_usage?: string;
        php_version?: string;
        laravel_version?: string;
    };
}

interface QueueMetrics {
    pending: number;
    processing: number;
    failed: number;
    completed_last_hour: number;
}

export const SystemHealth: React.FC = () => {
    const [health, setHealth] = useState<SystemHealthData | null>(null);
    const [queueMetrics, setQueueMetrics] = useState<QueueMetrics | null>(null);
    const [loading, setLoading] = useState(true);
    const [refreshing, setRefreshing] = useState(false);
    const [lastRefresh, setLastRefresh] = useState<string>('');

    const fetchHealth = useCallback(async (showRefreshing = false) => {
        try {
            if (showRefreshing) setRefreshing(true);
            else setLoading(true);

            const [healthRes, queueRes] = await Promise.allSettled([
                apiClient.get('/super-admin/system/health'),
                apiClient.get('/super-admin/system/metrics/queue'),
            ]);

            if (healthRes.status === 'fulfilled') {
                const data = healthRes.value.data?.data || healthRes.value.data;
                setHealth(data);
            }
            if (queueRes.status === 'fulfilled') {
                const data = queueRes.value.data?.data || queueRes.value.data;
                setQueueMetrics(data);
            }

            setLastRefresh(new Date().toLocaleTimeString('id-ID'));
        } catch (error) {
            console.error('Failed to fetch health:', error);
            showToast.error('Gagal memuat data system health');
        } finally {
            setLoading(false);
            setRefreshing(false);
        }
    }, []);

    useEffect(() => {
        fetchHealth();
        const interval = setInterval(() => fetchHealth(true), 30000); // Auto-refresh every 30s
        return () => clearInterval(interval);
    }, [fetchHealth]);

    const getStatusColor = (status: string) => {
        switch (status) {
            case 'healthy':
            case 'up':
            case 'ok':
                return 'text-green-600 bg-green-50 border-green-200';
            case 'degraded':
            case 'warning':
                return 'text-amber-600 bg-amber-50 border-amber-200';
            case 'down':
            case 'error':
            case 'critical':
                return 'text-red-600 bg-red-50 border-red-200';
            default:
                return 'text-slate-600 bg-slate-50 border-slate-200';
        }
    };

    const getStatusIcon = (status: string) => {
        switch (status) {
            case 'healthy':
            case 'up':
            case 'ok':
                return <CheckCircle className="w-5 h-5 text-green-600" />;
            case 'degraded':
            case 'warning':
                return <AlertTriangle className="w-5 h-5 text-amber-600" />;
            default:
                return <XCircle className="w-5 h-5 text-red-600" />;
        }
    };

    if (loading) {
        return (
            <div className="flex items-center justify-center h-64">
                <div className="text-center">
                    <Loader2 className="w-10 h-10 text-blue-600 animate-spin mx-auto mb-3" />
                    <p className="text-slate-600">Memeriksa kesehatan sistem...</p>
                </div>
            </div>
        );
    }

    return (
        <div className="min-h-screen bg-slate-50 p-6">
            {/* Header */}
            <div className="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 mb-2">System Health</h1>
                    <p className="text-slate-600">Monitor kesehatan dan performa sistem secara real-time</p>
                </div>
                <div className="flex items-center gap-3">
                    {lastRefresh && (
                        <span className="text-xs text-slate-500">
                            Terakhir update: {lastRefresh}
                        </span>
                    )}
                    <button
                        onClick={() => fetchHealth(true)}
                        disabled={refreshing}
                        className="flex items-center gap-2 px-4 py-2 text-sm text-slate-600 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 disabled:opacity-50"
                    >
                        <RefreshCw className={`w-4 h-4 ${refreshing ? 'animate-spin' : ''}`} />
                        Refresh
                    </button>
                </div>
            </div>

            {/* Overall Status Banner */}
            {health && (
                <div className={`p-4 rounded-xl border mb-6 ${getStatusColor(health.status)}`}>
                    <div className="flex items-center gap-3">
                        {getStatusIcon(health.status)}
                        <div>
                            <p className="font-semibold text-lg capitalize">
                                Sistem {health.status === 'healthy' ? 'Sehat' : health.status === 'degraded' ? 'Terdegradasi' : 'Down'}
                            </p>
                            <p className="text-sm opacity-80">
                                {health.status === 'healthy'
                                    ? 'Semua layanan berjalan normal'
                                    : health.status === 'degraded'
                                        ? 'Beberapa layanan mengalami masalah'
                                        : 'Sistem mengalami gangguan kritis'}
                            </p>
                        </div>
                    </div>
                </div>
            )}

            {/* Service Health Checks */}
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                {/* Database */}
                <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                    <div className="flex items-center justify-between mb-3">
                        <div className="flex items-center gap-2">
                            <Database className="w-5 h-5 text-blue-600" />
                            <span className="font-semibold text-slate-900">Database</span>
                        </div>
                        {getStatusIcon(health?.checks?.database?.status || 'unknown')}
                    </div>
                    <p className={`text-sm capitalize ${health?.checks?.database?.status === 'up' ? 'text-green-600' : 'text-red-600'}`}>
                        {health?.checks?.database?.status || 'Unknown'}
                    </p>
                </div>

                {/* Cache */}
                <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                    <div className="flex items-center justify-between mb-3">
                        <div className="flex items-center gap-2">
                            <Layers className="w-5 h-5 text-purple-600" />
                            <span className="font-semibold text-slate-900">Cache</span>
                        </div>
                        {getStatusIcon(health?.checks?.cache?.status || 'unknown')}
                    </div>
                    <p className={`text-sm capitalize ${health?.checks?.cache?.status === 'up' ? 'text-green-600' : 'text-red-600'}`}>
                        {health?.checks?.cache?.status || 'Unknown'}
                    </p>
                </div>

                {/* Queue */}
                <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                    <div className="flex items-center justify-between mb-3">
                        <div className="flex items-center gap-2">
                            <Server className="w-5 h-5 text-orange-600" />
                            <span className="font-semibold text-slate-900">Queue</span>
                        </div>
                        {getStatusIcon(health?.checks?.queue?.status || 'unknown')}
                    </div>
                    <p className={`text-sm capitalize ${health?.checks?.queue?.status === 'up' ? 'text-green-600' : 'text-red-600'}`}>
                        {health?.checks?.queue?.status || 'Unknown'}
                    </p>
                </div>

                {/* Storage */}
                <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
                    <div className="flex items-center justify-between mb-3">
                        <div className="flex items-center gap-2">
                            <HardDrive className="w-5 h-5 text-green-600" />
                            <span className="font-semibold text-slate-900">Storage</span>
                        </div>
                        {getStatusIcon(health?.checks?.storage?.status || 'unknown')}
                    </div>
                    <p className={`text-sm capitalize ${health?.checks?.storage?.status === 'up' ? 'text-green-600' : 'text-red-600'}`}>
                        {health?.checks?.storage?.status || 'Unknown'}
                    </p>
                </div>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* System Metrics */}
                <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                    <div className="flex items-center gap-3 mb-6">
                        <div className="p-3 bg-blue-50 rounded-lg">
                            <Activity className="w-6 h-6 text-blue-600" />
                        </div>
                        <div>
                            <h2 className="text-lg font-bold text-slate-900">System Metrics</h2>
                            <p className="text-sm text-slate-600">Informasi runtime server</p>
                        </div>
                    </div>

                    <div className="space-y-4">
                        <div className="flex items-center justify-between p-3 bg-slate-50 rounded-lg">
                            <div className="flex items-center gap-2">
                                <Clock className="w-4 h-4 text-slate-500" />
                                <span className="text-sm text-slate-700">Uptime</span>
                            </div>
                            <span className="text-sm font-semibold text-slate-900">
                                {health?.metrics?.uptime || '-'}
                            </span>
                        </div>
                        <div className="flex items-center justify-between p-3 bg-slate-50 rounded-lg">
                            <div className="flex items-center gap-2">
                                <Cpu className="w-4 h-4 text-slate-500" />
                                <span className="text-sm text-slate-700">CPU Load</span>
                            </div>
                            <span className="text-sm font-semibold text-slate-900">
                                {health?.metrics?.cpu_load?.join(' / ') || '-'}
                            </span>
                        </div>
                        <div className="flex items-center justify-between p-3 bg-slate-50 rounded-lg">
                            <div className="flex items-center gap-2">
                                <Activity className="w-4 h-4 text-slate-500" />
                                <span className="text-sm text-slate-700">Memory Usage</span>
                            </div>
                            <span className="text-sm font-semibold text-slate-900">
                                {health?.metrics?.memory_usage || '-'}
                            </span>
                        </div>
                        <div className="flex items-center justify-between p-3 bg-slate-50 rounded-lg">
                            <div className="flex items-center gap-2">
                                <HardDrive className="w-4 h-4 text-slate-500" />
                                <span className="text-sm text-slate-700">Disk Usage</span>
                            </div>
                            <span className="text-sm font-semibold text-slate-900">
                                {health?.metrics?.disk_usage || '-'}
                            </span>
                        </div>
                        <div className="flex items-center justify-between p-3 bg-slate-50 rounded-lg">
                            <div className="flex items-center gap-2">
                                <Wifi className="w-4 h-4 text-slate-500" />
                                <span className="text-sm text-slate-700">PHP Version</span>
                            </div>
                            <span className="text-sm font-semibold text-slate-900">
                                {health?.metrics?.php_version || '-'}
                            </span>
                        </div>
                        <div className="flex items-center justify-between p-3 bg-slate-50 rounded-lg">
                            <div className="flex items-center gap-2">
                                <Server className="w-4 h-4 text-slate-500" />
                                <span className="text-sm text-slate-700">Laravel Version</span>
                            </div>
                            <span className="text-sm font-semibold text-slate-900">
                                {health?.metrics?.laravel_version || '-'}
                            </span>
                        </div>
                    </div>
                </div>

                {/* Queue Metrics */}
                <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                    <div className="flex items-center gap-3 mb-6">
                        <div className="p-3 bg-orange-50 rounded-lg">
                            <Server className="w-6 h-6 text-orange-600" />
                        </div>
                        <div>
                            <h2 className="text-lg font-bold text-slate-900">Queue Metrics</h2>
                            <p className="text-sm text-slate-600">Status job queue system</p>
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="p-4 bg-blue-50 rounded-lg text-center">
                            <p className="text-2xl font-bold text-blue-900">
                                {queueMetrics?.pending ?? '-'}
                            </p>
                            <p className="text-xs text-blue-700 mt-1">Pending</p>
                        </div>
                        <div className="p-4 bg-amber-50 rounded-lg text-center">
                            <p className="text-2xl font-bold text-amber-900">
                                {queueMetrics?.processing ?? '-'}
                            </p>
                            <p className="text-xs text-amber-700 mt-1">Processing</p>
                        </div>
                        <div className="p-4 bg-red-50 rounded-lg text-center">
                            <p className="text-2xl font-bold text-red-900">
                                {queueMetrics?.failed ?? '-'}
                            </p>
                            <p className="text-xs text-red-700 mt-1">Failed</p>
                        </div>
                        <div className="p-4 bg-green-50 rounded-lg text-center">
                            <p className="text-2xl font-bold text-green-900">
                                {queueMetrics?.completed_last_hour ?? '-'}
                            </p>
                            <p className="text-xs text-green-700 mt-1">Completed (1h)</p>
                        </div>
                    </div>

                    {queueMetrics && (queueMetrics.failed ?? 0) > 0 && (
                        <div className="mt-4 p-3 bg-red-50 border border-red-200 rounded-lg">
                            <div className="flex items-center gap-2">
                                <AlertTriangle className="w-4 h-4 text-red-600" />
                                <p className="text-sm text-red-800">
                                    Ada {queueMetrics.failed} job yang gagal. Periksa log untuk detail.
                                </p>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
};
