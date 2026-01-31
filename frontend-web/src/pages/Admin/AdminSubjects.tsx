import React, { useMemo, useState } from 'react';
import { BookOpen, ClipboardCheck, Trash2 } from 'lucide-react';
import { useLocation } from 'react-router-dom';
import Loading from '../../components/common/Loading';
import ErrorMessage from '../../components/common/ErrorMessage';
import {
    useAdminSubjects,
    useAdminTeachers,
    useAdminClasses,
    useTeacherAssignments,
    useAcademicYears,
} from '../../modules/admin/hooks';
import { apiClient } from '../../lib/api';
import showToast from '../../utils/toast';

const titleMap: Record<string, string> = {
    '/admin/subjects': 'Daftar Mapel',
    '/admin/subjects/teacher-mapping': 'Mapel ↔ Guru',
    '/admin/subjects/class-mapping': 'Mapel ↔ Kelas',
};

const AdminSubjects: React.FC = () => {
    const location = useLocation();
    const { data, isLoading, error, refetch } = useAdminSubjects();
    const { data: teacherData } = useAdminTeachers();
    const { data: classData } = useAdminClasses();
    const { data: assignments, isLoading: assignmentLoading, error: assignmentError, refetch: refetchAssignments } = useTeacherAssignments();
    const { data: academicYears } = useAcademicYears();
    const title = titleMap[location.pathname] ?? 'Manajemen Mapel';
    const isMappingPage = location.pathname.includes('teacher-mapping') || location.pathname.includes('class-mapping');
    const [formState, setFormState] = useState({
        teacher_id: '',
        subject_id: '',
        class_id: '',
        academic_year_id: '',
    });

    const activeYear = useMemo(() => academicYears?.years?.find((year) => year.is_active), [academicYears]);

    if (isLoading || (isMappingPage && assignmentLoading)) {
        return <Loading text="Memuat data mapel..." />;
    }

    if (error || (isMappingPage && assignmentError)) {
        return (
            <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
                <ErrorMessage message="Gagal memuat data mapel" onRetry={() => {
                    refetch();
                    refetchAssignments();
                }} />
            </div>
        );
    }

    const handleSubmitMapping = async (event: React.FormEvent) => {
        event.preventDefault();

        try {
            await apiClient.post('/admin/teachers/assignments', {
                teacher_id: Number(formState.teacher_id),
                subject_id: Number(formState.subject_id),
                class_id: Number(formState.class_id),
                academic_year_id: formState.academic_year_id
                    ? Number(formState.academic_year_id)
                    : activeYear?.id,
            });
            setFormState({
                teacher_id: '',
                subject_id: '',
                class_id: '',
                academic_year_id: '',
            });
            await refetchAssignments();
        } catch (err) {
            console.error(err);
            showToast.error('Gagal menyimpan mapping guru-mapel-kelas.');
        }
    };

    const handleDelete = async (assignmentId: number) => {
        if (!window.confirm('Hapus mapping ini?')) return;
        try {
            await apiClient.delete(`/admin/teachers/assignments/${assignmentId}`);
            await refetchAssignments();
        } catch (err) {
            console.error(err);
            showToast.error('Gagal menghapus mapping.');
        }
    };

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
                        <BookOpen className="w-6 h-6 text-blue-600" />
                        {title}
                    </h1>
                    <p className="text-sm text-gray-500 mt-1">Daftar mata pelajaran aktif.</p>
                </div>
                <div className="text-sm text-gray-600">Total: {data?.total ?? 0}</div>
            </div>

            {!isMappingPage ? (
                <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead>
                            <tr className="text-left text-gray-500 border-b">
                                <th className="py-3 px-6">Kode</th>
                                <th className="py-3 px-6">Nama Mapel</th>
                                <th className="py-3 px-6">Tingkat</th>
                                <th className="py-3 px-6">Jenjang</th>
                                <th className="py-3 px-6">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data?.subjects?.length ? (
                                data?.subjects?.map((subject) => (
                                    <tr key={subject.id} className="border-b last:border-0">
                                        <td className="py-3 px-6 text-gray-600">{subject.code}</td>
                                        <td className="py-3 px-6 font-medium text-gray-900">{subject.name}</td>
                                        <td className="py-3 px-6 text-gray-600">{subject.grade_level ?? '-'}</td>
                                        <td className="py-3 px-6 text-gray-600">{subject.school_level}</td>
                                        <td className="py-3 px-6">
                                            <span className={`text-xs font-semibold px-2 py-1 rounded-full ${subject.is_active
                                                ? 'bg-green-50 text-green-700'
                                                : 'bg-gray-100 text-gray-600'
                                                }`}>
                                                {subject.is_active ? 'Aktif' : 'Nonaktif'}
                                            </span>
                                        </td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td colSpan={5} className="py-10 text-center text-sm text-gray-500">
                                        Belum ada data mapel.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            ) : (
                <div className="space-y-6">
                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                        <h2 className="text-lg font-semibold text-gray-900 flex items-center gap-2 mb-4">
                            <ClipboardCheck className="w-5 h-5 text-blue-600" />
                            Tambah Mapping Guru - Mapel - Kelas
                        </h2>
                        <form onSubmit={handleSubmitMapping} className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label className="text-sm font-medium text-gray-700">Guru</label>
                                <select
                                    value={formState.teacher_id}
                                    onChange={(event) => setFormState((prev) => ({ ...prev, teacher_id: event.target.value }))}
                                    className="mt-1 w-full border border-gray-200 rounded-lg px-3 py-2"
                                    required
                                >
                                    <option value="">Pilih guru</option>
                                    {teacherData?.data?.map((teacher) => (
                                        <option key={teacher.id} value={teacher.id}>{teacher.name}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="text-sm font-medium text-gray-700">Mapel</label>
                                <select
                                    value={formState.subject_id}
                                    onChange={(event) => setFormState((prev) => ({ ...prev, subject_id: event.target.value }))}
                                    className="mt-1 w-full border border-gray-200 rounded-lg px-3 py-2"
                                    required
                                >
                                    <option value="">Pilih mapel</option>
                                    {data?.subjects?.map((subject) => (
                                        <option key={subject.id} value={subject.id}>{subject.name}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="text-sm font-medium text-gray-700">Kelas</label>
                                <select
                                    value={formState.class_id}
                                    onChange={(event) => setFormState((prev) => ({ ...prev, class_id: event.target.value }))}
                                    className="mt-1 w-full border border-gray-200 rounded-lg px-3 py-2"
                                    required
                                >
                                    <option value="">Pilih kelas</option>
                                    {classData?.classes?.map((item) => (
                                        <option key={item.id} value={item.id}>{item.name}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="text-sm font-medium text-gray-700">Tahun Ajaran</label>
                                <select
                                    value={formState.academic_year_id}
                                    onChange={(event) => setFormState((prev) => ({ ...prev, academic_year_id: event.target.value }))}
                                    className="mt-1 w-full border border-gray-200 rounded-lg px-3 py-2"
                                >
                                    <option value="">Default (Aktif)</option>
                                    {academicYears?.years?.map((year) => (
                                        <option key={year.id} value={year.id}>{year.name} - Sem {year.semester}</option>
                                    ))}
                                </select>
                            </div>
                            <div className="md:col-span-2 flex justify-end">
                                <button
                                    type="submit"
                                    className="px-4 py-2 text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 rounded-lg"
                                >
                                    Simpan Mapping
                                </button>
                            </div>
                        </form>
                    </div>

                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead>
                                <tr className="text-left text-gray-500 border-b">
                                    <th className="py-3 px-6">Guru</th>
                                    <th className="py-3 px-6">Mapel</th>
                                    <th className="py-3 px-6">Kelas</th>
                                    <th className="py-3 px-6">Tahun Ajaran</th>
                                    <th className="py-3 px-6">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                {assignments?.assignments?.length ? (
                                    assignments?.assignments?.map((item) => (
                                        <tr key={item.id} className="border-b last:border-0">
                                            <td className="py-3 px-6 text-gray-600">{item.teacher?.name ?? '-'}</td>
                                            <td className="py-3 px-6 text-gray-600">{item.subject?.name ?? '-'}</td>
                                            <td className="py-3 px-6 text-gray-600">{item.class?.name ?? '-'}</td>
                                            <td className="py-3 px-6 text-gray-600">{item.academic_year?.name ?? '-'}</td>
                                            <td className="py-3 px-6">
                                                <button
                                                    onClick={() => handleDelete(item.id)}
                                                    className="text-xs font-semibold px-3 py-1 rounded-full border border-red-200 text-red-600 hover:bg-red-50 inline-flex items-center gap-1"
                                                >
                                                    <Trash2 className="w-3 h-3" />
                                                    Hapus
                                                </button>
                                            </td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td colSpan={5} className="py-10 text-center text-sm text-gray-500">
                                            Belum ada mapping guru-mapel-kelas.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </div>
    );
};

export default AdminSubjects;
