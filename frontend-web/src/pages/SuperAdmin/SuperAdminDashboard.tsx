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

interface DashboardStats {
    total_schools: number;
    school_growth: number;
    total_students: number;
    total_teachers: number;
    attendance_today: number;
    revenue_this_month: number;
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
    const [loading, setLoading] = useState(true);
    const [period, setPeriod] = useState<'today' | 'week' | 'month'>('today');

    // Debug function untuk tracking clicks
    const handleNavigation = (path: string, label: string) => {
        console.log(`[NAVIGATION DEBUG] Attempting to navigate to: ${path} (${label})`);
        try {
            navigate(path);
            console.log(`[NAVIGATION DEBUG] Navigation successful to: ${path}`);
        } catch (error) {
            console.error(`[NAVIGATION DEBUG] Navigation failed to: ${path}`, error);
            // Fallback: manual window location change
            window.location.href = path;
        }
    };

    // Debug function untuk period changes
    const handlePeriodChange = (newPeriod: 'today' | 'week' | 'month') => {
        console.log(`[PERIOD DEBUG] Changing period from ${period} to ${newPeriod}`);
        setPeriod(newPeriod);
    };


    // Mock Data for Package Distribution (until backend supports it)
    const packageData = [
        { name: 'Basic', value: 30, color: '#3B82F6' },
        { name: 'Pro', value: 45, color: '#8B5CF6' },
        { name: 'Premium', value: 25, color: '#F59E0B' },
    ];

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
                // setSchoolsByLevel removed since state variable was removed
                setRecentActivities(data.recent_activities || []);
            }
        } catch (error) {
            console.error('Failed to fetch dashboard data:', error);
            // Set default empty data on error
            setStats({
                total_schools: 0,
                school_growth: 0,
                total_students: 0,
                total_teachers: 0,
                attendance_today: 0,
                revenue_this_month: 0
            });
            setRecentSchools([]);
            setAttendanceChart([]);
            setRecentActivities([]);
        } finally {
            setLoading(false);
        }
    };

    const formatCurrency = (amount: number) => {
        return new Intl.NumberFormat('id-ID', {
            style: 'currency',
            currency: 'IDR',
            minimumFractionDigits: 0,
            maximumFractionDigits: 0,
        }).format(amount);
    };

    if (loading && !stats) {
        return (
            <div className="flex items-center justify-center h-screen bg-slate-50">
                <div className="text-center">
                    <div className="w-16 h-16 border-4 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto mb-4"></div>
                    <p className="text-slate-600 font-medium">Memuat Dashboard...</p>
                </div>
            </div>
        );
    }

    const getPeriodLabel = (p: string) => {
        switch (p) {
            case 'today': return 'Hari Ini';
            case 'week': return 'Minggu Ini';
            case 'month': return 'Bulan Ini';
            default: return '';
        }
    };

    return (
        <div className="min-h-screen bg-slate-50 pb-12 font-sans">
            {/* Page Header (Simpler, standard style) */}
            <div className="bg-white border-b border-slate-200 px-6 py-6 sm:flex sm:items-center sm:justify-between sticky top-0 z-30 shadow-sm/50">
                <div>
                    <h1 className="text-2xl font-bold text-slate-800 tracking-tight">Ringkasan Dashboard</h1>
                    <p className="text-sm text-slate-500 mt-1">Selamat datang kembali, Super Administrator</p>
                </div>

                {/* Period Filter */}
                <div className="flex items-center gap-2 mt-4 sm:mt-0 bg-slate-100 p-1 rounded-lg border border-slate-200">
                    <button
                        onClick={(e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            handlePeriodChange('today');
                        }}
                        className={`px-3 py-1.5 text-xs font-semibold rounded-md transition-all cursor-pointer ${period === 'today'
                            ? 'bg-white text-blue-700 shadow-sm'
                            : 'text-slate-500 hover:text-slate-700 hover:bg-slate-200/50'
                            }`}
                        style={{ pointerEvents: 'auto' }}
                    >
                        Hari Ini
                    </button>
                    <button
                        onClick={(e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            handlePeriodChange('week');
                        }}
                        className={`px-3 py-1.5 text-xs font-semibold rounded-md transition-all cursor-pointer ${period === 'week'
                            ? 'bg-white text-blue-700 shadow-sm'
                            : 'text-slate-500 hover:text-slate-700 hover:bg-slate-200/50'
                            }`}
                        style={{ pointerEvents: 'auto' }}
                    >
                        Minggu Ini
                    </button>
                    <button
                        onClick={(e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            handlePeriodChange('month');
                        }}
                        className={`px-3 py-1.5 text-xs font-semibold rounded-md transition-all cursor-pointer ${period === 'month'
                            ? 'bg-white text-blue-700 shadow-sm'
                            : 'text-slate-500 hover:text-slate-700 hover:bg-slate-200/50'
                            }`}
                        style={{ pointerEvents: 'auto' }}
                    >
                        Bulan Ini
                    </button>
                </div>
            </div>

            <div className="p-6 lg:p-8 max-w-[1600px] mx-auto space-y-8">

                {/* 1. Quick Actions */}
                <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-4">
                    <button 
                        onClick={(e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            handleNavigation('/super-admin/schools/activation', 'Aktivasi Sekolah');
                        }}
                        className="flex flex-col items-center justify-center p-4 bg-white border border-slate-200 rounded-xl hover:shadow-md hover:border-blue-300 transition-all group cursor-pointer"
                        style={{ pointerEvents: 'auto' }}
                    >
                        <div className="w-10 h-10 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                            <Building2 className="w-5 h-5" />
                        </div>
                        <span className="text-sm font-semibold text-slate-700">Aktivasi Sekolah</span>
                    </button>
                    <button 
                        onClick={(e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            handleNavigation('/super-admin/billing/invoices', 'Buat Invoice');
                        }}
                        className="flex flex-col items-center justify-center p-4 bg-white border border-slate-200 rounded-xl hover:shadow-md hover:border-purple-300 transition-all group cursor-pointer"
                        style={{ pointerEvents: 'auto' }}
                    >
                        <div className="w-10 h-10 rounded-full bg-purple-50 text-purple-600 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                            <FileText className="w-5 h-5" />
                        </div>
                        <span className="text-sm font-semibold text-slate-700">Buat Invoice</span>
                    </button>
                    <button 
                        onClick={(e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            handleNavigation('/super-admin/users/admins', 'Kelola Admin');
                        }}
                        className="flex flex-col items-center justify-center p-4 bg-white border border-slate-200 rounded-xl hover:shadow-md hover:border-green-300 transition-all group cursor-pointer"
                        style={{ pointerEvents: 'auto' }}
                    >
                        <div className="w-10 h-10 rounded-full bg-green-50 text-green-600 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                            <Users className="w-5 h-5" />
                        </div>
                        <span className="text-sm font-semibold text-slate-700">Kelola Admin</span>
                    </button>
                    <button 
                        onClick={(e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            handleNavigation('/super-admin/security/audit', 'Audit Log');
                        }}
                        className="flex flex-col items-center justify-center p-4 bg-white border border-slate-200 rounded-xl hover:shadow-md hover:border-amber-300 transition-all group cursor-pointer"
                        style={{ pointerEvents: 'auto' }}
                    >
                        <div className="w-10 h-10 rounded-full bg-amber-50 text-amber-600 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                            <Shield className="w-5 h-5" />
                        </div>
                        <span className="text-sm font-semibold text-slate-700">Audit Log</span>
                    </button>
                    <button 
                        onClick={(e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            handleNavigation('/super-admin/reports/attendance', 'Export Laporan');
                        }}
                        className="flex flex-col items-center justify-center p-4 bg-white border border-slate-200 rounded-xl hover:shadow-md hover:border-rose-300 transition-all group cursor-pointer"
                        style={{ pointerEvents: 'auto' }}
                    >
                        <div className="w-10 h-10 rounded-full bg-rose-50 text-rose-600 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                            <Download className="w-5 h-5" />
                        </div>
                        <span className="text-sm font-semibold text-slate-700">Export Laporan</span>
                    </button>
                </div>

                {/* 2. Key Metrics Cards (Soft Pastel Gradients & Glass Effect) */}
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    {/* Stat 1: Total Schools */}
                    <div className="relative overflow-hidden bg-gradient-to-br from-indigo-50/80 to-blue-50/80 backdrop-blur-sm rounded-2xl shadow-sm hover:shadow-lg border border-indigo-100/50 p-6 group transition-all duration-300 hover:-translate-y-1">
                        <div className="absolute top-0 right-0 w-32 h-32 bg-indigo-100/50 rounded-bl-full -mr-8 -mt-8 transition-transform group-hover:scale-110"></div>
                        <div className="relative z-10">
                            <div className="flex items-center gap-3 mb-4">
                                <div className="p-2.5 bg-white rounded-xl text-indigo-600 shadow-sm ring-1 ring-indigo-100">
                                    <Building2 className="w-6 h-6" />
                                </div>
                                <span className="px-2.5 py-1 text-xs font-bold text-indigo-700 bg-indigo-100/50 rounded-full border border-indigo-200/50">
                                    +{stats?.school_growth}%
                                </span>
                            </div>
                            <h3 className="text-3xl font-extrabold text-slate-800 mb-1 tracking-tight">{stats?.total_schools}</h3>
                            <p className="text-sm font-medium text-slate-500">Total Sekolah Aktif</p>
                        </div>
                    </div>

                    {/* Stat 2: Total Students */}
                    <div className="relative overflow-hidden bg-gradient-to-br from-fuchsia-50/80 to-purple-50/80 backdrop-blur-sm rounded-2xl shadow-sm hover:shadow-lg border border-fuchsia-100/50 p-6 group transition-all duration-300 hover:-translate-y-1">
                        <div className="absolute top-0 right-0 w-32 h-32 bg-fuchsia-100/50 rounded-bl-full -mr-8 -mt-8 transition-transform group-hover:scale-110"></div>
                        <div className="relative z-10">
                            <div className="flex items-center gap-3 mb-4">
                                <div className="p-2.5 bg-white rounded-xl text-fuchsia-600 shadow-sm ring-1 ring-fuchsia-100">
                                    <Users className="w-6 h-6" />
                                </div>
                                <span className="px-2.5 py-1 text-xs font-bold text-fuchsia-700 bg-fuchsia-100/50 rounded-full border border-fuchsia-200/50">Aktif</span>
                            </div>
                            <h3 className="text-3xl font-extrabold text-slate-800 mb-1 tracking-tight">{(stats?.total_students || 0).toLocaleString('id-ID')}</h3>
                            <p className="text-sm font-medium text-slate-500">Total Siswa Terdaftar</p>
                        </div>
                    </div>

                    {/* Stat 3: Revenue Period (Soft Emerald) */}
                    <div className="relative overflow-hidden bg-gradient-to-br from-emerald-50/80 to-teal-50/80 backdrop-blur-sm rounded-2xl shadow-sm hover:shadow-lg border border-emerald-100/50 p-6 group transition-all duration-300 hover:-translate-y-1">
                        <div className="absolute top-0 right-0 w-32 h-32 bg-emerald-100/50 rounded-bl-full -mr-8 -mt-8 transition-transform group-hover:scale-110"></div>
                        <div className="relative z-10">
                            <div className="flex items-center gap-3 mb-4">
                                <div className="p-2.5 bg-white rounded-xl text-emerald-600 shadow-sm ring-1 ring-emerald-100">
                                    <TrendingUp className="w-6 h-6" />
                                </div>
                                <span className="px-2.5 py-1 text-xs font-bold text-emerald-700 bg-emerald-100/50 rounded-full border border-emerald-200/50 capitalize">
                                    {getPeriodLabel(period) || 'Bulanan'}
                                </span>
                            </div>
                            <h3 className="text-3xl font-extrabold text-slate-800 mb-1 tracking-tight">
                                {formatCurrency((stats as any)?.revenue_amount || stats?.revenue_this_month || 0)}
                            </h3>
                            <p className="text-sm font-medium text-slate-500">Estimasi Pendapatan</p>
                        </div>
                    </div>

                    {/* Stat 4: Attendance Period (Soft Amber/Orange) */}
                    <div className="relative overflow-hidden bg-gradient-to-br from-amber-50/80 to-orange-50/80 backdrop-blur-sm rounded-2xl shadow-sm hover:shadow-lg border border-amber-100/50 p-6 group transition-all duration-300 hover:-translate-y-1">
                        <div className="absolute top-0 right-0 w-32 h-32 bg-amber-100/50 rounded-bl-full -mr-8 -mt-8 transition-transform group-hover:scale-110"></div>
                        <div className="relative z-10">
                            <div className="flex items-center gap-3 mb-4">
                                <div className="p-2.5 bg-white rounded-xl text-amber-600 shadow-sm ring-1 ring-amber-100 animate-pulse">
                                    <Activity className="w-6 h-6" />
                                </div>
                                <span className="px-2.5 py-1 text-xs font-bold text-amber-700 bg-amber-100/50 rounded-full border border-amber-200/50 flex items-center gap-1 capitalize">
                                    {period === 'today' && <span className="w-1.5 h-1.5 bg-amber-500 rounded-full animate-ping"></span>}
                                    {getPeriodLabel(period) || 'Langsung'}
                                </span>
                            </div>
                            <h3 className="text-3xl font-extrabold text-slate-800 mb-1 tracking-tight">
                                {((stats as any)?.attendance_count ?? stats?.attendance_today ?? 0).toLocaleString('id-ID')}
                            </h3>
                            <p className="text-sm font-medium text-slate-500">Scan Absensi {getPeriodLabel(period)}</p>
                        </div>
                    </div>
                </div>

                {/* 3. Charts Section (Recharts) */}
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">

                    {/* Main Chart: Attendance Trend */}
                    <div className="lg:col-span-2 bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                        <div className="flex items-center justify-between mb-6">
                            <div>
                                <h3 className="text-lg font-bold text-slate-900">Tren Aktivitas Absensi</h3>
                                <p className="text-sm text-slate-500">Grafik jumlah scan harian (7 hari terakhir)</p>
                            </div>

                        </div>
                        <div className="h-[300px] w-full">
                            <ResponsiveContainer width="100%" height={300}>
                                <AreaChart data={attendanceChart}>
                                    <defs>
                                        <linearGradient id="colorCount" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="5%" stopColor="#3B82F6" stopOpacity={0.3} />
                                            <stop offset="95%" stopColor="#3B82F6" stopOpacity={0} />
                                        </linearGradient>
                                    </defs>
                                    <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#E2E8F0" />
                                    <XAxis
                                        dataKey="day"
                                        axisLine={false}
                                        tickLine={false}
                                        tick={{ fill: '#64748B', fontSize: 12 }}
                                        dy={10}
                                    />
                                    <YAxis
                                        axisLine={false}
                                        tickLine={false}
                                        tick={{ fill: '#64748B', fontSize: 12 }}
                                        dx={-10}
                                    />
                                    <Tooltip
                                        contentStyle={{ borderRadius: '8px', border: 'none', boxShadow: '0 4px 6px -1px rgb(0 0 0 / 0.1)' }}
                                        cursor={{ stroke: '#3B82F6', strokeWidth: 2 }}
                                    />
                                    <Area
                                        type="monotone"
                                        dataKey="count"
                                        stroke="#3B82F6"
                                        strokeWidth={3}
                                        fillOpacity={1}
                                        fill="url(#colorCount)"
                                        animationDuration={1500}
                                    />
                                </AreaChart>
                            </ResponsiveContainer>
                        </div>
                    </div>

                    {/* Secondary Chart: Package Distribution */}
                    <div className="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 flex flex-col">
                        <div className="mb-6">
                            <h3 className="text-lg font-bold text-slate-900">Distribusi Paket</h3>
                            <p className="text-sm text-slate-500">Persentase sekolah berdasarkan paket langganan</p>
                        </div>
                        <div className="flex-1 min-h-[250px] relative">
                            <ResponsiveContainer width="100%" height={250}>
                                <PieChart>
                                    <Pie
                                        data={packageData}
                                        cx="50%"
                                        cy="50%"
                                        innerRadius={60}
                                        outerRadius={80}
                                        paddingAngle={5}
                                        dataKey="value"
                                    >
                                        {packageData.map((entry, index) => (
                                            <Cell key={`cell-${index}`} fill={entry.color} strokeWidth={0} />
                                        ))}
                                    </Pie>
                                    <Tooltip contentStyle={{ borderRadius: '8px', padding: '8px 12px' }} />
                                    <Legend verticalAlign="bottom" height={36} iconType="circle" />
                                </PieChart>
                            </ResponsiveContainer>
                            {/* Center Text */}
                            <div className="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-[calc(50%+20px)] text-center">
                                <span className="block text-3xl font-bold text-slate-800">{stats?.total_schools}</span>
                                <span className="text-xs text-slate-500 font-medium uppercase tracking-wide">Total</span>
                            </div>
                        </div>
                    </div>
                </div>

                {/* 4. Recent Data Tables */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {/* Recent Registrations */}
                    <div className="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                        <div className="p-6 border-b border-slate-100 flex justify-between items-center">
                            <h3 className="font-bold text-slate-900">Sekolah Terbaru</h3>
                            <button 
                                onClick={(e) => {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    handleNavigation('/super-admin/schools', 'Lihat Semua Sekolah');
                                }} 
                                className="text-sm text-blue-600 font-medium hover:underline cursor-pointer"
                                style={{ pointerEvents: 'auto' }}
                            >
                                Lihat Semua
                            </button>
                        </div>
                        <div className="divide-y divide-slate-100">
                            {recentSchools.length === 0 ? (
                                <div className="p-8 text-center text-slate-500 italic">Belum ada sekolah baru.</div>
                            ) : (
                                recentSchools.map((school, idx) => (
                                    <div key={idx} className="p-4 flex items-center justify-between hover:bg-slate-50 transition-colors">
                                        <div className="flex items-center gap-3">
                                            <div className="w-10 h-10 rounded-lg bg-slate-100 flex items-center justify-center text-slate-600 font-bold border border-slate-200">
                                                {school.name.substring(0, 2).toUpperCase()}
                                            </div>
                                            <div>
                                                <p className="font-semibold text-slate-900 text-sm">{school.name}</p>
                                                <p className="text-xs text-slate-500">{new Date(school.created_at).toLocaleDateString()}</p>
                                            </div>
                                        </div>
                                        <span className={`text-xs px-2.5 py-1 rounded-full font-medium ${school.status === 'active' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'
                                            }`}>
                                            {school.status}
                                        </span>
                                    </div>
                                ))
                            )}
                        </div>
                    </div>

                    {/* Recent Audit Logs */}
                    <div className="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                        <div className="p-6 border-b border-slate-100 flex justify-between items-center">
                            <h3 className="font-bold text-slate-900">Aktivitas Sistem</h3>
                            <button 
                                onClick={(e) => {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    handleNavigation('/super-admin/security/audit', 'Lihat Log Audit');
                                }} 
                                className="text-sm text-blue-600 font-medium hover:underline cursor-pointer"
                                style={{ pointerEvents: 'auto' }}
                            >
                                Lihat Log
                            </button>
                        </div>
                        <div className="divide-y divide-slate-100">
                            {recentActivities.length === 0 ? (
                                <div className="p-8 text-center text-slate-500 italic">Belum ada aktivitas tercatat.</div>
                            ) : (
                                recentActivities.map((log, idx) => (
                                    <div key={idx} className="p-4 flex gap-4 hover:bg-slate-50 transition-colors">
                                        <div className="w-2 h-2 mt-2 rounded-full bg-blue-500 ring-4 ring-blue-50 flex-shrink-0"></div>
                                        <div className="flex-1">
                                            <p className="text-sm font-medium text-slate-900">
                                                {log.action} <span className="text-slate-500 font-normal">oleh</span> {log.user}
                                            </p>
                                            <p className="text-xs text-slate-500 mt-1">{log.school || 'System'}</p>
                                        </div>
                                        <span className="text-xs text-slate-400 whitespace-nowrap">{log.time}</span>
                                    </div>
                                ))
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
};

