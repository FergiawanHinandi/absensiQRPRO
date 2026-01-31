import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import {
    Shield,
    AlertTriangle,
    Activity,
    Building2,
    Clock,
    CheckCircle2,
    RefreshCw,
    TrendingUp,
    BarChart3,
    AlertCircle,
    ChevronDown,
    MapPin,
} from 'lucide-react';
import {
    LineChart,
    Line,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    ResponsiveContainer,
    BarChart,
    Bar,
    Legend,
} from 'recharts';
import {
    useSecuritySummary,
    useSecurityTrend,
    useSecurityByType,
    useSecurityBySchool,
    useCriticalAlerts,
    useAcknowledgeAlert,
    useBulkAcknowledgeAlerts,
    type CriticalAlert,
} from '../../modules/admin/hooks/useSecurityDashboard';
import Loading from '../../components/common/Loading';
import ErrorMessage from '../../components/common/ErrorMessage';

// Severity colors
const SEVERITY_BG = {
    critical: 'bg-red-100 text-red-700 border-red-200',
    high: 'bg-orange-100 text-orange-700 border-orange-200',
    medium: 'bg-yellow-100 text-yellow-700 border-yellow-200',
    low: 'bg-gray-100 text-gray-600 border-gray-200',
};

const SEVERITY_LABELS: Record<string, string> = {
    critical: 'Kritis',
    high: 'Tinggi',
    medium: 'Sedang',
    low: 'Rendah',
};

