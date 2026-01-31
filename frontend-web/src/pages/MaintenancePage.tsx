import React from 'react';
import { Wrench, Clock, AlertTriangle } from 'lucide-react';
import { useAuthStore } from '../modules/auth/stores/useAuthStore';

const MaintenancePage: React.FC = () => {
    const { user } = useAuthStore();

    return (
        <div className="flex flex-col items-center justify-center h-full min-h-[60vh] text-center p-6 space-y-6 animate-fade-in-up">
            <div className="relative">
                <div className="absolute inset-0 bg-blue-100 rounded-full animate-ping opacity-75"></div>
                <div className="relative bg-white p-6 rounded-full shadow-xl border-4 border-blue-50">
                    <Wrench className="w-16 h-16 text-blue-600 animate-pulse" />
                </div>
                <div className="absolute -bottom-2 -right-2 bg-amber-100 p-2 rounded-full border-2 border-white shadow-lg">
                    <AlertTriangle className="w-6 h-6 text-amber-600" />
                </div>
            </div>

            <div className="max-w-md space-y-3">
                <h1 className="text-3xl font-bold text-slate-900">
                    Sistem Sedang Dalam Pemeliharaan
                </h1>
                <p className="text-slate-600 text-lg">
                    Website dan Aplikasi sedang menjalani perawatan rutin untuk meningkatkan performa dan layanan.
                </p>
                <div className="bg-blue-50 border border-blue-100 rounded-xl p-4 mt-6">
                    <div className="flex items-center gap-3 justify-center text-blue-800 font-medium">
                        <Clock className="w-5 h-5" />
                        <span>Mohon tunggu, kami akan segera kembali.</span>
                    </div>
                </div>
            </div>

            {user && (
                <div className="text-sm text-slate-400 mt-8">
                    User ID: {user.email}
                </div>
            )}
        </div>
    );
};

export default MaintenancePage;
