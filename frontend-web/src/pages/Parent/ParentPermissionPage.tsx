import React, { useEffect, useState } from 'react';
import {
    FileText,
    Plus,
    X,
    Clock,
    CheckCircle,
    XCircle,
    Loader2,
    Calendar,
    Send
} from 'lucide-react';
import { apiClient } from '../../lib/api';
import showToast from '../../utils/toast';
import { Skeleton, SkeletonCard } from '../../components/ui/LoadingStates';
import { EmptyState } from '../../components/ui/EmptyStates';

interface Permission {
    id: number;
    student_name: string;
    student_nis: string;
    type: 'sick' | 'permission' | 'dispensation';
    reason: string;
    start_date: string;
    end_date: string;
    status: 'pending' | 'approved' | 'rejected';
    attachment_url?: string;
    reviewed_by?: string;
    reviewed_at?: string;
    created_at: string;
}

interface Child {
    id: number;
    name: string;
    nis: string;
    class_name: string;
}

export const ParentPermissionPage: React.FC = () => {
    const [permissions, setPermissions] = useState<Permission[]>([]);
    const [children, setChildren] = useState<Child[]>([]);
    const [loading, setLoading] = useState(true);
    const [showForm, setShowForm] = useState(false);
    const [submitting, setSubmitting] = useState(false);

    // Form state
    const [formData, setFormData] = useState({
        student_id: '',
        type: 'sick' as 'sick' | 'permission' | 'dispensation',
        reason: '',
        start_date: new Date().toISOString().split('T')[0],
        end_date: new Date().toISOString().split('T')[0],
    });

    useEffect(() => {
        fetchData();
    }, []);

    const fetchData = async () => {
        try {
            setLoading(true);
            const [permRes, childRes] = await Promise.allSettled([
                apiClient.get('/parent/permissions'),
                apiClient.get('/parent/my-children'),
            ]);

            if (permRes.status === 'fulfilled') {
                const data = permRes.value.data?.data || permRes.value.data || [];
                setPermissions(Array.isArray(data) ? data : data.data || []);
            }

            if (childRes.status === 'fulfilled') {
                const data = childRes.value.data?.data || childRes.value.data || [];
                const childList = Array.isArray(data) ? data : data.children || data.data || [];
                setChildren(childList);
                if (childList.length > 0 && !formData.student_id) {
                    setFormData(prev => ({ ...prev, student_id: String(childList[0].id) }));
                }
            }
        } catch (error) {
            console.error('Failed to fetch data:', error);
        } finally {
            setLoading(false);
        }
    };

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!formData.student_id || !formData.reason.trim()) {
            showToast.error('Mohon lengkapi semua field');
            return;
        }

        try {
            setSubmitting(true);
            await apiClient.post('/parent/permissions', {
                student_id: parseInt(formData.student_id),
                type: formData.type,
                reason: formData.reason,
                start_date: formData.start_date,
                end_date: formData.end_date,
            });
            showToast.success('Permohonan izin berhasil diajukan');
            setShowForm(false);
            setFormData({
                student_id: children[0]?.id ? String(children[0].id) : '',
                type: 'sick',
                reason: '',
                start_date: new Date().toISOString().split('T')[0],
                end_date: new Date().toISOString().split('T')[0],
            });
            fetchData();
        } catch (error: any) {
            console.error('Submit failed:', error);
            showToast.error(error.response?.data?.message || 'Gagal mengajukan permohonan izin');
        } finally {
            setSubmitting(false);
        }
    };

    const getStatusBadge = (status: string) => {
        switch (status) {
            case 'approved':
                return (
                    <span className="flex items-center gap-1 text-xs font-medium text-green-700 bg-green-50 px-2.5 py-1 rounded-full">
                        <CheckCircle className="w-3 h-3" /> Disetujui
                    </span>
                );
            case 'rejected':
                return (
                    <span className="flex items-center gap-1 text-xs font-medium text-red-700 bg-red-50 px-2.5 py-1 rounded-full">
                        <XCircle className="w-3 h-3" /> Ditolak
                    </span>
                );
            default:
                return (
                    <span className="flex items-center gap-1 text-xs font-medium text-amber-700 bg-amber-50 px-2.5 py-1 rounded-full">
                        <Clock className="w-3 h-3" /> Menunggu
                    </span>
                );
        }
    };

    const getTypeBadge = (type: string) => {
        switch (type) {
            case 'sick':
                return <span className="text-xs font-medium text-blue-700 bg-blue-50 px-2.5 py-1 rounded-full">Sakit</span>;
            case 'permission':
                return <span className="text-xs font-medium text-purple-700 bg-purple-50 px-2.5 py-1 rounded-full">Izin</span>;
            case 'dispensation':
                return <span className="text-xs font-medium text-orange-700 bg-orange-50 px-2.5 py-1 rounded-full">Dispensasi</span>;
            default:
                return <span className="text-xs font-medium text-slate-700 bg-slate-50 px-2.5 py-1 rounded-full">{type}</span>;
        }
    };

    if (loading) {
        return (
            <div className="min-h-screen bg-slate-50 p-6 space-y-6">
                <Skeleton variant="rounded" width={300} height={36} />
                <div className="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden p-6">
                    <SkeletonCard className="h-28" />
                    <SkeletonCard className="h-28" />
                    <SkeletonCard className="h-28" />
                </div>
            </div>
        );
    }

    return (
        <div className="min-h-screen bg-slate-50 p-6">
            {/* Header */}
            <div className="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 mb-1">Izin / Sakit</h1>
                    <p className="text-slate-600">Ajukan permohonan izin tidak masuk untuk anak Anda</p>
                </div>
                <button
                    onClick={() => setShowForm(!showForm)}
                    className="flex items-center gap-2 px-4 py-2.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium"
                >
                    {showForm ? <X className="w-4 h-4" /> : <Plus className="w-4 h-4" />}
                    {showForm ? 'Batal' : 'Ajukan Izin Baru'}
                </button>
            </div>

            {/* Form */}
            {showForm && (
                <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6 mb-6">
                    <h2 className="text-lg font-bold text-slate-900 mb-4">Form Pengajuan Izin</h2>
                    <form onSubmit={handleSubmit} className="space-y-4">
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label className="block text-sm font-medium text-slate-700 mb-1">Anak</label>
                                <select
                                    value={formData.student_id}
                                    onChange={(e) => setFormData(prev => ({ ...prev, student_id: e.target.value }))}
                                    className="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                >
                                    {children.map(child => (
                                        <option key={child.id} value={child.id}>
                                            {child.name} ({child.nis}) - {child.class_name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-slate-700 mb-1">Jenis</label>
                                <select
                                    value={formData.type}
                                    onChange={(e) => setFormData(prev => ({ ...prev, type: e.target.value as any }))}
                                    className="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                >
                                    <option value="sick">Sakit</option>
                                    <option value="permission">Izin</option>
                                    <option value="dispensation">Dispensasi</option>
                                </select>
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-slate-700 mb-1">Tanggal Mulai</label>
                                <input
                                    type="date"
                                    value={formData.start_date}
                                    onChange={(e) => setFormData(prev => ({ ...prev, start_date: e.target.value }))}
                                    className="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                />
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-slate-700 mb-1">Tanggal Selesai</label>
                                <input
                                    type="date"
                                    value={formData.end_date}
                                    onChange={(e) => setFormData(prev => ({ ...prev, end_date: e.target.value }))}
                                    className="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                />
                            </div>
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-slate-700 mb-1">Alasan</label>
                            <textarea
                                value={formData.reason}
                                onChange={(e) => setFormData(prev => ({ ...prev, reason: e.target.value }))}
                                rows={3}
                                placeholder="Jelaskan alasan izin/sakit..."
                                className="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                required
                            />
                        </div>
                        <div className="flex justify-end">
                            <button
                                type="submit"
                                disabled={submitting}
                                className="flex items-center gap-2 px-6 py-2.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 font-medium"
                            >
                                {submitting ? (
                                    <Loader2 className="w-4 h-4 animate-spin" />
                                ) : (
                                    <Send className="w-4 h-4" />
                                )}
                                {submitting ? 'Mengirim...' : 'Ajukan Permohonan'}
                            </button>
                        </div>
                    </form>
                </div>
            )}

            {/* Permission List */}
            <div className="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <div className="px-6 py-4 border-b border-slate-200 bg-slate-50">
                    <h2 className="font-semibold text-slate-900">Riwayat Permohonan</h2>
                </div>

                {permissions.length === 0 ? (
                    <EmptyState
                        preset="no-data"
                        title="Belum Ada Permohonan Izin"
                        description="Klik \"Ajukan Izin Baru\" untuk membuat permohonan izin/sakit anak Anda."
                        size="md"
                    />
                ) : (
                    <div className="divide-y divide-slate-100">
                        {permissions.map((perm) => (
                            <div key={perm.id} className="px-6 py-4 hover:bg-slate-50">
                                <div className="flex items-start justify-between gap-4">
                                    <div className="flex-1">
                                        <div className="flex items-center gap-2 mb-1">
                                            <p className="font-medium text-slate-900">{perm.student_name}</p>
                                            <span className="text-xs text-slate-500">({perm.student_nis})</span>
                                        </div>
                                        <p className="text-sm text-slate-600 mb-2">{perm.reason}</p>
                                        <div className="flex items-center gap-3 text-xs text-slate-500">
                                            <span className="flex items-center gap-1">
                                                <Calendar className="w-3 h-3" />
                                                {new Date(perm.start_date).toLocaleDateString('id-ID')}
                                                {perm.start_date !== perm.end_date && (
                                                    <> - {new Date(perm.end_date).toLocaleDateString('id-ID')}</>
                                                )}
                                            </span>
                                            <span className="flex items-center gap-1">
                                                <Clock className="w-3 h-3" />
                                                {new Date(perm.created_at).toLocaleDateString('id-ID')}
                                            </span>
                                        </div>
                                    </div>
                                    <div className="flex flex-col items-end gap-2">
                                        {getStatusBadge(perm.status)}
                                        {getTypeBadge(perm.type)}
                                    </div>
                                </div>
                                {perm.reviewed_by && (
                                    <p className="text-xs text-slate-400 mt-2">
                                        Ditinjau oleh {perm.reviewed_by}
                                        {perm.reviewed_at && ` pada ${new Date(perm.reviewed_at).toLocaleDateString('id-ID')}`}
                                    </p>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
};
