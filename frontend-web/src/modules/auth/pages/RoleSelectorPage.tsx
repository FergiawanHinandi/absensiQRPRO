import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';

interface RoleOption {
    id: string;
    name: string;
    description: string;
    icon: string;
    color: string;
    gradient: string;
    path: string;
}

const roles: RoleOption[] = [
    {
        id: 'super_admin',
        name: 'Super Admin',
        description: 'Kelola seluruh platform',
        icon: '👑',
        color: 'from-amber-500 to-yellow-600',
        gradient: 'bg-gradient-to-br from-amber-500 via-yellow-500 to-orange-500',
        path: '/login/super-admin',
    },
    {
        id: 'admin',
        name: 'Admin Sekolah',
        description: 'Kelola data sekolah',
        icon: '🏫',
        color: 'from-blue-500 to-indigo-600',
        gradient: 'bg-gradient-to-br from-blue-500 via-indigo-500 to-purple-500',
        path: '/login/admin',
    },
    {
        id: 'principal',
        name: 'Kepala Sekolah',
        description: 'Monitor & approve',
        icon: '🎓',
        color: 'from-teal-500 to-cyan-600',
        gradient: 'bg-gradient-to-br from-teal-500 via-cyan-500 to-blue-500',
        path: '/login/teacher',
    },
    {
        id: 'teacher',
        name: 'Guru',
        description: 'Kelola absensi siswa',
        icon: '📚',
        color: 'from-emerald-500 to-teal-600',
        gradient: 'bg-gradient-to-br from-emerald-500 via-teal-500 to-cyan-500',
        path: '/login/teacher',
    },
    {
        id: 'student',
        name: 'Siswa',
        description: 'Lihat absensi & jadwal',
        icon: '🎒',
        color: 'from-purple-500 to-pink-600',
        gradient: 'bg-gradient-to-br from-purple-500 via-pink-500 to-rose-500',
        path: '/login/student',
    },
    {
        id: 'parent',
        name: 'Orang Tua',
        description: 'Monitor progress anak',
        icon: '👨‍👩‍👧‍👦',
        color: 'from-orange-500 to-red-600',
        gradient: 'bg-gradient-to-br from-orange-500 via-amber-500 to-yellow-500',
        path: '/login/parent',
    },
];

export const RoleSelectorPage: React.FC = () => {
    const navigate = useNavigate();
    const [hoveredRole, setHoveredRole] = useState<string | null>(null);
    const [selectedRole, setSelectedRole] = useState<string | null>(null);

    const handleRoleSelect = (role: RoleOption) => {
        setSelectedRole(role.id);
        setTimeout(() => {
            navigate(role.path);
        }, 300);
    };

    return (
        <div className="min-h-screen bg-gradient-to-br from-slate-900 via-purple-900 to-slate-900 flex items-center justify-center p-4 overflow-hidden relative">
            {/* Animated Background */}
            <div className="absolute inset-0 overflow-hidden">
                <div className="absolute -top-40 -right-40 w-80 h-80 bg-purple-500 rounded-full mix-blend-multiply filter blur-xl opacity-20 animate-pulse"></div>
                <div className="absolute -bottom-40 -left-40 w-80 h-80 bg-blue-500 rounded-full mix-blend-multiply filter blur-xl opacity-20 animate-pulse animation-delay-2000"></div>
                <div className="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-80 h-80 bg-pink-500 rounded-full mix-blend-multiply filter blur-xl opacity-20 animate-pulse animation-delay-4000"></div>
                
                {/* Floating particles */}
                {[...Array(20)].map((_, i) => (
                    <div
                        key={i}
                        className="absolute w-2 h-2 bg-white rounded-full opacity-10"
                        style={{
                            left: `${Math.random() * 100}%`,
                            top: `${Math.random() * 100}%`,
                            animation: `float ${3 + Math.random() * 4}s ease-in-out infinite`,
                            animationDelay: `${Math.random() * 2}s`,
                        }}
                    ></div>
                ))}
            </div>

            <div className="relative z-10 max-w-4xl w-full">
                {/* Header */}
                <div className="text-center mb-12">
                    <div className="inline-flex items-center justify-center w-20 h-20 bg-white/10 backdrop-blur-lg rounded-2xl mb-6 shadow-2xl border border-white/20">
                        <span className="text-4xl">📱</span>
                    </div>
                    <h1 className="text-5xl font-bold text-white mb-4 tracking-tight">
                        Absensi<span className="text-transparent bg-clip-text bg-gradient-to-r from-purple-400 to-pink-400">QR</span> Pro
                    </h1>
                    <p className="text-xl text-white/70 max-w-md mx-auto">
                        Pilih peran Anda untuk masuk ke sistem
                    </p>
                </div>

                {/* Role Cards Grid */}
                <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                    {roles.map((role, index) => (
                        <button
                            key={role.id}
                            onClick={() => handleRoleSelect(role)}
                            onMouseEnter={() => setHoveredRole(role.id)}
                            onMouseLeave={() => setHoveredRole(null)}
                            className={`group relative bg-white/10 backdrop-blur-lg rounded-2xl p-6 border border-white/20 transition-all duration-300 transform hover:scale-105 hover:shadow-2xl ${
                                selectedRole === role.id ? 'scale-95 opacity-70' : ''
                            } ${hoveredRole === role.id ? 'border-white/40' : ''}`}
                            style={{
                                animationDelay: `${index * 100}ms`,
                            }}
                        >
                            {/* Hover Gradient Background */}
                            <div className={`absolute inset-0 ${role.gradient} rounded-2xl opacity-0 group-hover:opacity-100 transition-opacity duration-300`}></div>
                            
                            {/* Content */}
                            <div className="relative z-10">
                                <div className="text-5xl mb-4 transform group-hover:scale-110 transition-transform duration-300">
                                    {role.icon}
                                </div>
                                <h3 className="text-lg font-bold text-white mb-2">
                                    {role.name}
                                </h3>
                                <p className="text-sm text-white/60 group-hover:text-white/80 transition-colors">
                                    {role.description}
                                </p>
                            </div>

                            {/* Arrow indicator */}
                            <div className="absolute bottom-4 right-4 opacity-0 group-hover:opacity-100 transform translate-x-2 group-hover:translate-x-0 transition-all duration-300">
                                <svg className="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                                </svg>
                            </div>
                        </button>
                    ))}
                </div>

                {/* Footer */}
                <div className="text-center mt-12">
                    <p className="text-white/40 text-sm">
                        © 2026 AbsensiQR Pro. Sistem Absensi Sekolah Digital
                    </p>
                </div>
            </div>

            <style>{`
                @keyframes float {
                    0%, 100% { transform: translateY(0px) rotate(0deg); opacity: 0.1; }
                    50% { transform: translateY(-20px) rotate(180deg); opacity: 0.3; }
                }
                .animation-delay-2000 { animation-delay: 2s; }
                .animation-delay-4000 { animation-delay: 4s; }
            `}</style>
        </div>
    );
};
