import React, { useEffect, useState } from 'react';
import { Activity, ShieldAlert, BarChart3, Radio, Server, AlertTriangle, CheckCircle } from 'lucide-react';
import { apiClient } from '../../lib/api';

interface TrafficData {
    hour: string;
    requests: number;
    blocked: number;
}

interface TopIp {
    ip: string;
    requests: number;
    status: 'normal' | 'warning' | 'blocked';
}

interface Metrics {
    total_requests: number;
    blocked_requests: number;
    average_latency: string;
    active_ips: number;
}

export const RateLimit: React.FC = () => {
    const [stats, setStats] = useState<{ metrics: Metrics; traffic: TrafficData[]; top_ips: TopIp[] } | null>(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        fetchStats();
    }, []);

    const fetchStats = async () => {
        try {
            setLoading(true);
            const response = await apiClient.get('/super-admin/security/rate-limit');
            if (response.data.success) {
                setStats(response.data.data);
            }
        } catch (error) {
            console.error('Failed to fetch rate limit stats:', error);
        } finally {
            setLoading(false);
        }
    };

    const getMaxRequests = () => {
        if (!stats) return 1000;
        return Math.max(...stats.traffic.map(t => t.requests));
    };

    if (loading) {
        return (
            <div className="flex justify-center items-center min-h-screen bg-slate-50">
                <div className="w-8 h-8 border-4 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
            </div>
        );
    }

    if (!stats) return null;

    return (
        <div className="min-h-screen bg-slate-50 p-6">
            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 mb-2">API Rate Limiting & Traffic</h1>
                <p className="text-slate-600">Monitor penggunaan API dan pembatasan request</p>
            </div>

            {/* Metrics Cards */}
            <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div className="bg-white p-5 rounded-xl shadow-sm border border-slate-200">
                    <div className="flex justify-between items-start">
                        <div>
                            <p className="text-sm font-medium text-slate-500 mb-1">Total Requests (24h)</p>
                            <h3 className="text-2xl font-bold text-slate-900">{stats.metrics.total_requests.toLocaleString()}</h3>
                        </div>
                        <div className="p-2 bg-blue-50 rounded-lg text-blue-600">
                            <Activity className="w-5 h-5" />
                        </div>
                    </div>
                </div>
                <div className="bg-white p-5 rounded-xl shadow-sm border border-slate-200">
                    <div className="flex justify-between items-start">
                        <div>
                            <p className="text-sm font-medium text-slate-500 mb-1">Blocked Requests</p>
                            <h3 className="text-2xl font-bold text-red-600">{stats.metrics.blocked_requests}</h3>
                        </div>
                        <div className="p-2 bg-red-50 rounded-lg text-red-600">
                            <ShieldAlert className="w-5 h-5" />
                        </div>
                    </div>
                </div>
                <div className="bg-white p-5 rounded-xl shadow-sm border border-slate-200">
                    <div className="flex justify-between items-start">
                        <div>
                            <p className="text-sm font-medium text-slate-500 mb-1">Avg Latency</p>
                            <h3 className="text-2xl font-bold text-slate-900">{stats.metrics.average_latency}</h3>
                        </div>
                        <div className="p-2 bg-green-50 rounded-lg text-green-600">
                            <Server className="w-5 h-5" />
                        </div>
                    </div>
                </div>
                <div className="bg-white p-5 rounded-xl shadow-sm border border-slate-200">
                    <div className="flex justify-between items-start">
                        <div>
                            <p className="text-sm font-medium text-slate-500 mb-1">Active IPs</p>
                            <h3 className="text-2xl font-bold text-slate-900">{stats.metrics.active_ips}</h3>
                        </div>
                        <div className="p-2 bg-purple-50 rounded-lg text-purple-600">
                            <Radio className="w-5 h-5" />
                        </div>
                    </div>
                </div>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* Traffic Chart (Simplified CSS Bar Chart) */}
                <div className="lg:col-span-2 bg-white p-6 rounded-xl shadow-sm border border-slate-200">
                    <div className="flex items-center justify-between mb-6">
                        <h3 className="font-bold text-slate-800 flex items-center gap-2">
                            <BarChart3 className="w-5 h-5 text-slate-500" />
                            Hourly Traffic Volume
                        </h3>
                    </div>
                    <div className="h-64 flex items-end justify-between gap-1">
                        {stats.traffic.map((item, index) => {
                            const height = (item.requests / getMaxRequests()) * 100;
                            return (
                                <div key={index} className="flex-1 flex flex-col items-center group">
                                    <div
                                        className="w-full bg-blue-500 rounded-t-sm hover:bg-blue-600 transition-all relative"
                                        style={{ height: `${height}%` }}
                                    >
                                        <div className="absolute bottom-full mb-2 left-1/2 -translate-x-1/2 bg-slate-800 text-white text-xs py-1 px-2 rounded opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap z-10">
                                            {item.requests} reqs
                                        </div>
                                    </div>
                                    <span className="text-[10px] text-slate-400 mt-2 rotate-0 truncate w-full text-center">
                                        {index % 4 === 0 ? item.hour : ''}
                                    </span>
                                </div>
                            );
                        })}
                    </div>
                </div>

                {/* Top IPs */}
                <div className="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
                    <h3 className="font-bold text-slate-800 mb-4">Top Active IPs</h3>
                    <div className="space-y-4">
                        {stats.top_ips.map((ip, index) => (
                            <div key={index} className="flex items-center justify-between p-3 bg-slate-50 rounded-lg">
                                <div>
                                    <p className="font-mono text-sm font-medium text-slate-700">{ip.ip}</p>
                                    <p className="text-xs text-slate-500">{ip.requests.toLocaleString()} requests</p>
                                </div>
                                <div>
                                    {ip.status === 'normal' && <CheckCircle className="w-5 h-5 text-green-500" />}
                                    {ip.status === 'warning' && <AlertTriangle className="w-5 h-5 text-amber-500" />}
                                    {ip.status === 'blocked' && <ShieldAlert className="w-5 h-5 text-red-500" />}
                                </div>
                            </div>
                        ))}
                    </div>
                    <button className="w-full mt-4 text-sm text-blue-600 font-medium hover:underline">
                        View All IPs
                    </button>
                </div>
            </div>
        </div>
    );
};
