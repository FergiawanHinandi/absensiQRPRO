import React, { useEffect, useState } from 'react';
import {
    Search,
    Plus,
    Edit,
    Trash2,
    Eye,
    MapPin,
    Users,
    CheckCircle,
    XCircle,
    X,
    Save,
    Loader2,
    LogIn
} from 'lucide-react';
import { apiClient } from '../../lib/api';
import showToast from '../../utils/toast';
import { getErrorMessage } from '../../utils/errorHandler';

interface School {
    id: number;
    name: string;
    npsn: string | null;
    address: string;
    school_level: string;
    email: string;
    phone: string | null;
    is_active: boolean;
    users_count: number;
    created_at: string;
}

interface SchoolFormData {
    name: string;
    npsn: string;
    school_level: string;
    address: string;
    email: string;
    phone: string;
}

const initialForm: SchoolFormData = {
    name: '',
    npsn: '',
    school_level: 'SD',
    address: '',
    email: '',
    phone: ''
};

export const SchoolsManagement: React.FC = () => {
    const [schools, setSchools] = useState<School[]>([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [currentPage, setCurrentPage] = useState(1);
    const [totalPages, setTotalPages] = useState(1);

    // Modal State
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [isEditMode, setIsEditMode] = useState(false);
    const [isViewMode, setIsViewMode] = useState(false);
    const [selectedSchoolId, setSelectedSchoolId] = useState<number | null>(null);
    const [formData, setFormData] = useState<SchoolFormData>(initialForm);
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        fetchSchools();
    }, [currentPage, search]);

    const fetchSchools = async () => {
        try {
            setLoading(true);
            const response = await apiClient.get('/super-admin/schools', {
                params: {
                    page: currentPage,
                    per_page: 10,
                    search: search || undefined,
                },
            });

            // Handle unwrapped response (interceptor may have unwrapped it)
            const responseData = response.data;

            // Check if responseData IS the paginator (unwrapped)
            if (responseData && (Array.isArray(responseData.data) || responseData.current_page)) {
                setSchools(responseData.data || []);
                setTotalPages(responseData.last_page || 1);
            }
            // Check if responseData is wrapped (has success and data keys)
            else if (responseData && responseData.success && responseData.data) {
                setSchools(responseData.data.data);
                setTotalPages(responseData.data.last_page);
            }
        } catch (error) {
            console.error('Failed to fetch schools:', error);
        } finally {
            setLoading(false);
        }
    };

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        setCurrentPage(1);
        fetchSchools();
    };

    // --- Action Handlers ---

    const handleAddClick = () => {
        setIsEditMode(false);
        setIsViewMode(false);
        setFormData(initialForm);
        setSelectedSchoolId(null);
        setIsModalOpen(true);
    };

    const handleEditClick = (school: School) => {
        setIsEditMode(true);
        setIsViewMode(false);
        setSelectedSchoolId(school.id);
        setFormData({
            name: school.name,
            npsn: school.npsn || '',
            school_level: school.school_level,
            address: school.address || '',
            email: school.email || '',
            phone: school.phone || ''
        });
        setIsModalOpen(true);
    };

    const handleViewClick = (school: School) => {
        setIsEditMode(false);
        setIsViewMode(true);
        setSelectedSchoolId(school.id);
        setFormData({
            name: school.name,
            npsn: school.npsn || '',
            school_level: school.school_level,
            address: school.address || '',
            email: school.email || '',
            phone: school.phone || ''
        });
        setIsModalOpen(true);
    };

    const handleDeleteClick = async (id: number, name: string) => {
        if (window.confirm(`Apakah Anda yakin ingin menghapus sekolah "${name}"? Data yang sudah dihapus tidak dapat dikembalikan.`)) {
            try {
                await apiClient.delete(`/super-admin/schools/${id}`);
                fetchSchools(); // Refresh list
            } catch (error) {
                console.error('Failed to delete school:', error);
                showToast.error(getErrorMessage(error));
            }
        }
    };

    const handleImpersonate = async (id: number, name: string) => {
        if (window.confirm(`Login sebagai Admin untuk sekolah "${name}"? Anda akan logout dari Super Admin.`)) {
            try {
                const response = await apiClient.post(`/super-admin/schools/${id}/impersonate`);

                // Handle unwrapped vs wrapped response
                let data;
                if (response.data && response.data.token) {
                    // Unwrapped
                    data = response.data;
                } else if (response.data && response.data.success && response.data.data) {
                    // Wrapped
                    data = response.data.data;
                }

                if (data && data.token) {
                    const { useAuthStore } = await import('../../modules/auth/stores/useAuthStore');
                    useAuthStore.getState().login(data.token, data.user);

                    // Force redirect to Admin Dashboard to reload context
                    window.location.href = '/admin/dashboard';
                }
            } catch (error: any) {
                console.error('Impersonation failed:', error);
                showToast.error(getErrorMessage(error));
            }
        }
    };

    const handleFormSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (isViewMode) {
            setIsModalOpen(false);
            return;
        }
        setSubmitting(true);
        try {
            if (isEditMode && selectedSchoolId) {
                await apiClient.put(`/super-admin/schools/${selectedSchoolId}`, formData);
            } else {
                await apiClient.post('/super-admin/schools', formData);
            }
            setIsModalOpen(false);
            fetchSchools();
        } catch (error: any) {
            console.error('Failed to save school:', error);
            showToast.error(getErrorMessage(error));
        } finally {
            setSubmitting(false);
        }
    };

    const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => {
        const { name, value } = e.target;
        setFormData(prev => ({ ...prev, [name]: value }));
    };

    return (
        <div className="min-h-screen bg-slate-50 p-6">
            {/* Header */}
            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 mb-2">Manajemen Sekolah</h1>
                <p className="text-slate-600">Kelola semua sekolah dalam platform</p>
            </div>

            {/* Actions Bar */}
            <div className="bg-white rounded-lg shadow-sm border border-slate-200 p-4 mb-6">
                <div className="flex flex-col md:flex-row gap-4 items-center justify-between">
                    {/* Search */}
                    <form onSubmit={handleSearch} className="flex-1 max-w-md">
                        <div className="relative">
                            <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-slate-400" />
                            <input
                                type="text"
                                placeholder="Cari sekolah (Nama / NPSN)..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="w-full pl-10 pr-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                            />
                        </div>
                    </form>

                    {/* Add Button */}
                    <button
                        onClick={handleAddClick}
                        className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
                    >
                        <Plus className="w-5 h-5" />
                        <span>Tambah Sekolah</span>
                    </button>
                </div>
            </div>

            {/* Schools Table */}
            <div className="bg-white rounded-lg shadow-sm border border-slate-200 overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full">
                        <thead className="bg-slate-50 border-b border-slate-200">
                            <tr>
                                <th className="px-6 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                                    Sekolah
                                </th>
                                <th className="px-6 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                                    Jenjang
                                </th>
                                <th className="px-6 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                                    Siswa
                                </th>
                                <th className="px-6 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                                    Status
                                </th>
                                <th className="px-6 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider">
                                    Aksi
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-200">
                            {loading ? (
                                <tr>
                                    <td colSpan={5} className="px-6 py-12 text-center">
                                        <div className="flex justify-center">
                                            <div className="w-8 h-8 border-4 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
                                        </div>
                                    </td>
                                </tr>
                            ) : schools.length === 0 ? (
                                <tr>
                                    <td colSpan={5} className="px-6 py-12 text-center text-slate-500">
                                        Tidak ada data sekolah. Silakan tambah sekolah baru.
                                    </td>
                                </tr>
                            ) : (
                                schools.map((school) => (
                                    <tr key={school.id} className="hover:bg-slate-50 transition-colors">
                                        <td className="px-6 py-4">
                                            <div className="flex items-center gap-3">
                                                <div className="w-10 h-10 rounded-lg bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white font-bold shrink-0">
                                                    {school.name.substring(0, 2).toUpperCase()}
                                                </div>
                                                <div>
                                                    <p className="font-semibold text-slate-900">{school.name}</p>
                                                    <div className="text-sm text-slate-500 flex flex-col gap-0.5">
                                                        <span className="flex items-center gap-1">
                                                            <MapPin className="w-3 h-3" />
                                                            {school.address || 'Alamat tidak tersedia'}
                                                        </span>
                                                        {school.npsn && <span className="text-xs">NPSN: {school.npsn}</span>}
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td className="px-6 py-4">
                                            <span className="px-3 py-1 bg-blue-50 text-blue-700 text-sm font-medium rounded-full uppercase">
                                                {school.school_level}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4">
                                            <div className="flex items-center gap-2 text-slate-700">
                                                <Users className="w-4 h-4" />
                                                <span className="font-medium">{school.users_count || 0}</span>
                                            </div>
                                        </td>
                                        <td className="px-6 py-4">
                                            {school.is_active ? (
                                                <span className="flex items-center gap-2 text-green-600">
                                                    <CheckCircle className="w-4 h-4" />
                                                    <span className="text-sm font-medium">Aktif</span>
                                                </span>
                                            ) : (
                                                <span className="flex items-center gap-2 text-red-600">
                                                    <XCircle className="w-4 h-4" />
                                                    <span className="text-sm font-medium">Nonaktif</span>
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-6 py-4">
                                            <div className="flex items-center gap-2">
                                                <button
                                                    onClick={() => handleImpersonate(school.id, school.name)}
                                                    className="p-2 text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors"
                                                    title="Login sebagai Admin Sekolah"
                                                >
                                                    <LogIn className="w-4 h-4" />
                                                </button>
                                                <button
                                                    onClick={() => handleViewClick(school)}
                                                    className="p-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                                                    title="Lihat Detail"
                                                >
                                                    <Eye className="w-4 h-4" />
                                                </button>
                                                <button
                                                    onClick={() => handleEditClick(school)}
                                                    className="p-2 text-slate-600 hover:bg-slate-100 rounded-lg transition-colors"
                                                    title="Edit Sekolah"
                                                >
                                                    <Edit className="w-4 h-4" />
                                                </button>
                                                <button
                                                    onClick={() => handleDeleteClick(school.id, school.name)}
                                                    className="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                                                    title="Hapus Sekolah"
                                                >
                                                    <Trash2 className="w-4 h-4" />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Pagination */}
                {totalPages > 1 && (
                    <div className="px-6 py-4 border-t border-slate-200 flex items-center justify-between">
                        <p className="text-sm text-slate-600">
                            Halaman {currentPage} dari {totalPages}
                        </p>
                        <div className="flex gap-2">
                            <button
                                onClick={() => setCurrentPage(prev => Math.max(1, prev - 1))}
                                disabled={currentPage === 1}
                                className="px-4 py-2 border border-slate-300 rounded-lg hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                Sebelumnya
                            </button>
                            <button
                                onClick={() => setCurrentPage(prev => Math.min(totalPages, prev + 1))}
                                disabled={currentPage === totalPages}
                                className="px-4 py-2 border border-slate-300 rounded-lg hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                Selanjutnya
                            </button>
                        </div>
                    </div>
                )}
            </div>

            {/* Create/Edit/View Modal */}
            {isModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
                    <div className="bg-white rounded-xl shadow-xl w-full max-w-lg overflow-hidden animate-in fade-in zoom-in duration-200">
                        <div className="px-6 py-4 border-b border-slate-200 flex justify-between items-center bg-slate-50">
                            <h3 className="text-lg font-bold text-slate-900">
                                {isViewMode ? 'Detail Sekolah' : (isEditMode ? 'Edit Sekolah' : 'Tambah Sekolah Baru')}
                            </h3>
                            <button onClick={() => setIsModalOpen(false)} className="text-slate-400 hover:text-slate-600">
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        <form onSubmit={handleFormSubmit} className="p-6 space-y-4">
                            <fieldset disabled={isViewMode} className="space-y-4 disabled:opacity-80">
                                <div>
                                    <label className="block text-sm font-medium text-slate-700 mb-1">Nama Sekolah</label>
                                    <input
                                        type="text"
                                        name="name"
                                        required
                                        value={formData.name}
                                        onChange={handleChange}
                                        className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 disabled:bg-slate-50 disabled:text-slate-500"
                                        placeholder="Contoh: SMA Negeri 1 Jakarta"
                                    />
                                </div>

                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <label className="block text-sm font-medium text-slate-700 mb-1">NPSN</label>
                                        <input
                                            type="text"
                                            name="npsn"
                                            value={formData.npsn}
                                            onChange={handleChange}
                                            className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 disabled:bg-slate-50 disabled:text-slate-500"
                                            placeholder="Nomor Pokok Sekolah Nasional"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-slate-700 mb-1">Jenjang</label>
                                        <select
                                            name="school_level"
                                            value={formData.school_level}
                                            onChange={handleChange}
                                            className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 disabled:bg-slate-50 disabled:text-slate-500"
                                        >
                                            <option value="SD">SD / MI</option>
                                            <option value="SMP">SMP / MTs</option>
                                            <option value="SMA">SMA / MA</option>
                                            <option value="SMK">SMK</option>
                                        </select>
                                    </div>
                                </div>

                                <div>
                                    <label className="block text-sm font-medium text-slate-700 mb-1">Email Sekolah</label>
                                    <input
                                        type="email"
                                        name="email"
                                        required
                                        value={formData.email}
                                        onChange={handleChange}
                                        className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 disabled:bg-slate-50 disabled:text-slate-500"
                                        placeholder="admin@sekolah.sch.id"
                                    />
                                </div>

                                <div>
                                    <label className="block text-sm font-medium text-slate-700 mb-1">Telepon</label>
                                    <input
                                        type="tel"
                                        name="phone"
                                        value={formData.phone}
                                        onChange={handleChange}
                                        className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 disabled:bg-slate-50 disabled:text-slate-500"
                                        placeholder="021-xxxxxxx"
                                    />
                                </div>

                                <div>
                                    <label className="block text-sm font-medium text-slate-700 mb-1">Alamat Lengkap</label>
                                    <textarea
                                        name="address"
                                        rows={3}
                                        required
                                        value={formData.address}
                                        onChange={handleChange}
                                        className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 disabled:bg-slate-50 disabled:text-slate-500"
                                        placeholder="Jl. Raya No. 1..."
                                    ></textarea>
                                </div>
                            </fieldset>

                            <div className="flex gap-3 pt-4">
                                <button
                                    type="button"
                                    onClick={() => setIsModalOpen(false)}
                                    className="flex-1 px-4 py-2 border border-slate-300 text-slate-700 rounded-lg hover:bg-slate-50 font-medium"
                                >
                                    {isViewMode ? 'Tutup' : 'Batal'}
                                </button>
                                {!isViewMode && (
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
                                )}
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
};
