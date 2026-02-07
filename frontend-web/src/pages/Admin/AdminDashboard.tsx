import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { motion, type Variants } from 'framer-motion';
import {
    Users,
    CheckCircle2,
    AlertTriangle,
    Clock,
    Activity,
    FileEdit,
    UserX,
    TrendingUp,
    Shield,
    AlertCircle,
    Calendar,
    ArrowRight
} from 'lucide-react';
import {
    useDailyReport,
    useClassAttendanceSummary,
    useTeacherAbsence,
    useLateAlpha,
} from '../../modules/admin/hooks';
import { useRiskOverview } from '../../modules/admin/hooks/useAdminService';
import { AnnouncementWidget } from '../../components/AnnouncementWidget';
import Loading from '../../components/common/Loading';
import ErrorMessage from '../../components/common/ErrorMessage';
import {
    BarChart,
    Bar,
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

const AdminDashboard: React.FC = () => {
    const navigate = useNavigate();
    const selectedDate = new Date().toISOString().split('T')[0];
    const [loading, setLoading] = useState(true);

    // Fetch Data Hooks
    const { data: stats, isLoading: statsLoading, error, refetch } = useDailyReport(selectedDate);
    const { data: classSummary } = useClassAttendanceSummary(selectedDate);
    const { data: teacherAbsent } = useTeacherAbsence(selectedDate);
    const { data: lateAlpha } = useLateAlpha(selectedDate);
    const { data: riskData } = useRiskOverview();

    useEffect(() => {
        if (!statsLoading) {
            const timer = setTimeout(() => setLoading(false), 800);
            return () => clearTimeout(timer);
        }
    }, [statsLoading]);

    // Prepare Charts Data
    const attendancePieData = stats ? [
        { name: 'Hadir', value: stats.present, color: '#10B981' }, // Emerald-500
        { name: 'Terlambat', value: stats.late, color: '#F59E0B' }, // Amber-500
        { name: 'Sakit/Izin', value: (stats.sick + stats.permission), color: '#3B82F6' }, // Blue-500
        { name: 'Alpa', value: stats.alpha, color: '#EF4444' }, // Red-500
    ] : [];

    // Filter class data for chart
    const classChartData = classSummary?.classes
        ?.slice()
        .sort((a, b) => (parseInt(a?.present?.toString() || '0') - parseInt(b?.present?.toString() || '0')))
        .slice(0, 7)
        .map(c => ({
            name: c.class_name,
            Hadir: parseInt(c.present?.toString() || '0'),
            Alpa: parseInt(c.alpha?.toString() || '0'),
            Terlambat: parseInt(c.late?.toString() || '0')
        })) || [];

    if (loading || (statsLoading && !stats)) return <Loading text="Memuat Dashboard..." />;

    if (error) {
        return (
            <div className="min-h-screen bg-slate-50 flex items-center justify-center p-4">
                <ErrorMessage message="Gagal memuat laporan harian" onRetry={() => refetch()} />
            </div>
        );
    }

    const containerVariants: Variants = {
        hidden: { opacity: 0 },
        visible: {
            opacity: 1,
            transition: {
                staggerChildren: 0.1
            }
        }
    };

    const itemVariants: Variants = {
        hidden: { y: 20, opacity: 0 },
        visible: {
            y: 0,
            opacity: 1,
            transition: {
                type: "spring",
                stiffness: 100
            }
        }
    };

    return (
        <motion.div
            className="pb-12 font-sans text-slate-900"
            variants={containerVariants}
            initial="hidden"
            animate="visible"
        >
            {/* Header Section */}
            <div className="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h1 className="text-3xl font-bold text-slate-900 tracking-tight flex items-center gap-3">
                        Dashboard Sekolah
                        <span className="px-3 py-1 rounded-full bg-blue-600 text-white text-xs font-bold shadow-sm shadow-blue-500/30">
                            ADMIN PANEL
                        </span>
                    </h1>
                    <p className="text-slate-500 mt-2 text-lg">Ringkasan aktivitas akademik & operasional hari ini.</p>
                </div>

                <div className="flex items-center gap-3">
                    <button onClick={() => navigate('/admin/school-profile')}
                        className="px-5 py-2.5 rounded-xl bg-white border border-slate-200 text-slate-700 font-semibold hover:bg-slate-50 hover:border-slate-300 shadow-sm transition-all flex items-center gap-2">
                        <Shield size={18} /> Profil Sekolah
                    </button>
                    <button onClick={() => navigate('/admin/active-academic-year')}
                        className="px-5 py-2.5 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 text-white font-semibold hover:shadow-lg hover:shadow-blue-500/30 transition-all flex items-center gap-2">
                        <Calendar size={18} /> Tahun Ajaran Aktif
                    </button>
                </div>
            </div>

            {/* 1. Quick Stats (Hero Cards) */}
            <motion.div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8" variants={itemVariants}>
                {[
                    { title: "Siswa Terdaftar", value: stats?.total_students, bg: "from-blue-500 to-blue-600", icon: Users, shadow: "shadow-blue-200" },
                    { title: "Presentase Hadir", value: `${stats?.total_students ? Math.round((stats.present / stats.total_students) * 100) : 0}%`, bg: "from-emerald-500 to-emerald-600", icon: CheckCircle2, shadow: "shadow-emerald-200" },
                    { title: "Siswa Terlambat", value: stats?.late, bg: "from-amber-400 to-amber-500", icon: Clock, shadow: "shadow-amber-200" },
                    { title: "Tanpa Keterangan", value: stats?.alpha, bg: "from-red-500 to-red-600", icon: AlertTriangle, shadow: "shadow-red-200" },
                    { title: "Risiko Tinggi", value: riskData?.high_risk_count || 0, bg: "from-orange-500 to-orange-600", icon: AlertCircle, shadow: "shadow-orange-200", onClick: () => navigate('/admin/risk-overview') }
                ].map((stat, idx) => (
                    <motion.div
                        key={idx}
                        whileHover={{ y: -5 }}
                        onClick={stat.onClick}
                        className={`relative overflow-hidden rounded-2xl bg-white p-6 shadow-lg border border-slate-100 ${stat.onClick ? 'cursor-pointer' : ''}`}
                    >
                        <div className={`absolute top-0 right-0 p-3 opacity-10 bg-gradient-to-br ${stat.bg} rounded-bl-3xl`}>
                            <stat.icon size={48} />
                        </div>
                        <div className="relative z-10">
                            <div className={`w-12 h-12 rounded-xl bg-gradient-to-br ${stat.bg} flex items-center justify-center text-white shadow-xl ${stat.shadow} mb-4`}>
                                <stat.icon size={24} />
                            </div>
                            <h3 className="text-3xl font-bold text-slate-800">{stat.value}</h3>
                            <p className="text-sm font-medium text-slate-500 mt-1">{stat.title}</p>
                        </div>
                    </motion.div>
                ))}
            </motion.div>

            {/* 2. Quick Actions & Anomalies */}
            <motion.div className="grid grid-cols-1 lg:grid-cols-4 gap-6 mb-8" variants={itemVariants}>
                {/* Quick Actions Panel */}
                <div className="lg:col-span-3 bg-white rounded-2xl p-6 shadow-sm border border-slate-100">
                    <h3 className="text-lg font-bold text-slate-800 mb-6 flex items-center gap-2">
                        <Activity className="text-blue-500" /> Akses Cepat
                    </h3>
                    <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                        {[
                            { label: "Input Izin Manual", icon: FileEdit, color: "text-blue-600", bg: "bg-blue-50", to: '/admin/attendance/override' },
                            { label: "Guru Tidak Hadir", icon: UserX, color: "text-red-600", bg: "bg-red-50", to: '/admin/dashboard/teacher-absent' },
                            { label: "Laporan Harian", icon: Activity, color: "text-purple-600", bg: "bg-purple-50", to: '/admin/reports/class' },
                            { label: "Pengaturan Sekolah", icon: Shield, color: "text-indigo-600", bg: "bg-indigo-50", to: '/admin/settings' }
                        ].map((action, idx) => (
                            <motion.button
                                key={idx}
                                whileHover={{ scale: 1.02 }}
                                whileTap={{ scale: 0.98 }}
                                onClick={() => navigate(action.to)}
                                className="flex flex-col items-center justify-center p-6 border border-slate-100 rounded-2xl hover:border-slate-200 hover:shadow-md transition-all group"
                            >
                                <div className={`w-14 h-14 ${action.bg} ${action.color} rounded-2xl flex items-center justify-center mb-3 group-hover:scale-110 transition-transform shadow-sm`}>
                                    <action.icon size={28} />
                                </div>
                                <span className="font-semibold text-slate-700 text-sm group-hover:text-slate-900 text-center">{action.label}</span>
                            </motion.button>
                        ))}
                    </div>
                </div>

                {/* Announcements Wrapper */}
                <div className="lg:col-span-1">
                    <AnnouncementWidget />
                </div>
            </motion.div>

            {/* 3. Main Charts Section */}
            <motion.div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8" variants={itemVariants}>
                {/* Class Performance Chart */}
                <div className="lg:col-span-2 bg-white p-6 rounded-2xl border border-slate-100 shadow-lg shadow-slate-200/50">
                    <div className="flex items-center justify-between mb-6">
                        <h3 className="text-lg font-bold text-slate-800 flex items-center gap-2">
                            <TrendingUp className="text-emerald-500" />
                            Performa Kehadiran (Terbawah)
                        </h3>
                        <button className="text-sm font-medium text-blue-600 hover:text-blue-700 flex items-center gap-1">
                            Lihat Semua <ArrowRight size={14} />
                        </button>
                    </div>
                    <div className="h-[350px] w-full">
                        {classChartData.length > 0 ? (
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart data={classChartData} margin={{ top: 20, right: 30, left: 20, bottom: 5 }}>
                                    <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#E2E8F0" />
                                    <XAxis dataKey="name" axisLine={false} tickLine={false} tick={{ fill: '#64748B', fontSize: 12 }} dy={10} />
                                    <YAxis axisLine={false} tickLine={false} tick={{ fill: '#64748B', fontSize: 12 }} />
                                    <Tooltip
                                        cursor={{ fill: '#F8FAFC' }}
                                        contentStyle={{ borderRadius: '12px', border: 'none', boxShadow: '0 10px 15px -3px rgb(0 0 0 / 0.1)', padding: '12px' }}
                                    />
                                    <Legend wrapperStyle={{ paddingTop: '20px' }} />
                                    <Bar dataKey="Hadir" fill="#10B981" radius={[6, 6, 0, 0]} barSize={32} />
                                    <Bar dataKey="Terlambat" fill="#F59E0B" radius={[6, 6, 0, 0]} barSize={32} />
                                    <Bar dataKey="Alpa" fill="#EF4444" radius={[6, 6, 0, 0]} barSize={32} />
                                </BarChart>
                            </ResponsiveContainer>
                        ) : (
                            <div className="h-full flex flex-col items-center justify-center text-slate-400 gap-3">
                                <Activity size={48} className="opacity-20" />
                                <span>Belum ada data visualisasi kelas.</span>
                            </div>
                        )}
                    </div>
                </div>

                {/* Attendance Distribution Pie */}
                <div className="bg-white p-6 rounded-2xl border border-slate-100 shadow-lg shadow-slate-200/50 flex flex-col">
                    <h3 className="text-lg font-bold text-slate-800 mb-6">Distribusi Kehadiran</h3>
                    <div className="flex-1 min-h-[300px] relative">
                        <ResponsiveContainer width="100%" height="100%">
                            <PieChart>
                                <Pie
                                    data={attendancePieData}
                                    cx="50%"
                                    cy="50%"
                                    innerRadius={70}
                                    outerRadius={100}
                                    paddingAngle={5}
                                    dataKey="value"
                                    cornerRadius={8}
                                >
                                    {attendancePieData.map((entry, index) => (
                                        <Cell key={`cell-${index}`} fill={entry.color} strokeWidth={0} />
                                    ))}
                                </Pie>
                                <Tooltip contentStyle={{ borderRadius: '12px', border: 'none', boxShadow: '0 10px 15px -3px rgb(0 0 0 / 0.1)' }} />
                                <Legend verticalAlign="bottom" height={36} iconType="circle" />
                            </PieChart>
                        </ResponsiveContainer>
                        {/* Center Text */}
                        <div className="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 text-center -mt-8">
                            <span className="block text-4xl font-extrabold text-slate-800 tracking-tight">{stats?.total_students || 0}</span>
                            <span className="text-xs text-slate-400 font-bold uppercase tracking-widest">Total Siswa</span>
                        </div>
                    </div>
                </div>
            </motion.div>

            {/* 4. Critical Alerts Section */}
            <motion.div className="grid grid-cols-1 md:grid-cols-2 gap-6" variants={itemVariants}>
                {/* Teacher Absence - Critical */}
                <div className="bg-white rounded-2xl border border-red-100 shadow-sm overflow-hidden group hover:shadow-md transition-all">
                    <div className="px-6 py-5 border-b border-red-50 bg-red-50/30 flex justify-between items-center">
                        <h3 className="font-bold text-red-700 flex items-center gap-3">
                            <div className="p-2 bg-red-100 rounded-lg">
                                <AlertTriangle className="w-5 h-5" />
                            </div>
                            Guru Tidak Hadir
                        </h3>
                        <button onClick={() => navigate('/admin/dashboard/teacher-absent')} className="text-xs font-bold text-red-600 hover:text-red-700 flex items-center gap-1 bg-white px-3 py-1.5 rounded-lg border border-red-100 shadow-sm">
                            Lihat Detail <ArrowRight size={12} />
                        </button>
                    </div>
                    <div className="divide-y divide-slate-50">
                        {teacherAbsent?.teachers?.length ? (
                            teacherAbsent.teachers.map((t) => (
                                <div key={t.teacher_id} className="p-5 flex items-center justify-between hover:bg-red-50/20 transition-colors">
                                    <div className="flex items-center gap-4">
                                        <div className="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center text-red-600 font-bold text-sm">
                                            {t.teacher_name.substring(0, 2)}
                                        </div>
                                        <div>
                                            <p className="font-bold text-slate-800">{t.teacher_name}</p>
                                            <p className="text-xs text-slate-500 font-medium">Missing: <span className="text-red-600">{t.missing_qr} Kode QR</span></p>
                                        </div>
                                    </div>
                                    <span className="px-3 py-1 rounded-full bg-red-100 text-red-700 text-xs font-bold shadow-sm">ALPHA</span>
                                </div>
                            ))
                        ) : (
                            <div className="p-8 flex flex-col items-center justify-center text-center">
                                <div className="w-16 h-16 bg-green-50 rounded-full flex items-center justify-center mb-3">
                                    <CheckCircle2 size={32} className="text-green-500" />
                                </div>
                                <p className="text-slate-900 font-medium">Semua Guru Hadir</p>
                                <p className="text-slate-500 text-sm">Tidak ada absensi guru yang kosong.</p>
                            </div>
                        )}
                    </div>
                </div>

                {/* Late Students List */}
                <div className="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden group hover:shadow-md transition-all">
                    <div className="px-6 py-5 border-b border-slate-50 bg-slate-50/50 flex justify-between items-center">
                        <h3 className="font-bold text-slate-800 flex items-center gap-3">
                            <div className="p-2 bg-amber-100 rounded-lg">
                                <Clock className="w-5 h-5 text-amber-600" />
                            </div>
                            Siswa Terlambat Terbaru
                        </h3>
                        <button onClick={() => navigate('/admin/dashboard/late-absent')} className="text-xs font-bold text-blue-600 hover:text-blue-700 flex items-center gap-1 bg-white px-3 py-1.5 rounded-lg border border-slate-200 shadow-sm">
                            Lihat Semua <ArrowRight size={12} />
                        </button>
                    </div>
                    <div className="divide-y divide-slate-50">
                        {lateAlpha?.late?.students?.slice(0, 5).map((s) => (
                            <div key={`${s.student_id}`} className="p-5 flex items-center justify-between hover:bg-slate-50 transition-colors">
                                <div className="flex items-center gap-3">
                                    <div className="w-10 h-10 rounded-full bg-gradient-to-br from-amber-100 to-amber-200 flex items-center justify-center text-amber-700 text-xs font-bold shadow-sm border border-amber-200">
                                        {s.student_name.substring(0, 2)}
                                    </div>
                                    <div>
                                        <p className="font-bold text-slate-900 text-sm">{s.student_name}</p>
                                        <p className="text-xs text-slate-500 font-medium">{s.class_name}</p>
                                    </div>
                                </div>
                                <span className="text-xs font-bold text-amber-600 bg-amber-50 px-2 py-1 rounded border border-amber-100">
                                    {s.time || 'Terlambat'}
                                </span>
                            </div>
                        )) || (
                                <div className="p-8 flex flex-col items-center justify-center text-center">
                                    <div className="w-16 h-16 bg-blue-50 rounded-full flex items-center justify-center mb-3">
                                        <Activity size={32} className="text-blue-500" />
                                    </div>
                                    <p className="text-slate-900 font-medium">Tidak Ada Keterlambatan</p>
                                    <p className="text-slate-500 text-sm">Semua siswa hadir tepat waktu hari ini.</p>
                                </div>
                            )}
                    </div>
                </div>
            </motion.div>
        </motion.div>
    );
};

export default AdminDashboard;
