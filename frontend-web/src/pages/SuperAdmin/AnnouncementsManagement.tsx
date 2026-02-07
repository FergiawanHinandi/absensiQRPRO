import React, { useEffect, useState } from 'react';
import {
    Bell,
    Plus,
    Edit,
    Trash2,
    X,
    Save,
    Loader2,
    AlertCircle,
    Info,
    AlertTriangle,
    CheckCircle,
    Search
} from 'lucide-react';
import { apiClient } from '../../lib/api';
import showToast from '../../utils/toast';

interface Announcement {
    id: number;
    title: string;
    content: string;
    type: 'info' | 'warning' | 'critical' | 'success';
    target_role: 'all' | 'admin' | 'school_admin' | 'teacher' | 'student';
    is_active: boolean;
    expires_at: string | null;
    created_at: string;
}

interface AnnouncementFormData {
    title: string;
    content: string;
    type: 'info' | 'warning' | 'critical' | 'success';
    target_role: 'all' | 'admin' | 'school_admin' | 'teacher' | 'student';
    is_active: boolean;
    expires_at: string;
}

const initialForm: AnnouncementFormData = {
    title: '',
    content: '',
    type: 'info',
    target_role: 'all',
    is_active: true,
    expires_at: ''
};

const typeIcons = {
    info: Info,
    warning: AlertTriangle,
    critical: AlertCircle,
    success: CheckCircle
};

const typeColors = {
    info: 'bg-blue-500/20 text-blue-300 border-blue-500/30',
    warning: 'bg-amber-500/20 text-amber-300 border-amber-500/30',
    critical: 'bg-red-500/20 text-red-300 border-red-500/30',
    success: 'bg-green-500/20 text-green-300 border-green-500/30'
};

