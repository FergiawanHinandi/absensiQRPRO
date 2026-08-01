import React, { useState, useEffect } from 'react';
import { ClipboardCheck, Users, Calendar, Clock } from 'lucide-react';
import { teacherService } from '../../services/teacherService';
import type { TeacherSchedule } from '../../services/teacherService';
import showToast from '../../utils/toast';
import { Skeleton, SkeletonTable } from '../../components/ui/LoadingStates';
import { EmptyState } from '../../components/ui/EmptyStates';

const TeacherAttendanceList: React.FC = () => {
    const [sessions, setSessions] = useState<TeacherSchedule[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    const [selectedSessionId, setSelectedSessionId] = useState<number | null>(null);
    const [students, setStudents] = useState<any[]>([]);
    const [isStudentsLoading, setIsStudentsLoading] = useState(false);

    useEffect(() => {
        loadSessions();
    }, []);

    const loadSessions = async () => {
        setIsLoading(true);
        try {
            // Get this week's schedules
            const today = new Date();
            const startOfWeek = new Date(today.setDate(today.getDate() - today.getDay() + 1)).toISOString().split('T')[0];
            const data = await teacherService.getSchedules(startOfWeek);

            // Filter only today's schedule for simplicity in this view,
            // or just show all of them. We will show all from the current week.
            setSessions(data || []);
        } catch (error) {
            showToast.error("Gagal memuat jadwal pelajaran");
        } finally {
            setIsLoading(false);
        }
    };

    const loadStudents = async (sessionId: number) => {
        setSelectedSessionId(sessionId);
        setIsStudentsLoading(true);
        try {
            const data = await teacherService.getStudentsBySession(sessionId);
            setStudents(data);
        } catch (error: any) {
            showToast.error(error?.response?.data?.message || 'Gagal memuat daftar hadir');
            setStudents([]);
        } finally {
            setIsStudentsLoading(false);
        }
    };

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
                    <ClipboardCheck className="w-6 h-6 text-blue-600" />
                    Daftar Hadir Siswa
                </h1>
                <p className="text-sm text-gray-500 mt-1">Lihat daftar kehadiran siswa berdasarkan sesi pelajaran</p>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* Session List */}
                <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6 lg:col-span-1">
                    <h2 className="text-lg font-semibold text-gray-900 flex items-center gap-2 mb-4">
                        <Calendar className="w-5 h-5 text-gray-400" />
                        Jadwal & Sesi
                    </h2>

                    {isLoading ? (
                        <div className="space-y-3">
                            <Skeleton variant="rounded" width="100%" height={72} />
                            <Skeleton variant="rounded" width="100%" height={72} />
                            <Skeleton variant="rounded" width="100%" height={72} />
                        </div>
                    ) : sessions.length === 0 ? (
                        <EmptyState
                            preset="no-schedules"
                            title="Tidak Ada Jadwal Minggu Ini"
                            description="Belum ada jadwal pelajaran untuk pekan ini."
                            size="sm"
                        />
                    ) : (
                        <div className="space-y-3">
                            {sessions.map((session) => (
                                <div
                                    key={session.id}
                                    onClick={() => loadStudents(session.id)}
                                    className={`p-4 border rounded-lg cursor-pointer transition-colors ${selectedSessionId === session.id ? 'border-blue-500 bg-blue-50' : 'border-gray-200 hover:border-blue-300 hover:bg-gray-50'}`}
                                >
                                    <div className="font-semibold text-gray-900">{session.subject_name}</div>
                                    <div className="text-sm text-gray-500 mt-1">{session.class_name}</div>
                                    <div className="flex items-center gap-2 mt-2 text-xs text-gray-400">
                                        <Clock className="w-3 h-3" />
                                        <span className="capitalize">{session.day_of_week}</span>, {session.start_time} - {session.end_time}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                {/* Student Attendance List */}
                <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6 lg:col-span-2">
                    <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
                        <h2 className="text-lg font-semibold text-gray-900 flex items-center gap-2">
                            <Users className="w-5 h-5 text-gray-400" />
                            Detail Kehadiran Siswa
                        </h2>
                    </div>

                    {!selectedSessionId ? (
                        <EmptyState
                            icon={ClipboardCheck}
                            title="Pilih Sesi untuk Melihat Daftar Hadir"
                            description="Pilih sesi di sebelah kiri untuk melihat daftar hadir siswa."
                            size="sm"
                        />
                    ) : isStudentsLoading ? (
                        <SkeletonTable rows={6} cols={5} />
                    ) : students.length === 0 ? (
                        <EmptyState
                            preset="no-students"
                            title="Belum Ada Data Siswa"
                            description="Belum ada siswa tercatat untuk sesi ini."
                            size="sm"
                        />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm text-left">
                                <thead className="bg-gray-50 text-gray-600 font-medium border-b border-gray-200">
                                    <tr>
                                        <th className="px-4 py-3 rounded-tl-lg">No</th>
                                        <th className="px-4 py-3">NIS</th>
                                        <th className="px-4 py-3">Nama Siswa</th>
                                        <th className="px-4 py-3">Status</th>
                                        <th className="px-4 py-3 rounded-tr-lg">Catatan</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {students.map((student, index) => (
                                        <tr key={student.id} className="hover:bg-gray-50 transition-colors">
                                            <td className="px-4 py-3 text-gray-500 w-12">{index + 1}</td>
                                            <td className="px-4 py-3 text-gray-600">{student.nis || '-'}</td>
                                            <td className="px-4 py-3 font-medium text-gray-900">{student.name}</td>
                                            <td className="px-4 py-3 text-gray-600">
                                                {!student.attendance_status ? (
                                                    <span className="px-2.5 py-1 bg-gray-100 text-gray-600 rounded-full text-xs font-medium">Belum Absen</span>
                                                ) : student.attendance_status === 'present' ? (
                                                    <span className="px-2.5 py-1 bg-green-100 text-green-700 rounded-full text-xs font-medium">Hadir</span>
                                                ) : student.attendance_status === 'late' ? (
                                                    <span className="px-2.5 py-1 bg-orange-100 text-orange-700 rounded-full text-xs font-medium">Terlambat</span>
                                                ) : student.attendance_status === 'sick' ? (
                                                    <span className="px-2.5 py-1 bg-blue-100 text-blue-700 rounded-full text-xs font-medium">Sakit</span>
                                                ) : student.attendance_status === 'permit' ? (
                                                    <span className="px-2.5 py-1 bg-purple-100 text-purple-700 rounded-full text-xs font-medium">Izin</span>
                                                ) : (
                                                    <span className="px-2.5 py-1 bg-red-100 text-red-700 rounded-full text-xs font-medium">Alpha</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-gray-500 italic">
                                                {student.attendance_notes || '-'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
};

export default TeacherAttendanceList;
