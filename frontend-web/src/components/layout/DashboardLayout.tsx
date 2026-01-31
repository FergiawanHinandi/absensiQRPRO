import React, { useEffect, useRef, useState } from 'react';
import { Outlet } from 'react-router-dom';
import Sidebar from './Sidebar';
import { TopNavbar } from './TopNavbar';
import { Footer } from './Footer';
import { useMaintenanceStore } from '../../store/maintenanceStore';
import { useAuthStore } from '../../modules/auth/stores/useAuthStore';
import MaintenancePage from '../../pages/MaintenancePage';

const DashboardLayout: React.FC = () => {
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const { isMaintenance } = useMaintenanceStore();
    const { user } = useAuthStore();
    const isSuperAdmin = user?.role_type === 'super_admin' || user?.email === 'super@admin.com';
    const echoInitialized = useRef(false);

    const toggleSidebar = () => {
        setSidebarOpen(!sidebarOpen);
    };

    useEffect(() => {
        // Temporarily disable Echo to fix WebSocket errors
        return;
        
        if (!user || echoInitialized.current) return;
        if (!import.meta.env.VITE_REVERB_APP_KEY) return;

        import('../../lib/echo')
            .then(() => {
                echoInitialized.current = true;
            })
            .catch((error) => {
                console.error('Failed to initialize realtime client:', error);
            });
    }, [user]);

    return (
        <div className="min-h-screen bg-slate-50">
            {/* Top Navbar (Fixed) */}
            <TopNavbar toggleSidebar={toggleSidebar} />

            {/* Sidebar (Fixed Left) */}
            <Sidebar isOpen={sidebarOpen} toggleSidebar={toggleSidebar} />

            {/* Main Content Wrapper */}
            <div className="pt-16 lg:ml-72 min-h-screen flex flex-col transition-all duration-300">
                {/* Main Content */}
                <main className="flex-1 overflow-x-hidden overflow-y-auto px-8 py-6">
                    {isMaintenance && !isSuperAdmin ? (
                        <MaintenancePage />
                    ) : (
                        <Outlet />
                    )}
                </main>

                {/* Footer */}
                <div className="px-6 py-4">
                    <Footer />
                </div>
            </div>
        </div>
    );
};

export default DashboardLayout;
