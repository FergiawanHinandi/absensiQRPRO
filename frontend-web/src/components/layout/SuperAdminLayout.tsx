import React, { useState, useRef, useEffect } from 'react';
import { Outlet, useNavigate, useLocation } from 'react-router-dom';
import { DarkThemeWrapper } from './DarkThemeWrapper';
import {
    Building2,
    Users,
    LayoutDashboard,
    Settings,
    CreditCard,
    BarChart3,
    Shield,
    Menu,
    X,
    ChevronDown,
    Bell,
    LogOut,
    User,
    Clock
} from 'lucide-react';
import { useAuthStore } from '../../modules/auth/stores/useAuthStore';
import { useNotificationLogs } from '../../modules/admin/hooks/useAdminService';

export const SuperAdminLayout: React.FC = () => {
    const navigate = useNavigate();
    const location = useLocation();
    const { user, logout } = useAuthStore();
    const [sidebarOpen, setSidebarOpen] = useState(false);

    // Dropdown States
    const [userDropdownOpen, setUserDropdownOpen] = useState(false);
    const [notificationDropdownOpen, setNotificationDropdownOpen] = useState(false);

    // Refs for click outside
    const userDropdownRef = useRef<HTMLDivElement>(null);
    const notificationRef = useRef<HTMLDivElement>(null);

    const [expandedMenus, setExpandedMenus] = useState<{ [key: string]: boolean }>({
        dashboard: false,
        schools: false,
        users: false,
        billing: false,
        security: false,
        reports: false,
        system: false
    });

    // Fetch Notifications
    const { data: notifData } = useNotificationLogs(1, 5);
    const notifications = notifData?.notifications || [];
    const hasUnread = notifications.length > 0;

    // Click Outside Handler
    useEffect(() => {
        function handleClickOutside(event: MouseEvent) {
            if (userDropdownRef.current && !userDropdownRef.current.contains(event.target as Node)) {
                setUserDropdownOpen(false);
            }
            if (notificationRef.current && !notificationRef.current.contains(event.target as Node)) {
                setNotificationDropdownOpen(false);
            }
        }

        document.addEventListener('mousedown', handleClickOutside);
        return () => {
            document.removeEventListener('mousedown', handleClickOutside);
        };
    }, []);

    const handleLogout = () => {
        logout();
        navigate('/login');
    };

    // Menu structure
    const menuItems = [
        {
            id: 'dashboard',
            label: 'Dashboard',
            icon: LayoutDashboard,
            path: '/super-admin/dashboard'
        },
        {
            id: 'schools',
            label: 'Manajemen Sekolah',
            icon: Building2,
            children: [
                { label: 'Daftar Sekolah', path: '/super-admin/schools' },
                { label: 'Aktivasi Sekolah', path: '/super-admin/schools/activation' },
                { label: 'Paket & Limit', path: '/super-admin/schools/packages' }
            ]
        },
        {
            id: 'users',
            label: 'Manajemen User',
            icon: Users,
            children: [
                { label: 'Admin Sekolah', path: '/super-admin/users/admins' },
                { label: 'Activity Logs', path: '/super-admin/users/activity-logs' },
                { label: 'Reset Access', path: '/super-admin/users/reset-access' }
            ]
        },
        {
            id: 'billing',
            label: 'Billing & Langganan',
            icon: CreditCard,
            children: [
                { label: 'Paket Langganan', path: '/super-admin/billing/packages' },
                { label: 'Invoice', path: '/super-admin/billing/invoices' },
                { label: 'Riwayat Pembayaran', path: '/super-admin/billing/payment-history' }
            ]
        },
        {
            id: 'security',
            label: 'Security & Audit',
            icon: Shield,
            children: [
                { label: 'Audit Log', path: '/super-admin/security/audit' },
                { label: 'Role & Permission', path: '/super-admin/security/roles' },
                { label: 'Rate Limiting', path: '/super-admin/security/rate-limit' }
            ]
        },
        {
            id: 'reports',
            label: 'Laporan Global',
            icon: BarChart3,
            children: [
                { label: 'Rekap Absensi', path: '/super-admin/reports/attendance' },
                { label: 'Statistik Platform', path: '/super-admin/reports/statistics' },
                { label: 'Export Data', path: '/super-admin/reports/export' }
            ]
        },
        {
            id: 'system',
            label: 'Pengaturan Sistem',
            icon: Settings,
            children: [
                { label: 'Feature Flags', path: '/super-admin/config/features' },
                { label: 'Maintenance Mode', path: '/super-admin/system/maintenance' },
                { label: 'Backup Database', path: '/super-admin/system/backup' },
                { label: 'Pengumuman', path: '/super-admin/announcements' }
            ]
        }
    ];

    const toggleMenu = (menuId: string) => {
        setExpandedMenus(prev => ({
            ...prev,
            [menuId]: !prev[menuId]
        }));
    };

    const handleNavigation = (path: string) => {
        navigate(path);
        setSidebarOpen(false);
    };

    const isActiveRoute = (path: string) => {
        return location.pathname === path || location.pathname.startsWith(path + '/');
    };

    return (
        <div className="flex h-screen bg-gradient-to-br from-gray-900 via-gray-900 to-black text-gray-100 overflow-hidden">
            {/* Mobile Overlay */}
            {sidebarOpen && (
                <div
                    onClick={() => setSidebarOpen(false)}
                    className="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden"
                />
            )}

            {/* Sidebar */}
            <aside
                className={`fixed lg:static inset-y-0 left-0 z-50 w-72 bg-gray-900 border-r border-gray-800 flex flex-col transform transition-transform duration-300 lg:transform-none ${sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'
                    }`}
            >
                {/* Logo Section */}
                <div className="h-16 flex items-center justify-between px-6 border-b border-gray-800">
                    <div className="flex items-center gap-3">
                        <div className="w-8 h-8 rounded-lg bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center">
                            <span className="text-white font-bold text-sm">SA</span>
                        </div>
                        <div>
                            <h1 className="text-sm font-bold text-white">Super Admin</h1>
                            <p className="text-xs text-gray-400">AbsensiQR Pro</p>
                        </div>
                    </div>
                    <button
                        onClick={() => setSidebarOpen(false)}
                        className="lg:hidden p-2 hover:bg-gray-800 rounded-lg transition-colors"
                    >
                        <X className="w-5 h-5" />
                    </button>
                </div>


                {/* Navigation Menu */}
                <nav className="flex-1 overflow-y-auto px-4 py-4 space-y-1">
                    {menuItems.map((item) => {
                        const Icon = item.icon;
                        const isExpanded = expandedMenus[item.id];
                        const hasChildren = item.children && item.children.length > 0;
                        const isActive = item.path ? isActiveRoute(item.path) : false;

                        return (
                            <div key={item.id}>
                                {hasChildren ? (
                                    <>
                                        <button
                                            onClick={() => toggleMenu(item.id)}
                                            className="w-full flex items-center justify-between px-4 py-3 text-sm font-medium text-gray-300 hover:bg-gray-800 hover:text-white rounded-lg transition-all group"
                                        >
                                            <div className="flex items-center gap-3">
                                                <Icon className="w-5 h-5" />
                                                <span>{item.label}</span>
                                            </div>
                                            <ChevronDown
                                                className={`w-4 h-4 transition-transform duration-200 ${isExpanded ? 'rotate-180' : ''
                                                    }`}
                                            />
                                        </button>
                                        {isExpanded && (
                                            <div className="ml-4 mt-1 space-y-1">
                                                {item.children!.map((child) => (
                                                    <button
                                                        key={child.path}
                                                        onClick={() => handleNavigation(child.path)}
                                                        className={`w-full flex items-center gap-3 px-4 py-2 text-sm rounded-lg transition-all ${isActiveRoute(child.path)
                                                            ? 'bg-blue-600 text-white'
                                                            : 'text-gray-400 hover:bg-gray-800 hover:text-white'
                                                            }`}
                                                    >
                                                        <div className="w-1.5 h-1.5 rounded-full bg-gray-600"></div>
                                                        <span>{child.label}</span>
                                                    </button>
                                                ))}
                                            </div>
                                        )}
                                    </>
                                ) : (
                                    <button
                                        onClick={() => item.path && handleNavigation(item.path)}
                                        className={`w-full flex items-center gap-3 px-4 py-3 text-sm font-medium rounded-lg transition-all ${isActive
                                            ? 'bg-gradient-to-r from-blue-600 to-blue-500 text-white'
                                            : 'text-gray-300 hover:bg-gray-800 hover:text-white'
                                            }`}
                                    >
                                        <Icon className="w-5 h-5" />
                                        <span>{item.label}</span>
                                    </button>
                                )}
                            </div>
                        );
                    })}
                </nav>

                {/* Footer */}
                <div className="p-4 border-t border-gray-800">
                    <div className="text-xs text-gray-500 text-center">
                        <p>AbsensiQR Pro v2.0</p>
                        <p className="mt-1">© 2026 Super Admin Panel</p>
                    </div>
                </div>
            </aside>

            {/* Main Content */}
            <div className="flex-1 flex flex-col overflow-hidden">
                {/* Top Navbar */}
                <div className="h-16 bg-gray-900 border-b border-gray-800 px-6 flex items-center justify-between sticky top-0 z-30 shadow-lg">
                    <div className="flex items-center gap-4">
                        <button
                            onClick={() => setSidebarOpen(!sidebarOpen)}
                            className="lg:hidden p-2 hover:bg-gray-800 rounded-lg transition-colors"
                        >
                            <Menu className="w-6 h-6" />
                        </button>
                        <div>
                            <h1 className="text-xl md:text-2xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-blue-400 to-purple-500">
                                Super Admin Panel
                            </h1>
                            <p className="text-xs text-gray-400 mt-0.5">Selamat datang kembali, {user?.name || 'Administrator'}</p>
                        </div>
                    </div>

                    {/* Right Side: Notifications & User Menu */}
                    <div className="flex items-center gap-3">
                        {/* Notification Bell */}
                        <div className="relative" ref={notificationRef}>
                            <button
                                onClick={() => setNotificationDropdownOpen(!notificationDropdownOpen)}
                                className={`relative p-2 rounded-lg transition-colors group ${notificationDropdownOpen ? 'bg-gray-800 text-white' : 'hover:bg-gray-800'}`}
                            >
                                <Bell className={`w-5 h-5 ${notificationDropdownOpen ? 'text-white' : 'text-gray-400 group-hover:text-white'}`} />
                                {hasUnread && (
                                    <span className="absolute top-1 right-1 w-2 h-2 bg-red-500 rounded-full"></span>
                                )}
                            </button>

                            {/* Notification Dropdown */}
                            {notificationDropdownOpen && (
                                <div className="absolute right-0 mt-2 w-80 sm:w-96 bg-gray-800 border border-gray-700 rounded-xl shadow-xl py-0 z-50 transform origin-top-right animate-in fade-in zoom-in-95 duration-200 overflow-hidden">
                                    <div className="px-4 py-3 border-b border-gray-700 bg-gray-800/50 flex items-center justify-between">
                                        <h3 className="font-semibold text-gray-100 text-sm">Notifikasi Sistem</h3>
                                        <button
                                            onClick={() => {
                                                navigate('/super-admin/notifications');
                                                setNotificationDropdownOpen(false);
                                            }}
                                            className="text-xs text-blue-400 hover:text-blue-300 font-medium"
                                        >
                                            Lihat Semua
                                        </button>
                                    </div>
                                    <div className="max-h-[320px] overflow-y-auto">
                                        {notifications.length > 0 ? (
                                            notifications.map((notif: any, idx: number) => (
                                                <div key={idx} className="px-4 py-3 border-b border-gray-700/50 hover:bg-gray-700/50 transition-colors cursor-pointer group">
                                                    <div className="flex items-start gap-3">
                                                        <div className={`mt-0.5 min-w-[8px] h-2 rounded-full ${notif.type === 'error' ? 'bg-red-500' :
                                                                notif.type === 'warning' ? 'bg-amber-500' :
                                                                    notif.type === 'success' ? 'bg-green-500' : 'bg-blue-500'
                                                            }`}></div>
                                                        <div className="flex-1 min-w-0">
                                                            <p className="text-sm font-medium text-gray-200 truncate mb-0.5 group-hover:text-blue-400 transition-colors">{notif.title}</p>
                                                            <p className="text-xs text-gray-400 line-clamp-2 leading-relaxed">{notif.message}</p>
                                                            <div className="flex items-center gap-2 mt-1.5">
                                                                <Clock size={10} className="text-gray-500" />
                                                                <span className="text-[10px] text-gray-500">
                                                                    {new Date(notif.created_at).toLocaleDateString()}
                                                                </span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            ))
                                        ) : (
                                            <div className="p-8 text-center">
                                                <div className="inline-flex items-center justify-center w-12 h-12 rounded-full bg-gray-700/50 mb-3">
                                                    <Bell size={20} className="text-gray-500" />
                                                </div>
                                                <p className="text-sm text-gray-500">Belum ada notifikasi baru</p>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            )}
                        </div>

                        {/* User Dropdown */}
                        <div className="relative" ref={userDropdownRef}>
                            <button
                                onClick={() => setUserDropdownOpen(!userDropdownOpen)}
                                className="flex items-center gap-3 p-2 hover:bg-gray-800 rounded-lg transition-colors"
                            >
                                <div className="w-8 h-8 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white font-bold text-sm">
                                    {user?.name?.substring(0, 2).toUpperCase() || 'SA'}
                                </div>
                                <div className="hidden md:block text-left">
                                    <p className="text-sm font-semibold text-white">{user?.name || 'Super Admin'}</p>
                                    <p className="text-xs text-gray-400">Super Administrator</p>
                                </div>
                                <ChevronDown className={`w-4 h-4 text-gray-400 transition-transform ${userDropdownOpen ? 'rotate-180' : ''}`} />
                            </button>

                            {/* Dropdown Menu */}
                            {userDropdownOpen && (
                                <div className="absolute right-0 mt-2 w-56 bg-gray-800 border border-gray-700 rounded-lg shadow-xl py-2 z-50 animate-in fade-in zoom-in-95 duration-200">
                                    <div className="px-4 py-3 border-b border-gray-700">
                                        <p className="text-sm font-semibold text-white">{user?.name || 'Super Admin'}</p>
                                        <p className="text-xs text-gray-400 mt-1">{user?.email || 'admin@system.com'}</p>
                                    </div>
                                    <button
                                        onClick={() => navigate('/super-admin/profile')}
                                        className="w-full flex items-center gap-3 px-4 py-2 text-sm text-gray-300 hover:bg-gray-700 hover:text-white transition-colors"
                                    >
                                        <User className="w-4 h-4" />
                                        <span>Profil Saya</span>
                                    </button>
                                    <button
                                        onClick={() => navigate('/super-admin/config/features')}
                                        className="w-full flex items-center gap-3 px-4 py-2 text-sm text-gray-300 hover:bg-gray-700 hover:text-white transition-colors"
                                    >
                                        <Settings className="w-4 h-4" />
                                        <span>Pengaturan</span>
                                    </button>
                                    <div className="border-t border-gray-700 my-2"></div>
                                    <button
                                        onClick={handleLogout}
                                        className="w-full flex items-center gap-3 px-4 py-2 text-sm text-red-400 hover:bg-red-500/10 hover:text-red-300 transition-colors"
                                    >
                                        <LogOut className="w-4 h-4" />
                                        <span>Keluar</span>
                                    </button>
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                {/* Page Content */}
                <main className="flex-1 overflow-y-auto">
                    <div className="p-4 md:p-6 lg:p-8">
                        <DarkThemeWrapper>
                            <Outlet />
                        </DarkThemeWrapper>
                    </div>
                </main>
            </div>
        </div>
    );
};
