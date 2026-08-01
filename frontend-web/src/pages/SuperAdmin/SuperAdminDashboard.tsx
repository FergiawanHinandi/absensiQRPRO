import React, { useEffect, useState } from 'react';
import {
    Building2,
    Users,
    Activity,
    TrendingUp,
    FileText,
    Shield,
    Download
} from 'lucide-react';
import { apiClient } from '../../lib/api';
import {
    AreaChart,
    Area,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip,
    ResponsiveContainer,
    PieChart,
    Pie,
    Cell,
    Legend
} from 'recharts';
import { useNavigate } from 'react-router-dom';
import { motion } from 'framer-motion';

interface DashboardStats {
    total_schools: number;
    school_growth: number;
    total_students: number;
    total_teachers: number;
    attendance_today: number;
    revenue_this_month: number;
    package_distribution?: { name: string; value: number; color: string }[];
}

interface RecentSchool {
    id: number;
    name: string;
    students: number;
    status: string;
    created_at: string;
}

interface RecentActivity {
    id: number;
    user: string;
    school: string;
    action: string;
    description: string;
    time: string;
}

export const SuperAdminDashboard: React.FC = () => {
    const navigate = useNavigate();
    const [stats, setStats] = useState<DashboardStats | null>(null);
    const [recentSchools, setRecentSchools] = useState<RecentSchool[]>([]);
    const [attendanceChart, setAttendanceChart] = useState<{ date: string; day: string; count: number }[]>([]);
    const [recentActivities, setRecentActivities] = useState<RecentActivity[]>([]);
    const [packageData, setPackageData] = useState<{ name: string; value: number; color: string }[]>([]);
    const [loading, setLoading] = useState(true);
    const [period, setPeriod] = useState<'today' | 'week' | 'month'>('today');

    const handleNavigation = (path: string) => {
        navigate(path);
    };

    const handlePeriodChange = (newPeriod: 'today' | 'week' | 'month') => {
        setPeriod(newPeriod);
    };

    useEffect(() => {
        fetchDashboardData();
    }, [period]);

    const fetchDashboardData = async () => {
        try {
            const response = await apiClient.get('/super-admin/dashboard/stats', {
                params: { period }
            });
            if (response.data.success) {
                const data = response.data.data;
                setStats(data.stats);
                setRecentSchools(data.recent_schools || []);
                setAttendanceChart(data.attendance_chart || []);
                setRecentActivities(data.recent_activities || []);
                setPackageData(data.package_distribution || [
                    { name: 'Basic', value: 0, color: '#3B82F6' },
                    { name: 'Pro', value: 0, color: '#8B5CF6' },
                    { name: 'Premium', value: 0, color: '#F59E0B' },
                ]);
            }
        } catch (error) {
            if (import.meta.env.DEV) {
                console.error('Failed to fetch dashboard data:', error);
            }
        } finally {
            setLoading(false);
        }
    };

    const formatCurrency = (amount: number) => {
        return new Intl.NumberFormat('id-ID', {
            style: 'currency',
            currency: 'IDR',
            minimumFractionDigits: 0
        }).format(amount);
    };

    const getPeriodLabel = (p: string) => {
        switch (p) {
            case 'today': return 'hari ini';
            case 'week': return 'minggu ini';
            case 'month': return 'bulan ini';
            default: return '';
        }
    };

    if (loading) {
        return (
            <div className="flex items-center justify-center h-96">
                <div className="relative">
                    <div className="w-16 h-16 border-4 border-blue-500/30 border-t-blue-500 rounded-full animate-spin"></div>
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-8">
            {/* Header with Period Filter */}
            <div className="flex items-center justify-between">
                <div>
                    <h2 className="text-3xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-blue-400 to-purple-500">
                        Dashboard Overview
                    </h2>
                    <p className="text-gray-400 mt-2">Monitor platform statistics and activities</p>
                </div>
                <div className="flex items-center gap-2 bg-gray-800 p-1 rounded-lg border border-gray-700">
                    <button
                        onClick={() => handlePeriodChange('today')}
                        className={`px-4 py-2 text-sm font-semibold rounded-md transition-all ${period === 'today'
                            ? 'bg-gradient-to-r from-blue-600 to-blue-500 text-white shadow-lg'
                            : 'text-gray-300 hover:text-white hover:bg-gray-700'
                            }`}
                    >
                        Hari Ini
                    </button>
                    <button
                        onClick={() => handlePeriodChange('week')}
                        className={`px-4 py-2 text-sm font-semibold rounded-md transition-all ${period === 'week'
                            ? 'bg-gradient-to-r from-blue-600 to-blue-500 text-white shadow-lg'
                            : 'text-gray-300 hover:text-white hover:bg-gray-700'
                            }`}
                    >
                        Minggu Ini
                    </button>
                    <button
                        onClick={() => handlePeriodChange('month')}
                        className={`px-4 py-2 text-sm font-semibold rounded-md transition-all ${period === 'month'
                            ? 'bg-gradient-to-r from-blue-600 to-blue-500 text-white shadow-lg'
                            : 'text-gray-300 hover:text-white hover:bg-gray-700'
                            }`}
                    >
                        Bulan Ini
                    </button>
                </div>
            </div>

            {/* Quick Actions */}
            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
                {[
                    { label: 'Aktivasi Sekolah', icon: Building2, path: '/super-admin/schools/activation', color: 'blue' },
                    { label: 'Buat Invoice', icon: FileText, path: '/super-admin/billing/invoices', color: 'purple' },
                    { label: 'Kelola Admin', icon: Users, path: '/super-admin/users/admins', color: 'green' },
                    { label: 'Audit Log', icon: Shield, path: '/super-admin/security/audit', color: 'amber' },
                    { label: 'Export Laporan', icon: Download, path: '/super-admin/reports/attendance', color: 'rose' }
                ].map((action, idx) => (
                    <motion.button
                        key={idx}
                        onClick={() => handleNavigation(action.path)}
                        whileHover={{ scale: 1.05 }}
                        whileTap={{ scale: 0.95 }}
                        className="flex flex-col items-center justify-center p-4 bg-gray-800 border border-gray-700 rounded-xl hover:border-gray-600 transition-all group"
                    >
                        <div className={`w-12 h-12 rounded-full bg-${action.color}-500/20 text-${action.color}-400 flex items-center justify-center mb-3 group-hover:scale-110 transition-transform`}>
                            <action.icon className="w-6 h-6" />
                        </div>
                        <span className="text-sm font-semibold text-gray-200 text-center">{action.label}</span>
                    </motion.button>
                ))}
            </div>

            {/* Stats Cards */}
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                {[
                    {
                        title: 'Sekolah Aktif',
                        value: stats?.total_schools || 0,
                        icon: Building2,
                        subtitle: `+${stats?.school_growth || 0}% pertumbuhan`,
                        gradient: 'from-blue-500 to-blue-600'
                    },
                    {
                        title: 'Total Siswa',
                        value: (stats?.total_students || 0).toLocaleString('id-ID'),
                        icon: Users,
                        subtitle: 'Siswa terdaftar',
                        gradient: 'from-purple-500 to-purple-600'
                    },
                    {
                        title: 'Pendapatan',
                        value: formatCurrency(stats?.revenue_this_month || 0),
                        icon: TrendingUp,
                        subtitle: getPeriodLabel(period),
                        gradient: 'from-green-500 to-green-600',
                        small: true
                    },
                    {
                        title: 'Scan Absensi',
                        value: (stats?.attendance_today || 0).toLocaleString('id-ID'),
                        icon: Activity,
                        subtitle: getPeriodLabel(period),
                        gradient: 'from-orange-500 to-orange-600'
                    }
                ].map((stat, index) => (
                    <motion.div
                        key={index}
                        initial={{ opacity: 0, y: 20 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ delay: index * 0.1 }}
                        whileHover={{ y: -5 }}
                        className="bg-gray-800 rounded-xl p-6 border border-gray-700 shadow-lg hover:border-gray-600 transition-all"
                    >
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="text-gray-400 text-sm font-medium">{stat.title}</p>
                                <p className={`${stat.small ? 'text-xl' : 'text-3xl'} font-bold text-white mt-2`}>
                                    {stat.value}
                                </p>
                                <p className="text-xs text-gray-500 mt-1">{stat.subtitle}</p>
                            </div>
                            <div className={`w-14 h-14 rounded-full bg-gradient-to-br ${stat.gradient} flex items-center justify-center text-white shadow-lg`}>
                                <stat.icon className="w-7 h-7" />
                            </div>
                        </div>
                        <div className="mt-4 h-2 bg-gray-700 rounded-full overflow-hidden">
                            <motion.div
                                className={`h-full bg-gradient-to-r ${stat.gradient}`}
                                initial={{ width: 0 }}
                                animate={{ width: `${(index + 1) * 20 + 15}%` }}
                                transition={{ duration: 1, delay: 0.5 }}
                            />
                        </div>
                    </motion.div>
                ))}
            </div>

            {/* Charts Section */}
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* Attendance Trend Chart */}
                <div className="lg:col-span-2 bg-gray-800 rounded-xl p-6 border border-gray-700 shadow-lg">
                    <h3 className="text-xl font-bold text-white mb-4 flex items-center gap-2">
                        <span className="text-2xl">📈</span>
                        Tren Aktivitas Absensi
                    </h3>
                    <p className="text-sm text-gray-400 mb-6">Grafik jumlah scan harian (7 hari terakhir)</p>
                    <div className="h-80">
                        <ResponsiveContainer width="100%" height="100%">
                            <AreaChart data={attendanceChart}>
                                <defs>
                                    <linearGradient id="colorCount" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="5%" stopColor="#3B82F6" stopOpacity={0.3} />
                                        <stop offset="95%" stopColor="#3B82F6" stopOpacity={0} />
                                    </linearGradient>
                                </defs>
                                <CartesianGrid strokeDasharray="3 3" stroke="#374151" />
                                <XAxis dataKey="day" stroke="#9CA3AF" />
                                <YAxis stroke="#9CA3AF" />
                                <Tooltip
                                    contentStyle={{
                                        backgroundColor: '#1F2937',
                                        border: '1px solid #374151',
                                        borderRadius: '8px',
                                        color: '#E5E7EB'
                                    }}
                                />
                                <Area
                                    type="monotone"
                                    dataKey="count"
                                    stroke="#3B82F6"
                                    strokeWidth={3}
                                    fillOpacity={1}
                                    fill="url(#colorCount)"
                                />
                            </AreaChart>
                        </ResponsiveContainer>
                    </div>
                </div>

                {/* Package Distribution */}
                <div className="bg-gray-800 rounded-xl p-6 border border-gray-700 shadow-lg">
                    <h3 className="text-xl font-bold text-white mb-4 flex items-center gap-2">
                        <span className="text-2xl">📦</span>
                        Distribusi Paket
                    </h3>
                    <div className="h-64">
                        <ResponsiveContainer width="100%" height="100%">
                            <PieChart>
                                <Pie
                                    data={packageData}
                                    cx="50%"
                                    cy="50%"
                                    innerRadius={60}
                                    outerRadius={90}
                                    paddingAngle={2}
                                    dataKey="value"
                                >
                                    {packageData.map((entry, index) => (
                                        <Cell key={`cell-${index}`} fill={entry.color} />
                                    ))}
                                </Pie>
                                <Tooltip
                                    contentStyle={{
                                        backgroundColor: '#1F2937',
                                        border: '1px solid #374151',
                                        borderRadius: '8px',
                                        color: '#E5E7EB'
                                    }}
                                />
                                <Legend
                                    wrapperStyle={{ color: '#9CA3AF' }}
                                    iconType="circle"
                                />
                            </PieChart>
                        </ResponsiveContainer>
                    </div>
                    <div className="mt-4 space-y-2">
                        {packageData.map((pkg, idx) => (
                            <div key={idx} className="flex items-center justify-between text-sm">
                                <div className="flex items-center gap-2">
                                    <div className="w-3 h-3 rounded-full" style={{ backgroundColor: pkg.color }}></div>
                                    <span className="text-gray-300">{pkg.name}</span>
                                </div>
                                <span className="text-white font-semibold">{pkg.value}%</span>
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            {/* Recent Schools & Activities */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Recent Schools */}
                <div className="bg-gray-800 rounded-xl border border-gray-700 shadow-lg overflow-hidden">
                    <div className="p-6 border-b border-gray-700">
                        <h3 className="text-xl font-bold text-white flex items-center gap-2">
                            <span className="text-2xl">🏫</span>
                            Sekolah Terbaru
                        </h3>
                    </div>
                    <div className="divide-y divide-gray-700">
                        {recentSchools.length > 0 ? (
                            recentSchools.map((school) => (
                                <div key={school.id} className="p-4 hover:bg-gray-750 transition-colors">
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <h4 className="font-semibold text-white">{school.name}</h4>
                                            <p className="text-sm text-gray-400">{school.students} siswa</p>
                                        </div>
                                        <span className={`px-3 py-1 rounded-full text-xs font-semibold ${school.status === 'active'
                                            ? 'bg-green-500/20 text-green-300 border border-green-500/30'
                                            : 'bg-gray-700 text-gray-300 border border-gray-600'
                                            }`}>
                                            {school.status === 'active' ? 'Aktif' : 'Pending'}
                                        </span>
                                    </div>
                                </div>
                            ))
                        ) : (
                            <div className="p-8 text-center text-gray-500">
                                Belum ada sekolah terdaftar
                            </div>
                        )}
                    </div>
                </div>

                {/* Recent Activities */}
                <div className="bg-gray-800 rounded-xl border border-gray-700 shadow-lg overflow-hidden">
                    <div className="p-6 border-b border-gray-700">
                        <h3 className="text-xl font-bold text-white flex items-center gap-2">
                            <span className="text-2xl">⚡</span>
                            Aktivitas Terbaru
                        </h3>
                    </div>
                    <div className="divide-y divide-gray-700">
                        {recentActivities.length > 0 ? (
                            recentActivities.map((activity) => (
                                <div key={activity.id} className="p-4 hover:bg-gray-750 transition-colors">
                                    <div className="flex items-start gap-3">
                                        <div className="w-2 h-2 rounded-full bg-blue-500 mt-2"></div>
                                        <div className="flex-1">
                                            <p className="text-sm text-white font-medium">{activity.description}</p>
                                            <p className="text-xs text-gray-400 mt-1">
                                                {activity.user} • {activity.school}
                                            </p>
                                            <p className="text-xs text-gray-500 mt-1">{activity.time}</p>
                                        </div>
                                    </div>
                                </div>
                            ))
                        ) : (
                            <div className="p-8 text-center text-gray-500">
                                Belum ada aktivitas
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
};
