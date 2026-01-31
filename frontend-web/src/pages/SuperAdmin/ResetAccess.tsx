import React, { useEffect, useState } from 'react';
import {
    Key,
    Search,
    Shield,
    CheckCircle,
    AlertTriangle,
    User,
    Building2,
} from 'lucide-react';
import { apiClient } from '../../lib/api';
import showToast from '../../utils/toast';

interface SchoolAdmin {
    id: number;
    name: string;
    email: string;
    username: string;
    is_active: boolean;
    school: {
        id: number;
        name: string;
    };
    last_login?: string;
}

export const ResetAccess: React.FC = () => {
    const [admins, setAdmins] = useState<SchoolAdmin[]>([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [resetting, setResetting] = useState<number | null>(null);

    useEffect(() => {
        fetchAdmins();
    }, [search]);

    const fetchAdmins = async () => {
        try {
            setLoading(true);
            const response = await apiClient.get('/super-admin/users/admins', {
                params: { search: search || undefined },
            });
            if (response.data.success) {
                setAdmins(response.data.data.data);
            }
        } catch (error) {
            console.error('Failed to fetch admins:', error);
        } finally {
            setLoading(false);
        }
    };

    const handleResetPassword = async (admin: SchoolAdmin) => {
        const confirmed = window.confirm(
            `Reset password untuk ${admin.name}?\n\nPassword baru akan dikirim ke email: ${admin.email}`
        );

        if (!confirmed) return;

        const newPassword = prompt('Masukkan password baru (minimal 6 karakter):');

        if (!newPassword) {
            showToast.error('Reset password dibatalkan');
            return;
        }

        if (newPassword.length < 6) {
            showToast.error('Password minimal 6 karakter');
            return;
        }

        try {
            setResetting(admin.id);
            await apiClient.post('/super-admin/users/reset-access', {
                user_id: admin.id,
                type: 'password',
                new_password: newPassword,
            });

            showToast.success(`✅ Password berhasil direset untuk ${admin.name}\n\nPassword baru: ${newPassword}\n\nSilakan informasikan ke admin sekolah.`);
        } catch (error) {
            console.error('Failed to reset password:', error);
            showToast.error('❌ Gagal reset password. Silakan coba lagi.');
        } finally {
            setResetting(null);
        }
    };

    const generateRandomPassword = () => {
        const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        let password = '';
        for (let i = 0; i < 8; i++) {
            password += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        return password;
    };

    const handleQuickReset = async (admin: SchoolAdmin) => {
        const newPassword = generateRandomPassword();
        const confirmed = window.confirm(
            `Reset password untuk ${admin.name}?\n\nPassword baru (random): ${newPassword}\n\nLanjutkan?`
        );

        if (!confirmed) return;

        try {
            setResetting(admin.id);
            await apiClient.post('/super-admin/users/reset-access', {
                user_id: admin.id,
                type: 'password',
                new_password: newPassword,
            });

            // Copy to clipboard
            navigator.clipboard.writeText(newPassword);

            showToast.success(
                `✅ Password berhasil direset!\n\n` +
                `User: ${admin.username}\n` +
                `Password: ${newPassword}\n\n` +
                `Password telah disalin ke clipboard.\n` +
                `Silakan informasikan ke admin sekolah.`
            );
        } catch (error) {
            console.error('Failed to reset password:', error);
            showToast.error('❌ Gagal reset password. Silakan coba lagi.');
        } finally {
            setResetting(null);
        }
    };

    return (
        <div className="min-h-screen bg-slate-50 p-6">
            {/* Header */}
            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 mb-2">Reset Akses</h1>
                <p className="text-slate-600">Reset password admin sekolah yang lupa atau terkunci</p>
            </div>

            {/* Info Box */}
            <div className="bg-blue-50 border border-blue-200 rounded-xl p-6 mb-6">
                <div className="flex gap-3">
                    <Shield className="w-6 h-6 text-blue-600 flex-shrink-0" />
                    <div>
                        <h3 className="font-semibold text-blue-900 mb-2">Panduan Reset Password</h3>
                        <ul className="text-sm text-blue-800 space-y-1">
                            <li>• Gunakan <strong>Reset Manual</strong> untuk memasukkan password sendiri</li>
                            <li>• Gunakan <strong>Reset Otomatis</strong> untuk generate password random (8 karakter)</li>
                            <li>• Password minimal 6 karakter</li>
                            <li>• Pastikan menyampaikan password baru ke admin sekolah</li>
                            <li>• Rekomendasikan admin untuk mengganti password setelah login pertama</li>
                        </ul>
                    </div>
                </div>
            </div>

            {/* Search */}
            <div className="bg-white rounded-lg shadow-sm border border-slate-200 p-4 mb-6">
                <div className="relative">
                    <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-slate-400" />
                    <input
                        type="text"
                        placeholder="Cari admin sekolah..."
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className="w-full pl-10 pr-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                    />
                </div>
            </div>

            {/* Admins List */}
            <div className="space-y-4">
                {loading ? (
                    <div className="flex justify-center py-12">
                        <div className="w-8 h-8 border-4 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
                    </div>
                ) : admins.length === 0 ? (
                    <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-12 text-center text-slate-500">
                        Tidak ada admin sekolah
                    </div>
                ) : (
                    admins.map((admin) => (
                        <div
                            key={admin.id}
                            className="bg-white rounded-xl shadow-sm border border-slate-200 p-6 hover:shadow-md transition-shadow"
                        >
                            <div className="flex items-start justify-between gap-6">
                                {/* Admin Info */}
                                <div className="flex items-start gap-4 flex-1">
                                    {/* Avatar */}
                                    <div className="w-14 h-14 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white font-bold text-xl flex-shrink-0">
                                        {admin.name.substring(0, 2).toUpperCase()}
                                    </div>

                                    {/* Details */}
                                    <div className="flex-1">
                                        <div className="flex items-center gap-3 mb-2">
                                            <h3 className="text-lg font-bold text-slate-900">{admin.name}</h3>
                                            {admin.is_active ? (
                                                <span className="flex items-center gap-1 px-2 py-1 bg-green-100 text-green-700 text-xs font-semibold rounded-full">
                                                    <CheckCircle className="w-3 h-3" />
                                                    Aktif
                                                </span>
                                            ) : (
                                                <span className="flex items-center gap-1 px-2 py-1 bg-red-100 text-red-700 text-xs font-semibold rounded-full">
                                                    <AlertTriangle className="w-3 h-3" />
                                                    Nonaktif
                                                </span>
                                            )}
                                        </div>

                                        <div className="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                                            <div className="flex items-center gap-2 text-slate-600">
                                                <User className="w-4 h-4" />
                                                <span>Username: <strong className="text-slate-900">{admin.username}</strong></span>
                                            </div>
                                            <div className="flex items-center gap-2 text-slate-600">
                                                <Building2 className="w-4 h-4" />
                                                <span className="truncate">{admin.school.name}</span>
                                            </div>
                                        </div>

                                        <div className="mt-2 text-sm text-slate-600">
                                            Email: <strong className="text-slate-900">{admin.email}</strong>
                                        </div>

                                        {admin.last_login && (
                                            <div className="mt-2 text-xs text-slate-500">
                                                Last login: {new Date(admin.last_login).toLocaleString('id-ID')}
                                            </div>
                                        )}
                                    </div>
                                </div>

                                {/* Actions */}
                                <div className="flex flex-col gap-2">
                                    <button
                                        onClick={() => handleResetPassword(admin)}
                                        disabled={resetting === admin.id}
                                        className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed whitespace-nowrap"
                                    >
                                        <Key className="w-4 h-4" />
                                        <span>{resetting === admin.id ? 'Resetting...' : 'Reset Manual'}</span>
                                    </button>
                                    <button
                                        onClick={() => handleQuickReset(admin)}
                                        disabled={resetting === admin.id}
                                        className="flex items-center gap-2 px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed whitespace-nowrap"
                                    >
                                        <Shield className="w-4 h-4" />
                                        <span>Reset Otomatis</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    ))
                )}
            </div>
        </div>
    );
};
