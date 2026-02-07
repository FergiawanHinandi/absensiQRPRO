import React, { useState } from 'react';
import { Clock, Save, Settings } from 'lucide-react';
import Loading from '../../components/common/Loading';
import ErrorMessage from '../../components/common/ErrorMessage';
import { useAttendanceSettings, useUpdateAttendanceSettings } from '../../modules/admin/hooks/useAdminService';


const AdminAttendanceSettings: React.FC = () => {
    const { data, isLoading, error, refetch } = useAttendanceSettings();
    const updateMutation = useUpdateAttendanceSettings();

    const [formData, setFormData] = useState({
        check_in_start: '',
        check_in_end: '',
        late_threshold_minutes: '',
        qr_validity_minutes: '',
    });

    React.useEffect(() => {
        if (data) {
            setFormData({
                check_in_start: data.check_in_start || '',
                check_in_end: data.check_in_end || '',
                late_threshold_minutes: data.late_threshold_minutes?.toString() || '',
                qr_validity_minutes: data.qr_validity_minutes?.toString() || '',
            });
        }
    }, [data]);

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();

        try {
            await updateMutation.mutateAsync({
                check_in_start: formData.check_in_start,
                check_in_end: formData.check_in_end,
                late_threshold_minutes: parseInt(formData.late_threshold_minutes),
                qr_validity_minutes: parseInt(formData.qr_validity_minutes),
            });
            refetch();
        } catch (error) {
            console.error('Failed to update settings:', error);
        }
    };

    if (isLoading) {
        return <Loading text="Memuat pengaturan absensi..." />;
    }

    if (error) {
        return (
            <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
                <ErrorMessage message="Gagal memuat pengaturan absensi" onRetry={() => refetch()} />
            </div>
        );
    }

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
                        <Settings className="w-6 h-6 text-blue-600" />
                        Pengaturan Absensi
                    </h1>
                    <p className="text-sm text-gray-500 mt-1">Konfigurasi waktu dan toleransi absensi sekolah</p>
                </div>
            </div>

            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h2 className="text-lg font-semibold text-gray-900 flex items-center gap-2 mb-6">
                    <Clock className="w-5 h-5 text-blue-600" />
                    Pengaturan Waktu Absensi
                </h2>

                <form onSubmit={handleSubmit} className="space-y-6">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                        {/* Check-in Start Time */}
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-2">
                                Waktu Mulai Check-in
                            </label>
                            <input
                                type="time"
                                value={formData.check_in_start}
                                onChange={(e) => setFormData({ ...formData, check_in_start: e.target.value })}
                                className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                required
                            />
                            <p className="mt-1 text-xs text-gray-500">Waktu paling awal siswa dapat melakukan absensi</p>
                        </div>

                        {/* Check-in End Time */}
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-2">
                                Waktu Akhir Check-in
                            </label>
                            <input
                                type="time"
                                value={formData.check_in_end}
                                onChange={(e) => setFormData({ ...formData, check_in_end: e.target.value })}
                                className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                required
                            />
                            <p className="mt-1 text-xs text-gray-500">Batas waktu absensi tepat waktu</p>
                        </div>

                        {/* Late Threshold */}
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-2">
                                Toleransi Keterlambatan (Menit)
                            </label>
                            <input
                                type="number"
                                min="0"
                                max="60"
                                value={formData.late_threshold_minutes}
                                onChange={(e) => setFormData({ ...formData, late_threshold_minutes: e.target.value })}
                                className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                required
                            />
                            <p className="mt-1 text-xs text-gray-500">Menit setelah waktu akhir yang masih dihitung terlambat (bukan alpha)</p>
                        </div>

                        {/* QR Validity */}
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-2">
                                Validitas QR Code (Menit)
                            </label>
                            <input
                                type="number"
                                min="1"
                                max="30"
                                value={formData.qr_validity_minutes}
                                onChange={(e) => setFormData({ ...formData, qr_validity_minutes: e.target.value })}
                                className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                required
                            />
                            <p className="mt-1 text-xs text-gray-500">Durasi QR code valid setelah dibuat oleh guru</p>
                        </div>
                    </div>

                    {/* Current Settings Preview */}
                    <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
                        <h3 className="text-sm font-semibold text-blue-900 mb-2">Pengaturan Saat Ini</h3>
                        <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                            <div>
                                <span className="text-blue-600 font-medium">Check-in Mulai:</span>
                                <p className="text-blue-900 font-semibold">{data?.check_in_start || '-'}</p>
                            </div>
                            <div>
                                <span className="text-blue-600 font-medium">Check-in Akhir:</span>
                                <p className="text-blue-900 font-semibold">{data?.check_in_end || '-'}</p>
                            </div>
                            <div>
                                <span className="text-blue-600 font-medium">Toleransi:</span>
                                <p className="text-blue-900 font-semibold">{data?.late_threshold_minutes || 0} menit</p>
                            </div>
                            <div>
                                <span className="text-blue-600 font-medium">Validitas QR:</span>
                                <p className="text-blue-900 font-semibold">{data?.qr_validity_minutes || 0} menit</p>
                            </div>
                        </div>
                    </div>

                    {/* Submit Button */}
                    <div className="flex justify-end gap-3">
                        <button
                            type="button"
                            onClick={() => refetch()}
                            className="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 font-medium"
                        >
                            Batal
                        </button>
                        <button
                            type="submit"
                            disabled={updateMutation.isPending}
                            className="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <Save className="w-4 h-4" />
                            {updateMutation.isPending ? 'Menyimpan...' : 'Simpan Pengaturan'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
};

export default AdminAttendanceSettings;
