import { useState, useEffect } from 'react';
import { motion, AnimatePresence } from "framer-motion";
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, PieChart, Pie, Cell, LineChart, Line, ResponsiveContainer, AreaChart, Area } from "recharts";

export const NewDashboard = () => {
    const [activeMenu, setActiveMenu] = useState('dashboard-overview');
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [expandedMenus, setExpandedMenus] = useState({
        dashboard: true,
        schools: false,
        users: false,
        monitoring: false,
        security: false,
        billing: false,
        reports: false,
        settings: false
    });
    const [systemHealth, setSystemHealth] = useState({
        cpu: 45,
        memory: 62,
        disk: 38,
        uptime: '15d 7h 22m'
    });
    const [securityEvents] = useState(12);
    const [activeSchools, setActiveSchools] = useState(87);
    const [totalUsers, setTotalUsers] = useState(15420);
    const [loading, setLoading] = useState(true);

    // Mock data for charts
    const schoolGrowthData = [
        { month: 'Jan', schools: 45 },
        { month: 'Feb', schools: 52 },
        { month: 'Mar', schools: 61 },
        { month: 'Apr', schools: 68 },
        { month: 'May', schools: 75 },
        { month: 'Jun', schools: 87 }
    ];

    const packageDistribution = [
        { name: 'Basic', value: 35 },
        { name: 'Professional', value: 42 },
        { name: 'Enterprise', value: 10 }
    ];

    const attendanceData = [
        { day: 'Senin', hadir: 4250, izin: 320, alpha: 180 },
        { day: 'Selasa', hadir: 4380, izin: 290, alpha: 150 },
        { day: 'Rabu', hadir: 4410, izin: 310, alpha: 140 },
        { day: 'Kamis', hadir: 4350, izin: 305, alpha: 165 },
        { day: 'Jumat', hadir: 4120, izin: 380, alpha: 220 }
    ];

    const COLORS = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444'];

    // Simulate loading state
    useEffect(() => {
        const timer = setTimeout(() => setLoading(false), 1200);
        return () => clearTimeout(timer);
    }, []);

    // Simulate real-time system health updates
    useEffect(() => {
        const interval = setInterval(() => {
            setSystemHealth(prev => ({
                cpu: Math.max(20, Math.min(90, prev.cpu + (Math.random() - 0.5) * 10)),
                memory: Math.max(30, Math.min(85, prev.memory + (Math.random() - 0.5) * 8)),
                disk: Math.min(95, prev.disk + 0.1),
                uptime: prev.uptime
            }));
        }, 3000);

        return () => clearInterval(interval);
    }, []);

    // Animate counters on mount
    useEffect(() => {
        if (loading) return;

        let schoolCount = 0;
        let userCount = 0;
        const schoolInterval = setInterval(() => {
            schoolCount += 3;
            if (schoolCount >= activeSchools) {
                clearInterval(schoolInterval);
                schoolCount = activeSchools;
            }
            setActiveSchools(schoolCount);
        }, 50);

        const userInterval = setInterval(() => {
            userCount += 150;
            if (userCount >= totalUsers) {
                clearInterval(userInterval);
                userCount = totalUsers;
            }
            setTotalUsers(userCount);
        }, 20);

        return () => {
            clearInterval(schoolInterval);
            clearInterval(userInterval);
        };
    }, [loading]);

    // Menu structure with submenus
    const menuItems = [
        {
            id: 'dashboard',
            label: 'Dashboard Utama',
            icon: '📊',
            submenus: [
                { id: 'dashboard-overview', label: 'Ringkasan sistem global' },
                { id: 'dashboard-school-stats', label: 'Statistik sekolah aktif' },
                { id: 'dashboard-activity', label: 'Aktivitas terbaru' }
            ]
        },
        {
            id: 'schools',
            label: 'Manajemen Sekolah',
            icon: '🏫',
            submenus: [
                { id: 'schools-list', label: 'Daftar Sekolah' },
                { id: 'schools-activation', label: 'Aktivasi Sekolah' },
                { id: 'schools-packages', label: 'Paket & Limit' }
            ]
        },
        {
            id: 'users',
            label: 'Manajemen Pengguna Global',
            icon: '👥',
            submenus: [
                { id: 'users-superadmin', label: 'Super Admin' },
                { id: 'users-support', label: 'Support/Admin Internal' }
            ]
        },
        {
            id: 'monitoring',
            label: 'Monitoring Sistem',
            icon: '🔍',
            submenus: [
                { id: 'monitoring-health', label: 'System Health' },
                { id: 'monitoring-logs', label: 'Error Logs' },
                { id: 'monitoring-queue', label: 'Queue Status' }
            ]
        },
        {
            id: 'security',
            label: 'Security Monitoring Global',
            icon: '🛡️',
            submenus: [
                { id: 'security-events', label: 'Security Events' },
                { id: 'security-suspicious', label: 'Suspicious Activity' },
                { id: 'security-api-abuse', label: 'API Abuse Logs' }
            ]
        },
        {
            id: 'billing',
            label: 'Billing & Subscription',
            icon: '💳',
            submenus: [
                { id: 'billing-packages', label: 'Paket Langganan' },
                { id: 'billing-invoices', label: 'Tagihan Sekolah' },
                { id: 'billing-history', label: 'Riwayat Pembayaran' }
            ]
        },
        {
            id: 'reports',
            label: 'Laporan Global',
            icon: '📈',
            submenus: [
                { id: 'reports-usage', label: 'Statistik Penggunaan' },
                { id: 'reports-attendance', label: 'Rekap Absensi Global' }
            ]
        },
        {
            id: 'settings',
            label: 'Pengaturan Sistem',
            icon: '⚙️',
            submenus: [
                { id: 'settings-flags', label: 'Feature Flags' },
                { id: 'settings-maintenance', label: 'Maintenance Mode' }
            ]
        }
    ];

    const toggleMenu = (menuId: string) => {
        setExpandedMenus((prev: any) => ({
            ...prev,
            [menuId]: !prev[menuId as keyof typeof prev]
        }));
    };

    const getActiveMenuLabel = () => {
        for (const menu of menuItems) {
            const submenu = menu.submenus.find(sub => sub.id === activeMenu);
            if (submenu) return submenu.label;
        }
        return 'Dashboard';
    };

    // Content components for each submenu (PLACEHOLDERS)
    const renderDashboardOverview = () => (
        <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.5 }}
            className="space-y-6"
        >
            {/* Stats Cards */}
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                {[
                    { title: 'Sekolah Aktif', value: activeSchools, icon: '🏫', color: 'bg-blue-500', subtitle: 'Dari 128 sekolah terdaftar' },
                    { title: 'Total Pengguna', value: totalUsers.toLocaleString('id-ID'), icon: '👥', color: 'bg-green-500', subtitle: 'Siswa, guru & admin' },
                    { title: 'Event Keamanan', value: securityEvents, icon: '⚠️', color: 'bg-yellow-500', subtitle: '24 jam terakhir' },
                    { title: 'Uptime Sistem', value: systemHealth.uptime, icon: '⏱️', color: 'bg-purple-500', subtitle: 'Tanpa downtime' }
                ].map((stat, index) => (
                    <motion.div
                        key={index}
                        initial={{ opacity: 0, y: 20 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ delay: index * 0.1 }}
                        whileHover={{ y: -5 }}
                        className="bg-gray-800 rounded-xl p-6 border border-gray-700 shadow-lg"
                    >
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="text-gray-400 text-sm">{stat.title}</p>
                                <p className="text-2xl font-bold mt-1">{stat.value}</p>
                                <p className="text-xs text-gray-500 mt-1">{stat.subtitle}</p>
                            </div>
                            <div className={`w-12 h-12 rounded-full ${stat.color} flex items-center justify-center text-white text-xl`}>
                                {stat.icon}
                            </div>
                        </div>
                        <div className="mt-4 h-2 bg-gray-700 rounded-full overflow-hidden">
                            <motion.div
                                className={`h-full ${stat.color}`}
                                initial={{ width: 0 }}
                                animate={{ width: `${(index + 1) * 20 + 15}%` }}
                                transition={{ duration: 1, delay: 0.5 }}
                            />
                        </div>
                    </motion.div>
                ))}
            </div>

            {/* Charts Section */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* School Growth Chart */}
                <motion.div
                    initial={{ opacity: 0, x: -20 }}
                    animate={{ opacity: 1, x: 0 }}
                    transition={{ duration: 0.5, delay: 0.2 }}
                    className="bg-gray-800 rounded-xl p-6 border border-gray-700 shadow-lg"
                >
                    <h3 className="text-xl font-bold mb-4 flex items-center">
                        <span className="mr-2">📈</span>Pertumbuhan Sekolah Aktif
                    </h3>
                    <div className="h-80">
                        <ResponsiveContainer width="100%" height="100%">
                            <LineChart data={schoolGrowthData} margin={{ top: 20, right: 30, left: 20, bottom: 10 }}>
                                <CartesianGrid strokeDasharray="3 3" stroke="#374151" />
                                <XAxis dataKey="month" stroke="#9CA3AF" />
                                <YAxis stroke="#9CA3AF" />
                                <Tooltip
                                    contentStyle={{ backgroundColor: '#1F2937', border: '1px solid #374151', borderRadius: '8px' }}
                                    labelStyle={{ color: '#E5E7EB' }}
                                />
                                <Legend />
                                <Line
                                    type="monotone"
                                    dataKey="schools"
                                    stroke="#3B82F6"
                                    strokeWidth={3}
                                    dot={{ fill: '#3B82F6', strokeWidth: 2, r: 4 }}
                                    activeDot={{ r: 8 }}
                                />
                            </LineChart>
                        </ResponsiveContainer>
                    </div>
                </motion.div>

                {/* Package Distribution */}
                <motion.div
                    initial={{ opacity: 0, x: 20 }}
                    animate={{ opacity: 1, x: 0 }}
                    transition={{ duration: 0.5, delay: 0.3 }}
                    className="bg-gray-800 rounded-xl p-6 border border-gray-700 shadow-lg"
                >
                    <h3 className="text-xl font-bold mb-4 flex items-center">
                        <span className="mr-2">📦</span>Distribusi Paket Langganan
                    </h3>
                    <div className="flex flex-col items-center">
                        <div className="w-full h-64">
                            <ResponsiveContainer width="100%" height="100%">
                                <PieChart>
                                    <Pie
                                        data={packageDistribution}
                                        cx="50%"
                                        cy="50%"
                                        innerRadius={60}
                                        outerRadius={80}
                                        fill="#8884d8"
                                        paddingAngle={2}
                                        dataKey="value"
                                        label={({ name, percent }: any) => `${name} ${(percent * 100).toFixed(0)}%`}
                                    >
                                        {packageDistribution.map((_, index) => (
                                            <Cell key={`cell-${index}`} fill={COLORS[index % COLORS.length]} />
                                        ))}
                                    </Pie>
                                    <Tooltip
                                        contentStyle={{ backgroundColor: '#1F2937', border: '1px solid #374151', borderRadius: '8px' }}
                                        labelStyle={{ color: '#E5E7EB' }}
                                    />
                                    <Legend
                                        verticalAlign="bottom"
                                        height={36}
                                        formatter={(value) => (
                                            <span className="text-gray-300 text-sm">{value}</span>
                                        )}
                                    />
                                </PieChart>
                            </ResponsiveContainer>
                        </div>
                        <div className="grid grid-cols-3 gap-4 mt-4 w-full">
                            {packageDistribution.map((item, index) => (
                                <div key={index} className="text-center">
                                    <div className={`w-3 h-3 rounded-full mx-auto mb-1 ${index === 0 ? 'bg-blue-500' : index === 1 ? 'bg-green-500' : 'bg-yellow-500'}`}></div>
                                    <p className="text-sm font-medium">{item.name}</p>
                                    <p className="text-xs text-gray-400">{item.value} Sekolah</p>
                                </div>
                            ))}
                        </div>
                    </div>
                </motion.div>
            </div>

            {/* Recent Activities */}
            <motion.div
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                transition={{ duration: 0.5, delay: 0.4 }}
                className="bg-gray-800 rounded-xl p-6 border border-gray-700 shadow-lg"
            >
                <h3 className="text-xl font-bold mb-4 flex items-center">
                    <span className="mr-2">🔔</span>Aktivitas Terbaru Sistem
                </h3>
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-700">
                        <thead>
                            <tr>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Waktu</th>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Sekolah</th>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Aksi</th>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Pengguna</th>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-700">
                            {[
                                { time: '09:45:23', school: 'SMA Negeri 1 Jakarta', action: 'Login Admin', user: 'admin@smajkt.sch.id', status: 'success' },
                                { time: '09:32:17', school: 'SMP Islam Terpadu', action: 'Generate QR Code', user: 'guru@smpit.sch.id', status: 'success' },
                                { time: '09:15:44', school: 'SD Budi Luhur', action: 'Export Report', user: 'kepsek@sdbl.sch.id', status: 'success' },
                                { time: '08:57:09', school: 'SMK Teknologi', action: 'Update Profile', user: 'admin@smktek.sch.id', status: 'warning' },
                                { time: '08:41:36', school: 'SMA Negeri 5 Bandung', action: 'New Absence', user: 'siswa@smabdg.sch.id', status: 'success' },
                                { time: '08:22:51', school: 'SMA Negeri 1 Jakarta', action: 'Failed Login', user: 'unknown', status: 'error' }
                            ].map((activity, index) => (
                                <motion.tr
                                    key={index}
                                    initial={{ opacity: 0, x: -20 }}
                                    animate={{ opacity: 1, x: 0 }}
                                    transition={{ delay: 0.5 + index * 0.1 }}
                                    className="hover:bg-gray-750 transition-colors"
                                >
                                    <td className="px-4 py-3 whitespace-nowrap text-sm text-blue-400">{activity.time}</td>
                                    <td className="px-4 py-3 whitespace-nowrap text-sm font-medium">{activity.school}</td>
                                    <td className="px-4 py-3 whitespace-nowrap">
                                        <span className={`px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${activity.status === 'success' ? 'bg-blue-900 text-blue-300' :
                                            activity.status === 'warning' ? 'bg-yellow-900 text-yellow-300' : 'bg-red-900 text-red-300'
                                            }`}>
                                            {activity.action}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-300">{activity.user}</td>
                                    <td className="px-4 py-3 whitespace-nowrap">
                                        {activity.status === 'success' && <span className="text-green-400">✓ Berhasil</span>}
                                        {activity.status === 'warning' && <span className="text-yellow-400">⚠️ Peringatan</span>}
                                        {activity.status === 'error' && <span className="text-red-400">✗ Gagal</span>}
                                    </td>
                                </motion.tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </motion.div>
        </motion.div>
    );
    const renderSchoolStats = () => (
        <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            transition={{ duration: 0.5 }}
            className="space-y-6"
        >
            <div className="bg-gray-800 rounded-xl p-6 border border-gray-700 shadow-lg">
                <h2 className="text-2xl font-bold mb-6 flex items-center">
                    <span className="mr-2">🏫</span>Statistik Sekolah Aktif
                </h2>

                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                    {[
                        { title: 'Total Sekolah', value: '128', trend: '+12%', color: 'text-green-400' },
                        { title: 'Sekolah Aktif', value: '87', trend: '+5%', color: 'text-green-400' },
                        { title: 'Sekolah Nonaktif', value: '41', trend: '-3%', color: 'text-red-400' }
                    ].map((stat, index) => (
                        <div key={index} className="bg-gray-700 p-5 rounded-lg border border-gray-600 text-center">
                            <p className="text-gray-400 text-sm">{stat.title}</p>
                            <p className="text-3xl font-bold mt-1">{stat.value}</p>
                            <p className={`mt-1 font-medium ${stat.color}`}>{stat.trend}</p>
                        </div>
                    ))}
                </div>

                <div className="h-80 mb-8">
                    <ResponsiveContainer width="100%" height="100%">
                        <AreaChart data={schoolGrowthData} margin={{ top: 20, right: 30, left: 20, bottom: 10 }}>
                            <CartesianGrid strokeDasharray="3 3" stroke="#374151" />
                            <XAxis dataKey="month" stroke="#9CA3AF" />
                            <YAxis stroke="#9CA3AF" />
                            <Tooltip
                                contentStyle={{ backgroundColor: '#1F2937', border: '1px solid #374151', borderRadius: '8px' }}
                                labelStyle={{ color: '#E5E7EB' }}
                            />
                            <defs>
                                <linearGradient id="colorSchools" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="5%" stopColor="#3B82F6" stopOpacity={0.8} />
                                    <stop offset="95%" stopColor="#3B82F6" stopOpacity={0} />
                                </linearGradient>
                            </defs>
                            <Area
                                type="monotone"
                                dataKey="schools"
                                stroke="#3B82F6"
                                fillOpacity={1}
                                fill="url(#colorSchools)"
                                strokeWidth={2}
                            />
                        </AreaChart>
                    </ResponsiveContainer>
                </div>

                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-700">
                        <thead>
                            <tr>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Peringkat</th>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Nama Sekolah</th>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Paket</th>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Pengguna Aktif</th>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Absensi Minggu Ini</th>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-700">
                            {[
                                { rank: 1, name: 'SMA Negeri 1 Jakarta', package: 'Enterprise', users: 1250, attendance: 8740, status: 'Aktif' },
                                { rank: 2, name: 'SMA Negeri 5 Bandung', package: 'Enterprise', users: 1100, attendance: 7650, status: 'Aktif' },
                                { rank: 3, name: 'SMK Teknologi', package: 'Professional', users: 980, attendance: 6820, status: 'Aktif' },
                                { rank: 4, name: 'SMP Islam Terpadu', package: 'Professional', users: 850, attendance: 5930, status: 'Aktif' },
                                { rank: 5, name: 'SMA Negeri 3 Surabaya', package: 'Professional', users: 920, attendance: 5410, status: 'Aktif' }
                            ].map((school, index) => (
                                <tr key={index} className="hover:bg-gray-750">
                                    <td className="px-4 py-3 whitespace-nowrap text-xl font-bold text-yellow-400">{school.rank}</td>
                                    <td className="px-4 py-3 whitespace-nowrap font-medium">{school.name}</td>
                                    <td className="px-4 py-3 whitespace-nowrap">
                                        <span className={`px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${school.package === 'Enterprise' ? 'bg-purple-900 text-purple-300' : 'bg-blue-900 text-blue-300'
                                            }`}>
                                            {school.package}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 whitespace-nowrap text-gray-300">{school.users.toLocaleString('id-ID')}</td>
                                    <td className="px-4 py-3 whitespace-nowrap text-green-400 font-medium">{school.attendance.toLocaleString('id-ID')}</td>
                                    <td className="px-4 py-3 whitespace-nowrap">
                                        <span className="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-900 text-green-300">
                                            {school.status}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </motion.div>
    );
    const renderSchoolsList = () => (
        <div className="space-y-6">
            <div className="bg-gray-800 rounded-xl p-6 border border-gray-700 shadow-lg">
                <div className="flex flex-col md:flex-row md:items-center md:justify-between mb-6">
                    <h2 className="text-2xl font-bold flex items-center">
                        <span className="mr-2">🏫</span>Daftar Sekolah
                    </h2>
                    <div className="mt-4 md:mt-0 flex space-x-3">
                        <div className="relative">
                            <input
                                type="text"
                                placeholder="Cari sekolah..."
                                className="bg-gray-700 text-gray-200 rounded-lg py-2 px-4 w-full md:w-64 focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            <div className="absolute right-3 top-2.5 text-gray-400">🔍</div>
                        </div>
                        <button className="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center transition-colors">
                            <span className="mr-2">➕</span>Tambah Sekolah
                        </button>
                    </div>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
                    <div className="bg-gray-700 p-4 rounded-lg text-center border border-gray-600">
                        <h3 className="text-lg font-semibold mb-2">Total Sekolah</h3>
                        <p className="text-3xl font-bold text-blue-400">128</p>
                        <p className="text-gray-400 mt-1">Terdaftar di sistem</p>
                    </div>
                    <div className="bg-gray-700 p-4 rounded-lg text-center border border-gray-600">
                        <h3 className="text-lg font-semibold mb-2">Sekolah Aktif</h3>
                        <p className="text-3xl font-bold text-green-400">87</p>
                        <p className="text-gray-400 mt-1">Menggunakan sistem aktif</p>
                    </div>
                    <div className="bg-gray-700 p-4 rounded-lg text-center border border-gray-600">
                        <h3 className="text-lg font-semibold mb-2">Pertumbuhan</h3>
                        <p className="text-3xl font-bold text-purple-400">+12</p>
                        <p className="text-gray-400 mt-1">Bulan ini</p>
                    </div>
                </div>

                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-700">
                        <thead className="bg-gray-700">
                            <tr>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Nama Sekolah</th>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Alamat</th>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Paket</th>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Pengguna</th>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Terakhir Aktif</th>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Status</th>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Aksi</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-700">
                            {[
                                {
                                    name: 'SMA Negeri 1 Jakarta',
                                    address: 'Jl. Budi Utomo No.7, Jakarta Pusat',
                                    package: 'Enterprise',
                                    users: 1250,
                                    lastActive: '2 jam lalu',
                                    status: 'Aktif'
                                },
                                {
                                    name: 'SMP Islam Terpadu',
                                    address: 'Jl. KH. Wahid Hasyim No.45, Bandung',
                                    package: 'Professional',
                                    users: 850,
                                    lastActive: '1 hari lalu',
                                    status: 'Aktif'
                                },
                                {
                                    name: 'SD Budi Luhur',
                                    address: 'Jl. Sudirman No.123, Surabaya',
                                    package: 'Basic',
                                    users: 420,
                                    lastActive: '3 hari lalu',
                                    status: 'Nonaktif'
                                },
                                {
                                    name: 'SMK Teknologi',
                                    address: 'Jl. Teknologi No.88, Yogyakarta',
                                    package: 'Professional',
                                    users: 980,
                                    lastActive: '5 jam lalu',
                                    status: 'Aktif'
                                },
                                {
                                    name: 'SMA Negeri 5 Bandung',
                                    address: 'Jl. Belitung No.8, Bandung',
                                    package: 'Enterprise',
                                    users: 1100,
                                    lastActive: '1 jam lalu',
                                    status: 'Aktif'
                                }
                            ].map((school, index) => (
                                <tr key={index} className="hover:bg-gray-750 transition-colors">
                                    <td className="px-4 py-3 whitespace-nowrap font-medium">{school.name}</td>
                                    <td className="px-4 py-3 whitespace-nowrap text-gray-300 text-sm">{school.address}</td>
                                    <td className="px-4 py-3 whitespace-nowrap">
                                        <span className={`px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${school.package === 'Enterprise' ? 'bg-purple-900 text-purple-300' :
                                            school.package === 'Professional' ? 'bg-blue-900 text-blue-300' : 'bg-yellow-900 text-yellow-300'
                                            }`}>
                                            {school.package}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 whitespace-nowrap text-gray-300">{school.users.toLocaleString('id-ID')}</td>
                                    <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-400">{school.lastActive}</td>
                                    <td className="px-4 py-3 whitespace-nowrap">
                                        <span className={`px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${school.status === 'Aktif' ? 'bg-green-900 text-green-300' : 'bg-red-900 text-red-300'
                                            }`}>
                                            {school.status}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 whitespace-nowrap">
                                        <button className="text-blue-400 hover:text-blue-300 mr-3 text-lg">✏️</button>
                                        <button className="text-green-400 hover:text-green-300 mr-3 text-lg">👁️</button>
                                        <button className="text-red-400 hover:text-red-300 text-lg">🗑️</button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="mt-6 flex justify-between items-center">
                    <p className="text-gray-400">Menampilkan 1-5 dari 128 sekolah</p>
                    <div className="flex space-x-2">
                        <button className="px-4 py-2 bg-gray-700 text-gray-300 rounded-lg hover:bg-gray-600 transition-colors">Sebelumnya</button>
                        <button className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">1</button>
                        <button className="px-4 py-2 bg-gray-700 text-gray-300 rounded-lg hover:bg-gray-600 transition-colors">2</button>
                        <button className="px-4 py-2 bg-gray-700 text-gray-300 rounded-lg hover:bg-gray-600 transition-colors">3</button>
                        <button className="px-4 py-2 bg-gray-700 text-gray-300 rounded-lg hover:bg-gray-600 transition-colors">Selanjutnya</button>
                    </div>
                </div>
            </div>
        </div>
    );
    const renderSystemHealth = () => (
        <div className="space-y-6">
            <h2 className="text-2xl font-bold mb-6 flex items-center">
                <span className="mr-2">🖥️</span>System Health Monitoring
            </h2>

            {/* System Health Metrics */}
            <div className="bg-gray-800 rounded-xl p-6 border border-gray-700 shadow-lg">
                <h3 className="text-xl font-bold mb-4 flex items-center">
                    <span className="mr-2">📊</span>Real-time System Metrics
                </h3>
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    {[
                        {
                            label: 'CPU Usage',
                            value: systemHealth.cpu,
                            color: systemHealth.cpu > 70 ? 'bg-red-500' : systemHealth.cpu > 50 ? 'bg-yellow-500' : 'bg-green-500',
                            icon: '⚡',
                            detail: 'Normal < 70%'
                        },
                        {
                            label: 'Memory Usage',
                            value: systemHealth.memory,
                            color: systemHealth.memory > 75 ? 'bg-red-500' : systemHealth.memory > 60 ? 'bg-yellow-500' : 'bg-green-500',
                            icon: '🧠',
                            detail: 'Normal < 75%'
                        },
                        {
                            label: 'Disk Usage',
                            value: systemHealth.disk,
                            color: systemHealth.disk > 80 ? 'bg-red-500' : systemHealth.disk > 60 ? 'bg-yellow-500' : 'bg-green-500',
                            icon: '💾',
                            detail: 'Normal < 80%'
                        },
                        {
                            label: 'System Uptime',
                            value: systemHealth.uptime,
                            color: 'bg-blue-500',
                            icon: '⏱️',
                            detail: 'Tanpa gangguan'
                        }
                    ].map((metric, index) => (
                        <motion.div
                            key={index}
                            initial={{ opacity: 0, y: 20 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ delay: index * 0.1 }}
                            className="bg-gray-700 p-5 rounded-lg border border-gray-600"
                        >
                            <div className="flex items-center mb-3">
                                <div className={`w-10 h-10 rounded-full ${metric.color} flex items-center justify-center text-white mr-3`}>
                                    <span className="text-lg">{metric.icon}</span>
                                </div>
                                <div>
                                    <p className="text-gray-400 text-sm">{metric.label}</p>
                                    <p className="text-2xl font-bold mt-1">
                                        {typeof metric.value === 'number' ? metric.value.toFixed(1) + '%' : metric.value}
                                    </p>
                                </div>
                            </div>
                            <div className="w-full bg-gray-600 rounded-full h-2.5 mb-2">
                                {typeof metric.value === 'number' && (
                                    <motion.div
                                        className={`h-2.5 rounded-full ${metric.color}`}
                                        initial={{ width: 0 }}
                                        animate={{ width: `${metric.value}%` }}
                                        transition={{ duration: 1 }}
                                    />
                                )}
                            </div>
                            <p className="text-xs text-gray-400">{metric.detail}</p>
                        </motion.div>
                    ))}
                </div>
            </div>

            {/* Server Status */}
            <div className="bg-gray-800 rounded-xl p-6 border border-gray-700 shadow-lg">
                <h3 className="text-xl font-bold mb-4 flex items-center">
                    <span className="mr-2">🌐</span>Status Server Global
                </h3>
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    {[
                        { name: 'Server Jakarta', status: 'online', load: 45, region: 'Asia Tenggara' },
                        { name: 'Server Singapura', status: 'online', load: 38, region: 'Asia Pasifik' },
                        { name: 'Server Frankfurt', status: 'maintenance', load: 12, region: 'Eropa' },
                        { name: 'Server California', status: 'online', load: 52, region: 'Amerika Utara' },
                        { name: 'Server Sydney', status: 'online', load: 29, region: 'Australia' },
                        { name: 'Server Mumbai', status: 'degraded', load: 78, region: 'Asia Selatan' }
                    ].map((server, index) => (
                        <div key={index} className="bg-gray-700 p-4 rounded-lg border border-gray-600">
                            <div className="flex justify-between items-start mb-2">
                                <div>
                                    <h4 className="font-bold">{server.name}</h4>
                                    <p className="text-xs text-gray-400">{server.region}</p>
                                </div>
                                <span className={`px-2 py-1 text-xs font-semibold rounded-full ${server.status === 'online' ? 'bg-green-900 text-green-300' :
                                    server.status === 'maintenance' ? 'bg-yellow-900 text-yellow-300' : 'bg-red-900 text-red-300'
                                    }`}>
                                    {server.status === 'online' ? 'Online' : server.status === 'maintenance' ? 'Maintenance' : 'Degraded'}
                                </span>
                            </div>
                            <div className="mt-3">
                                <div className="flex justify-between text-sm mb-1">
                                    <span className="text-gray-400">Load:</span>
                                    <span className="font-medium">{server.load}%</span>
                                </div>
                                <div className="w-full bg-gray-600 rounded-full h-2">
                                    <div
                                        className={`h-2 rounded-full ${server.load > 70 ? 'bg-red-500' : server.load > 50 ? 'bg-yellow-500' : 'bg-green-500'
                                            }`}
                                        style={{ width: `${server.load}%` }}
                                    ></div>
                                </div>
                            </div>
                            <div className="mt-3 pt-3 border-t border-gray-600 flex justify-between text-xs">
                                <span>Response Time:</span>
                                <span className="font-medium">{server.load < 50 ? '28ms' : server.load < 70 ? '45ms' : '89ms'}</span>
                            </div>
                        </div>
                    ))}
                </div>
            </div>

            {/* Performance Metrics */}
            <div className="bg-gray-800 rounded-xl p-6 border border-gray-700 shadow-lg">
                <h3 className="text-xl font-bold mb-4 flex items-center">
                    <span className="mr-2">🚀</span>Performance Metrics (24 Jam Terakhir)
                </h3>
                <div className="h-80">
                    <ResponsiveContainer width="100%" height="100%">
                        <LineChart data={[
                            { time: '00:00', response: 45, errors: 2 },
                            { time: '04:00', response: 38, errors: 1 },
                            { time: '08:00', response: 52, errors: 3 },
                            { time: '12:00', response: 68, errors: 5 },
                            { time: '16:00', response: 75, errors: 8 },
                            { time: '20:00', response: 62, errors: 4 },
                            { time: '24:00', response: 48, errors: 2 }
                        ]} margin={{ top: 20, right: 30, left: 20, bottom: 10 }}>
                            <CartesianGrid strokeDasharray="3 3" stroke="#374151" />
                            <XAxis dataKey="time" stroke="#9CA3AF" />
                            <YAxis yAxisId="left" stroke="#3B82F6" />
                            <YAxis yAxisId="right" orientation="right" stroke="#EF4444" />
                            <Tooltip
                                contentStyle={{ backgroundColor: '#1F2937', border: '1px solid #374151', borderRadius: '8px' }}
                                labelStyle={{ color: '#E5E7EB' }}
                            />
                            <Legend />
                            <Line
                                yAxisId="left"
                                type="monotone"
                                dataKey="response"
                                name="Response Time (ms)"
                                stroke="#3B82F6"
                                strokeWidth={2}
                                dot={{ fill: '#3B82F6', r: 3 }}
                            />
                            <Line
                                yAxisId="right"
                                type="monotone"
                                dataKey="errors"
                                name="Error Count"
                                stroke="#EF4444"
                                strokeWidth={2}
                                dot={{ fill: '#EF4444', r: 3 }}
                            />
                        </LineChart>
                    </ResponsiveContainer>
                </div>
            </div>
        </div>
    );
    const renderSecurityEvents = () => (
        <div className="space-y-6">
            <h2 className="text-2xl font-bold mb-6 flex items-center">
                <span className="mr-2">🛡️</span>Security Monitoring Global
            </h2>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div className="bg-red-900 bg-opacity-20 border border-red-800 rounded-xl p-6">
                    <div className="flex items-center justify-between mb-4">
                        <h3 className="text-red-400 font-bold">Critical Threats</h3>
                        <span className="bg-red-900 text-red-200 text-xs px-2 py-1 rounded">Live</span>
                    </div>
                    <p className="text-4xl font-bold text-white mb-2">3</p>
                    <p className="text-sm text-gray-400">Terdeteksi dalam 1 jam terakhir</p>
                </div>

                <div className="bg-yellow-900 bg-opacity-20 border border-yellow-800 rounded-xl p-6">
                    <div className="flex items-center justify-between mb-4">
                        <h3 className="text-yellow-400 font-bold">Suspicious Activities</h3>
                        <span className="bg-yellow-900 text-yellow-200 text-xs px-2 py-1 rounded">Live</span>
                    </div>
                    <p className="text-4xl font-bold text-white mb-2">12</p>
                    <p className="text-sm text-gray-400">Login gagal berulang</p>
                </div>

                <div className="bg-blue-900 bg-opacity-20 border border-blue-800 rounded-xl p-6">
                    <div className="flex items-center justify-between mb-4">
                        <h3 className="text-blue-400 font-bold">API Abuse</h3>
                        <span className="bg-blue-900 text-blue-200 text-xs px-2 py-1 rounded">Monitor</span>
                    </div>
                    <p className="text-4xl font-bold text-white mb-2">0</p>
                    <p className="text-sm text-gray-400">Rate limit exceeded</p>
                </div>
            </div>

            <div className="bg-gray-800 rounded-xl p-6 border border-gray-700 shadow-lg">
                <h3 className="text-xl font-bold mb-4">Recent Security Logs</h3>
                <div className="space-y-4">
                    {[
                        { time: '10:42:15', ip: '192.168.1.45', event: 'Brute force attempt', target: 'admin@sekolaha.sch.id', severity: 'high' },
                        { time: '10:38:22', ip: '202.14.33.12', event: 'SQL Injection detected', target: '/api/v1/students', severity: 'critical' },
                        { time: '10:15:09', ip: '180.22.11.90', event: 'Multiple failed logins', target: 'guru@sekolahb.sch.id', severity: 'medium' },
                        { time: '09:55:31', ip: '103.44.22.11', event: 'Suspicious file upload', target: '/upload/docs', severity: 'medium' },
                        { time: '09:12:44', ip: '36.77.11.22', event: 'Unauthorized access', target: '/admin/settings', severity: 'high' }
                    ].map((log, index) => (
                        <div key={index} className="flex items-center justify-between p-4 bg-gray-700 rounded-lg border border-gray-600">
                            <div className="flex items-center space-x-4">
                                <div className={`w-2 h-12 rounded-full ${log.severity === 'critical' ? 'bg-red-600' :
                                    log.severity === 'high' ? 'bg-orange-500' : 'bg-yellow-500'
                                    }`}></div>
                                <div>
                                    <p className="font-bold text-gray-200">{log.event}</p>
                                    <div className="flex space-x-3 text-xs text-gray-400 mt-1">
                                        <span>{log.time}</span>
                                        <span>•</span>
                                        <span className="font-mono">{log.ip}</span>
                                        <span>•</span>
                                        <span>{log.target}</span>
                                    </div>
                                </div>
                            </div>
                            <button className="px-3 py-1 bg-gray-600 hover:bg-gray-500 rounded text-xs transition-colors">
                                Investigasi
                            </button>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
    const renderBillingPackages = () => (
        <div className="space-y-6">
            <h2 className="text-2xl font-bold mb-6 flex items-center">
                <span className="mr-2">💳</span>Billing & Subscription
            </h2>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                {[
                    { name: 'Basic', price: 'Rp 500rb', period: '/bulan', features: ['Max 500 Siswa', 'Absensi QR Basic', 'Laporan Bulanan', 'Email Support'] },
                    { name: 'Professional', price: 'Rp 1.2jt', period: '/bulan', features: ['Max 2000 Siswa', 'Absensi QR + Face', 'Laporan Real-time', 'WhatsApp Notif', 'Priority Support'], popular: true },
                    { name: 'Enterprise', price: 'Hubungi', period: '', features: ['Unlimited Siswa', 'Custom Features', 'Dedicated Server', 'SLA 99.9%', '24/7 Support'] }
                ].map((pkg, index) => (
                    <motion.div
                        key={index}
                        whileHover={{ y: -10 }}
                        className={`relative rounded-xl p-6 border ${pkg.popular ? 'bg-gradient-to-b from-blue-900 to-gray-800 border-blue-500 shadow-blue-900/20 shadow-xl' : 'bg-gray-800 border-gray-700'
                            }`}
                    >
                        {pkg.popular && (
                            <div className="absolute top-0 left-1/2 transform -translate-x-1/2 -translate-y-1/2 bg-blue-500 text-white text-xs font-bold px-3 py-1 rounded-full uppercase tracking-wider">
                                Most Popular
                            </div>
                        )}
                        <h3 className="text-xl font-bold mb-2">{pkg.name}</h3>
                        <div className="mb-6">
                            <span className="text-3xl font-bold">{pkg.price}</span>
                            <span className="text-gray-400 text-sm">{pkg.period}</span>
                        </div>
                        <ul className="space-y-3 mb-8">
                            {pkg.features.map((feature, idx) => (
                                <li key={idx} className="flex items-center text-sm text-gray-300">
                                    <span className="text-green-500 mr-2">✓</span> {feature}
                                </li>
                            ))}
                        </ul>
                        <button className={`w-full py-2 rounded-lg font-medium transition-colors ${pkg.popular ? 'bg-blue-600 hover:bg-blue-700 text-white' : 'bg-gray-700 hover:bg-gray-600 text-gray-300'
                            }`}>
                            Edit Paket
                        </button>
                    </motion.div>
                ))}
            </div>

            <div className="bg-gray-800 rounded-xl p-6 border border-gray-700 shadow-lg">
                <h3 className="text-xl font-bold mb-4">Riwayat Transaksi Terbaru</h3>
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-700">
                        <thead>
                            <tr>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Invoice ID</th>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Sekolah</th>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Paket</th>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Jumlah</th>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Tanggal</th>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-700">
                            {[
                                { id: 'INV-2023-001', school: 'SMA Negeri 1 Jakarta', package: 'Enterprise Yearly', amount: 'Rp 15.000.000', date: '01 Feb 2026', status: 'paid' },
                                { id: 'INV-2023-002', school: 'SMP Islam Terpadu', package: 'Professional Monthly', amount: 'Rp 1.200.000', date: '02 Feb 2026', status: 'paid' },
                                { id: 'INV-2023-003', school: 'SMK Teknologi', package: 'Professional Monthly', amount: 'Rp 1.200.000', date: '03 Feb 2026', status: 'pending' },
                                { id: 'INV-2023-004', school: 'SD Budi Luhur', package: 'Basic Monthly', amount: 'Rp 500.000', date: '03 Feb 2026', status: 'failed' }
                            ].map((inv, index) => (
                                <tr key={index} className="hover:bg-gray-750">
                                    <td className="px-4 py-3 whitespace-nowrap text-sm font-mono text-gray-400">{inv.id}</td>
                                    <td className="px-4 py-3 whitespace-nowrap font-medium">{inv.school}</td>
                                    <td className="px-4 py-3 whitespace-nowrap text-gray-300">{inv.package}</td>
                                    <td className="px-4 py-3 whitespace-nowrap font-medium">{inv.amount}</td>
                                    <td className="px-4 py-3 whitespace-nowrap text-gray-400">{inv.date}</td>
                                    <td className="px-4 py-3 whitespace-nowrap">
                                        <span className={`px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${inv.status === 'paid' ? 'bg-green-900 text-green-300' :
                                            inv.status === 'pending' ? 'bg-yellow-900 text-yellow-300' : 'bg-red-900 text-red-300'
                                            }`}>
                                            {inv.status.toUpperCase()}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
    const renderAttendanceReport = () => (
        <div className="space-y-6">
            <h2 className="text-2xl font-bold mb-6 flex items-center">
                <span className="mr-2">📈</span>Laporan Absensi Global
            </h2>

            <div className="bg-gray-800 rounded-xl p-6 border border-gray-700 shadow-lg">
                <h3 className="text-xl font-bold mb-4">Statistik Kehadiran Mingguan</h3>
                <div className="h-80 mb-6">
                    <ResponsiveContainer width="100%" height="100%">
                        <BarChart
                            data={attendanceData}
                            margin={{ top: 20, right: 30, left: 20, bottom: 5 }}
                        >
                            <CartesianGrid strokeDasharray="3 3" stroke="#374151" />
                            <XAxis dataKey="day" stroke="#9CA3AF" />
                            <YAxis stroke="#9CA3AF" />
                            <Tooltip
                                contentStyle={{ backgroundColor: '#1F2937', border: '1px solid #374151', borderRadius: '8px' }}
                                labelStyle={{ color: '#E5E7EB' }}
                                cursor={{ fill: 'rgba(55, 65, 81, 0.5)' }}
                            />
                            <Legend />
                            <Bar dataKey="hadir" name="Hadir" fill="#3B82F6" radius={[4, 4, 0, 0]} />
                            <Bar dataKey="izin" name="Izin" fill="#10B981" radius={[4, 4, 0, 0]} />
                            <Bar dataKey="alpha" name="Alpha" fill="#EF4444" radius={[4, 4, 0, 0]} />
                        </BarChart>
                    </ResponsiveContainer>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div className="bg-gray-700 p-4 rounded-lg border border-gray-600 text-center">
                        <h4 className="text-gray-400 mb-1">Rata-rata Kehadiran</h4>
                        <p className="text-3xl font-bold text-blue-400">92.5%</p>
                        <p className="text-green-400 text-sm mt-1">↑ 1.2% dari minggu lalu</p>
                    </div>
                    <div className="bg-gray-700 p-4 rounded-lg border border-gray-600 text-center">
                        <h4 className="text-gray-400 mb-1">Total Keterlambatan</h4>
                        <p className="text-3xl font-bold text-yellow-400">4.8%</p>
                        <p className="text-red-400 text-sm mt-1">↑ 0.5% dari minggu lalu</p>
                    </div>
                    <div className="bg-gray-700 p-4 rounded-lg border border-gray-600 text-center">
                        <h4 className="text-gray-400 mb-1">Top School Attendance</h4>
                        <p className="text-xl font-bold text-white mt-1">SMA Negeri 1 Jakarta</p>
                        <p className="text-blue-400 font-bold text-lg">98.2%</p>
                    </div>
                </div>
            </div>

            <div className="bg-gray-800 rounded-xl p-6 border border-gray-700 shadow-lg">
                <div className="flex justify-between items-center mb-4">
                    <h3 className="text-xl font-bold">Laporan Anomali Absensi</h3>
                    <button className="px-3 py-1 bg-blue-600 hover:bg-blue-700 rounded text-sm transition-colors">
                        Download Report
                    </button>
                </div>
                <div className="space-y-4">
                    {[
                        { school: 'SD Budi Luhur', issue: 'Tingkat alpha tinggi (>15%)', date: '03 Feb 2026', status: 'investigating' },
                        { school: 'SMK Teknologi', issue: 'Jam masuk tidak wajar (03:00)', date: '02 Feb 2026', status: 'resolved' },
                        { school: 'SMP Islam Terpadu', issue: 'Lonjakan absensi izin', date: '01 Feb 2026', status: 'pending' }
                    ].map((item, index) => (
                        <div key={index} className="flex items-center justify-between p-4 bg-gray-700 rounded-lg border border-gray-600">
                            <div>
                                <h4 className="font-bold text-white">{item.school}</h4>
                                <p className="text-red-400 text-sm">{item.issue}</p>
                                <p className="text-gray-500 text-xs mt-1">{item.date}</p>
                            </div>
                            <span className={`px-2 py-1 text-xs font-semibold rounded-full ${item.status === 'resolved' ? 'bg-green-900 text-green-300' :
                                item.status === 'investigating' ? 'bg-blue-900 text-blue-300' : 'bg-yellow-900 text-yellow-300'
                                }`}>
                                {item.status.toUpperCase()}
                            </span>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
    const renderFeatureFlags = () => (
        <div className="space-y-6">
            <h2 className="text-2xl font-bold mb-6 flex items-center">
                <span className="mr-2">⚙️</span>Feature Flags & Configuration
            </h2>

            <div className="bg-gray-800 rounded-xl p-6 border border-gray-700 shadow-lg">
                <h3 className="text-xl font-bold mb-6">Global Feature Flags</h3>
                <div className="space-y-6">
                    {[
                        { id: 'face_recognition', name: 'Face Recognition Attendance', desc: 'Enable facial recognition for attendance check-in', status: true, beta: false },
                        { id: 'location_fencing', name: 'GPS Geofencing Strict Mode', desc: 'Enforce strict GPS coordinates validation', status: true, beta: false },
                        { id: 'ai_analytics', name: 'AI Attendance Analytics', desc: 'Predictive analytics for student attendance patterns', status: false, beta: true },
                        { id: 'whatsapp_integration', name: 'WhatsApp Gateway v2', desc: 'New faster WhatsApp notification provider', status: true, beta: true },
                        { id: 'dark_mode_default', name: 'Force Dark Mode', desc: 'Default to dark mode for all new users', status: false, beta: false }
                    ].map((feature, index) => (
                        <div key={index} className="flex items-center justify-between p-4 bg-gray-700 rounded-lg border border-gray-600">
                            <div className="flex-1">
                                <div className="flex items-center">
                                    <h4 className="font-bold text-white mr-2">{feature.name}</h4>
                                    {feature.beta && (
                                        <span className="bg-purple-900 text-purple-300 text-xs px-2 py-0.5 rounded border border-purple-700">BETA</span>
                                    )}
                                </div>
                                <p className="text-gray-400 text-sm mt-1">{feature.desc}</p>
                                <code className="text-xs text-gray-500 mt-2 block">{feature.id}</code>
                            </div>
                            <div className="ml-4">
                                <label className="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" className="sr-only peer" checked={feature.status} readOnly />
                                    <div className="w-11 h-6 bg-gray-600 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-800 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                                </label>
                            </div>
                        </div>
                    ))}
                </div>
            </div>

            <div className="bg-gray-800 rounded-xl p-6 border border-gray-700 shadow-lg">
                <h3 className="text-xl font-bold mb-4 flex items-center text-yellow-500">
                    <span className="mr-2">⚠️</span>Dangerous Zone
                </h3>
                <p className="text-gray-400 mb-6">Tindakan berikut dapat mempengaruhi seluruh sistem dan harus dilakukan dengan hati-hati.</p>

                <div className="space-y-4">
                    <div className="flex items-center justify-between p-4 border border-red-800 rounded-lg bg-red-900 bg-opacity-10">
                        <div>
                            <h4 className="font-bold text-red-400">Maintenance Mode</h4>
                            <p className="text-gray-400 text-sm">Aktifkan mode pemeliharaan untuk seluruh sistem. Hanya Super Admin yang dapat login.</p>
                        </div>
                        <button className="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg font-medium transition-colors">
                            Aktifkan
                        </button>
                    </div>

                    <div className="flex items-center justify-between p-4 border border-yellow-800 rounded-lg bg-yellow-900 bg-opacity-10">
                        <div>
                            <h4 className="font-bold text-yellow-400">Flush System Cache</h4>
                            <p className="text-gray-400 text-sm">Bersihkan seluruh cache aplikasi global (Redis).</p>
                        </div>
                        <button className="px-4 py-2 bg-yellow-600 hover:bg-yellow-700 text-white rounded-lg font-medium transition-colors">
                            Clear Cache
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );

    const renderContent = () => {
        if (loading) {
            return (
                <div className="flex items-center justify-center h-full">
                    <div className="text-center">
                        <div className="w-16 h-16 border-4 border-blue-500 border-t-transparent rounded-full animate-spin mx-auto mb-4"></div>
                        <p className="text-xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-blue-400 to-purple-500">
                            Memuat Dashboard...
                        </p>
                        <p className="text-gray-400 mt-2">Mempersiapkan data sistem global</p>
                    </div>
                </div>
            );
        }

        switch (activeMenu) {
            case 'dashboard-overview': return renderDashboardOverview();
            case 'dashboard-school-stats': return renderSchoolStats();
            case 'schools-list': return renderSchoolsList();
            case 'monitoring-health': return renderSystemHealth();
            case 'security-events': return renderSecurityEvents();
            case 'billing-packages': return renderBillingPackages();
            case 'reports-attendance': return renderAttendanceReport();
            case 'settings-flags': return renderFeatureFlags();
            default:
                return (
                    <motion.div
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        transition={{ duration: 0.3 }}
                        className="bg-gray-800 rounded-xl p-8 border border-gray-700 shadow-lg text-center"
                    >
                        <h2 className="text-2xl font-bold mb-4">{getActiveMenuLabel()}</h2>
                        <p className="text-gray-400 mb-8">Konten untuk menu ini akan dikembangkan lebih lanjut</p>
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6 max-w-4xl mx-auto">
                            <div className="bg-gray-700 p-6 rounded-xl border border-gray-600">
                                <div className="text-5xl mb-4 animate-bounce">📊</div>
                                <h3 className="text-xl font-bold mb-2">Visualisasi Data</h3>
                                <p className="text-gray-300">Grafik dan diagram interaktif untuk analisis mendalam</p>
                            </div>
                            <div className="bg-gray-700 p-6 rounded-xl border border-gray-600">
                                <div className="text-5xl mb-4 animate-pulse">⚙️</div>
                                <h3 className="text-xl font-bold mb-2">Konfigurasi Lanjutan</h3>
                                <p className="text-gray-300">Pengaturan khusus untuk kebutuhan spesifik sistem</p>
                            </div>
                            <div className="bg-gray-700 p-6 rounded-xl border border-gray-600">
                                <div className="text-5xl mb-4 animate-spin">🔄</div>
                                <h3 className="text-xl font-bold mb-2">Integrasi Sistem</h3>
                                <p className="text-gray-300">Konektivitas dengan layanan eksternal dan API</p>
                            </div>
                            <div className="bg-gray-700 p-6 rounded-xl border border-gray-600">
                                <div className="text-5xl mb-4 animate-wiggle">🚀</div>
                                <h3 className="text-xl font-bold mb-2">Performa Optimal</h3>
                                <p className="text-gray-300">Pemantauan dan optimasi untuk pengalaman terbaik</p>
                            </div>
                        </div>
                    </motion.div>
                );
        }
    };

    return (
        <div className="flex h-screen bg-gradient-to-br from-gray-900 via-gray-900 to-black text-gray-100 overflow-hidden">
            {/* Sidebar */}
            <AnimatePresence>
                {(sidebarOpen || window.innerWidth > 768) && (
                    <motion.div
                        initial={{ x: -300 }}
                        animate={{ x: 0 }}
                        exit={{ x: -300 }}
                        transition={{ type: "spring", damping: 25 }}
                        className={`fixed md:static z-40 h-full w-64 md:w-72 bg-gray-900 border-r border-gray-800 shadow-xl overflow-y-auto`}
                    >
                        <div className="p-4 border-b border-gray-800">
                            <div className="flex items-center space-x-2">
                                <div className="bg-gradient-to-r from-blue-600 to-purple-700 w-10 h-10 rounded-xl flex items-center justify-center font-bold text-xl shadow-lg">
                                    A
                                </div>
                                <div>
                                    <h1 className="font-bold text-xl bg-clip-text text-transparent bg-gradient-to-r from-blue-400 to-purple-500">
                                        AbsensiQR Pro
                                    </h1>
                                    <p className="text-xs text-gray-400">Super Admin Dashboard</p>
                                </div>
                            </div>
                        </div>

                        <nav className="mt-2 px-2">
                            {menuItems.map((item) => (
                                <div key={item.id} className="mb-1">
                                    <motion.button
                                        whileHover={{ x: 5 }}
                                        whileTap={{ scale: 0.98 }}
                                        onClick={() => toggleMenu(item.id)}
                                        className={`flex items-center justify-between w-full text-left px-4 py-3 rounded-lg mb-1 transition-all ${expandedMenus[item.id as keyof typeof expandedMenus]
                                            ? 'bg-gray-800 text-blue-400 border-l-4 border-blue-500'
                                            : 'text-gray-300 hover:bg-gray-800'
                                            }`}
                                    >
                                        <div className="flex items-center">
                                            <span className="text-xl mr-3">{item.icon}</span>
                                            <span className="font-medium">{item.label}</span>
                                        </div>
                                        {item.submenus && (
                                            <span className={`transform transition-transform duration-300 ${expandedMenus[item.id as keyof typeof expandedMenus] ? 'rotate-180' : ''
                                                }`}>
                                                ▼
                                            </span>
                                        )}
                                    </motion.button>

                                    {item.submenus && expandedMenus[item.id as keyof typeof expandedMenus] && (
                                        <motion.div
                                            initial={{ opacity: 0, height: 0 }}
                                            animate={{ opacity: 1, height: "auto" }}
                                            exit={{ opacity: 0, height: 0 }}
                                            transition={{ duration: 0.3 }}
                                            className="ml-4 mt-1 mb-2"
                                        >
                                            {item.submenus.map((submenu) => (
                                                <motion.button
                                                    key={submenu.id}
                                                    whileHover={{ x: 5 }}
                                                    whileTap={{ scale: 0.98 }}
                                                    onClick={() => {
                                                        setActiveMenu(submenu.id);
                                                        setSidebarOpen(false);
                                                    }}
                                                    className={`flex items-center w-full text-left px-4 py-2 rounded-lg mb-1 transition-all text-sm ${activeMenu === submenu.id
                                                        ? 'bg-gradient-to-r from-blue-600 to-purple-700 text-white font-medium shadow-md'
                                                        : 'text-gray-300 hover:bg-gray-800'
                                                        }`}
                                                >
                                                    <span className="mr-2">•</span>
                                                    <span>{submenu.label}</span>
                                                </motion.button>
                                            ))}
                                        </motion.div>
                                    )}
                                </div>
                            ))}
                        </nav>

                        <div className="absolute bottom-0 left-0 right-0 p-4 border-t border-gray-800 bg-gray-900">
                            <div className="flex items-center justify-between">
                                <div className="flex items-center">
                                    <div className="bg-green-500 w-3 h-3 rounded-full mr-3 animate-pulse"></div>
                                    <span className="text-sm text-gray-300">Sistem Online</span>
                                </div>
                                <span className="text-xs text-gray-500 bg-gray-800 px-2 py-1 rounded">v2.5.1</span>
                            </div>
                        </div>
                    </motion.div>
                )}
            </AnimatePresence>

            {/* Main Content */}
            <div className="flex flex-col flex-1">
                {/* Top Bar */}
                <header className="bg-gray-900 border-b border-gray-800 p-4 flex items-center justify-between shadow-lg">
                    <div className="flex items-center">
                        <button
                            onClick={() => setSidebarOpen(!sidebarOpen)}
                            className="md:hidden mr-4 p-2 hover:bg-gray-800 rounded-lg transition-colors"
                        >
                            <div className="w-6 h-0.5 bg-blue-400 mb-1.5 rounded-full"></div>
                            <div className="w-6 h-0.5 bg-blue-400 mb-1.5 rounded-full"></div>
                            <div className="w-6 h-0.5 bg-blue-400 rounded-full"></div>
                        </button>
                        <div className="relative hidden md:block">
                            <input
                                type="text"
                                placeholder="Cari di dashboard..."
                                className="bg-gray-800 text-gray-200 rounded-lg py-2 px-4 w-64 focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            <div className="absolute right-3 top-2.5 text-gray-400">🔍</div>
                        </div>
                    </div>

                    <div className="flex items-center space-x-4">
                        <button className="p-2 hover:bg-gray-800 rounded-lg relative">
                            🔔
                            <span className="absolute top-1 right-1 bg-red-500 text-xs w-5 h-5 rounded-full flex items-center justify-center animate-pulse">3</span>
                        </button>
                        <button className="p-2 hover:bg-gray-800 rounded-lg relative">
                            📧
                            <span className="absolute top-1 right-1 bg-blue-500 text-xs w-5 h-5 rounded-full flex items-center justify-center">7</span>
                        </button>
                        <div className="flex items-center bg-gray-800 rounded-lg p-2">
                            <div className="w-8 h-8 rounded-full bg-gradient-to-r from-blue-500 to-purple-600 flex items-center justify-center font-bold mr-2">
                                SA
                            </div>
                            <div className="hidden md:block">
                                <p className="text-sm font-medium">Super Admin</p>
                                <p className="text-xs text-gray-400">admin@absensiqrpro.com</p>
                            </div>
                            <div className="ml-2 text-xs">▼</div>
                        </div>
                    </div>
                </header>

                {/* Page Content */}
                <main className="p-4 md:p-6 flex-1 overflow-y-auto">
                    {/* Mock data warning banner */}
                    <div className="mb-4 px-4 py-3 rounded-lg bg-yellow-500/20 border border-yellow-500/40 text-yellow-300 text-sm font-medium">
                        ⚠️ Dashboard V2 - Data masih menggunakan mock/contoh, belum terhubung ke API
                    </div>
                    <motion.h1
                        initial={{ opacity: 0, y: -20 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.5 }}
                        className="text-2xl md:text-3xl font-bold mb-6 bg-clip-text text-transparent bg-gradient-to-r from-blue-400 to-purple-500"
                    >
                        {getActiveMenuLabel()}
                    </motion.h1>

                    {renderContent()}
                </main>

                {/* Footer */}
                <footer className="bg-gray-900 border-t border-gray-800 p-4 text-center text-gray-500 text-sm">
                    <p>© 2026 AbsensiQR Pro - Super Admin Dashboard. Semua hak dilindungi.</p>
                </footer>
            </div>

            {/* Mobile Overlay */}
            {sidebarOpen && (
                <div
                    className="fixed inset-0 bg-black bg-opacity-50 z-30 md:hidden"
                    onClick={() => setSidebarOpen(false)}
                ></div>
            )}
        </div>
    );
};
