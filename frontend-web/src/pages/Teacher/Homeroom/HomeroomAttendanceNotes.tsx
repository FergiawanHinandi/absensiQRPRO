import React, { useState, useEffect } from 'react';
import { FileText, Save, Users } from 'lucide-react';
import { apiClient } from '../../../lib/api';
import showToast from '../../../utils/toast';
import { SkeletonCard } from '../../../components/ui/LoadingStates';
import { EmptyState } from '../../../components/ui/EmptyStates';

interface StudentNote {
    student_id: number;
    student_name: string;
    note: string;
}

const HomeroomAttendanceNotes: React.FC = () => {
    const [students, setStudents] = useState<StudentNote[]>([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [selectedDate, setSelectedDate] = useState(new Date().toISOString().split('T')[0]);

    useEffect(() => {
        fetchStudents();
    }, [selectedDate]);

    const fetchStudents = async () => {
        try {
            setLoading(true);
            const response = await apiClient.get('/teacher/class-attendance/notes', {
                params: { date: selectedDate }
            });
            setStudents(response.data.data || []);
        } catch (error) {
            if (import.meta.env.DEV) {
                console.error('Failed to fetch students:', error);
            }
            showToast.error('Gagal memuat data siswa');
        } finally {
            setLoading(false);
        }
    };

    const handleNoteChange = (studentId: number, note: string) => {
        setStudents(prev => prev.map(s =>
            s.student_id === studentId ? { ...s, note } : s
        ));
    };

    const handleSave = async () => {
        try {
            setSaving(true);
            await apiClient.put('/teacher/class-attendance/notes', {
                date: selectedDate,
                notes: students.map(s => ({
                    student_id: s.student_id,
                    note: s.note,
                })),
            });
            showToast.success('Catatan berhasil disimpan');
        } catch (error) {
            showToast.error('Gagal menyimpan catatan');
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between">
                <div className="flex items-center gap-3">
                    <div className="w-10 h-10 rounded-full bg-orange-100 flex items-center justify-center">
                        <FileText className="w-5 h-5 text-orange-600" />
                    </div>
                    <div>
                        <h1 className="text-lg font-bold text-slate-900">Catatan Kehadiran</h1>
                        <p className="text-sm text-slate-500">Buat catatan untuk siswa yang bermasalah</p>
                    </div>
                </div>
                <input
                    type="date"
                    value={selectedDate}
                    onChange={(e) => setSelectedDate(e.target.value)}
                    className="px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
            </div>

            {loading ? (
                <div className="space-y-3">
                    <SkeletonCard className="h-24" />
                    <SkeletonCard className="h-24" />
                    <SkeletonCard className="h-24" />
                </div>
            ) : students.length === 0 ? (
                <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
                    <EmptyState
                        preset="no-students"
                        title="Semua Siswa Sudah Beres! 🎉"
                        description="Tidak ada catatan yang perlu dibuat untuk tanggal ini."
                        size="md"
                    />
                </div>
            ) : (
                <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
                    <div className="divide-y divide-slate-100">
                        {students.map((student) => (
                            <div key={student.student_id} className="p-4 hover:bg-slate-50 transition-colors">
                                <div className="flex items-start gap-4">
                                    <div className="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 text-sm font-bold shrink-0">
                                        {student.student_name.charAt(0)}
                                    </div>
                                    <div className="flex-1 min-w-0">
                                        <p className="font-medium text-slate-900 text-sm">{student.student_name}</p>
                                        <textarea
                                            value={student.note}
                                            onChange={(e) => handleNoteChange(student.student_id, e.target.value)}
                                            placeholder="Tambahkan catatan..."
                                            className="mt-2 w-full px-3 py-2 border border-slate-200 rounded-lg text-sm resize-none focus:outline-none focus:ring-2 focus:ring-blue-500"
                                            rows={2}
                                        />
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {students.length > 0 && (
                <div className="flex justify-end">
                    <button
                        onClick={handleSave}
                        disabled={saving}
                        className="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700 disabled:opacity-50 flex items-center gap-2"
                    >
                        <Save className="w-4 h-4" />
                        {saving ? 'Menyimpan...' : 'Simpan Catatan'}
                    </button>
                </div>
            )}
        </div>
    );
};

export default HomeroomAttendanceNotes;
