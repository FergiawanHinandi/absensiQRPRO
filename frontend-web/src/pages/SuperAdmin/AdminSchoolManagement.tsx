import React, { useEffect, useState } from 'react';
import {
    Search,
    Plus,
    Key,
    Power,
    Activity,
    Mail,
    Building2,
    X,
    Loader2,
    Save
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
    created_at: string;
}

interface SchoolOption {
    id: number;
    name: string;
}

export const AdminSchoolManagement: React.FC = () => {
    const [admins, setAdmins] = useState<SchoolAdmin[]>([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');

    // Modal & Form State
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [schools, setSchools] = useState<SchoolOption[]>([]);
    const [loadingSchools, setLoadingSchools] = useState(false);
    const [submitting, setSubmitting] = useState(false);

    const [formData, setFormData] = useState({
        name: '',
        email: '',
        password: '',
        school_id: ''
    });

    useEffect(() => {
        fetchAdmins();
    }, [search]);

    useEffect(() => {
        if (isModalOpen) {
            fetchSchools();
        }
    }, [isModalOpen]);

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

    const fetchSchools = async () => {
        try {
            setLoadingSchools(true);
            const response = await apiClient.get('/super-admin/schools', {
                params: { per_page: 100, status: 'active' }
            });
            if (response.data.success) {
                setSchools(response.data.data.data);
            }
        } catch (error) {
            console.error('Failed to fetch schools:', error);
        } finally {
            setLoadingSchools(false);
        }
    };

    const handleResetPassword = async (adminId: number) => {
        const newPassword = prompt('Masukkan password baru (min. 6 karakter):');
        if (!newPassword || newPassword.length < 6) {
            showToast.error('Password minimal 6 karakter');
            return;
        }

        try {
            await apiClient.post('/super-admin/users/reset-access', {
                user_id: adminId,
                type: 'password',
                new_password: newPassword,
            });
            showToast.success('Password berhasil direset');
        } catch (error) {
            console.error('Failed to reset password:', error);
            showToast.error('Gagal reset password');
        }
    };

    const handleToggleStatus = async (adminId: number) => {
        const admin = admins.find(a => a.id === adminId);
        const action = admin?.is_active ? 'menonaktifkan' : 'mengaktifkan';

        if (!confirm(`Apakah Anda yakin ingin ${action} admin ini?`)) return;

        try {
            const response = await apiClient.patch(`/super-admin/users/${adminId}/status`);

            if (response.data.success) {
                showToast.success(response.data.message || `Admin berhasil ${admin?.is_active ? 'dinonaktifkan' : 'diaktifkan'}`);
                fetchAdmins(); // Refresh list
            }
        } catch (error: any) {
            console.error('Failed to toggle status:', error);
            showToast.error(error.response?.data?.message || 'Gagal mengubah status admin');
        }
    };

    const handleFormSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        setSubmitting(true);

        try {
            await apiClient.post('/super-admin/users/admins', formData);
            showToast.success('Admin berhasil ditambahkan');
            setIsModalOpen(false);
            setFormData({ name: '', email: '', password: '', school_id: '' });
            fetchAdmins();
        } catch (error: any) {
            console.error('Failed to create admin:', error);
            showToast.error(error.response?.data?.message || 'Gagal menambahkan admin');
        } finally {
            setSubmitting(false);
        }
    };

    const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
        setFormData({ ...formData, [e.target.name]: e.target.value });
    };

    return (
        <div className="min-h-screen bg-slate-50 p-6">
            {/* Header */}
            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 mb-2">Admin Sekolah</h1>
                <p className="text-slate-600">Kelola akun admin sekolah dalam platform</p>
            </div>

            {/* Actions Bar */}
            <div className="bg-white rounded-lg shadow-sm border border-slate-200 p-4 mb-6">
                <div className="flex flex-col md:flex-row gap-4 items-center justify-between">
                    {/* Search */}
                    <div className="flex-1 max-w-md relative">
                        <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-slate-400" />
                        <input
                            type="text"
                            placeholder="Cari admin..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="w-full pl-10 pr-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        />
                    </div>

                    {/* Add Button */}
                    <button
                        onClick={() => setIsModalOpen(true)}
                        className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
                    >
                        <Plus className="w-5 h-5" />
                        <span>Tambah Admin</span>
                    </button>
                </div>
            </div>

            {/* Admins Grid */}
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                {loading ? (
                    <div className="col-span-full flex justify-center py-12">
                        <div className="w-8 h-8 border-4 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
                    </div>
                ) : admins.length === 0 ? (
                    <div className="col-span-full text-center py-12 text-slate-500">
                        Tidak ada admin sekolah. Silakan tambah admin baru.
                    </div>
                ) : (
                    admins.map((admin) => (
                        <div
                            key={admin.id}
                            className="bg-white rounded-xl shadow-sm border border-slate-200 p-6 hover:shadow-md transition-shadow"
                        >
                            {/* Admin Header */}
                            <div className="flex items-start justify-between mb-4">
                                <div className="flex items-center gap-3">
                                    <div className="w-12 h-12 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white font-bold text-lg">
                                        {admin.name.substring(0, 2).toUpperCase()}
                                    </div>
                                    <div>
                                        <h3 className="font-bold text-slate-900">{admin.name}</h3>
                                        <p className="text-sm text-slate-600">@{admin.username}</p>
                                    </div>
                                </div>
                                {admin.is_active ? (
                                    <span className="px-2 py-1 bg-green-100 text-green-700 text-xs font-semibold rounded-full">
                                        Aktif
                                    </span>
                                ) : (
                                    <span className="px-2 py-1 bg-red-100 text-red-700 text-xs font-semibold rounded-full">
                                        Nonaktif
                                    </span>
                                )}
                            </div>

                            {/* Admin Info */}
                            <div className="space-y-2 mb-4">
                                <div className="flex items-center gap-2 text-sm text-slate-600">
                                    <Mail className="w-4 h-4" />
                                    <span className="truncate">{admin.email}</span>
                                </div>
                                <div className="flex items-center gap-2 text-sm text-slate-600">
                                    <Building2 className="w-4 h-4" />
                                    <span className="truncate">{admin.school?.name || 'Sekolah Tidak Ditemukan'}</span>
                                </div>
                                <div className="flex items-center gap-2 text-sm text-slate-500">
                                    <Activity className="w-4 h-4" />
                                    <span>Bergabung: {new Date(admin.created_at).toLocaleDateString('id-ID')}</span>
                                </div>
                            </div>

                            {/* Actions */}
                            <div className="flex gap-2">
                                <button
                                    onClick={() => handleResetPassword(admin.id)}
                                    className="flex-1 flex items-center justify-center gap-2 px-3 py-2 bg-blue-50 text-blue-700 rounded-lg hover:bg-blue-100 transition-colors text-sm font-medium"
                                >
                                    <Key className="w-4 h-4" />
                                    <span>Reset</span>
                                </button>
                                <button
                                    onClick={() => handleToggleStatus(admin.id)}
                                    className={`flex-1 flex items-center justify-center gap-2 px-3 py-2 rounded-lg transition-colors text-sm font-medium ${admin.is_active
                                        ? 'bg-red-50 text-red-700 hover:bg-red-100'
                                        : 'bg-green-50 text-green-700 hover:bg-green-100'
                                        }`}
                                >
                                    <Power className="w-4 h-4" />
                                    <span>{admin.is_active ? 'Nonaktifkan' : 'Aktifkan'}</span>
                                </button>
                            </div>
                        </div>
                    ))
                )}
            </div>

            {/* Create Admin Modal */}
            {isModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
                    <div className="bg-white rounded-xl shadow-xl w-full max-w-lg overflow-hidden animate-in fade-in zoom-in duration-200">
                        <div className="px-6 py-4 border-b border-slate-200 flex justify-between items-center bg-slate-50">
                            <h3 className="text-lg font-bold text-slate-900">Tambah Admin Sekolah</h3>
                            <button onClick={() => setIsModalOpen(false)} className="text-slate-400 hover:text-slate-600">
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        <form onSubmit={handleFormSubmit} className="p-6 space-y-4">
                            <div>
                                <label className="block text-sm font-medium text-slate-700 mb-1">Nama Lengkap</label>
                                <input
                                    type="text"
                                    name="name"
                                    required
                                    value={formData.name}
                                    onChange={handleChange}
                                    className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                    placeholder="Nama Admin"
                                />
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-slate-700 mb-1">Email</label>
                                <input
                                    type="email"
                                    name="email"
                                    required
                                    value={formData.email}
                                    onChange={handleChange}
                                    className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                    placeholder="email@sekolah.sch.id"
                                />
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-slate-700 mb-1">Password</label>
                                <input
                                    type="password"
                                    name="password"
                                    required
                                    minLength={6}
                                    value={formData.password}
                                    onChange={handleChange}
                                    className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                    placeholder="Minimal 6 karakter"
                                />
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-slate-700 mb-1">Pilih Sekolah</label>
                                <select
                                    name="school_id"
                                    required
                                    value={formData.school_id}
                                    onChange={handleChange}
                                    className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                >
                                    <option value="">-- Pilih Sekolah --</option>
                                    {loadingSchools ? (
                                        <option disabled>Memuat sekolah...</option>
                                    ) : (
                                        schools.map((school) => (
                                            <option key={school.id} value={school.id}>
                                                {school.name}
                                            </option>
                                        ))
                                    )}
                                </select>
                            </div>

                            <div className="flex gap-3 pt-4">
                                <button
                                    type="button"
                                    onClick={() => setIsModalOpen(false)}
                                    className="flex-1 px-4 py-2 border border-slate-300 text-slate-700 rounded-lg hover:bg-slate-50 font-medium"
                                >
                                    Batal
                                </button>
                                <button
                                    type="submit"
                                    disabled={submitting}
                                    className="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium flex items-center justify-center gap-2 disabled:opacity-50"
                                >
                                    {submitting ? (
                                        <>
                                            <Loader2 className="w-4 h-4 animate-spin" />
                                            Menyimpan...
                                        </>
                                    ) : (
                                        <>
                                            <Save className="w-4 h-4" />
                                            Simpan
                                        </>
                                    )}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
};
