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
    info: 'bg-blue-50 text-blue-700 border-blue-200',
    warning: 'bg-amber-50 text-amber-700 border-amber-200',
    critical: 'bg-red-50 text-red-700 border-red-200',
    success: 'bg-green-50 text-green-700 border-green-200'
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
        <div className="min-h-screen bg-slate-50 p-6">
            {/* Header */}
            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 mb-2">Manajemen Pengumuman</h1>
                <p className="text-slate-600">Broadcast informasi penting ke seluruh platform</p>
            </div>

            {/* Actions Bar */}
            <div className="bg-white rounded-lg shadow-sm border border-slate-200 p-4 mb-6">
                <div className="flex flex-col md:flex-row gap-4 items-center justify-between">
                    {/* Search */}
                    <div className="flex-1 max-w-md">
                        <div className="relative">
                            <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-slate-400" />
                            <input
                                type="text"
                                placeholder="Cari pengumuman..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="w-full pl-10 pr-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                            />
                        </div>
                    </div>

                    {/* Add Button */}
                    <button
                        onClick={handleAddClick}
                        className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
                    >
                        <Plus className="w-5 h-5" />
                        <span>Buat Pengumuman</span>
                    </button>
                </div>
            </div>

            {/* Announcements List */}
            <div className="space-y-4">
                {loading ? (
                    <div className="flex justify-center py-12">
                        <div className="w-8 h-8 border-4 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
                    </div>
                ) : announcements.length === 0 ? (
                    <div className="bg-white rounded-lg shadow-sm border border-slate-200 p-12 text-center">
                        <Bell className="w-16 h-16 text-slate-300 mx-auto mb-4" />
                        <p className="text-slate-500">Belum ada pengumuman. Buat pengumuman pertama Anda!</p>
                    </div>
                ) : (
                    announcements.map((announcement) => (
                        <div key={announcement.id} className="bg-white rounded-lg shadow-sm border border-slate-200 p-6 hover:shadow-md transition-shadow">
                            <div className="flex items-start justify-between gap-4">
                                <div className="flex-1">
                                    <div className="flex items-center gap-3 mb-2">
                                        <span className={`flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold border ${typeColors[announcement.type]}`}>
                                            <TypeIcon type={announcement.type} />
                                            {announcement.type.toUpperCase()}
                                        </span>
                                        <span className="px-3 py-1 bg-slate-100 text-slate-700 text-xs font-medium rounded-full">
                                            {announcement.target_role === 'all' ? 'Semua User' : announcement.target_role}
                                        </span>
                                        {!announcement.is_active && (
                                            <span className="px-3 py-1 bg-red-100 text-red-700 text-xs font-medium rounded-full">
                                                Nonaktif
                                            </span>
                                        )}
                                    </div>
                                    <h3 className="text-lg font-bold text-slate-900 mb-2">{announcement.title}</h3>
                                    <p className="text-slate-600 mb-3">{announcement.content}</p>
                                    <div className="flex items-center gap-4 text-xs text-slate-500">
                                        <span>Dibuat: {new Date(announcement.created_at).toLocaleDateString('id-ID')}</span>
                                        {announcement.expires_at && (
                                            <span>Kadaluarsa: {new Date(announcement.expires_at).toLocaleDateString('id-ID')}</span>
                                        )}
                                    </div>
                                </div>
                                <div className="flex items-center gap-2">
                                    <button
                                        onClick={() => handleEditClick(announcement)}
                                        className="p-2 text-slate-600 hover:bg-slate-100 rounded-lg transition-colors"
                                        title="Edit"
                                    >
                                        <Edit className="w-4 h-4" />
                                    </button>
                                    <button
                                        onClick={() => handleDeleteClick(announcement.id, announcement.title)}
                                        className="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors"
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
                <div className="mt-6 flex items-center justify-between bg-white rounded-lg shadow-sm border border-slate-200 px-6 py-4">
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

            {/* Create/Edit Modal */}
            {isModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
                    <div className="bg-white rounded-xl shadow-xl w-full max-w-2xl overflow-hidden animate-in fade-in zoom-in duration-200">
                        <div className="px-6 py-4 border-b border-slate-200 flex justify-between items-center bg-slate-50">
                            <h3 className="text-lg font-bold text-slate-900">
                                {isEditMode ? 'Edit Pengumuman' : 'Buat Pengumuman Baru'}
                            </h3>
                            <button onClick={() => setIsModalOpen(false)} className="text-slate-400 hover:text-slate-600">
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        <form onSubmit={handleFormSubmit} className="p-6 space-y-4">
                            <div>
                                <label className="block text-sm font-medium text-slate-700 mb-1">Judul</label>
                                <input
                                    type="text"
                                    name="title"
                                    required
                                    value={formData.title}
                                    onChange={handleChange}
                                    className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                    placeholder="Contoh: Maintenance Server Malam Ini"
                                />
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-slate-700 mb-1">Konten</label>
                                <textarea
                                    name="content"
                                    rows={4}
                                    required
                                    value={formData.content}
                                    onChange={handleChange}
                                    className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                    placeholder="Tulis detail pengumuman..."
                                ></textarea>
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-sm font-medium text-slate-700 mb-1">Tipe</label>
                                    <select
                                        name="type"
                                        value={formData.type}
                                        onChange={handleChange}
                                        className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                    >
                                        <option value="info">Info</option>
                                        <option value="warning">Warning</option>
                                        <option value="critical">Critical</option>
                                        <option value="success">Success</option>
                                    </select>
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-slate-700 mb-1">Target</label>
                                    <select
                                        name="target_role"
                                        value={formData.target_role}
                                        onChange={handleChange}
                                        className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                    >
                                        <option value="all">Semua User</option>
                                        <option value="admin">Admin Sekolah</option>
                                        <option value="teacher">Guru</option>
                                        <option value="student">Siswa</option>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-slate-700 mb-1">Tanggal Kadaluarsa (Opsional)</label>
                                <input
                                    type="date"
                                    name="expires_at"
                                    value={formData.expires_at}
                                    onChange={handleChange}
                                    className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                />
                            </div>

                            <div className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    name="is_active"
                                    id="is_active"
                                    checked={formData.is_active}
                                    onChange={handleChange}
                                    className="w-4 h-4 text-blue-600 border-slate-300 rounded focus:ring-blue-500"
                                />
                                <label htmlFor="is_active" className="text-sm font-medium text-slate-700">
                                    Aktifkan pengumuman ini
                                </label>
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
