import React, { useEffect, useState } from 'react';
import { X, RefreshCw, UserCheck, UserX, Clock, FileText } from 'lucide-react';
import { apiClient as api } from '../../lib/api';
import type { Schedule, StudentAttendance } from '../../types/api.types';
import Loading from '../../components/common/Loading';
import ErrorMessage from '../../components/common/ErrorMessage';
import ManualInputModal from './ManualInputModal';
import { getErrorMessage } from '../../utils/errorHandler';

interface Props {
    isOpen: boolean;
    onClose: () => void;
    schedule: Schedule;
}

const ClassAttendanceModal: React.FC<Props> = ({ isOpen, onClose, schedule }) => {
    const [students, setStudents] = useState<StudentAttendance[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    // Manual Input State
    const [selectedStudent, setSelectedStudent] = useState<{ id: number; name: string } | null>(null);

    const fetchAttendance = async () => {
        setLoading(true);
        setError(null);
        try {
            const response = await api.get<{ students: StudentAttendance[] }>(`/attendance/class/${schedule.id}`);
            // Sort: Present first, then by name
            const sorted = response.data.students.sort((a, b) => {
                if (a.status === 'present' && b.status !== 'present') return -1;
                if (a.status !== 'present' && b.status === 'present') return 1;
                return a.name.localeCompare(b.name);
            });
            setStudents(sorted);
        } catch (err) {
            setError(getErrorMessage(err));
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        if (isOpen) {
            fetchAttendance();
        }
    }, [isOpen]);

    if (!isOpen) return null;

    const stats = {
        present: students.filter(s => s.status === 'present').length,
        late: students.filter(s => s.status === 'late').length,
        sick: students.filter(s => s.status === 'sick').length,
        permit: students.filter(s => s.status === 'permit').length,
        alpha: students.filter(s => s.status === 'alpha').length,
        total: students.length
    };

    const getStatusBadge = (status: string) => {
        switch (status) {
            case 'present': return <span className="bg-green-100 text-green-800 px-2 py-1 rounded-full text-xs font-bold">Hadir</span>;
            case 'late': return <span className="bg-yellow-100 text-yellow-800 px-2 py-1 rounded-full text-xs font-bold">Terlambat</span>;
            case 'sick': return <span className="bg-purple-100 text-purple-800 px-2 py-1 rounded-full text-xs font-bold">Sakit</span>;
            case 'permit': return <span className="bg-blue-100 text-blue-800 px-2 py-1 rounded-full text-xs font-bold">Izin</span>;
            case 'excused': return <span className="bg-indigo-100 text-indigo-800 px-2 py-1 rounded-full text-xs font-bold">Disp</span>;
            default: return <span className="bg-red-100 text-red-800 px-2 py-1 rounded-full text-xs font-bold">Alpa</span>;
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-gray-100 lg:bg-black/50 lg:backdrop-blur-sm lg:p-4">
            <div className="bg-white w-full h-full lg:h-auto lg:max-h-[90vh] lg:max-w-4xl lg:rounded-2xl shadow-xl flex flex-col animate-in fade-in zoom-in duration-200">

                {/* Header */}
                <div className="bg-white border-b border-gray-200 px-6 py-4 flex justify-between items-center sticky top-0 z-10">
                    <div>
                        <h2 className="text-xl font-bold text-gray-900">{schedule.class.name}</h2>
                        <p className="text-sm text-gray-500">{schedule.subject.name} • {schedule.room}</p>
                    </div>
                    <div className="flex gap-2">
                        <button
                            onClick={fetchAttendance}
                            className="p-2 text-gray-500 hover:bg-gray-100 rounded-full"
                            title="Refresh"
                        >
                            <RefreshCw className="w-5 h-5" />
                        </button>
                        <button
                            onClick={onClose}
                            className="p-2 text-gray-500 hover:bg-red-50 hover:text-red-600 rounded-full"
                            title="Tutup"
                        >
                            <X className="w-6 h-6" />
                        </button>
                    </div>
                </div>

                {/* Stats Bar */}
                <div className="bg-gray-50 px-6 py-3 border-b border-gray-200 flex gap-4 overflow-x-auto">
                    <div className="flex items-center gap-2 text-sm">
                        <UserCheck className="w-4 h-4 text-green-600" />
                        <span className="font-bold text-gray-900">{stats.present + stats.late}</span>
                        <span className="text-gray-500">Hadir</span>
                    </div>
                    <div className="flex items-center gap-2 text-sm">
                        <FileText className="w-4 h-4 text-blue-600" />
                        <span className="font-bold text-gray-900">{stats.sick + stats.permit}</span>
                        <span className="text-gray-500">Izin/Sakit</span>
                    </div>
                    <div className="flex items-center gap-2 text-sm">
                        <UserX className="w-4 h-4 text-red-600" />
                        <span className="font-bold text-gray-900">{stats.alpha}</span>
                        <span className="text-gray-500">Belum Absen</span>
                    </div>
                </div>

                {/* Content */}
                <div className="flex-1 overflow-y-auto p-0 lg:p-6 bg-gray-50">
                    {loading ? (
                        <Loading text="Memuat data kelas..." />
                    ) : error ? (
                        <div className="p-8">
                            <ErrorMessage message={error} onRetry={fetchAttendance} />
                        </div>
                    ) : (
                        <div className="bg-white rounded-none lg:rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-10">No</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nama Siswa</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Jam Masuk</th>
                                        <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody className="bg-white divide-y divide-gray-200">
                                    {students.map((student, index) => (
                                        <tr key={student.id} className="hover:bg-gray-50 transition-colors">
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {index + 1}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                <div className="flex items-center">
                                                    <div>
                                                        <div className="text-sm font-medium text-gray-900">{student.name}</div>
                                                        <div className="text-xs text-gray-500">{student.username}</div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                {getStatusBadge(student.status)}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {student.check_in_time ? (
                                                    <span className="flex items-center gap-1">
                                                        <Clock className="w-3 h-3" />
                                                        {student.check_in_time.split(' ')[1].slice(0, 5)}
                                                    </span>
                                                ) : '-'}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                {student.status === 'alpha' && (
                                                    <button
                                                        onClick={() => setSelectedStudent({ id: student.id, name: student.name })}
                                                        className="text-blue-600 hover:text-blue-900 bg-blue-50 hover:bg-blue-100 px-3 py-1 rounded-lg transition-colors"
                                                    >
                                                        Input Manual
                                                    </button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>

            {/* Manual Input Modal */}
            {selectedStudent && (
                <ManualInputModal
                    isOpen={!!selectedStudent}
                    onClose={() => setSelectedStudent(null)}
                    onSuccess={() => {
                        fetchAttendance(); // Refresh list after success
                    }}
                    schedule={schedule}
                    student={selectedStudent}
                />
            )}
        </div>
    );
};

export default ClassAttendanceModal;