export const AnnouncementsManagement: React.FC = () => {
    const [announcements, setAnnouncements] = useState<Announcement[]>([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [currentPage, setCurrentPage] = useState(1);
    const [totalPages, setTotalPages] = useState(1);

    // Modal State
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [isEditMode, setIsEditMode] = useState(false);
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [formData, setFormData] = useState<AnnouncementFormData>(initialForm);
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        fetchAnnouncements();
    }, [currentPage, search]);

    const fetchAnnouncements = async () => {
        try {
            setLoading(true);
            const response = await apiClient.get('/super-admin/announcements', {
                params: {
                    page: currentPage,
                    per_page: 10,
                    search: search || undefined,
                },
            });

            if (response.data.success) {
                setAnnouncements(response.data.data.data);
                setTotalPages(response.data.data.last_page);
            }
        } catch (error) {
            console.error('Failed to fetch announcements:', error);
        } finally {
            setLoading(false);
        }
    };

    const handleAddClick = () => {
        setIsEditMode(false);
        setFormData(initialForm);
        setSelectedId(null);
        setIsModalOpen(true);
    };

    const handleEditClick = (announcement: Announcement) => {
        setIsEditMode(true);
        setSelectedId(announcement.id);
        setFormData({
            title: announcement.title,
            content: announcement.content,
            type: announcement.type,
            target_role: announcement.target_role,
            is_active: announcement.is_active,
            expires_at: announcement.expires_at ? announcement.expires_at.split('T')[0] : ''
        });
        setIsModalOpen(true);
    };

    const handleDeleteClick = async (id: number, title: string) => {
        if (window.confirm(`Apakah Anda yakin ingin menghapus pengumuman "${title}"?`)) {
            try {
                await apiClient.delete(`/super-admin/announcements/${id}`);
                fetchAnnouncements();
                showToast.success('Pengumuman berhasil dihapus');
            } catch (error) {
                console.error('Failed to delete announcement:', error);
                showToast.error('Gagal menghapus pengumuman. Silakan coba lagi.');
            }
        }
    };

    const handleFormSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        setSubmitting(true);
        try {
            const payload = {
                ...formData,
                expires_at: formData.expires_at || null
            };

            if (isEditMode && selectedId) {
                await apiClient.put(`/super-admin/announcements/${selectedId}`, payload);
            } else {
                await apiClient.post('/super-admin/announcements', payload);
            }
            setIsModalOpen(false);
            fetchAnnouncements();
            showToast.success(`Pengumuman berhasil ${isEditMode ? 'diperbarui' : 'dibuat'}`);
        } catch (error: any) {
            console.error('Failed to save announcement:', error);
            const msg = error.response?.data?.message || 'Terjadi kesalahan saat menyimpan data.';
            showToast.error(msg);
        } finally {
            setSubmitting(false);
        }
    };

    const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => {
        const { name, value, type } = e.target;
        const checked = (e.target as HTMLInputElement).checked;
        setFormData(prev => ({
            ...prev,
            [name]: type === 'checkbox' ? checked : value
        }));
    };

    const TypeIcon = ({ type }: { type: Announcement['type'] }) => {
        const Icon = typeIcons[type];
        return <Icon className="w-4 h-4" />;
    };

    return (
        <div className="space-y-6">
            {/* Header */}
            <div className="mb-6">
                <h1 className="text-3xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-blue-400 to-purple-500">
                    Manajemen Pengumuman
                </h1>
                <p className="text-gray-400 mt-2">Broadcast informasi penting ke seluruh platform</p>
            </div>

            {/* Actions Bar */}
            <div className="bg-gray-800 rounded-xl border border-gray-700 p-4 shadow-lg">
                <div className="flex flex-col md:flex-row gap-4 items-center justify-between">
                    {/* Search */}
                    <div className="flex-1 max-w-md w-full">
                        <div className="relative">
                            <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-400" />
                            <input
                                type="text"
                                placeholder="Cari pengumuman..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="w-full pl-10 pr-4 py-2.5 bg-gray-900 border border-gray-600 rounded-lg text-gray-100 placeholder-gray-500 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
                            />
                        </div>
                    </div>

                    {/* Add Button */}
                    <button
                        onClick={handleAddClick}
                        className="flex items-center gap-2 px-5 py-2.5 bg-gradient-to-r from-blue-600 to-blue-500 text-white rounded-lg hover:from-blue-700 hover:to-blue-600 transition-all shadow-lg hover:shadow-blue-500/50 font-medium whitespace-nowrap"
                    >
                        <Plus className="w-5 h-5" />
                        <span>Buat Pengumuman</span>
                    </button>
                </div>
            </div>

            {/* Announcements List */}
            <div className="space-y-4">
                {loading ? (
                    <div className="flex justify-center py-16">
                        <div className="relative">
                            <div className="w-12 h-12 border-4 border-blue-500/30 border-t-blue-500 rounded-full animate-spin"></div>
                        </div>
                    </div>
                ) : announcements.length === 0 ? (
                    <div className="bg-gray-800 rounded-xl border border-gray-700 p-16 text-center shadow-lg">
                        <div className="w-20 h-20 rounded-full bg-gray-700 flex items-center justify-center mx-auto mb-4">
                            <Bell className="w-10 h-10 text-gray-500" />
                        </div>
                        <p className="text-gray-400 text-lg">Belum ada pengumuman</p>
                        <p className="text-gray-500 text-sm mt-2">Buat pengumuman pertama Anda untuk memulai!</p>
                    </div>
                ) : (
                    announcements.map((announcement) => (
                        <div
                            key={announcement.id}
                            className="bg-gray-800 rounded-xl border border-gray-700 p-6 hover:border-gray-600 hover:shadow-xl transition-all duration-300 group"
                        >
                            <div className="flex items-start justify-between gap-4">
                                <div className="flex-1">
                                    <div className="flex items-center gap-3 mb-3 flex-wrap">
                                        <span className={`flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-semibold border ${typeColors[announcement.type]}`}>
                                            <TypeIcon type={announcement.type} />
                                            {announcement.type.toUpperCase()}
                                        </span>
                                        <span className="px-3 py-1.5 bg-gray-700 text-gray-300 text-xs font-medium rounded-lg border border-gray-600">
                                            {announcement.target_role === 'all' ? 'Semua User' : announcement.target_role}
                                        </span>
                                        {!announcement.is_active && (
                                            <span className="px-3 py-1.5 bg-red-500/20 text-red-300 text-xs font-medium rounded-lg border border-red-500/30">
                                                Nonaktif
                                            </span>
                                        )}
                                    </div>
                                    <h3 className="text-xl font-bold text-white mb-2 group-hover:text-blue-400 transition-colors">
                                        {announcement.title}
                                    </h3>
                                    <p className="text-gray-400 mb-4 leading-relaxed">{announcement.content}</p>
                                    <div className="flex items-center gap-4 text-xs text-gray-500">
                                        <span className="flex items-center gap-1">
                                            <span className="w-1.5 h-1.5 rounded-full bg-blue-500"></span>
                                            Dibuat: {new Date(announcement.created_at).toLocaleDateString('id-ID')}
                                        </span>
                                        {announcement.expires_at && (
                                            <span className="flex items-center gap-1">
                                                <span className="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                                Kadaluarsa: {new Date(announcement.expires_at).toLocaleDateString('id-ID')}
                                            </span>
                                        )}
                                    </div>
                                </div>
                                <div className="flex items-center gap-2">
                                    <button
                                        onClick={() => handleEditClick(announcement)}
                                        className="p-2.5 text-gray-400 hover:text-blue-400 hover:bg-blue-500/10 rounded-lg transition-all"
                                        title="Edit"
                                    >
                                        <Edit className="w-4 h-4" />
                                    </button>
                                    <button
                                        onClick={() => handleDeleteClick(announcement.id, announcement.title)}
                                        className="p-2.5 text-gray-400 hover:text-red-400 hover:bg-red-500/10 rounded-lg transition-all"
                                        title="Hapus"
                                    >
                                        <Trash2 className="w-4 h-4" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    ))
                )}
            </div>

            {/* Pagination */}
            {totalPages > 1 && (
                <div className="flex items-center justify-between bg-gray-800 rounded-xl border border-gray-700 px-6 py-4 shadow-lg">
                    <p className="text-sm text-gray-400">
                        Halaman <span className="font-semibold text-white">{currentPage}</span> dari <span className="font-semibold text-white">{totalPages}</span>
                    </p>
                    <div className="flex gap-2">
                        <button
                            onClick={() => setCurrentPage(prev => Math.max(1, prev - 1))}
                            disabled={currentPage === 1}
                            className="px-4 py-2 bg-gray-700 border border-gray-600 text-gray-300 rounded-lg hover:bg-gray-600 disabled:opacity-50 disabled:cursor-not-allowed transition-all font-medium"
                        >
                            Sebelumnya
                        </button>
                        <button
                            onClick={() => setCurrentPage(prev => Math.min(totalPages, prev + 1))}
                            disabled={currentPage === totalPages}
                            className="px-4 py-2 bg-gray-700 border border-gray-600 text-gray-300 rounded-lg hover:bg-gray-600 disabled:opacity-50 disabled:cursor-not-allowed transition-all font-medium"
                        >
                            Selanjutnya
                        </button>
                    </div>
                </div>
            )}

            {/* Create/Edit Modal */}
            {isModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm p-4">
                    <div className="bg-gray-800 rounded-xl shadow-2xl w-full max-w-2xl border border-gray-700 overflow-hidden">
                        <div className="px-6 py-4 border-b border-gray-700 flex justify-between items-center bg-gray-900">
                            <h3 className="text-lg font-bold text-white">
                                {isEditMode ? 'Edit Pengumuman' : 'Buat Pengumuman Baru'}
                            </h3>
                            <button onClick={() => setIsModalOpen(false)} className="text-gray-400 hover:text-white transition-colors">
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        <form onSubmit={handleFormSubmit} className="p-6 space-y-4">
                            <div>
                                <label className="block text-sm font-medium text-gray-300 mb-2">Judul</label>
                                <input
                                    type="text"
                                    name="title"
                                    required
                                    value={formData.title}
                                    onChange={handleChange}
                                    className="w-full px-4 py-2.5 bg-gray-900 border border-gray-600 rounded-lg text-gray-100 placeholder-gray-500 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
                                    placeholder="Contoh: Maintenance Server Malam Ini"
                                />
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-300 mb-2">Konten</label>
                                <textarea
                                    name="content"
                                    rows={4}
                                    required
                                    value={formData.content}
                                    onChange={handleChange}
                                    className="w-full px-4 py-2.5 bg-gray-900 border border-gray-600 rounded-lg text-gray-100 placeholder-gray-500 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all resize-none"
                                    placeholder="Tulis detail pengumuman..."
                                ></textarea>
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-sm font-medium text-gray-300 mb-2">Tipe</label>
                                    <select
                                        name="type"
                                        value={formData.type}
                                        onChange={handleChange}
                                        className="w-full px-4 py-2.5 bg-gray-900 border border-gray-600 rounded-lg text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
                                    >
                                        <option value="info">Info</option>
                                        <option value="warning">Warning</option>
                                        <option value="critical">Critical</option>
                                        <option value="success">Success</option>
                                    </select>
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-gray-300 mb-2">Target</label>
                                    <select
                                        name="target_role"
                                        value={formData.target_role}
                                        onChange={handleChange}
                                        className="w-full px-4 py-2.5 bg-gray-900 border border-gray-600 rounded-lg text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
                                    >
                                        <option value="all">Semua User</option>
                                        <option value="admin">Admin Sekolah</option>
                                        <option value="teacher">Guru</option>
                                        <option value="student">Siswa</option>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-300 mb-2">Tanggal Kadaluarsa (Opsional)</label>
                                <input
                                    type="date"
                                    name="expires_at"
                                    value={formData.expires_at}
                                    onChange={handleChange}
                                    className="w-full px-4 py-2.5 bg-gray-900 border border-gray-600 rounded-lg text-gray-100 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
                                />
                            </div>

                            <div className="flex items-center gap-3 p-3 bg-gray-900 rounded-lg border border-gray-700">
                                <input
                                    type="checkbox"
                                    name="is_active"
                                    id="is_active"
                                    checked={formData.is_active}
                                    onChange={handleChange}
                                    className="w-4 h-4 text-blue-600 bg-gray-800 border-gray-600 rounded focus:ring-blue-500 focus:ring-2"
                                />
                                <label htmlFor="is_active" className="text-sm font-medium text-gray-300 cursor-pointer">
                                    Aktifkan pengumuman ini
                                </label>
                            </div>

                            <div className="flex gap-3 pt-4">
                                <button
                                    type="button"
                                    onClick={() => setIsModalOpen(false)}
                                    className="flex-1 px-4 py-2.5 bg-gray-700 border border-gray-600 text-gray-300 rounded-lg hover:bg-gray-600 font-medium transition-all"
                                >
                                    Batal
                                </button>
                                <button
                                    type="submit"
                                    disabled={submitting}
                                    className="flex-1 px-4 py-2.5 bg-gradient-to-r from-blue-600 to-blue-500 text-white rounded-lg hover:from-blue-700 hover:to-blue-600 font-medium flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed transition-all shadow-lg hover:shadow-blue-500/50"
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
