import React, { useEffect, useState } from 'react';
import { User, Lock, Save, Eye, EyeOff, CheckCircle, AlertCircle } from 'lucide-react';
import { apiClient } from '../../lib/api';
import { useAuthStore } from '../../modules/auth/stores/useAuthStore';

interface ProfileData {
    id: number;
    name: string;
    username: string;
    email: string;
    role_type: string;
    school?: { id: number; name: string; school_level: string } | null;
    roles: string[];
}

interface Toast {
    type: 'success' | 'error';
    message: string;
}

export const SuperAdminProfile: React.FC = () => {
    const { user: authUser, login, token } = useAuthStore();
    const [profile, setProfile] = useState<ProfileData | null>(null);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [changingPassword, setChangingPassword] = useState(false);

    // Profile form
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');

    // Password form
    const [currentPassword, setCurrentPassword] = useState('');
    const [newPassword, setNewPassword] = useState('');
    const [confirmPassword, setConfirmPassword] = useState('');
    const [showCurrentPassword, setShowCurrentPassword] = useState(false);
    const [showNewPassword, setShowNewPassword] = useState(false);

    // UI state
    const [toast, setToast] = useState<Toast | null>(null);
    const [activeTab, setActiveTab] = useState<'profile' | 'password'>('profile');

    useEffect(() => {
        fetchProfile();
    }, []);

    useEffect(() => {
        if (toast) {
            const timer = setTimeout(() => setToast(null), 4000);
            return () => clearTimeout(timer);
        }
    }, [toast]);

    const fetchProfile = async () => {
        try {
            const response = await apiClient.get('/auth/me');
            const data = response.data?.user || response.data;
            setProfile(data);
            setName(data.name || '');
            setEmail(data.email || '');
        } catch {
            setToast({ type: 'error', message: 'Gagal memuat data profil' });
        } finally {
            setLoading(false);
        }
    };

    const handleUpdateProfile = async (e: React.FormEvent) => {
        e.preventDefault();
        setSaving(true);
        try {
            const response = await apiClient.put('/super-admin/profile', { name, email });
            const updatedUser = response.data?.user || response.data;

            // Update auth store with new data
            if (token && authUser) {
                login(token, { ...authUser, name: updatedUser.name, email: updatedUser.email });
            }

            setProfile(prev => prev ? { ...prev, name: updatedUser.name, email: updatedUser.email } : prev);
            setToast({ type: 'success', message: 'Profil berhasil diperbarui' });
        } catch (err: any) {
            const message = err?.response?.data?.message || 'Gagal memperbarui profil';
            setToast({ type: 'error', message });
        } finally {
            setSaving(false);
        }
    };

    const handleChangePassword = async (e: React.FormEvent) => {
        e.preventDefault();

        if (newPassword !== confirmPassword) {
            setToast({ type: 'error', message: 'Konfirmasi password tidak cocok' });
            return;
        }

        if (newPassword.length < 8) {
            setToast({ type: 'error', message: 'Password baru minimal 8 karakter' });
            return;
        }

        setChangingPassword(true);
        try {
            await apiClient.post('/super-admin/change-password', {
                current_password: currentPassword,
                new_password: newPassword,
                new_password_confirmation: confirmPassword,
            });

            setCurrentPassword('');
            setNewPassword('');
            setConfirmPassword('');
            setToast({ type: 'success', message: 'Password berhasil diubah' });
        } catch (err: any) {
            const message = err?.response?.data?.message || err?.response?.data?.errors?.current_password?.[0] || 'Gagal mengubah password';
            setToast({ type: 'error', message });
        } finally {
            setChangingPassword(false);
        }
    };

    if (loading) {
        return (
            <div className="flex items-center justify-center h-96">
                <div className="relative">
                    <div className="w-16 h-16 border-4 border-blue-500/30 border-t-blue-500 rounded-full animate-spin"></div>
                </div>
            </div>
        );
    }

    return (
        <div className="max-w-2xl mx-auto space-y-6">
            {/* Toast */}
            {toast && (
                <div className={`fixed top-4 right-4 z-50 flex items-center gap-2 px-4 py-3 rounded-lg shadow-lg transition-all ${
                    toast.type === 'success'
                        ? 'bg-green-500/20 border border-green-500/30 text-green-400'
                        : 'bg-red-500/20 border border-red-500/30 text-red-400'
                }`}>
                    {toast.type === 'success' ? <CheckCircle className="w-5 h-5" /> : <AlertCircle className="w-5 h-5" />}
                    <span className="text-sm font-medium">{toast.message}</span>
                </div>
            )}

            {/* Header */}
            <div>
                <h2 className="text-3xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-blue-400 to-purple-500">
                    Profil Saya
                </h2>
                <p className="text-gray-400 mt-2">Kelola informasi akun Anda</p>
            </div>

            {/* Profile Card */}
            <div className="bg-gray-800 rounded-xl border border-gray-700 shadow-lg overflow-hidden">
                {/* User Info Header */}
                <div className="p-6 border-b border-gray-700 flex items-center gap-4">
                    <div className="w-16 h-16 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white text-2xl font-bold">
                        {profile?.name?.substring(0, 2).toUpperCase() || 'SA'}
                    </div>
                    <div>
                        <h3 className="text-xl font-bold text-white">{profile?.name}</h3>
                        <p className="text-gray-400 text-sm">{profile?.email}</p>
                        <div className="flex items-center gap-2 mt-1">
                            <span className="px-2 py-0.5 text-xs font-semibold rounded-full bg-yellow-500/20 text-yellow-400 border border-yellow-500/30">
                                Super Admin
                            </span>
                            {profile?.school && (
                                <span className="text-xs text-gray-500">• {profile.school.name}</span>
                            )}
                        </div>
                    </div>
                </div>

                {/* Tabs */}
                <div className="flex border-b border-gray-700">
                    <button
                        onClick={() => setActiveTab('profile')}
                        className={`flex-1 px-6 py-3 text-sm font-medium transition-colors ${
                            activeTab === 'profile'
                                ? 'text-blue-400 border-b-2 border-blue-400'
                                : 'text-gray-400 hover:text-gray-200'
                        }`}
                    >
                        <div className="flex items-center justify-center gap-2">
                            <User className="w-4 h-4" />
                            Informasi Profil
                        </div>
                    </button>
                    <button
                        onClick={() => setActiveTab('password')}
                        className={`flex-1 px-6 py-3 text-sm font-medium transition-colors ${
                            activeTab === 'password'
                                ? 'text-blue-400 border-b-2 border-blue-400'
                                : 'text-gray-400 hover:text-gray-200'
                        }`}
                    >
                        <div className="flex items-center justify-center gap-2">
                            <Lock className="w-4 h-4" />
                            Ubah Password
                        </div>
                    </button>
                </div>

                {/* Tab Content */}
                <div className="p-6">
                    {activeTab === 'profile' ? (
                        <form onSubmit={handleUpdateProfile} className="space-y-5">
                            <div>
                                <label className="block text-sm font-medium text-gray-300 mb-2">Nama Lengkap</label>
                                <input
                                    type="text"
                                    value={name}
                                    onChange={(e) => setName(e.target.value)}
                                    className="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-xl text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
                                    placeholder="Masukkan nama lengkap"
                                    required
                                />
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-300 mb-2">Email</label>
                                <input
                                    type="email"
                                    value={email}
                                    onChange={(e) => setEmail(e.target.value)}
                                    className="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-xl text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
                                    placeholder="Masukkan email"
                                    required
                                />
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-300 mb-2">Username</label>
                                <input
                                    type="text"
                                    value={profile?.username || ''}
                                    className="w-full px-4 py-3 bg-gray-700/30 border border-gray-600/50 rounded-xl text-gray-400 cursor-not-allowed"
                                    disabled
                                />
                                <p className="text-xs text-gray-500 mt-1">Username tidak dapat diubah</p>
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-300 mb-2">Role</label>
                                <input
                                    type="text"
                                    value={profile?.roles?.join(', ') || profile?.role_type || ''}
                                    className="w-full px-4 py-3 bg-gray-700/30 border border-gray-600/50 rounded-xl text-gray-400 cursor-not-allowed"
                                    disabled
                                />
                            </div>

                            <div className="flex justify-end pt-2">
                                <button
                                    type="submit"
                                    disabled={saving}
                                    className="flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-500 text-white font-semibold rounded-xl hover:from-blue-500 hover:to-blue-400 transform hover:scale-[1.02] transition-all duration-300 disabled:opacity-50 disabled:cursor-not-allowed shadow-lg shadow-blue-500/30"
                                >
                                    {saving ? (
                                        <>
                                            <div className="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin"></div>
                                            Menyimpan...
                                        </>
                                    ) : (
                                        <>
                                            <Save className="w-5 h-5" />
                                            Simpan Perubahan
                                        </>
                                    )}
                                </button>
                            </div>
                        </form>
                    ) : (
                        <form onSubmit={handleChangePassword} className="space-y-5">
                            <div>
                                <label className="block text-sm font-medium text-gray-300 mb-2">Password Saat Ini</label>
                                <div className="relative">
                                    <input
                                        type={showCurrentPassword ? 'text' : 'password'}
                                        value={currentPassword}
                                        onChange={(e) => setCurrentPassword(e.target.value)}
                                        className="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-xl text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all pr-12"
                                        placeholder="Masukkan password saat ini"
                                        required
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowCurrentPassword(!showCurrentPassword)}
                                        className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-200 transition-colors"
                                    >
                                        {showCurrentPassword ? <EyeOff className="w-5 h-5" /> : <Eye className="w-5 h-5" />}
                                    </button>
                                </div>
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-300 mb-2">Password Baru</label>
                                <div className="relative">
                                    <input
                                        type={showNewPassword ? 'text' : 'password'}
                                        value={newPassword}
                                        onChange={(e) => setNewPassword(e.target.value)}
                                        className="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-xl text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all pr-12"
                                        placeholder="Masukkan password baru (min. 8 karakter)"
                                        required
                                        minLength={8}
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowNewPassword(!showNewPassword)}
                                        className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-200 transition-colors"
                                    >
                                        {showNewPassword ? <EyeOff className="w-5 h-5" /> : <Eye className="w-5 h-5" />}
                                    </button>
                                </div>
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-300 mb-2">Konfirmasi Password Baru</label>
                                <input
                                    type={showNewPassword ? 'text' : 'password'}
                                    value={confirmPassword}
                                    onChange={(e) => setConfirmPassword(e.target.value)}
                                    className="w-full px-4 py-3 bg-gray-700/50 border border-gray-600 rounded-xl text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
                                    placeholder="Ulangi password baru"
                                    required
                                    minLength={8}
                                />
                                {newPassword && confirmPassword && newPassword !== confirmPassword && (
                                    <p className="text-xs text-red-400 mt-1">Password tidak cocok</p>
                                )}
                            </div>

                            <div className="flex justify-end pt-2">
                                <button
                                    type="submit"
                                    disabled={changingPassword || !currentPassword || !newPassword || !confirmPassword || newPassword !== confirmPassword}
                                    className="flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-amber-600 to-orange-500 text-white font-semibold rounded-xl hover:from-amber-500 hover:to-orange-400 transform hover:scale-[1.02] transition-all duration-300 disabled:opacity-50 disabled:cursor-not-allowed shadow-lg shadow-amber-500/30"
                                >
                                    {changingPassword ? (
                                        <>
                                            <div className="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin"></div>
                                            Mengubah...
                                        </>
                                    ) : (
                                        <>
                                            <Lock className="w-5 h-5" />
                                            Ubah Password
                                        </>
                                    )}
                                </button>
                            </div>
                        </form>
                    )}
                </div>
            </div>

            {/* Account Info */}
            <div className="bg-gray-800/50 rounded-xl border border-gray-700/50 p-6">
                <h4 className="text-sm font-semibold text-gray-300 mb-3">Informasi Akun</h4>
                <div className="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <span className="text-gray-500">User ID</span>
                        <p className="text-gray-300 font-mono">#{profile?.id}</p>
                    </div>
                    <div>
                        <span className="text-gray-500">Role</span>
                        <p className="text-gray-300">{profile?.role_type}</p>
                    </div>
                    <div>
                        <span className="text-gray-500">Sekolah</span>
                        <p className="text-gray-300">{profile?.school?.name || 'Platform (Global)'}</p>
                    </div>
                    <div>
                        <span className="text-gray-500">Permissions</span>
                        <p className="text-gray-300">{profile?.roles?.length || 0} role(s)</p>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default SuperAdminProfile;
