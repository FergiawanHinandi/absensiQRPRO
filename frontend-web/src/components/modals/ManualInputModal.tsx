import React, { useState } from 'react';
import { X, Save, AlertCircle } from 'lucide-react';
import { apiClient as api } from '../../lib/api';
import type { Schedule } from '../../types/api.types';
import { getErrorMessage } from '../../utils/errorHandler';

interface Props {
    isOpen: boolean;
    onClose: () => void;
    onSuccess: () => void;
    schedule: Schedule;
    student: { id: number; name: string };
}

const ManualInputModal: React.FC<Props> = ({ isOpen, onClose, onSuccess, schedule, student }) => {
    const [status, setStatus] = useState<'sick' | 'permit' | 'alpha' | 'excused'>('sick');
    const [notes, setNotes] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        setLoading(true);
        setError(null);

        try {
            await api.post('/attendance/manual', {
                schedule_id: schedule.id,
                student_id: student.id,
                attendance_date: new Date().toISOString().split('T')[0], // YYYY-MM-DD
                status,
                notes,
            });
            onSuccess();
            onClose();
        } catch (err) {
            setError(getErrorMessage(err));
        } finally {
            setLoading(false);
        }
    };

    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
            <div className="bg-white rounded-xl shadow-xl w-full max-w-md animate-in fade-in zoom-in duration-200">
                <div className="flex justify-between items-center p-4 border-b border-gray-100">
                    <h3 className="font-bold text-lg text-gray-900">Input Manual</h3>
                    <button onClick={onClose} className="text-gray-400 hover:text-gray-600">
                        <X className="w-5 h-5" />
                    </button>
                </div>

                <form onSubmit={handleSubmit} className="p-4 space-y-4">
                    <div className="bg-blue-50 p-3 rounded-lg">
                        <p className="text-sm text-blue-900">Siswa: <span className="font-bold">{student.name}</span></p>
                        <p className="text-xs text-blue-700 mt-1">Kelas: {schedule.class.name}</p>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Status Kehadiran</label>
                        <div className="grid grid-cols-2 gap-2">
                            {(['sick', 'permit', 'alpha', 'excused'] as const).map((s) => (
                                <button
                                    key={s}
                                    type="button"
                                    onClick={() => setStatus(s)}
                                    className={`py-2 px-3 rounded-lg text-sm font-medium border transition-colors ${status === s
                                        ? 'bg-blue-600 text-white border-blue-600'
                                        : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50'
                                        }`}
                                >
                                    {s === 'sick' && 'Sakit 🤒'}
                                    {s === 'permit' && 'Izin 📩'}
                                    {s === 'alpha' && 'Alpa ❌'}
                                    {s === 'excused' && 'Dispensasi 🏫'}
                                </button>
                            ))}
                        </div>
                        <p className="text-xs text-gray-500 mt-2">
                            *Hadir/Terlambat hanya bisa via scan QR.
                        </p>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Catatan (Wajib)</label>
                        <textarea
                            required
                            rows={3}
                            value={notes}
                            onChange={(e) => setNotes(e.target.value)}
                            placeholder="Contoh: Sakit demam, surat menyusul..."
                            className="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                        />
                    </div>

                    {error && (
                        <div className="flex items-start gap-2 bg-red-50 text-red-700 p-3 rounded-lg text-sm">
                            <AlertCircle className="w-4 h-4 mt-0.5 shrink-0" />
                            <p>{error}</p>
                        </div>
                    )}

                    <div className="flex gap-3 pt-2">
                        <button
                            type="button"
                            onClick={onClose}
                            className="flex-1 py-2 px-4 border border-gray-300 rounded-lg text-gray-700 font-medium hover:bg-gray-50"
                        >
                            Batal
                        </button>
                        <button
                            type="submit"
                            disabled={loading}
                            className="flex-1 py-2 px-4 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 disabled:bg-blue-400 flex items-center justify-center gap-2"
                        >
                            {loading ? 'Menyimpan...' : (
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
    );
};

export default ManualInputModal;
