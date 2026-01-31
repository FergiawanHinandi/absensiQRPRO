import React, { useEffect, useState } from 'react';
import { apiClient } from '../../../lib/api';
import { CheckCircle, FileText, Calendar, Plus, X } from 'lucide-react';
import Loading from '../../../components/common/Loading';
import showToast from '../../../utils/toast';

interface PermissionRequest {
    id: number;
    student_name: string;
    student_nis: string;
    class_name: string;
    type: 'sick' | 'permit';
    reason: string;
    description: string;
    attachment_path: string | null;
    start_date: string;
    end_date: string;
    status: 'pending' | 'approved' | 'rejected';
    created_at: string;
}

interface StudentOption {
    id: number;
    name: string;
    nis: string;
}

export const HomeroomPermissions: React.FC = () => {
    const [permissions, setPermissions] = useState<PermissionRequest[]>([]);
    const [loading, setLoading] = useState(true);
    const [selectedImage, setSelectedImage] = useState<string | null>(null);

    // Modal States
    const [showModal, setShowModal] = useState(false);
    const [students, setStudents] = useState<StudentOption[]>([]);
    const [modalLoading, setModalLoading] = useState(false);
    const [formData, setFormData] = useState({
        student_id: '',
        type: 'sick',
        reason: '',
        start_date: new Date().toISOString().split('T')[0],
        end_date: new Date().toISOString().split('T')[0],
    });

    useEffect(() => {
        fetchPermissions();
    }, []);

    // Load students when modal opens
    useEffect(() => {
        if (showModal && students.length === 0) {
            fetchStudents();
        }
    }, [showModal, students.length]);

    const fetchPermissions = async () => {
        try {
            setLoading(true);
            const response = await apiClient.get('/teacher/permissions', {
                params: { status: 'pending' }
            });
            if (response.data.success) {
                setPermissions(response.data.data.data);
            }
        } catch (error) {
            console.error('Failed to fetch permissions', error);
        } finally {
            setLoading(false);
        }
    };

    const fetchStudents = async () => {
        try {
            const response = await apiClient.get('/teacher/my-students');
            if (response.data.success) {
                setStudents(response.data.data);
            }
        } catch (error) {
            console.error('Failed to fetch students', error);
        }
    };

    const handleAction = async (id: number, status: 'approved' | 'rejected') => {
        if (!confirm(`Apakah Anda yakin ingin me-${status === 'approved' ? 'nyetujui' : 'nolak'} izin ini?`)) return;

        try {
            await apiClient.patch(`/teacher/permissions/${id}/status`, { status });
            fetchPermissions();
        } catch (error) {
            console.error(error);
            showToast.error('Gagal memproses permintaan.');
        }
    };

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!formData.student_id) {
            showToast.warning('Pilih siswa terlebih dahulu');
            return;
        }

        try {
            setModalLoading(true);
            const payload = {
                ...formData,
                attachment: null // Upload file logic omitted for simplicity in this MVP step
            };

            await apiClient.post('/teacher/permissions', payload);
            showToast.success('Izin berhasil ditambahkan & disetujui otomatis.');
            setShowModal(false);
            setFormData({ ...formData, student_id: '', reason: '' });
            fetchPermissions(); // Refresh list to see if it appears (though list filters pending usually)
        } catch (error: unknown) {
            console.error(error);
            // Type-safe error handling
            const msg = (error as any)?.response?.data?.message || 'Gagal menyimpan izin.';
            showToast.error(msg);
        } finally {
            setModalLoading(false);
        }
    };

    if (loading) return <Loading text="Memuat request izin siswa..." />;

    return (
        <div className="max-w-5xl mx-auto p-6 min-h-screen bg-slate-50">
            <div className="flex justify-between items-center mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900">Validasi Izin & Sakit</h1>
                    <p className="text-slate-600">Persetujuan surat keterangan siswa</p>
                </div>
                <button
                    onClick={() => setShowModal(true)}
                    className="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium transition-colors"
                >
                    <Plus className="w-4 h-4" />
                    Input Izin Manual
                </button>
            </div>

            {permissions.length === 0 ? (
                <div className="text-center py-20 bg-white rounded-xl border border-dashed border-slate-300">
                    <CheckCircle className="w-16 h-16 text-green-200 mx-auto mb-4" />
                    <h3 className="text-lg font-medium text-slate-700">Semua Bersih!</h3>
                    <p className="text-slate-500">Tidak ada permintaan izin yang menunggu persetujuan.</p>
                </div>
            ) : (
                <div className="grid gap-6">
                    {permissions.map((p) => (
                        <div key={p.id} className="bg-white rounded-xl shadow-sm border border-slate-200 p-6 flex flex-col md:flex-row gap-6 animate-in fade-in slide-in-from-bottom-4 duration-300">
                            {/* Left: Info */}
                            <div className="flex-1">
                                <div className="flex items-center gap-3 mb-2">
                                    <span className={`px-2 py-1 text-xs font-bold uppercase rounded ${p.type === 'sick' ? 'bg-red-100 text-red-700' : 'bg-blue-100 text-blue-700'
                                        }`}>
                                        {p.type === 'sick' ? 'Sakit' : 'Izin'}
                                    </span>
                                    <span className="text-sm text-slate-500">{new Date(p.created_at).toLocaleDateString()}</span>
                                </div>

                                <h3 className="text-lg font-bold text-slate-900">{p.student_name}</h3>
                                <p className="text-sm text-slate-600 mb-4">{p.student_nis} • {p.class_name}</p>

                                <div className="bg-slate-50 rounded-lg p-4 mb-4">
                                    <h4 className="font-semibold text-sm mb-1">{p.reason}</h4>
                                    <p className="text-sm text-slate-700 whitespace-pre-wrap">{p.description || '-'}</p>
                                </div>

                                <div className="flex items-center gap-2 text-sm font-medium text-slate-700">
                                    <Calendar className="w-4 h-4 text-slate-400" />
                                    <span>
                                        {new Date(p.start_date).toLocaleDateString()} - {new Date(p.end_date).toLocaleDateString()}
                                    </span>
                                </div>
                            </div>

                            {/* Right: Actions */}
                            <div className="w-full md:w-64 flex flex-col gap-4">
                                {p.attachment_path ? (
                                    <div
                                        className="h-32 bg-slate-100 rounded-lg overflow-hidden relative group cursor-pointer border border-slate-200"
                                        onClick={() => setSelectedImage(p.attachment_path || '')}
                                    >
                                        <div className="absolute inset-0 flex items-center justify-center text-slate-400">
                                            <FileText className="w-8 h-8" />
                                            <span className="text-xs ml-2">Lihat Lampiran</span>
                                        </div>
                                    </div>
                                ) : (
                                    <div className="h-32 bg-slate-50 rounded-lg border border-dashed border-slate-300 flex items-center justify-center text-slate-400 text-xs">
                                        Tidak ada lampiran
                                    </div>
                                )}

                                <div className="grid grid-cols-2 gap-2 mt-auto">
                                    <button
                                        onClick={() => handleAction(p.id, 'rejected')}
                                        className="py-2 px-4 border border-red-200 text-red-700 rounded-lg hover:bg-red-50 font-medium text-sm transition-colors"
                                    >
                                        Tolak
                                    </button>
                                    <button
                                        onClick={() => handleAction(p.id, 'approved')}
                                        className="py-2 px-4 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium text-sm transition-colors shadow-sm"
                                    >
                                        Setujui
                                    </button>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {/* Input Permission Modal */}
            {showModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
                    <div className="bg-white rounded-xl shadow-xl w-full max-w-lg p-6 relative">
                        <button
                            onClick={() => setShowModal(false)}
                            className="absolute right-4 top-4 text-slate-400 hover:text-slate-600"
                        >
                            <X className="w-5 h-5" />
                        </button>

                        <h2 className="text-xl font-bold mb-4">Input Izin Manual</h2>
                        <form onSubmit={handleSubmit} className="space-y-4">
                            <div>
                                <label className="block text-sm font-medium text-slate-700 mb-1">Pilih Siswa</label>
                                <select
                                    className="w-full border-slate-300 rounded-lg shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                    value={formData.student_id}
                                    onChange={(e) => setFormData({ ...formData, student_id: e.target.value })}
                                    required
                                >
                                    <option value="">-- Pilih Siswa --</option>
                                    {students.map(s => (
                                        <option key={s.id} value={s.id}>{s.name} ({s.nis})</option>
                                    ))}
                                </select>
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-sm font-medium text-slate-700 mb-1">Tipe</label>
                                    <select
                                        className="w-full border-slate-300 rounded-lg shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                        value={formData.type}
                                        onChange={(e) => setFormData({ ...formData, type: e.target.value as 'sick' | 'permit' })}
                                    >
                                        <option value="sick">Sakit</option>
                                        <option value="permit">Izin</option>
                                    </select>
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-slate-700 mb-1">Alasan</label>
                                    <input
                                        type="text"
                                        className="w-full border-slate-300 rounded-lg shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                        placeholder="Contoh: Demam, Urusan Keluarga"
                                        value={formData.reason}
                                        onChange={(e) => setFormData({ ...formData, reason: e.target.value })}
                                        required
                                    />
                                </div>
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-sm font-medium text-slate-700 mb-1">Mulai</label>
                                    <input
                                        type="date"
                                        className="w-full border-slate-300 rounded-lg shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                        value={formData.start_date}
                                        onChange={(e) => setFormData({ ...formData, start_date: e.target.value })}
                                        required
                                    />
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-slate-700 mb-1">Sampai</label>
                                    <input
                                        type="date"
                                        className="w-full border-slate-300 rounded-lg shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                        value={formData.end_date}
                                        onChange={(e) => setFormData({ ...formData, end_date: e.target.value })}
                                        required
                                    />
                                </div>
                            </div>

                            <div className="pt-4 flex justify-end gap-2">
                                <button
                                    type="button"
                                    onClick={() => setShowModal(false)}
                                    className="px-4 py-2 text-slate-700 hover:bg-slate-100 rounded-lg font-medium"
                                >
                                    Batal
                                </button>
                                <button
                                    type="submit"
                                    disabled={modalLoading}
                                    className="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium shadow-sm disabled:opacity-50"
                                >
                                    {modalLoading ? 'Menyimpan...' : 'Simpan Izin'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Placeholder Image Viewer */}
            {selectedImage && (
                <div className="fixed inset-0 z-[60] bg-black/80 flex items-center justify-center p-4" onClick={() => setSelectedImage(null)}>
                    <div className="bg-white p-2 rounded-lg max-w-2xl w-full">
                        <div className="h-96 bg-gray-200 flex items-center justify-center">
                            <span className="text-gray-500">Gambar: {selectedImage}</span>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};
