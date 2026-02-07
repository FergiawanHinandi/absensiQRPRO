import React, { useState, useRef, useEffect } from 'react';
import { Outlet, useNavigate, useLocation } from 'react-router-dom';
import {
    Menu,
    X,
    ChevronDown,
    Bell,
    LogOut,
    User,
    Settings,
    LayoutDashboard,
    Clock
} from 'lucide-react';
import { useAuthStore } from '../../modules/auth/stores/useAuthStore';
import { MENUS } from '../../config/navigation';
import type { RoleType, MenuItem } from '../../config/navigation';
import { useNotificationLogs } from '../../modules/admin/hooks/useAdminService';

const DashboardLayout: React.FC = () => {
    const navigate = useNavigate();
    const location = useLocation();
    const { user, logout } = useAuthStore();
    const [sidebarOpen, setSidebarOpen] = useState(false);

    // Dropdown States
    const [userDropdownOpen, setUserDropdownOpen] = useState(false);
    const [notificationDropdownOpen, setNotificationDropdownOpen] = useState(false);

    const [expandedMenus, setExpandedMenus] = useState<string[]>([]);

    // Refs for click outside detection
    const userDropdownRef = useRef<HTMLDivElement>(null);
    const notificationRef = useRef<HTMLDivElement>(null);

    // Fetch Notifications
    const { data: notifData } = useNotificationLogs(1, 5);
    const notifications = notifData?.notifications || [];
    const hasUnread = notifications.length > 0;

    const role = (user?.role_type as RoleType) || 'school_admin';

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
        if (window.confirm('Apakah Anda yakin ingin keluar?')) {
            logout();
            navigate('/login');
        }
    };

    const handleNavigation = (path: string) => {
        navigate(path);
        setSidebarOpen(false);
    };

    const toggleSubmenu = (label: string) => {
        setExpandedMenus(prev =>
            prev.includes(label)
                ? prev.filter(item => item !== label)
                : [...prev, label]
        );
    };

    const isActiveRoute = (path: string) => {
        return location.pathname === path || location.pathname.startsWith(path + '/');
    };

    // Helper functions
    const getRoleDisplayName = (r: string) => {
        if (!r) return 'User';
        return r.replace('_', ' ').toUpperCase();
    };

    // Safe Menu Logic
    const getMenus = (): MenuItem[] => {
        if (!role || !MENUS) return [];
        return MENUS[role] || [];
    };

    const menus = getMenus();

    return (
        <div className="flex h-screen bg-slate-50 text-slate-800 overflow-hidden font-sans">
            {/* Mobile Overlay */}
            {sidebarOpen && (
                <div
                    onClick={() => setSidebarOpen(false)}
                    className="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden backdrop-blur-sm"
                />
            )}

            {/* SIDEBAR (Premium Dark Theme) */}
            <aside
                className={`fixed lg:static inset-y-0 left-0 z-50 w-72 bg-slate-900 text-white flex flex-col transform transition-transform duration-300 lg:transform-none shadow-2xl ${sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'}`}
            >
                {/* Logo Section */}
                <div className="h-16 flex items-center justify-between px-6 border-b border-slate-800 bg-slate-900">
                    <div className="flex items-center gap-3">
                        <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-blue-600 to-indigo-600 flex items-center justify-center shadow-lg hover:shadow-blue-500/20 transition-shadow">
                            <span className="text-white font-bold text-lg">A</span>
                        </div>
                        <div>
                            <h1 className="text-sm font-bold text-white tracking-wide">AbsensiQR</h1>
                            <div className="flex items-center gap-1">
                                <span className="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                                <p className="text-[10px] text-slate-400 uppercase tracking-widest">{getRoleDisplayName(role)}</p>
                            </div>
                        </div>
                    </div>
                    <button
                        onClick={() => setSidebarOpen(false)}
                        className="lg:hidden p-1 text-slate-400 hover:text-white"
                    >
                        <X size={20} />
                    </button>
                </div>

                {/* Navigation */}
                <nav className="flex-1 overflow-y-auto px-4 py-6 space-y-1 custom-scrollbar">
                    {menus.map((item, index) => {
                        const showSection = item.section && (index === 0 || item.section !== menus[index - 1].section);
                        const Icon = item.icon || LayoutDashboard;
                        const hasChildren = item.children && item.children.length > 0;
                        const isExpanded = expandedMenus.includes(item.label);
                        const isActive = isActiveRoute(item.path);

                        return (
                            <React.Fragment key={index}>
                                {showSection && (
                                    <div className="mt-6 mb-3 px-3 text-xs font-bold text-slate-500 uppercase tracking-wider">
                                        {item.section}
                                    </div>
                                )}
                                {hasChildren ? (
                                    <div className="mb-1">
                                        <button
                                            onClick={() => toggleSubmenu(item.label)}
                                            className={`w-full flex items-center justify-between px-3 py-2.5 rounded-lg transition-all duration-200 group ${isExpanded ? 'bg-slate-800/50 text-white' : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                                                }`}
                                        >
                                            <div className="flex items-center gap-3">
                                                <Icon size={18} className={isExpanded ? 'text-blue-400' : 'group-hover:text-blue-400 transition-colors'} />
                                                <span className="font-medium text-sm">{item.label}</span>
                                            </div>
                                            <ChevronDown size={14} className={`transition-transform duration-200 ${isExpanded ? 'rotate-180 text-blue-400' : ''}`} />
                                        </button>
                                        <div
                                            className={`overflow-hidden transition-all duration-300 ease-in-out ${isExpanded ? 'max-h-96 opacity-100 mt-1' : 'max-h-0 opacity-0'}`}
                                        >
                                            <div className="ml-4 pl-3 border-l-2 border-slate-800 space-y-1 py-1">
                                                {item.children!.map((child, cIdx) => {
                                                    const isChildActive = isActiveRoute(child.path);
                                                    return (
                                                        <button
                                                            key={cIdx}
                                                            onClick={() => handleNavigation(child.path)}
                                                            className={`block w-full text-left px-3 py-2 text-sm rounded-md transition-all duration-200 ${isChildActive
                                                                ? 'bg-blue-600/10 text-blue-400 font-medium'
                                                                : 'text-slate-500 hover:text-slate-200 hover:bg-slate-800/50'
                                                                }`}
                                                        >
                                                            {child.label}
                                                        </button>
                                                    )
                                                })}
                                            </div>
                                        </div>
                                    </div>
                                ) : (
                                    <button
                                        onClick={() => handleNavigation(item.path)}
                                        className={`w-full flex items-center gap-3 px-3 py-2.5 mb-1 rounded-lg transition-all duration-200 group ${isActive
                                            ? 'bg-gradient-to-r from-blue-600 to-blue-500 text-white shadow-lg shadow-blue-500/20'
                                            : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                                            }`}
                                    >
                                        <Icon size={18} className={isActive ? 'text-white' : 'group-hover:text-blue-400 transition-colors'} />
                                        <span className="font-medium text-sm">{item.label}</span>
                                    </button>
                                )}
                            </React.Fragment>
                        )
                    })}
                </nav>

                {/* Sidebar Footer */}
                <div className="p-4 bg-slate-900 border-t border-slate-800">
                    <button
                        onClick={handleLogout}
                        className="w-full flex items-center justify-center gap-2 py-2.5 px-4 rounded-lg bg-red-500/10 text-red-500 hover:bg-red-500 hover:text-white transition-all duration-200 font-medium text-sm border border-red-500/20 hover:border-red-50 shadow-sm"
                    >
                        <LogOut size={16} />
                        <span>Sign Out</span>
                    </button>
                </div>
            </aside>

            {/* MAIN CONTENT AREA */}
            <div className="flex-1 flex flex-col min-w-0 overflow-hidden bg-slate-50 relative">
                {/* Header */}
                <header className="h-16 bg-white border-b border-slate-200 flex items-center justify-between px-4 lg:px-8 sticky top-0 z-30 shadow-sm/50">
                    <div className="flex items-center gap-4">
                        <button
                            onClick={() => setSidebarOpen(true)}
                            className="lg:hidden p-2 -ml-2 text-slate-500 hover:bg-slate-100 rounded-lg transition-colors"
                        >
                            <Menu size={24} />
                        </button>
                        <div className="hidden md:block">
                            <h2 className="text-lg font-bold text-slate-800">Panel Sekolah</h2>
                            <p className="text-xs text-slate-500">Kelola operasional sekolah Anda dengan mudah</p>
                        </div>
                    </div>

                    <div className="flex items-center gap-4">
                        {/* Notifications */}
                        <div className="relative" ref={notificationRef}>
                            <button
                                onClick={() => setNotificationDropdownOpen(!notificationDropdownOpen)}
                                className={`relative p-2 rounded-full transition-colors group ${notificationDropdownOpen ? 'bg-blue-50 text-blue-600' : 'text-slate-500 hover:bg-slate-100'}`}
                            >
                                <Bell size={20} className="group-hover:text-blue-600 transition-colors" />
                                {hasUnread && (
                                    <span className="absolute top-1.5 right-1.5 w-2 h-2 bg-red-500 rounded-full border-2 border-white animate-pulse"></span>
                                )}
                            </button>

                            {/* Notification Dropdown */}
                            {notificationDropdownOpen && (
                                <div className="absolute right-0 mt-2 w-80 sm:w-96 bg-white rounded-xl shadow-xl border border-slate-100 py-0 z-50 transform origin-top-right animate-in fade-in zoom-in-95 duration-200 overflow-hidden">
                                    <div className="px-4 py-3 border-b border-slate-50 bg-slate-50/50 flex items-center justify-between">
                                        <h3 className="font-semibold text-slate-800 text-sm">Notifikasi</h3>
                                        <button
                                            onClick={() => {
                                                navigate('/admin/notification-logs');
                                                setNotificationDropdownOpen(false);
                                            }}
                                            className="text-xs text-blue-600 hover:text-blue-700 font-medium"
                                        >
                                            Lihat Semua
                                        </button>
                                    </div>
                                    <div className="max-h-[320px] overflow-y-auto">
                                        {notifications.length > 0 ? (
                                            notifications.map((notif: any, idx: number) => (
                                                <div key={idx} className="px-4 py-3 border-b border-slate-50 hover:bg-slate-50 transition-colors cursor-pointer group">
                                                    <div className="flex items-start gap-3">
                                                        <div className={`mt-0.5 min-w-[8px] h-2 rounded-full ${notif.type === 'error' ? 'bg-red-500' :
                                                                notif.type === 'warning' ? 'bg-amber-500' :
                                                                    notif.type === 'success' ? 'bg-green-500' : 'bg-blue-500'
                                                            }`}></div>
                                                        <div className="flex-1 min-w-0">
                                                            <p className="text-sm font-medium text-slate-800 truncate mb-0.5 group-hover:text-blue-700 transition-colors">{notif.title}</p>
                                                            <p className="text-xs text-slate-500 line-clamp-2 leading-relaxed">{notif.message}</p>
                                                            <div className="flex items-center gap-2 mt-1.5">
                                                                <Clock size={10} className="text-slate-400" />
                                                                <span className="text-[10px] text-slate-400">
                                                                    {new Date(notif.created_at).toLocaleDateString()}
                                                                </span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            ))
                                        ) : (
                                            <div className="p-8 text-center">
                                                <div className="inline-flex items-center justify-center w-12 h-12 rounded-full bg-slate-50 mb-3">
                                                    <Bell size={20} className="text-slate-400" />
                                                </div>
                                                <p className="text-sm text-slate-500">Belum ada notifikasi baru</p>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            )}
                        </div>

                        {/* User Profile */}
                        <div className="relative" ref={userDropdownRef}>
                            <button
                                onClick={() => setUserDropdownOpen(!userDropdownOpen)}
                                className="flex items-center gap-3 pl-2 pr-1 py-1 rounded-full hover:bg-slate-50 border border-transparent hover:border-slate-200 transition-all cursor-pointer"
                            >
                                <div className="text-right hidden md:block">
                                    <p className="text-sm font-semibold text-slate-700 leading-none">{user?.name || 'Admin'}</p>
                                    <p className="text-[10px] text-slate-500 font-medium mt-1">{role.replace('_', ' ').toUpperCase()}</p>
                                </div>
                                <div className="w-9 h-9 rounded-full bg-gradient-to-tr from-blue-500 to-indigo-500 p-0.5 shadow-sm">
                                    <div className="w-full h-full rounded-full bg-white flex items-center justify-center">
                                        <User size={18} className="text-blue-600" />
                                    </div>
                                </div>
                                <ChevronDown size={14} className="text-slate-400 mr-1" />
                            </button>

                            {/* Dropdown */}
                            {userDropdownOpen && (
                                <div className="absolute right-0 mt-2 w-56 bg-white rounded-xl shadow-lg border border-slate-100 py-2 z-50 transform origin-top-right transition-all animate-in fade-in zoom-in-95 duration-200">
                                    <div className="px-4 py-3 border-b border-slate-50">
                                        <p className="text-sm font-semibold text-slate-800">{user?.name}</p>
                                        <p className="text-xs text-slate-500 truncate">{user?.email}</p>
                                    </div>
                                    <div className="p-1">
                                        <button className="w-full flex items-center gap-3 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50 hover:text-blue-600 rounded-lg transition-colors">
                                            <User size={16} /> Profil Saya
                                        </button>
                                        <button className="w-full flex items-center gap-3 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50 hover:text-blue-600 rounded-lg transition-colors">
                                            <Settings size={16} /> Pengaturan
                                        </button>
                                    </div>
                                    <div className="border-t border-slate-50 my-1 p-1">
                                        <button
                                            onClick={handleLogout}
                                            className="w-full flex items-center gap-3 px-3 py-2 text-sm text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                                        >
                                            <LogOut size={16} /> Keluar
                                        </button>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                </header>

                {/* Content */}
                <main className="flex-1 overflow-y-auto bg-slate-50/50 p-4 lg:p-8">
                    <div className="max-w-7xl mx-auto space-y-6">
                        <Outlet />
                    </div>
                </main>
            </div>
        </div>
    );
};

export default DashboardLayout;