const SecurityMonitoring: React.FC = () => {
    const [range, setRange] = useState<'7d' | '30d'>('7d');
    const [selectedAlerts, setSelectedAlerts] = useState<number[]>([]);
    const [autoRefresh, setAutoRefresh] = useState(true);

    // Fetch data
    const { data: summary, isLoading: summaryLoading, error: summaryError, refetch: refetchSummary } = useSecuritySummary();
    const { data: trendData, isLoading: trendLoading } = useSecurityTrend(range);
    const { data: typeData, isLoading: typeLoading } = useSecurityByType(range);
    const { data: schoolData, isLoading: schoolLoading } = useSecurityBySchool(range);
    const { data: criticalAlerts, isLoading: alertsLoading, refetch: refetchAlerts } = useCriticalAlerts(20);

    // Mutations
    const acknowledgeAlert = useAcknowledgeAlert();
    const bulkAcknowledge = useBulkAcknowledgeAlerts();

    // Auto refresh effect
    useEffect(() => {
        if (!autoRefresh) return;
        const interval = setInterval(() => {
            refetchAlerts();
            refetchSummary();
        }, 30000);
        return () => clearInterval(interval);
    }, [autoRefresh, refetchAlerts, refetchSummary]);

    const handleAcknowledge = async (id: number) => {
        await acknowledgeAlert.mutateAsync(id);
    };

    const handleBulkAcknowledge = async () => {
        if (selectedAlerts.length === 0) return;
        await bulkAcknowledge.mutateAsync(selectedAlerts);
        setSelectedAlerts([]);
    };

    const toggleSelectAlert = (id: number) => {
        setSelectedAlerts(prev =>
            prev.includes(id) ? prev.filter(a => a !== id) : [...prev, id]
        );
    };

    const selectAllUnresolved = () => {
        const unresolvedIds = criticalAlerts?.filter(a => !a.is_resolved).map(a => a.id) || [];
        setSelectedAlerts(unresolvedIds);
    };

    if (summaryLoading && !summary) {
        return <Loading text="Memuat Security Dashboard..." />;
    }

    if (summaryError) {
        return (
            <div className="min-h-screen bg-slate-50 flex items-center justify-center p-4">
                <ErrorMessage message="Gagal memuat Security Dashboard" onRetry={() => refetchSummary()} />
            </div>
        );
    }

    return (
        <div className="min-h-screen bg-slate-50 pb-12 font-sans text-slate-900">
            {/* Header */}
            <div className="bg-white border-b border-slate-200 sticky top-0 z-30 shadow-sm backdrop-blur-md bg-white/95">
                <div className="px-6 py-4 flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <div className="p-2 bg-red-50 text-red-600 rounded-lg">
                            <Shield className="w-6 h-6" />
                        </div>
                        <div>
                            <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Security Monitoring</h1>
                            <p className="text-sm text-slate-500">Pemantauan aktivitas keamanan dan anomali sistem</p>
                        </div>
                    </div>

                    <div className="flex items-center gap-3">
                        {/* Teacher Heatmap Link */}
                        <Link
                            to="/admin/teacher-heatmap"
                            className="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium bg-purple-50 text-purple-700 border border-purple-200 hover:bg-purple-100 transition-all"
                        >
                            <MapPin className="w-4 h-4" />
                            Teacher Heatmap
                        </Link>

                        {/* Range Toggle */}
                        <div className="flex items-center bg-slate-100 rounded-lg p-1">
                            <button
                                onClick={() => setRange('7d')}
                                className={`px-3 py-1.5 rounded-md text-sm font-medium transition-all ${range === '7d' ? 'bg-white shadow-sm text-slate-900' : 'text-slate-600 hover:text-slate-900'
                                    }`}
                            >
                                7 Hari
                            </button>
                            <button
                                onClick={() => setRange('30d')}
                                className={`px-3 py-1.5 rounded-md text-sm font-medium transition-all ${range === '30d' ? 'bg-white shadow-sm text-slate-900' : 'text-slate-600 hover:text-slate-900'
                                    }`}
                            >
                                30 Hari
                            </button>
                        </div>

                        {/* Auto Refresh Toggle */}
                        <button
                            onClick={() => setAutoRefresh(!autoRefresh)}
                            className={`flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-all ${autoRefresh
                                ? 'bg-green-50 text-green-700 border border-green-200'
                                : 'bg-slate-100 text-slate-600 border border-slate-200'
                                }`}
                        >
                            <RefreshCw className={`w-4 h-4 ${autoRefresh ? 'animate-spin' : ''}`} />
                            {autoRefresh ? 'Auto Refresh ON' : 'Auto Refresh OFF'}
                        </button>
                    </div>
                </div>
            </div>

            <div className="p-6 max-w-[1800px] mx-auto space-y-6">
                {/* Summary Cards */}
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    {/* Total Alerts 24h */}
                    <div className="p-6 rounded-2xl bg-white border border-slate-200 shadow-sm relative overflow-hidden">
                        <div className="flex items-center justify-between mb-4 relative z-10">
                            <div className="p-2 bg-blue-50 text-blue-600 rounded-lg">
                                <Activity className="w-6 h-6" />
                            </div>
                            <span className="text-xs font-semibold bg-blue-100 text-blue-700 px-2 py-1 rounded-full">24 Jam</span>
                        </div>
                        <div className="relative z-10">
                            <h3 className="text-3xl font-bold text-slate-900">{summary?.alerts_last_24h || 0}</h3>
                            <p className="text-sm text-slate-500 font-medium">Total Alert</p>
                        </div>
                        <div className="absolute right-0 bottom-0 opacity-5 transform translate-x-4 translate-y-4">
                            <Activity className="w-32 h-32 text-blue-600" />
                        </div>
                    </div>

                    {/* Critical Alerts */}
                    <div className="p-6 rounded-2xl bg-white border border-red-200 shadow-sm relative overflow-hidden">
                        <div className="flex items-center justify-between mb-4 relative z-10">
                            <div className="p-2 bg-red-50 text-red-600 rounded-lg">
                                <AlertTriangle className="w-6 h-6" />
                            </div>
                            <span className="text-xs font-semibold bg-red-100 text-red-700 px-2 py-1 rounded-full animate-pulse">Kritis</span>
                        </div>
                        <div className="relative z-10">
                            <h3 className="text-3xl font-bold text-red-600">{summary?.critical_alerts || 0}</h3>
                            <p className="text-sm text-slate-500 font-medium">Alert Kritis</p>
                        </div>
                        <div className="absolute right-0 bottom-0 opacity-5 transform translate-x-4 translate-y-4">
                            <AlertTriangle className="w-32 h-32 text-red-600" />
                        </div>
                    </div>

                    {/* Schools with Alerts */}
                    <div className="p-6 rounded-2xl bg-white border border-slate-200 shadow-sm">
                        <div className="flex items-center justify-between mb-4">
                            <div className="p-2 bg-orange-50 text-orange-600 rounded-lg">
                                <Building2 className="w-6 h-6" />
                            </div>
                            <span className="text-xs font-semibold bg-orange-100 text-orange-700 px-2 py-1 rounded-full">Terpengaruh</span>
                        </div>
                        <div>
                            <h3 className="text-3xl font-bold text-slate-900">{summary?.schools_with_alerts || 0}</h3>
                            <p className="text-sm text-slate-500 font-medium">Sekolah</p>
                        </div>
                    </div>

                    {/* Most Common Threat */}
                    <div className="p-6 rounded-2xl bg-white border border-slate-200 shadow-sm">
                        <div className="flex items-center justify-between mb-4">
                            <div className="p-2 bg-purple-50 text-purple-600 rounded-lg">
                                <TrendingUp className="w-6 h-6" />
                            </div>
                            <span className="text-xs font-semibold bg-purple-100 text-purple-700 px-2 py-1 rounded-full">Terbanyak</span>
                        </div>
                        <div>
                            <h3 className="text-lg font-bold text-slate-900 truncate">
                                {summary?.most_common_event?.replace(/_/g, ' ') || '-'}
                            </h3>
                            <p className="text-sm text-slate-500 font-medium">
                                {summary?.most_common_event_count || 0} kejadian
                            </p>
                        </div>
                    </div>
                </div>

                {/* Charts Section */}
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {/* Trend Chart */}
                    <div className="lg:col-span-2 bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
                        <div className="flex items-center justify-between mb-6">
                            <h3 className="text-lg font-bold text-slate-900 flex items-center gap-2">
                                <TrendingUp className="w-5 h-5 text-blue-600" />
                                Tren Alert ({range === '7d' ? '7 Hari' : '30 Hari'})
                            </h3>
                        </div>
                        <div className="h-[300px] w-full">
                            {trendLoading ? (
                                <div className="h-full flex items-center justify-center text-slate-400">
                                    <RefreshCw className="w-6 h-6 animate-spin" />
                                </div>
                            ) : trendData && trendData.length > 0 ? (
                                <ResponsiveContainer width="100%" height="100%">
                                    <LineChart data={trendData}>
                                        <CartesianGrid strokeDasharray="3 3" vertical={false} />
                                        <XAxis
                                            dataKey="date"
                                            axisLine={false}
                                            tickLine={false}
                                            tick={{ fontSize: 12 }}
                                            tickFormatter={(value) => new Date(value).toLocaleDateString('id', { day: '2-digit', month: 'short' })}
                                        />
                                        <YAxis axisLine={false} tickLine={false} tick={{ fontSize: 12 }} />
                                        <Tooltip
                                            contentStyle={{ borderRadius: '8px', border: 'none', boxShadow: '0 4px 6px -1px rgb(0 0 0 / 0.1)' }}
                                            labelFormatter={(value) => new Date(value).toLocaleDateString('id', { weekday: 'long', day: 'numeric', month: 'long' })}
                                        />
                                        <Legend />
                                        <Line type="monotone" dataKey="total" name="Total" stroke="#3b82f6" strokeWidth={2} dot={{ r: 4 }} />
                                        <Line type="monotone" dataKey="critical" name="Kritis" stroke="#ef4444" strokeWidth={2} dot={{ r: 4 }} />
                                        <Line type="monotone" dataKey="high" name="Tinggi" stroke="#f97316" strokeWidth={2} dot={{ r: 4 }} />
                                    </LineChart>
                                </ResponsiveContainer>
                            ) : (
                                <div className="h-full flex items-center justify-center text-slate-400">
                                    Belum ada data trend.
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Alert Types Distribution */}
                    <div className="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
                        <h3 className="text-lg font-bold text-slate-900 mb-4 flex items-center gap-2">
                            <BarChart3 className="w-5 h-5 text-purple-600" />
                            Distribusi Tipe Alert
                        </h3>
                        <div className="h-[300px]">
                            {typeLoading ? (
                                <div className="h-full flex items-center justify-center text-slate-400">
                                    <RefreshCw className="w-6 h-6 animate-spin" />
                                </div>
                            ) : typeData && typeData.length > 0 ? (
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={typeData.slice(0, 8)} layout="vertical">
                                        <CartesianGrid strokeDasharray="3 3" horizontal={false} />
                                        <XAxis type="number" axisLine={false} tickLine={false} />
                                        <YAxis
                                            type="category"
                                            dataKey="label"
                                            axisLine={false}
                                            tickLine={false}
                                            width={100}
                                            tick={{ fontSize: 11 }}
                                        />
                                        <Tooltip
                                            contentStyle={{ borderRadius: '8px', border: 'none', boxShadow: '0 4px 6px -1px rgb(0 0 0 / 0.1)' }}
                                        />
                                        <Bar dataKey="count" name="Jumlah" fill="#8b5cf6" radius={[0, 4, 4, 0]} />
                                    </BarChart>
                                </ResponsiveContainer>
                            ) : (
                                <div className="h-full flex items-center justify-center text-slate-400">
                                    Belum ada data tipe alert.
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                {/* Schools Table and Critical Feed */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {/* Schools with Most Alerts */}
                    <div className="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                        <div className="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                            <h3 className="font-bold text-slate-900 flex items-center gap-2">
                                <Building2 className="w-5 h-5 text-orange-600" />
                                Sekolah dengan Alert Terbanyak
                            </h3>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full">
                                <thead className="bg-slate-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">Sekolah</th>
                                        <th className="px-4 py-3 text-center text-xs font-semibold text-slate-600 uppercase">Total</th>
                                        <th className="px-4 py-3 text-center text-xs font-semibold text-slate-600 uppercase">Kritis</th>
                                        <th className="px-4 py-3 text-center text-xs font-semibold text-slate-600 uppercase">Unresolved</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {schoolLoading ? (
                                        <tr>
                                            <td colSpan={4} className="px-4 py-8 text-center text-slate-400">
                                                <RefreshCw className="w-5 h-5 animate-spin mx-auto" />
                                            </td>
                                        </tr>
                                    ) : schoolData && schoolData.length > 0 ? (
                                        schoolData.slice(0, 10).map((school) => (
                                            <tr key={school.school_id} className="hover:bg-slate-50 transition-colors">
                                                <td className="px-4 py-3">
                                                    <span className="font-medium text-slate-900">{school.school_name}</span>
                                                </td>
                                                <td className="px-4 py-3 text-center">
                                                    <span className="font-semibold text-slate-700">{school.total_alerts}</span>
                                                </td>
                                                <td className="px-4 py-3 text-center">
                                                    {school.critical > 0 ? (
                                                        <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700">
                                                            {school.critical}
                                                        </span>
                                                    ) : (
                                                        <span className="text-slate-400">0</span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-center">
                                                    {school.unresolved > 0 ? (
                                                        <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-orange-100 text-orange-700">
                                                            {school.unresolved}
                                                        </span>
                                                    ) : (
                                                        <span className="text-green-600">✓</span>
                                                    )}
                                                </td>
                                            </tr>
                                        ))
                                    ) : (
                                        <tr>
                                            <td colSpan={4} className="px-4 py-8 text-center text-slate-400">
                                                Tidak ada data sekolah.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {/* Critical Alert Feed */}
                    <div className="bg-white rounded-2xl border border-red-100 shadow-sm overflow-hidden">
                        <div className="px-6 py-4 border-b border-red-100 bg-red-50/50 flex items-center justify-between">
                            <h3 className="font-bold text-red-700 flex items-center gap-2">
                                <AlertCircle className="w-5 h-5" />
                                Alert Kritis Terbaru
                                {autoRefresh && (
                                    <span className="text-xs bg-red-100 text-red-600 px-2 py-0.5 rounded-full ml-2">
                                        Live
                                    </span>
                                )}
                            </h3>
                            <div className="flex items-center gap-2">
                                {selectedAlerts.length > 0 && (
                                    <button
                                        onClick={handleBulkAcknowledge}
                                        disabled={bulkAcknowledge.isPending}
                                        className="text-xs font-medium bg-green-600 text-white px-3 py-1.5 rounded-lg hover:bg-green-700 transition-colors disabled:opacity-50"
                                    >
                                        {bulkAcknowledge.isPending ? 'Processing...' : `Acknowledge (${selectedAlerts.length})`}
                                    </button>
                                )}
                                <button
                                    onClick={selectAllUnresolved}
                                    className="text-xs font-medium text-red-600 hover:underline"
                                >
                                    Select All
                                </button>
                            </div>
                        </div>
                        <div className="max-h-[400px] overflow-y-auto divide-y divide-red-50">
                            {alertsLoading ? (
                                <div className="p-6 text-center text-slate-400">
                                    <RefreshCw className="w-5 h-5 animate-spin mx-auto" />
                                </div>
                            ) : criticalAlerts && criticalAlerts.length > 0 ? (
                                criticalAlerts.map((alert) => (
                                    <AlertItem
                                        key={alert.id}
                                        alert={alert}
                                        selected={selectedAlerts.includes(alert.id)}
                                        onToggleSelect={() => toggleSelectAlert(alert.id)}
                                        onAcknowledge={() => handleAcknowledge(alert.id)}
                                        isPending={acknowledgeAlert.isPending}
                                    />
                                ))
                            ) : (
                                <div className="p-6 text-center text-slate-500">
                                    ✅ Tidak ada alert kritis saat ini.
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                {/* Unresolved Alert Count Banner */}
                {summary && summary.unresolved_alerts > 0 && (
                    <div className="bg-gradient-to-r from-amber-500 to-orange-500 rounded-2xl p-6 text-white shadow-lg">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-4">
                                <div className="p-3 bg-white/20 rounded-xl">
                                    <AlertTriangle className="w-8 h-8" />
                                </div>
                                <div>
                                    <h3 className="text-xl font-bold">{summary.unresolved_alerts} Alert Belum Ditangani</h3>
                                    <p className="text-white/80 text-sm">Segera review dan tandai sebagai resolved untuk menjaga keamanan sistem.</p>
                                </div>
                            </div>
                            <button
                                onClick={() => selectAllUnresolved()}
                                className="px-6 py-2 bg-white text-orange-600 rounded-lg font-semibold hover:bg-white/90 transition-colors"
                            >
                                Review Semua
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
};

// Alert Item Component
interface AlertItemProps {
    alert: CriticalAlert;
    selected: boolean;
    onToggleSelect: () => void;
    onAcknowledge: () => void;
    isPending: boolean;
}

const AlertItem: React.FC<AlertItemProps> = ({ alert, selected, onToggleSelect, onAcknowledge, isPending }) => {
    const [expanded, setExpanded] = useState(false);

    return (
        <div className={`p-4 transition-colors ${alert.is_resolved ? 'bg-slate-50/50' : 'hover:bg-red-50/50'}`}>
            <div className="flex items-start gap-3">
                {/* Checkbox */}
                {!alert.is_resolved && (
                    <input
                        type="checkbox"
                        checked={selected}
                        onChange={onToggleSelect}
                        className="mt-1 w-4 h-4 text-red-600 rounded border-slate-300 focus:ring-red-500"
                    />
                )}

                {/* Content */}
                <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                        <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium border ${SEVERITY_BG[alert.severity as keyof typeof SEVERITY_BG] || SEVERITY_BG.medium}`}>
                            {SEVERITY_LABELS[alert.severity] || alert.severity}
                        </span>
                        <span className="text-sm font-semibold text-slate-900">{alert.event_label}</span>
                        {alert.is_resolved && (
                            <span className="inline-flex items-center gap-1 text-xs text-green-600">
                                <CheckCircle2 className="w-3 h-3" />
                                Resolved
                            </span>
                        )}
                    </div>

                    <p className="text-sm text-slate-600 mt-1 line-clamp-2">{alert.description}</p>

                    <div className="flex items-center gap-3 mt-2 text-xs text-slate-500">
                        <span className="flex items-center gap-1">
                            <Clock className="w-3 h-3" />
                            {alert.time_ago}
                        </span>
                        <span>•</span>
                        <span>{alert.user_name}</span>
                        <span>•</span>
                        <span>{alert.school_name}</span>
                    </div>

                    {/* Expandable Details */}
                    {expanded && alert.details && (
                        <div className="mt-3 p-3 bg-slate-50 rounded-lg text-xs font-mono text-slate-600 overflow-x-auto">
                            <pre>{JSON.stringify(alert.details, null, 2)}</pre>
                        </div>
                    )}
                </div>

                {/* Actions */}
                <div className="flex items-center gap-2">
                    {alert.details && (
                        <button
                            onClick={() => setExpanded(!expanded)}
                            className="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded transition-colors"
                            title="Lihat detail"
                        >
                            <ChevronDown className={`w-4 h-4 transition-transform ${expanded ? 'rotate-180' : ''}`} />
                        </button>
                    )}

                    {!alert.is_resolved && (
                        <button
                            onClick={onAcknowledge}
                            disabled={isPending}
                            className="p-1.5 text-green-600 hover:text-green-700 hover:bg-green-50 rounded transition-colors disabled:opacity-50"
                            title="Tandai sebagai reviewed"
                        >
                            <CheckCircle2 className="w-4 h-4" />
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
};

export default SecurityMonitoring;
