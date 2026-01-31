import React from 'react';
import { Menu, Bell, LogOut, User, Settings } from 'lucide-react';
import { useAuthStore } from '../../modules/auth/stores/useAuthStore';

interface TopNavbarProps {
    toggleSidebar: () => void;
}

export const TopNavbar: React.FC<TopNavbarProps> = ({ toggleSidebar }) => {
    const { user, logout } = useAuthStore();

    const handleLogout = () => {
        if (window.confirm('Apakah Anda yakin ingin keluar?')) {
            logout();
        }
    };

    const initials = user?.name
        ? user.name.substring(0, 2).toUpperCase()
        : user?.email
            ? user.email.substring(0, 2).toUpperCase()
            : 'US';

    return (
        <header className="fixed top-0 left-0 right-0 h-16 bg-gradient-to-r from-blue-600 to-blue-500 shadow-md z-50 flex items-center justify-between px-4 lg:px-6">
            {/* Left: Brand & Toggle */}
            <div className="flex items-center gap-4">
                <button
                    onClick={toggleSidebar}
                    className="p-1 text-white hover:bg-white/10 rounded-lg lg:hidden"
                >
                    <Menu className="w-6 h-6" />
                </button>
                <div className="flex items-center gap-2">
                    <div className="w-8 h-8 rounded-lg bg-white/20 flex items-center justify-center backdrop-blur-sm">
                        <span className="text-white font-bold text-lg">A</span>
                    </div>
                    <span className="text-lg font-bold text-white hidden sm:block">AbsensiQR Pro</span>
                </div>
            </div>

            {/* Right: Actions & Profile */}
            <div className="flex items-center gap-4">
                {/* Notifications */}
                <button className="relative p-2 text-blue-100 hover:text-white hover:bg-white/10 rounded-lg transition-colors">
                    <Bell className="w-5 h-5" />
                    <span className="absolute top-1.5 right-1.5 w-2 h-2 bg-red-400 rounded-full border-2 border-blue-600"></span>
                </button>

                {/* Divider */}
                <div className="h-6 w-px bg-white/20 hidden sm:block"></div>

                {/* User Profile */}
                <div className="flex items-center gap-3 pl-2">
                    <div className="hidden md:block text-right">
                        <p className="text-sm font-semibold text-white leading-tight">{user?.name}</p>
                        <p className="text-xs text-blue-100 capitalize">{user?.role_type?.replace(/_/g, ' ')}</p>
                    </div>

                    <div className="relative group cursor-pointer">
                        <div className="w-9 h-9 rounded-full bg-white text-blue-600 flex items-center justify-center font-bold shadow-sm border-2 border-white/20">
                            {initials}
                        </div>

                        {/* Dropdown */}
                        <div className="absolute right-0 mt-2 w-48 bg-white rounded-xl shadow-lg border border-slate-100 py-1 invisible opacity-0 group-hover:visible group-hover:opacity-100 transition-all transform origin-top-right scale-95 group-hover:scale-100">
                            <div className="px-4 py-3 border-b border-slate-100 md:hidden">
                                <p className="text-sm font-semibold text-slate-900">{user?.name}</p>
                                <p className="text-xs text-slate-500 capitalize">{user?.role_type}</p>
                            </div>
                            <a href="#" className="flex items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 hover:text-blue-600">
                                <User className="w-4 h-4" />
                                <span>Profil Saya</span>
                            </a>
                            <a href="#" className="flex items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 hover:text-blue-600">
                                <Settings className="w-4 h-4" />
                                <span>Pengaturan</span>
                            </a>
                            <div className="border-t border-slate-100 my-1"></div>
                            <button
                                onClick={handleLogout}
                                className="w-full flex items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50"
                            >
                                <LogOut className="w-4 h-4" />
                                <span>Keluar</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </header>
    );
};
