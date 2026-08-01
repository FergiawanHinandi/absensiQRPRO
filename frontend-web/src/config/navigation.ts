import {
    LayoutDashboard,
    School,
    Users,
    CreditCard,
    FileText,
    Settings,
    Calendar,
    ClipboardCheck,
    History,
    User,
    Baby,
    Stethoscope,
    Building2,
    Package,
    Shield,
    BarChart3,
    Database,
    Globe,
    UserCog,
    Lock,
    Activity,
    TrendingUp,
    BookOpen,
    Clock,
    Receipt,
    Bell,
    HardDrive
} from 'lucide-react';

export type RoleType = 'super_admin' | 'school_admin' | 'principal' | 'teacher' | 'homeroom_teacher' | 'student' | 'parent';

export interface MenuItem {
    label: string;
    path: string;
    icon: any;
    badge?: string;
    children?: MenuItem[];
    section?: string;
}

export const MENUS: Record<RoleType, MenuItem[]> = {
    super_admin: [
        {
            label: 'Dashboard',
            path: '/super-admin/dashboard',
            icon: LayoutDashboard,
            section: 'UTAMA'
        },
        {
            label: 'Manajemen Sekolah',
            path: '/super-admin/schools',
            icon: Building2,
            section: 'MANAJEMEN',
            children: [
                { label: 'Daftar Sekolah', path: '/super-admin/schools', icon: Building2 },
                { label: 'Aktivasi Sekolah', path: '/super-admin/schools/activation', icon: Shield },
                { label: 'Paket & Limit', path: '/super-admin/schools/packages', icon: Package },
            ]
        },
        {
            label: 'Manajemen User',
            path: '/super-admin/users',
            icon: UserCog,
            children: [
                { label: 'Admin Sekolah', path: '/super-admin/users/admins', icon: Users },
                { label: 'Reset Akses', path: '/super-admin/users/reset-access', icon: Lock },
                { label: 'Log Aktivitas', path: '/super-admin/users/activity-logs', icon: Activity },
            ]
        },
        {
            label: 'Paket & Billing',
            path: '/super-admin/billing',
            icon: CreditCard,
            badge: 'New',
            children: [
                { label: 'Paket Berlangganan', path: '/super-admin/billing/packages', icon: Package },
                { label: 'Riwayat Pembayaran', path: '/super-admin/billing/payment-history', icon: FileText },
                { label: 'Invoice', path: '/super-admin/billing/invoices', icon: Receipt },
            ]
        },
        {
            label: 'Keamanan & Sistem',
            path: '/super-admin/security',
            icon: Shield,
            section: 'SISTEM',
            children: [
                { label: 'Role & Permission', path: '/super-admin/security/roles', icon: Lock },
                { label: 'Audit Log', path: '/super-admin/security/audit', icon: FileText },
                { label: 'Security Dashboard', path: '/super-admin/security-monitoring', icon: Shield },
                { label: 'Teacher Heatmap', path: '/super-admin/teacher-heatmap', icon: Globe },
                { label: 'Rate Limit', path: '/super-admin/security/rate-limit', icon: Activity },
            ]
        },
        {
            label: 'Konfigurasi Platform',
            path: '/super-admin/config',
            icon: Settings,
            children: [
                { label: 'Tahun Ajaran', path: '/super-admin/academic-year', icon: Calendar },
                { label: 'Template Jadwal', path: '/super-admin/schedule-templates', icon: Calendar },
                { label: 'Feature Flags', path: '/super-admin/config/features', icon: Settings },
            ]
        },
        {
            label: 'Laporan Global',
            path: '/super-admin/reports',
            icon: BarChart3,
            children: [
                { label: 'Rekap Absensi', path: '/super-admin/reports/attendance', icon: ClipboardCheck },
                { label: 'Statistik Platform', path: '/super-admin/reports/statistics', icon: TrendingUp },
                { label: 'Export Data', path: '/super-admin/reports/export', icon: Database },
            ]
        },
        {
            label: 'Pengumuman',
            path: '/super-admin/announcements',
            icon: Bell,
            badge: 'New'
        },
        {
            label: 'System Management',
            path: '/super-admin/system',
            icon: HardDrive,
            children: [
                { label: 'Database Backup', path: '/super-admin/system/backup', icon: Database },
                { label: 'Maintenance Mode', path: '/super-admin/system/maintenance', icon: Settings },
            ]
        },
    ],
    principal: [
        { label: 'Dashboard', path: '/principal/dashboard', icon: LayoutDashboard, section: 'UTAMA' },
        { label: 'Monitoring Absensi', path: '/principal/monitoring', icon: Activity, section: 'UTAMA' },
        { label: 'Laporan Sekolah', path: '/principal/reports', icon: FileText, section: 'LAPORAN' },
        { label: 'Approval', path: '/principal/approvals', icon: ClipboardCheck, section: 'OPERASIONAL' },
    ],
    school_admin: [
        {
            label: 'Dashboard',
            path: '/admin/dashboard',
            icon: LayoutDashboard,
            section: 'UTAMA'
        },
        {
            label: 'Monitoring Harian',
            path: '/admin/monitoring',
            icon: Activity,
            section: 'UTAMA',
            children: [
                { label: 'Absensi per Kelas', path: '/admin/dashboard/class-attendance', icon: School },
                { label: 'Guru Tidak Hadir', path: '/admin/dashboard/teacher-absent', icon: Users },
                { label: 'Siswa Terlambat / Alfa', path: '/admin/dashboard/late-absent', icon: Clock },
                { label: 'Anomali Data', path: '/admin/dashboard/anomalies', icon: Activity },
                { label: 'Security Monitoring', path: '/admin/security-monitoring', icon: Shield },
                { label: 'Teacher Heatmap', path: '/admin/teacher-heatmap', icon: Globe },
            ]
        },
        {
            label: 'Manajemen Akun',
            path: '/admin/accounts/generate',
            icon: UserCog,
            section: 'UTAMA'
        },
        {
            label: 'Manajemen Guru',
            path: '/admin/teachers',
            icon: Users,
            section: 'AKADEMIK',
            children: [
                { label: 'Daftar Guru', path: '/admin/teachers', icon: Users },
                { label: 'Guru Kelas', path: '/admin/teachers/homeroom', icon: User },
                { label: 'Guru Mapel', path: '/admin/teachers/subject', icon: BookOpen },
                // { label: 'Assign Kelas & Mapel', path: '/admin/teachers/assignments', icon: ClipboardCheck },
            ]
        },
        {
            label: 'Manajemen Siswa',
            path: '/admin/students',
            icon: Users,
            children: [
                { label: 'Daftar Siswa', path: '/admin/students', icon: Users },
                // { label: 'Penempatan Kelas', path: '/admin/students/placement', icon: School },
                { label: 'Kartu Pelajar & QR', path: '/admin/student-cards', icon: CreditCard },
                // { label: 'Mutasi / Alumni', path: '/admin/students/mutation', icon: History },
            ]
        },
        {
            label: 'Kelas & Mapel',
            path: '/admin/classes',
            icon: School,
            children: [
                { label: 'Daftar Kelas', path: '/admin/classes', icon: School },
                { label: 'Wali Kelas', path: '/admin/classes/homeroom', icon: User },
                { label: 'Daftar Mapel', path: '/admin/subjects', icon: BookOpen },
                // { label: 'Mapel ↔ Guru', path: '/admin/subjects/teacher-mapping', icon: ClipboardCheck },
                // { label: 'Mapel ↔ Kelas', path: '/admin/subjects/class-mapping', icon: ClipboardCheck },
            ]
        },
        {
            label: 'Jadwal & Kalender',
            path: '/admin/schedules',
            icon: Calendar,
            children: [
                { label: 'Jadwal Pelajaran', path: '/admin/schedules', icon: Calendar },
                // { label: 'Jam Masuk / Pulang', path: '/admin/schedules/timing', icon: Clock },
                // { label: 'Hari Libur', path: '/admin/schedules/holidays', icon: Calendar },
                // { label: 'Kalender Akademik', path: '/admin/schedules/academic-calendar', icon: Calendar },
            ]
        },
        {
            label: 'Manajemen Absensi',
            path: '/admin/attendance',
            icon: ClipboardCheck,
            section: 'OPERASIONAL',
            children: [
                { label: 'Pengaturan Jam Absensi', path: '/admin/attendance/settings', icon: Clock },
                { label: 'Toleransi Keterlambatan', path: '/admin/attendance/tolerance', icon: TrendingUp },
                { label: 'Lokasi Valid (GPS)', path: '/admin/attendance/location', icon: Globe },
                { label: 'Mode QR (Per Sesi/Hari)', path: '/admin/attendance/qr-mode', icon: CreditCard },
                { label: 'Override (Izin/Sakit)', path: '/admin/attendance/override', icon: Stethoscope },
            ]
        },
        {
            label: 'Orang Tua',
            path: '/admin/parents',
            icon: Baby,
            children: [
                { label: 'Akun Orang Tua', path: '/admin/parents', icon: Baby },
                // { label: 'Relasi Orang Tua ↔ Siswa', path: '/admin/parents/relations', icon: Users },
                // { label: 'Hak Akses Notifikasi', path: '/admin/parents/notifications', icon: Activity },
            ]
        },
        {
            label: 'Laporan & Rekap',
            path: '/admin/reports',
            icon: FileText,
            section: 'LAPORAN',
            children: [
                { label: 'Absensi per Kelas', path: '/admin/reports/class', icon: FileText },
                { label: 'Absensi per Guru', path: '/admin/reports/teacher', icon: FileText },
                { label: 'Rekap Bulanan', path: '/admin/reports/monthly', icon: BarChart3 },
                { label: 'Rekap Semester', path: '/admin/reports/semester', icon: BarChart3 },
                { label: 'Export PDF / Excel', path: '/admin/reports/export', icon: FileText },
            ]
        },
        {
            label: 'Pengaturan Sekolah',
            path: '/admin/settings',
            icon: Settings,
            section: 'SISTEM',
            children: [
                { label: 'Profil Sekolah', path: '/admin/settings/profile', icon: School },
                { label: 'Tahun Ajaran Aktif', path: '/admin/settings/academic-year', icon: Calendar },
                { label: 'Logo & Kop Laporan', path: '/admin/settings/branding', icon: FileText },
                { label: 'Notifikasi (WA/Email/App)', path: '/admin/settings/notifications', icon: Activity },
            ]
        },
        {
            label: 'Billing & Paket',
            path: '/admin/billing',
            icon: CreditCard,
            section: 'LANGGANAN',
            children: [
                { label: 'Upgrade Paket', path: '/admin/billing/pricing', icon: Package },
                { label: 'Riwayat Tagihan', path: '/admin/billing/history', icon: Receipt },
            ]
        },
    ],
    teacher: [
        {
            label: 'Dashboard',
            path: '/teacher/dashboard',
            icon: LayoutDashboard,
            section: 'UTAMA'
        },
        {
            label: 'Jadwal Mengajar',
            path: '/teacher/schedules',
            icon: Calendar,
            section: 'AKADEMIK'
        },
        {
            label: 'Absensi Mapel',
            path: '/teacher/attendance',
            icon: ClipboardCheck,
            section: 'OPERASIONAL',
            children: [
                { label: 'Generate QR', path: '/teacher/attendance/qr', icon: ClipboardCheck },
                { label: 'Validasi Manual', path: '/teacher/attendance/manual', icon: ClipboardCheck },
                { label: 'Daftar Hadir', path: '/teacher/attendance/list', icon: FileText },
            ]
        },
        {
            label: 'Laporan Pribadi',
            path: '/teacher/reports',
            icon: BarChart3,
            section: 'LAPORAN',
            children: [
                { label: 'Rekap Absensi', path: '/teacher/reports/attendance', icon: ClipboardCheck },
                { label: 'Riwayat Sesi', path: '/teacher/reports/sessions', icon: History },
            ]
        },
        {
            label: 'Profil',
            path: '/teacher/profile',
            icon: User,
            section: 'AKUN',
            children: [
                { label: 'Data Pribadi', path: '/teacher/profile/personal', icon: User },
                { label: 'Ganti Password', path: '/teacher/profile/password', icon: Lock },
                { label: 'Riwayat Login', path: '/teacher/profile/login-history', icon: History },
            ]
        },
    ],
    homeroom_teacher: [
        {
            label: 'Dashboard',
            path: '/teacher/dashboard',
            icon: LayoutDashboard,
            section: 'UTAMA'
        },
        {
            label: 'Jadwal Mengajar',
            path: '/teacher/schedules',
            icon: Calendar,
            section: 'AKADEMIK'
        },
        {
            label: 'Absensi Kelas',
            path: '/teacher/class-attendance',
            icon: Users,
            badge: 'Wali Kelas',
            section: 'WALI KELAS',
            children: [
                { label: 'Absensi Harian', path: '/teacher/class-attendance/daily', icon: ClipboardCheck },
                { label: 'Input Izin/Sakit', path: '/teacher/class-attendance/permissions', icon: Stethoscope },
                { label: 'Catatan Kehadiran', path: '/teacher/class-attendance/notes', icon: FileText },
                { label: 'Rekap Kelas', path: '/teacher/class-attendance/recap', icon: BarChart3 },
            ]
        },
        {
            label: 'Absensi Mapel',
            path: '/teacher/attendance',
            icon: ClipboardCheck,
            section: 'OPERASIONAL',
            children: [
                { label: 'Generate QR', path: '/teacher/attendance/qr', icon: ClipboardCheck },
                { label: 'Validasi Manual', path: '/teacher/attendance/manual', icon: ClipboardCheck },
                { label: 'Daftar Hadir', path: '/teacher/attendance/list', icon: FileText },
            ]
        },
        {
            label: 'Laporan Pribadi',
            path: '/teacher/reports',
            icon: BarChart3,
            section: 'LAPORAN',
            children: [
                { label: 'Rekap Kelas Wali', path: '/teacher/reports/homeroom', icon: Users },
                { label: 'Rekap Mapel', path: '/teacher/reports/subject', icon: ClipboardCheck },
                { label: 'Riwayat Sesi', path: '/teacher/reports/sessions', icon: History },
            ]
        },
        {
            label: 'Profil',
            path: '/teacher/profile',
            icon: User,
            section: 'AKUN',
            children: [
                { label: 'Data Pribadi', path: '/teacher/profile/personal', icon: User },
                { label: 'Ganti Password', path: '/teacher/profile/password', icon: Lock },
                { label: 'Riwayat Login', path: '/teacher/profile/login-history', icon: History },
            ]
        },
    ],
    student: [
        {
            label: 'Dashboard',
            path: '/student/dashboard',
            icon: LayoutDashboard,
            section: 'UTAMA'
        },
        {
            label: 'Riwayat Absensi',
            path: '/student/history',
            icon: History,
            section: 'AKADEMIK'
        },
        {
            label: 'Jadwal Saya',
            path: '/student/schedule',
            icon: Calendar,
            section: 'AKADEMIK'
        },
        {
            label: 'Profil',
            path: '/student/profile',
            icon: User,
            section: 'AKUN'
        },
    ],
    parent: [
        {
            label: 'Dashboard',
            path: '/parent/dashboard',
            icon: LayoutDashboard,
            section: 'UTAMA'
        },
        {
            label: 'Riwayat Anak',
            path: '/parent/children-history',
            icon: Baby,
            section: 'PEMANTAUAN'
        },
        {
            label: 'Izin / Sakit',
            path: '/parent/permissions',
            icon: Stethoscope,
            section: 'PEMANTAUAN'
        },
    ]
};
