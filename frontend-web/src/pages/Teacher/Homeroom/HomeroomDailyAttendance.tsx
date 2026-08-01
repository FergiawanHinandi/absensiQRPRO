import React, { useEffect, useState } from 'react';
import { Search, Save, Download } from 'lucide-react';
import { apiClient } from '../../../lib/api';
import showToast from '../../../utils/toast';
import { SkeletonTable } from '../../../components/ui/LoadingStates';
import { EmptyState } from '../../../components/ui/EmptyStates';

interface StudentAttendance {
    student_id: number;
    student_name: string;
    student_nis: string;
    status: 'present' | 'late' | 'sick' | 'permission' | 'alpha' | 'not_marked';
    check_in_time?: string;
    notes?: string;
}

export const HomeroomDailyAttendance: React.FC = () => {
    const [date, setDate] = useState(new Date().toISOString().split('T')[0]);
    const [students, setStudents] = useState<StudentAttendance[]>([]);
    const [loading, setLoading] = useState(true);
    const [className, setClassName] = useState('');
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        fetchAttendance();
    }, [date]);

    const fetchAttendance = async () => {
        try {
            setLoading(true);
            const response = await apiClient.get(`/teacher/attendance/today-sessions`);
            const data = response.data?.data || response.data;

            if (data) {
                // Extract class name from response
                setClassName(data.class_name || data.className || 'Kelas Perwalian');

                // Map students from API response
                const studentList = (data.students || data.attendances || []).map((s: any) => ({
                    student_id: s.student_id || s.id,
                    student_name: s.student_name || s.name,
                    student_nis: s.student_nis || s.nis || s.username || '',
                    status: s.status || 'not_marked',
                    check_in_time: s.check_in_time || s.checkInTime || undefined,
                    notes: s.notes || '',
                }));
                setStudents(studentList);
            }
        } catch (error: any) {
            console.error('Failed to fetch attendance:', error);
            // Graceful fallback — show empty state if API unavailable
            if (error.response?.status !== 404) {
                showToast.error('Gagal memuat data kehadiran');
            }
        } finally {
            setLoading(false);
        }
    };

    const handleStatusChange = (studentId: number, status: StudentAttendance['status']) => {
        setStudents(prev => prev.map(s =>
            s.student_id === studentId ? { ...s, status } : s
        ));
    };

    const handleSave = async () => {
        try {
            setSaving(true);
            const payload = students.map(s => ({
                student_id: s.student_id,
                status: s.status,
                notes: s.notes || '',
            }));
            await apiClient.post('/teacher/attendance/manual/bulk', {
                date,
                attendances: payload,
            });
            showToast.success('Perubahan berhasil disimpan');
        } catch (error: any) {
            console.error('Failed to save:', error);
            showToast.error(error.response?.data?.message || 'Gagal menyimpan perubahan');
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="min-h-screen bg-slate-50 p-6">
            <div className="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 mb-1">Absensi Harian Kelas {className}</h1>
                    <p className="text-slate-600">Kelola dan pantau kehadiran siswa perwalian Anda.</p>
                </div>
                <div className="flex items-center gap-3">
                    <input
                        type="date"
                        value={date}
                        onChange={(e) => setDate(e.target.value)}
                        className="px-4 py-2 border border-slate-300 rounded-lg text-slate-700 bg-white"
                    />
                    <button className="flex items-center gap-2 px-4 py-2 bg-white border border-slate-300 text-slate-700 rounded-lg hover:bg-slate-50">
                        <Download className="w-4 h-4" />
                        Export
                    </button>
                    <button
                        onClick={handleSave}
                        disabled={saving}
                        className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
                    >
                        <Save className="w-4 h-4" />
                        {saving ? 'Menyimpan...' : 'Simpan Perubahan'}
                    </button>
                </div>
            </div>

            <div className="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                <div className="p-4 border-b border-slate-200 bg-slate-50 flex gap-4 items-center">
                    <div className="relative flex-1 max-w-md">
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                        <input
                            type="text"
                            placeholder="Cari siswa..."
                            className="w-full pl-9 pr-4 py-2 border border-slate-300 rounded-lg text-sm"
                        />
                    </div>
                    <div className="flex items-center gap-2">
                        <div className="flex gap-1 text-xs font-medium bg-white px-3 py-1.5 rounded border border-slate-200">
                            <span className="w-3 h-3 rounded-full bg-green-500 mt-0.5"></span>
                            Hadir: {students.filter(s => s.status === 'present').length}
                        </div>
                        <div className="flex gap-1 text-xs font-medium bg-white px-3 py-1.5 rounded border border-slate-200">
                            <span className="w-3 h-3 rounded-full bg-yellow-500 mt-0.5"></span>
                            Telat: {students.filter(s => s.status === 'late').length}
                        </div>
                        <div className="flex gap-1 text-xs font-medium bg-white px-3 py-1.5 rounded border border-slate-200">
                            <span className="w-3 h-3 rounded-full bg-blue-500 mt-0.5"></span>
                            Sakit: {students.filter(s => s.status === 'sick').length}
                        </div>
                        <div className="flex gap-1 text-xs font-medium bg-white px-3 py-1.5 rounded border border-slate-200">
                            <span className="w-3 h-3 rounded-full bg-red-500 mt-0.5"></span>
                            Alpha: {students.filter(s => s.status === 'alpha').length}
                        </div>
                    </div>
                </div>

                <table className="min-w-full text-sm">
                    <thead className="bg-slate-50 text-slate-500 font-medium text-left">
                        <tr>
                            <th className="px-6 py-4 w-16">No</th>
                            <th className="px-6 py-4">NIS</th>
                            <th className="px-6 py-4">Nama Siswa</th>
                            <th className="px-6 py-4">Status Kehadiran</th>
                            <th className="px-6 py-4">Jam Masuk</th>
                            <th className="px-6 py-4">Catatan</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {loading ? (
                            <tr><td colSpan={6} className="p-4"><SkeletonTable rows={5} cols={6} /></td></tr>
                        ) : students.length === 0 ? (
                            <tr>
                                <td colSpan={6} className="p-4">
                                    <EmptyState
                                        preset="no-students"
                                        title="Semua Siswa Sudah Hadir! 🎉"
                                        description="Tidak ada data siswa yang perlu diinput untuk tanggal ini."
                                        size="sm"
                                    />
                                </td>
                            </tr>
                        ) : (
                            students.map((student, index) => (
                                <tr key={student.student_id} className="hover:bg-slate-50">
                                    <td className="px-6 py-3 text-slate-500">{index + 1}</td>
                                    <td className="px-6 py-3 font-mono text-slate-600">{student.student_nis}</td>
                                    <td className="px-6 py-3 font-medium text-slate-900">{student.student_name}</td>
                                    <td className="px-6 py-3">
                                        <div className="flex gap-1">
                                            {['present', 'late', 'sick', 'permission', 'alpha'].map((status) => (
                                                <button
                                                    key={status}
                                                    onClick={() => handleStatusChange(student.student_id, status as any)}
                                                    className={`w-8 h-8 rounded-lg flex items-center justify-center transition-all ${student.status === status
                                                        ? getStatusColor(status) + ' ring-2 ring-offset-1 ring-slate-200 font-bold text-white'
                                                        : 'bg-slate-100 text-slate-400 hover:bg-slate-200'
                                                        }`}
                                                    title={status}
                                                >
                                                    {getStatusInitial(status)}
                                                </button>
                                            ))}
                                        </div>
                                    </td>
                                    <td className="px-6 py-3 text-slate-600">{student.check_in_time || '-'}</td>
                                    <td className="px-6 py-3">
                                        <input
                                            type="text"
                                            className="w-full border-b border-transparent hover:border-slate-300 focus:border-blue-500 focus:outline-none bg-transparent py-1"
                                            placeholder="Tambah catatan..."
                                            defaultValue={student.notes}
                                        />
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
};

const getStatusColor = (status: string) => {
    switch (status) {
        case 'present': return 'bg-green-500';
        case 'late': return 'bg-yellow-500';
        case 'sick': return 'bg-blue-500';
        case 'permission': return 'bg-purple-500';
        case 'alpha': return 'bg-red-500';
        default: return 'bg-slate-500';
    }
};

const getStatusInitial = (status: string) => {
    switch (status) {
        case 'present': return 'H';
        case 'late': return 'T';
        case 'sick': return 'S';
        case 'permission': return 'I';
        case 'alpha': return 'A';
        default: return '-';
    }
};
