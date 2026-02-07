import React, { useState } from 'react';
import { Image, CheckCircle, XCircle, AlertTriangle } from 'lucide-react';
import Loading from '../../components/common/Loading';
import ErrorMessage from '../../components/common/ErrorMessage';
import { usePendingPhotos, useApprovePhoto, useRejectPhoto } from '../../modules/admin/hooks/useAdminService';

const AdminPhotoReview: React.FC = () => {
    const { data: photos, isLoading, error, refetch } = usePendingPhotos();
    const approveMutation = useApprovePhoto();
    const rejectMutation = useRejectPhoto();
    const [rejectReason, setRejectReason] = useState<string>('');
    const [rejectingId, setRejectingId] = useState<number | null>(null);

    const handleApprove = async (studentId: number) => {
        if (!window.confirm('Setujui foto siswa ini?')) return;

        try {
            await approveMutation.mutateAsync(studentId);
            refetch();
        } catch (error) {
            console.error('Approve failed:', error);
        }
    };

    const handleReject = async (studentId: number) => {
        try {
            await rejectMutation.mutateAsync({
                studentId,
                reason: rejectReason || 'Foto tidak memenuhi kriteria'
            });
            setRejectingId(null);
            setRejectReason('');
            refetch();
        } catch (error) {
            console.error('Reject failed:', error);
        }
    };

    if (isLoading) {
        return <Loading text="Memuat foto pending..." />;
    }

    if (error) {
        return (
            <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
                <ErrorMessage message="Gagal memuat foto pending" onRetry={() => refetch()} />
            </div>
        );
    }

    const pendingPhotos = photos?.students || [];

    return (
        <div className="space-y-6">
            {/* Header */}
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
                        <Image className="w-6 h-6 text-blue-600" />
                        Review Foto Siswa
                    </h1>
                    <p className="text-sm text-gray-500 mt-1">
                        Tinjau dan setujui foto profil siswa yang menunggu verifikasi
                    </p>
                </div>
                <div className="text-sm">
                    <span className="font-semibold text-gray-900">{pendingPhotos.length}</span>
                    <span className="text-gray-500"> foto menunggu review</span>
                </div>
            </div>

            {/* Guidelines */}
            <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <h3 className="text-sm font-semibold text-blue-900 mb-2 flex items-center gap-2">
                    <AlertTriangle className="w-4 h-4" />
                    Kriteria Foto yang Baik
                </h3>
                <ul className="text-xs text-blue-800 space-y-1">
                    <li>✓ Wajah terlihat jelas dan menghadap kamera</li>
                    <li>✓ Pencahayaan cukup (tidak terlalu gelap/terang)</li>
                    <li>✓ Latar belakang netral dan tidak mengganggu</li>
                    <li>✓ Tidak menggunakan filter atau efek berlebihan</li>
                    <li>✓ Ekspresi wajah natural dan sopan</li>
                </ul>
            </div>

            {/* Photo Grid */}
            {pendingPhotos.length === 0 ? (
                <div className="bg-white rounded-xl border border-slate-200 shadow-sm p-12">
                    <div className="text-center">
                        <CheckCircle className="w-16 h-16 text-green-500 mx-auto mb-4" />
                        <h3 className="text-lg font-semibold text-gray-900 mb-2">
                            Semua Foto Sudah Direview
                        </h3>
                        <p className="text-sm text-gray-500">
                            Tidak ada foto yang menunggu persetujuan saat ini
                        </p>
                    </div>
                </div>
            ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    {pendingPhotos.map((student: any) => (
                        <div
                            key={student.id}
                            className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden hover:shadow-md transition-shadow"
                        >
                            {/* Photo */}
                            <div className="aspect-square bg-slate-100 relative">
                                {student.photo_url ? (
                                    <img
                                        src={student.photo_url}
                                        alt={student.name}
                                        className="w-full h-full object-cover"
                                    />
                                ) : (
                                    <div className="w-full h-full flex items-center justify-center">
                                        <Image className="w-16 h-16 text-slate-300" />
                                    </div>
                                )}

                                {/* Upload Date Badge */}
                                {student.photo_uploaded_at && (
                                    <div className="absolute top-2 right-2 bg-black/60 text-white text-xs px-2 py-1 rounded">
                                        {new Date(student.photo_uploaded_at).toLocaleDateString('id-ID')}
                                    </div>
                                )}
                            </div>

                            {/* Student Info */}
                            <div className="p-4">
                                <h3 className="font-semibold text-gray-900 mb-1">{student.name}</h3>
                                <p className="text-sm text-gray-500 mb-1">{student.username}</p>
                                <p className="text-sm text-gray-500">{student.class_name || 'Belum ada kelas'}</p>

                                {/* Duplicate Warning */}
                                {student.is_duplicate && (
                                    <div className="mt-2 bg-amber-50 border border-amber-200 rounded px-2 py-1">
                                        <p className="text-xs text-amber-800 font-medium">
                                            ⚠️ Kemungkinan duplikat terdeteksi
                                        </p>
                                    </div>
                                )}
                            </div>

                            {/* Actions */}
                            {rejectingId === student.id ? (
                                <div className="p-4 border-t border-slate-200 space-y-3">
                                    <textarea
                                        value={rejectReason}
                                        onChange={(e) => setRejectReason(e.target.value)}
                                        placeholder="Alasan penolakan (opsional)"
                                        className="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg resize-none"
                                        rows={2}
                                    />
                                    <div className="flex gap-2">
                                        <button
                                            onClick={() => {
                                                setRejectingId(null);
                                                setRejectReason('');
                                            }}
                                            className="flex-1 px-3 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200"
                                        >
                                            Batal
                                        </button>
                                        <button
                                            onClick={() => handleReject(student.id)}
                                            disabled={rejectMutation.isPending}
                                            className="flex-1 px-3 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 disabled:opacity-50"
                                        >
                                            {rejectMutation.isPending ? 'Memproses...' : 'Konfirmasi Tolak'}
                                        </button>
                                    </div>
                                </div>
                            ) : (
                                <div className="p-4 border-t border-slate-200 flex gap-2">
                                    <button
                                        onClick={() => setRejectingId(student.id)}
                                        disabled={approveMutation.isPending || rejectMutation.isPending}
                                        className="flex-1 px-4 py-2 text-sm font-medium text-red-700 bg-red-50 border border-red-200 rounded-lg hover:bg-red-100 disabled:opacity-50 flex items-center justify-center gap-2"
                                    >
                                        <XCircle className="w-4 h-4" />
                                        Tolak
                                    </button>
                                    <button
                                        onClick={() => handleApprove(student.id)}
                                        disabled={approveMutation.isPending || rejectMutation.isPending}
                                        className="flex-1 px-4 py-2 text-sm font-medium text-white bg-green-600 rounded-lg hover:bg-green-700 disabled:opacity-50 flex items-center justify-center gap-2"
                                    >
                                        <CheckCircle className="w-4 h-4" />
                                        {approveMutation.isPending ? 'Memproses...' : 'Setujui'}
                                    </button>
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
};

export default AdminPhotoReview;
