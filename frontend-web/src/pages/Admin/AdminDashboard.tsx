import React, { useState } from 'react';
import Loading from '../../components/common/Loading';
import ErrorMessage from '../../components/common/ErrorMessage';
import {
    Users,
    CheckCircle2,
    AlertTriangle,
    Clock,
    Activity,
    FileEdit,
    UserX,
    TrendingUp,
    Shield
} from 'lucide-react';
import {
    useDailyReport,
    useClassAttendanceSummary,
    useTeacherAbsence,
    useLateAlpha,
    useAttendanceAnomalies,
} from '../../modules/admin/hooks';
import { AnnouncementWidget } from '../../components/AnnouncementWidget';
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
import { useNavigate } from 'react-router-dom';

const AdminDashboard: React.FC = () => {
    const navigate = useNavigate();
    const [selectedDate, setSelectedDate] = useState<string>(new Date().toISOString().split('T')[0]);

    // Fetch Data Hooks
    const { data: stats, isLoading, error, refetch } = useDailyReport(selectedDate);
    const { data: classSummary } = useClassAttendanceSummary(selectedDate);
    const { data: teacherAbsent } = useTeacherAbsence(selectedDate);
    const { data: lateAlpha } = useLateAlpha(selectedDate);
    const { data: anomalies } = useAttendanceAnomalies(selectedDate);

    // Prepare Charts Data
    const attendancePieData = stats ? [
        { name: 'Hadir', value: stats.present, color: '#22c55e' }, // Green
        { name: 'Terlambat', value: stats.late, color: '#eab308' }, // Yellow
        { name: 'Sakit/Izin', value: (stats.sick + stats.permission), color: '#3b82f6' }, // Blue
        { name: 'Alpa', value: stats.alpha, color: '#ef4444' }, // Red
    ] : [];

    // Filter class data for chart (Top 10 classes with lowest attendance maybe? Or just all if few)
    // For visualization let's show top 10 lowest presence to alert admin
    const classChartData = classSummary?.classes
        ?.slice() // Copy array before sorting
        .sort((a, b) => (parseInt(a?.present?.toString() || '0') - parseInt(b?.present?.toString() || '0'))) // Safe Sort
        .slice(0, 10)
        .map(c => ({
            name: c.class_name,
            Hadir: parseInt(c.present?.toString() || '0'),
            Alpa: parseInt(c.alpha?.toString() || '0'),
            Terlambat: parseInt(c.late?.toString() || '0')
        })) || [];

    if (isLoading && !stats) return <Loading text="Menyiapkan Dashboard Sekolah..." />;

    if (error) {
        return (
            <div className="min-h-screen bg-slate-50 flex items-center justify-center p-4">
                <ErrorMessage message="Gagal memuat laporan harian" onRetry={() => refetch()} />
            </div>
        );
    }

    return (
        <div className="min-h-screen bg-slate-50 pb-12 font-sans text-slate-900">
            {/* Header Section */}
            <div className="bg-white border-b border-slate-200 sticky top-0 z-30 shadow-sm backdrop-blur-md bg-white/90">
                <div className="px-6 py-4 flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 tracking-tight">Dashboard Sekolah</h1>
                        <p className="text-sm text-slate-500">Ringkasan aktivitas akademik hari ini.</p>
                    </div>

                    <div className="flex items-center gap-3">
                        <button onClick={() => navigate('/admin/school-profile')} className="px-4 py-2 rounded-lg bg-blue-50 text-blue-700 font-semibold hover:bg-blue-100">Profil Sekolah</button>
                        <button onClick={() => navigate('/admin/active-academic-year')} className="px-4 py-2 rounded-lg bg-green-50 text-green-700 font-semibold hover:bg-green-100">Tahun Ajaran Aktif</button>
                    </div>
                </div>
            </div>

            <div className="p-6 max-w-[1600px] mx-auto space-y-6">

                {/* 1. Quick Stats (Hero Cards) */}
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div className="p-6 rounded-2xl bg-white border border-slate-200 shadow-sm">
                        <div className="flex items-center justify-between mb-4">
                            <div className="p-2 bg-blue-50 text-blue-600 rounded-lg">
                                <Users className="w-6 h-6" />
                            </div>
                            <span className="text-xs font-semibold bg-slate-100 text-slate-600 px-2 py-1 rounded-full">Total</span>
                        </div>
                        <div className="flex items-end justify-between">
                            <div>
                                <h3 className="text-3xl font-bold text-slate-900">{stats?.total_students}</h3>
                                <p className="text-sm text-slate-500 font-medium">Siswa Terdaftar</p>
                            </div>
                        </div>
                    </div>

                    <div className="p-6 rounded-2xl bg-white border border-slate-200 shadow-sm relative overflow-hidden">
                        <div className="flex items-center justify-between mb-4 relative z-10">
                            <div className="p-2 bg-green-50 text-green-600 rounded-lg">
                                <CheckCircle2 className="w-6 h-6" />
                            </div>
                            <span className="text-xs font-semibold bg-green-100 text-green-700 px-2 py-1 rounded-full">
                                {stats?.total_students ? Math.round((stats.present / stats.total_students) * 100) : 0}% Rate
                            </span>
                        </div>
                        <div className="relative z-10">
                            <h3 className="text-3xl font-bold text-slate-900">{stats?.present}</h3>
                            <p className="text-sm text-slate-500 font-medium">Siswa Hadir</p>
                        </div>
                        {/* Background Decoration */}
                        <div className="absolute right-0 bottom-0 opacity-5 transform translate-x-4 translate-y-4">
                            <CheckCircle2 className="w-32 h-32 text-green-600" />
                        </div>
                    </div>

                    <div className="p-6 rounded-2xl bg-white border border-slate-200 shadow-sm">
                        <div className="flex items-center justify-between mb-4">
                            <div className="p-2 bg-amber-50 text-amber-600 rounded-lg">
                                <Clock className="w-6 h-6" />
                            </div>
                            <span className="text-xs font-semibold bg-amber-100 text-amber-700 px-2 py-1 rounded-full">Terlambat</span>
                        </div>
                        <div>
                            <h3 className="text-3xl font-bold text-slate-900">{stats?.late}</h3>
                            <p className="text-sm text-slate-500 font-medium">Siswa Terlambat</p>
                        </div>
                    </div>

                    <div className="p-6 rounded-2xl bg-white border border-slate-200 shadow-sm">
                        <div className="flex items-center justify-between mb-4">
                            <div className="p-2 bg-red-50 text-red-600 rounded-lg">
                                <AlertTriangle className="w-6 h-6" />
                            </div>
                            <span className="text-xs font-semibold bg-red-100 text-red-700 px-2 py-1 rounded-full">Perlu Tindakan</span>
                        </div>
                        <div>
                            <h3 className="text-3xl font-bold text-slate-900">{stats?.alpha}</h3>
                            <p className="text-sm text-slate-500 font-medium">Tanpa Keterangan (Alpha)</p>
                        </div>
                    </div>
                </div>

                {/* 2. Quick Actions & Anomalies */}
                <div className="grid grid-cols-1 lg:grid-cols-4 gap-6">
                    {/* Quick Actions Panel */}
                    <div className="lg:col-span-3 grid grid-cols-2 md:grid-cols-4 gap-4">
                        <button onClick={() => navigate('/admin/attendance/override')}
                            className="flex flex-col items-center justify-center p-4 bg-white border border-slate-200 rounded-xl hover:shadow-md hover:border-blue-300 transition-all group">
                            <div className="w-10 h-10 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                                <FileEdit className="w-5 h-5" />
                            </div>
                            <span className="text-sm font-semibold text-slate-700">Input Izin Manual</span>
                        </button>
                        <button onClick={() => navigate('/admin/dashboard/teacher-absent')}
                            className="flex flex-col items-center justify-center p-4 bg-white border border-slate-200 rounded-xl hover:shadow-md hover:border-red-300 transition-all group">
                            <div className="w-10 h-10 rounded-full bg-red-50 text-red-600 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                                <UserX className="w-5 h-5" />
                            </div>
                            <span className="text-sm font-semibold text-slate-700">Guru Tidak Hadir</span>
                        </button>
                        <button onClick={() => navigate('/admin/reports/class')}
                            className="flex flex-col items-center justify-center p-4 bg-white border border-slate-200 rounded-xl hover:shadow-md hover:border-purple-300 transition-all group">
                            <div className="w-10 h-10 rounded-full bg-purple-50 text-purple-600 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                                <Activity className="w-5 h-5" />
                            </div>
                            <span className="text-sm font-semibold text-slate-700">Laporan Harian</span>
                        </button>
                        <button onClick={() => navigate('/admin/settings')}
                            className="flex flex-col items-center justify-center p-4 bg-white border border-slate-200 rounded-xl hover:shadow-md hover:border-slate-300 transition-all group">
                            <div className="w-10 h-10 rounded-full bg-slate-50 text-slate-600 flex items-center justify-center mb-2 group-hover:scale-110 transition-transform">
                                <Shield className="w-5 h-5" />
                            </div>
                            <span className="text-sm font-semibold text-slate-700">Pengaturan Sekolah</span>
                        </button>
                    </div>

                    {/* Announcements Wrapper */}
                    <div className="lg:col-span-1">
                        <AnnouncementWidget />
                    </div>
                </div>

                {/* 3. Main Charts Section */}
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {/* Class Performance Chart */}
                    <div className="lg:col-span-2 bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
                        <h3 className="text-lg font-bold text-slate-900 mb-6 flex items-center gap-2">
                            <TrendingUp className="w-5 h-5 text-blue-600" />
                            Performa Kehadiran Kelas (Bottom 10)
                        </h3>
                        <div className="h-[300px] w-full">
                            {classChartData.length > 0 ? (
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={classChartData}>
                                        <CartesianGrid strokeDasharray="3 3" vertical={false} />
                                        <XAxis dataKey="name" axisLine={false} tickLine={false} />
                                        <YAxis axisLine={false} tickLine={false} />
                                        <Tooltip cursor={{ fill: '#f1f5f9' }} contentStyle={{ borderRadius: '8px', border: 'none', boxShadow: '0 4px 6px -1px rgb(0 0 0 / 0.1)' }} />
                                        <Legend />
                                        <Bar dataKey="Hadir" fill="#22c55e" radius={[4, 4, 0, 0]} stackId="a" />
                                        <Bar dataKey="Terlambat" fill="#eab308" radius={[4, 4, 0, 0]} stackId="a" />
                                        <Bar dataKey="Alpa" fill="#ef4444" radius={[4, 4, 0, 0]} stackId="a" />
                                    </BarChart>
                                </ResponsiveContainer>
                            ) : (
                                <div className="h-full flex items-center justify-center text-slate-400">Belum ada data kelas.</div>
                            )}
                        </div>
                    </div>

                    {/* Attendance Distribution Pie */}
                    <div className="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
                        <h3 className="text-lg font-bold text-slate-900 mb-2">Distribusi Kehadiran</h3>
                        <div className="h-[300px] relative">
                            <ResponsiveContainer width="100%" height="100%">
                                <PieChart>
                                    <Pie
                                        data={attendancePieData}
                                        cx="50%"
                                        cy="50%"
                                        innerRadius={60}
                                        outerRadius={80}
                                        paddingAngle={5}
                                        dataKey="value"
                                    >
                                        {attendancePieData.map((entry, index) => (
                                            <Cell key={`cell-${index}`} fill={entry.color} strokeWidth={0} />
                                        ))}
                                    </Pie>
                                    <Tooltip />
                                    <Legend verticalAlign="bottom" height={36} />
                                </PieChart>
                            </ResponsiveContainer>
                            {/* Center Text */}
                            <div className="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 text-center -mt-6">
                                <span className="block text-3xl font-bold text-slate-800">{stats?.total_students}</span>
                                <span className="text-xs text-slate-500 font-medium uppercase tracking-wide">Total</span>
                            </div>
                        </div>
                    </div>
                </div>

                {/* 4. Critical Alerts Section */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    {/* Teacher Absence - Critical */}
                    <div className="bg-white rounded-2xl border border-red-100 shadow-sm overflow-hidden">
                        <div className="px-6 py-4 border-b border-red-100 bg-red-50/50 flex justify-between items-center">
                            <h3 className="font-bold text-red-700 flex items-center gap-2">
                                <AlertTriangle className="w-5 h-5" /> Guru Tidak Hadir
                            </h3>
                            <button onClick={() => navigate('/admin/dashboard/teacher-absent')} className="text-xs font-medium text-red-600 hover:underline">Lihat Detail</button>
                        </div>
                        <div className="divide-y divide-red-50">
                            {teacherAbsent?.teachers?.length ? (
                                teacherAbsent.teachers.map((t) => (
                                    <div key={t.teacher_id} className="p-4 flex items-center justify-between hover:bg-red-50 transition-colors">
                                        <div>
                                            <p className="font-semibold text-slate-900">{t.teacher_name}</p>
                                            <p className="text-xs text-slate-500">Kurang {t.missing_qr} Kode QR</p>
                                        </div>
                                        <span className="text-xs bg-red-100 text-red-700 px-2 py-1 rounded font-medium">Alpha</span>
                                    </div>
                                ))
                            ) : (
                                <div className="p-6 text-center text-slate-500 text-sm">Semua guru hadir atau belum ada jadwal.</div>
                            )}
                        </div>
                    </div>

                    {/* Late Students List */}
                    <div className="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                        <div className="px-6 py-4 border-b border-slate-100 flex justify-between items-center">
                            <h3 className="font-bold text-slate-800 flex items-center gap-2">
                                <Clock className="w-5 h-5 text-amber-500" /> Siswa Terlambat Terbaru
                            </h3>
                            <button onClick={() => navigate('/admin/dashboard/late-absent')} className="text-xs font-medium text-blue-600 hover:underline">Lihat Semua</button>
                        </div>
                        <div className="divide-y divide-slate-100">
                            {lateAlpha?.late?.students?.slice(0, 5).map((s) => (
                                <div key={`${s.student_id}`} className="p-4 flex items-center justify-between hover:bg-slate-50 transition-colors">
                                    <div className="flex items-center gap-3">
                                        <div className="w-8 h-8 rounded-full bg-amber-50 flex items-center justify-center text-amber-600 text-xs font-bold border border-amber-100">
                                            {s.student_name.substring(0, 2)}
                                        </div>
                                        <div>
                                            <p className="font-medium text-slate-900 text-sm">{s.student_name}</p>
                                            <p className="text-xs text-slate-500">{s.class_name}</p>
                                        </div>
                                    </div>
                                    <span className="text-xs text-amber-600 font-medium">{s.time || 'Terlambat'}</span>
                                </div>
                            )) || <div className="p-6 text-center text-slate-500 text-sm">Tidak ada keterlambatan hari ini.</div>}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default AdminDashboard;
