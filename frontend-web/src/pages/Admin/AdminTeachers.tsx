import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
    Search,
    Plus,
    User,
    Mail,
    Phone,
    BookOpen,
    Edit,
    CheckCircle,
    XCircle,
    Upload,
    Key
} from 'lucide-react';
import { useLocation, Link } from 'react-router-dom';
import { apiClient } from '../../lib/api';
import { AxiosError } from 'axios';
import type { Teacher } from '../../types/Teacher';
import showToast from '../../utils/toast';



interface ClassOption {
    id: number;
    name: string;
    academic_year_id?: number | null;
    homeroom_teacher_id?: number | null;
    homeroom_teacher?: string;
}

interface SubjectOption {
    id: number;
    name: string;
}

interface AssignmentItem {
    id: number;
    teacher: { id: number; name: string };
    subject: { id: number; name: string };
    class: { id: number; name: string };
    academic_year?: { id: number; name: string } | null;
}

export default function AdminTeachers() {
    const location = useLocation();
    const isHomeroom = location.pathname.includes('/teachers/homeroom');
    const isSubject = location.pathname.includes('/teachers/subject');
    const isAssignments = location.pathname.includes('/teachers/assignments');
    // const isList = !isManage && !isHomeroom && !isSubject && !isAssignments; // Removed logic

    const titleMap: Record<string, string> = {
        '/admin/teachers': 'Daftar Guru',
        '/admin/teachers/manage': 'Tambah / Edit Guru',
        '/admin/teachers/homeroom': 'Guru Kelas (Wali Kelas)',
        '/admin/teachers/subject': 'Guru Mapel',
        '/admin/teachers/assignments': 'Assign Kelas & Mapel',
    };

    const pageTitle = titleMap[location.pathname] ?? 'Manajemen Guru';

    const [activeTab, setActiveTab] = useState<'list' | 'homeroom' | 'subject' | 'assignments'>(() => {
        if (isHomeroom) return 'homeroom';
        if (isSubject) return 'subject';
        if (isAssignments) return 'assignments';
        return 'list';
    });
    const [teachers, setTeachers] = useState<Teacher[]>([]);
    const [teacherOptions, setTeacherOptions] = useState<Teacher[]>([]);
    const [classes, setClasses] = useState<ClassOption[]>([]);
    const [subjects, setSubjects] = useState<SubjectOption[]>([]);
    const [assignments, setAssignments] = useState<AssignmentItem[]>([]);
    const [loading, setLoading] = useState(true);
    const [metaLoading, setMetaLoading] = useState(false);
    const [search, setSearch] = useState('');

    // Pagination State
    const [currentPage, setCurrentPage] = useState(1);
    const [totalPages, setTotalPages] = useState(1);

    const [showModal, setShowModal] = useState(false);
    const [editingTeacher, setEditingTeacher] = useState<Teacher | null>(null);
    const [importing, setImporting] = useState(false);
    const fileInputRef = useRef<HTMLInputElement | null>(null);

    // Form State
    const [formData, setFormData] = useState({
        name: '',
        email: '',
        nip: '',
        phone: '',
        gender: 'L',
        password: '',
    });

    const [assignmentForm, setAssignmentForm] = useState({
        teacher_id: '',
        subject_id: '',
        class_id: '',
    });

    const classYearMap = useMemo(() => {
        return new Map(classes.map((cls) => [cls.id, cls.academic_year_id]));
    }, [classes]);

    useEffect(() => {
        if (isHomeroom) setActiveTab('homeroom');
        else if (isSubject) setActiveTab('subject');
        else if (isAssignments) setActiveTab('assignments');
        else setActiveTab('list');
    }, [isHomeroom, isSubject, isAssignments]);

    useEffect(() => {
        if (activeTab === 'list') {
            fetchTeachers();
        } else {
            fetchMeta();
        }
    }, [search, currentPage, activeTab]);

    const fetchTeachers = async () => {
        try {
            setLoading(true);
            const response = await apiClient.get('/admin/teachers', {
                params: {
                    search: search || undefined,
                    page: currentPage,
                },
            });
            if (response.data.success) {
                setTeachers(response.data.data.data);
                setTotalPages(response.data.data.last_page);
            }
        } catch (error) {
            console.error('Failed to fetch teachers:', error);
        } finally {
            setLoading(false);
        }
    };

    const fetchMeta = async () => {
        try {
            setMetaLoading(true);
            const [teacherRes, classRes, subjectRes, assignmentRes] = await Promise.all([
                apiClient.get('/admin/teachers', { params: { per_page: 200 } }),
                apiClient.get('/admin/classes'),
                apiClient.get('/admin/subjects'),
                apiClient.get('/admin/teachers/assignments'),
            ]);

            const teachersPayload = teacherRes.data?.data?.data ?? [];
            setTeacherOptions(teachersPayload);

            const classPayload = classRes.data?.data;
            const classList = Array.isArray(classPayload) ? classPayload : (classPayload?.classes ?? []);
            setClasses(classList);

            const subjectPayload = subjectRes.data?.data;
            const subjectList = Array.isArray(subjectPayload) ? subjectPayload : (subjectPayload?.subjects ?? []);
            setSubjects(subjectList);

            const assignmentPayload = assignmentRes.data?.data;
            const assignmentList = Array.isArray(assignmentPayload) ? assignmentPayload : (assignmentPayload?.assignments ?? []);
            setAssignments(assignmentList);
        } catch (error) {
            console.error('Failed to fetch teacher meta:', error);
        } finally {
            setMetaLoading(false);
        }
    };

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        try {
            if (editingTeacher) {
                await apiClient.put(`/admin/teachers/${editingTeacher.id}`, formData);
                showToast.success('Guru berhasil diperbarui');
            } else {
                await apiClient.post('/admin/teachers', formData);
                showToast.success('Guru berhasil ditambahkan');
            }
            setShowModal(false);
            setEditingTeacher(null);
            fetchTeachers();
        } catch (err) {
            const error = err as AxiosError<{ message: string }>;
            console.error('Failed to save teacher:', error);
            showToast.error(error.response?.data?.message || 'Gagal menyimpan data guru');
        }
    };

    const handleEdit = (teacher: Teacher) => {
        setEditingTeacher(teacher);
        setFormData({
            name: teacher.name,
            email: teacher.email || '',
            nip: teacher.nip || '',
            phone: teacher.phone || '',
            gender: teacher.gender || 'L',
            password: '', // Leave blank unless changing
        });
        setShowModal(true);
    };

    const handleToggleStatus = async (teacher: Teacher) => {
        if (!confirm(`Ubah status guru ${teacher.name}?`)) return;

        try {
            await apiClient.patch(`/admin/teachers/${teacher.id}/status`);
            fetchTeachers();
        } catch (error) {
            console.error('Failed to toggle status:', error);
        }
    };

    const openModal = () => {
        setEditingTeacher(null);
        setFormData({
            name: '',
            email: '',
            nip: '',
            phone: '',
            gender: 'L',
            password: '',
        });
        setShowModal(true);
    };

    const handleImportClick = () => {
        fileInputRef.current?.click();
    };

    const handleImportFile = async (event: React.ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];
        if (!file) return;

        setImporting(true);
        try {
            const formData = new FormData();
            formData.append('file', file);

            const response = await apiClient.post('/admin/teachers/import', formData, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });

            if (response.data.success) {
                const info = response.data.data;
                showToast.success(`Import selesai. Berhasil: ${info.created}, dilewati ${info.skipped}.`);
                fetchTeachers();
            }
        } catch (err) {
            const error = err as AxiosError<{ message: string }>;
            console.error('Import failed:', error);
            showToast.error(error.response?.data?.message || 'Gagal import guru');
        } finally {
            setImporting(false);
            if (fileInputRef.current) {
                fileInputRef.current.value = '';
            }
        }
    };

    const handleAssignmentSubmit = async (event: React.FormEvent) => {
        event.preventDefault();
        if (!assignmentForm.teacher_id || !assignmentForm.subject_id || !assignmentForm.class_id) {
            showToast.warning('Lengkapi data penugasan.');
            return;
        }
        const academicYearId = classYearMap.get(Number(assignmentForm.class_id));
        if (!academicYearId) {
            showToast.warning('Tahun ajaran kelas belum tersedia.');
            return;
        }
        try {
            await apiClient.post('/admin/teachers/assignments', {
                teacher_id: Number(assignmentForm.teacher_id),
                subject_id: Number(assignmentForm.subject_id),
                class_id: Number(assignmentForm.class_id),
                academic_year_id: academicYearId,
            });
            setAssignmentForm({ teacher_id: '', subject_id: '', class_id: '' });
            await fetchMeta();
        } catch (err) {
            const error = err as AxiosError<{ message: string }>;
            console.error('Failed to save assignment:', error);
            showToast.error(error.response?.data?.message || 'Gagal menyimpan penugasan mapel.');
        }
    };

    const handleHomeroomSave = async (classItem: ClassOption, teacherId: string) => {
        if (!teacherId) return;
        if (!classItem.academic_year_id) {
            showToast.warning('Tahun ajaran kelas belum tersedia.');
            return;
        }
        try {
            await apiClient.post('/admin/teachers/homeroom', {
                teacher_id: Number(teacherId),
                class_id: classItem.id,
                academic_year_id: classItem.academic_year_id,
            });
            await fetchMeta();
        } catch (err) {
            const error = err as AxiosError<{ message: string }>;
            console.error('Failed to set homeroom teacher:', error);
            showToast.error(error.response?.data?.message || 'Gagal menyimpan wali kelas.');
        }
    };

    const handleDeleteAssignment = async (assignmentId: number) => {
        if (!confirm('Hapus penugasan mapel ini?')) return;
        try {
            await apiClient.delete(`/admin/teachers/assignments/${assignmentId}`);
            await fetchMeta();
        } catch (err) {
            const error = err as AxiosError<{ message: string }>;
            console.error('Failed to delete assignment:', error);
            showToast.error(error.response?.data?.message || 'Gagal menghapus penugasan mapel.');
        }
    };



    // --- Tab Change handler ---


    return (
        <div className="min-h-screen bg-slate-50 p-6">
            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 mb-2">{pageTitle}</h1>
                <p className="text-slate-600">Kelola data guru, wali kelas, dan penugasan mata pelajaran</p>
            </div>

            {/* Toolbar only for List Tab */}
            {activeTab === 'list' && (
                <div className="bg-white rounded-lg shadow-sm border border-slate-200 p-4 mb-6 flex flex-col md:flex-row gap-4 justify-between items-center">
                    <div className="relative w-full md:max-w-md">
                        <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-slate-400" />
                        <input
                            type="text"
                            placeholder="Cari nama, NIP, atau email..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="w-full pl-10 pr-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                        />
                    </div>
                    <div className="flex gap-2">
                        <input
                            ref={fileInputRef}
                            type="file"
                            accept=".csv"
                            className="hidden"
                            onChange={handleImportFile}
                        />
                        <button
                            onClick={openModal}
                            className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
                        >
                            <Plus className="w-5 h-5" />
                            <span>Tambah Guru</span>
                        </button>
                        <button
                            onClick={handleImportClick}
                            disabled={importing}
                            className="flex items-center gap-2 px-4 py-2 border border-blue-200 text-blue-600 rounded-lg hover:bg-blue-50 transition-colors"
                        >
                            <Upload className="w-5 h-5" />
                            <span>{importing ? 'Mengimpor...' : 'Import Guru'}</span>
                        </button>
                    </div>
                </div>
            )}

            {/* CONTENT: LIST TAB */}
            {
                activeTab === 'list' && (
                    <div className="bg-white rounded-xl shadow-sm border border-slate-200 overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead>
                                <tr className="text-left text-slate-500 border-b">
                                    <th className="py-3 px-6">Nama Guru</th>
                                    <th className="py-3 px-6">NIP</th>
                                    <th className="py-3 px-6">Kontak</th>
                                    <th className="py-3 px-6">L/P</th>
                                    <th className="py-3 px-6">Status</th>
                                    <th className="py-3 px-6 text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                {loading ? (
                                    <tr>
                                        <td colSpan={6} className="py-8 text-center text-slate-500">Memuat data...</td>
                                    </tr>
                                ) : teachers.length === 0 ? (
                                    <tr>
                                        <td colSpan={6} className="py-8 text-center text-slate-500">Belum ada data guru.</td>
                                    </tr>
                                ) : (
                                    teachers.map((teacher) => (
                                        <tr key={teacher.id} className="border-b last:border-0 hover:bg-slate-50 transition-colors">
                                            <td className="py-3 px-6">
                                                <div className="font-medium text-slate-900">{teacher.name}</div>
                                                <div className="text-xs text-slate-500">{teacher.username}</div>
                                            </td>
                                            <td className="py-3 px-6 text-slate-600">{teacher.nip || '-'}</td>
                                            <td className="py-3 px-6">
                                                <div className="flex items-center gap-1 text-slate-600">
                                                    <Mail className="w-3 h-3" /> {teacher.email}
                                                </div>
                                                {teacher.phone && (
                                                    <div className="flex items-center gap-1 text-slate-500 text-xs mt-0.5">
                                                        <Phone className="w-3 h-3" /> {teacher.phone}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="py-3 px-6 text-slate-600">{teacher.gender}</td>
                                            <td className="py-3 px-6">
                                                <span className={`inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${teacher.is_active ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-500'}`}>
                                                    {teacher.is_active ? 'Aktif' : 'Nonaktif'}
                                                </span>
                                            </td>
                                            <td className="py-3 px-6">
                                                <div className="flex items-center justify-center gap-2">
                                                    <button
                                                        onClick={() => handleEdit(teacher)}
                                                        className="p-1.5 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                                                        title="Edit Data"
                                                    >
                                                        <Edit className="w-4 h-4" />
                                                    </button>
                                                    <button
                                                        onClick={() => handleToggleStatus(teacher)}
                                                        className={`p-1.5 rounded-lg transition-colors ${teacher.is_active ? 'text-amber-600 hover:bg-amber-50' : 'text-green-600 hover:bg-green-50'}`}
                                                        title={teacher.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                                                    >
                                                        {teacher.is_active ? <XCircle className="w-4 h-4" /> : <CheckCircle className="w-4 h-4" />}
                                                    </button>
                                                    <Link
                                                        to={`/admin/accounts/generate?search=${encodeURIComponent(teacher.name)}&tab=teacher`}
                                                        className="p-1.5 text-slate-600 hover:bg-slate-100 rounded-lg transition-colors"
                                                        title="Kelola Akun & Password"
                                                    >
                                                        <Key className="w-4 h-4" />
                                                    </Link>
                                                </div>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>

                        {/* Pagination */}
                        <div className="p-4 border-t border-slate-100 flex justify-center gap-2">
                            <button
                                disabled={currentPage === 1}
                                onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
                                className="px-3 py-1 border border-slate-200 rounded text-slate-600 disabled:opacity-50 hover:bg-white"
                            >
                                Sebelumnya
                            </button>
                            <span className="px-3 py-1 text-slate-600">
                                Halaman {currentPage} dari {totalPages}
                            </span>
                            <button
                                disabled={currentPage === totalPages}
                                onClick={() => setCurrentPage(p => Math.min(totalPages, p + 1))}
                                className="px-3 py-1 border border-slate-200 rounded text-slate-600 disabled:opacity-50 hover:bg-white"
                            >
                                Selanjutnya
                            </button>
                        </div>
                    </div>
                )
            }
            {/* CONTENT: HOMEROOM TEACHER LIST (VIEW ONLY) */}
            {
                activeTab === 'homeroom' && (
                    <div className="bg-white rounded-xl shadow-sm border border-slate-200 overflow-x-auto">
                        <div className="p-4 bg-sky-50 border-b border-sky-100 text-sky-800 text-sm">
                            <p className="flex items-center gap-2">
                                <User className="w-4 h-4" />
                                <strong>Daftar Wali Kelas:</strong> Halaman ini menampilkan daftar guru yang telah diatur sebagai wali kelas.
                                Untuk mengubah/mengatur wali kelas, silakan edit melalui tab "Kelas" atau "Assign Kelas".
                            </p>
                        </div>
                        <table className="min-w-full text-sm">
                            <thead>
                                <tr className="text-left text-slate-500 border-b">
                                    <th className="py-3 px-6">Nama Guru</th>
                                    <th className="py-3 px-6">NIP</th>
                                    <th className="py-3 px-6">Wali Kelas</th>
                                    <th className="py-3 px-6">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {metaLoading ? (
                                    <tr>
                                        <td colSpan={4} className="py-8 text-center text-slate-500">Memuat data...</td>
                                    </tr>
                                ) : classes.filter(c => c.homeroom_teacher).length === 0 ? (
                                    <tr>
                                        <td colSpan={4} className="py-8 text-center text-slate-500">Belum ada wali kelas yang ditentukan.</td>
                                    </tr>
                                ) : (
                                    classes
                                        .filter(c => c.homeroom_teacher)
                                        .map((cls) => (
                                            <tr key={cls.id} className="border-b last:border-0 hover:bg-slate-50">
                                                <td className="py-3 px-6 text-slate-900 font-medium">{cls.homeroom_teacher}</td>
                                                <td className="py-3 px-6 text-slate-600">-</td>
                                                {/* Since backend logic returns homeroom_teacher name in class object, we might not have NIP here unless we join. 
                                            For now, we display name and class. Ideally backend should provide comprehensive list. */}
                                                <td className="py-3 px-6 text-blue-600 font-semibold">{cls.name}</td>
                                                <td className="py-3 px-6 text-xs text-green-600 font-medium">Aktif</td>
                                            </tr>
                                        ))
                                )}
                            </tbody>
                        </table>
                    </div>
                )
            }

            {/* CONTENT: SUBJECT TEACHER LIST (VIEW ONLY) */}
            {
                activeTab === 'subject' && (
                    <div className="bg-white rounded-xl shadow-sm border border-slate-200 overflow-x-auto">
                        <div className="p-4 bg-sky-50 border-b border-sky-100 text-sky-800 text-sm">
                            <p className="flex items-center gap-2">
                                <BookOpen className="w-4 h-4" />
                                <strong>Daftar Guru Mapel:</strong> Menampilkan guru yang memiliki jadwal mengajar mata pelajaran tertentu.
                            </p>
                        </div>
                        <table className="min-w-full text-sm">
                            <thead>
                                <tr className="text-left text-slate-500 border-b">
                                    <th className="py-3 px-6">Nama Guru</th>
                                    <th className="py-3 px-6">Mengajar Mapel</th>
                                    <th className="py-3 px-6">Kelas Ajar</th>
                                    <th className="py-3 px-6">Tahun Ajaran</th>
                                </tr>
                            </thead>
                            <tbody>
                                {metaLoading ? (
                                    <tr>
                                        <td colSpan={4} className="py-8 text-center text-slate-500">Memuat data...</td>
                                    </tr>
                                ) : assignments.length === 0 ? (
                                    <tr>
                                        <td colSpan={4} className="py-8 text-center text-slate-500">Belum ada guru mapel.</td>
                                    </tr>
                                ) : (
                                    assignments.map((assignment) => (
                                        <tr key={assignment.id} className="border-b last:border-0 hover:bg-slate-50">
                                            <td className="py-3 px-6 text-slate-900 font-medium">{assignment.teacher?.name}</td>
                                            <td className="py-3 px-6 text-slate-600 font-semibold">{assignment.subject?.name}</td>
                                            <td className="py-3 px-6 text-slate-600">{assignment.class?.name}</td>
                                            <td className="py-3 px-6 text-slate-500 text-xs">{assignment.academic_year?.name ?? '-'}</td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                )
            }

            {/* CONTENT: ASSIGNMENTS TAB (ACTION ONLY) */}
            {
                activeTab === 'assignments' && (
                    <div className="space-y-6">
                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            {/* 1. Set Homeroom Teacher */}
                            <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                                <h2 className="text-lg font-semibold text-slate-900 mb-4 flex items-center gap-2">
                                    <User className="w-5 h-5 text-blue-600" />
                                    Set Wali Kelas
                                </h2>
                                <p className="text-sm text-slate-500 mb-4">Tentukan wali kelas untuk setiap kelas aktif.</p>
                                <div className="space-y-3 max-h-80 overflow-y-auto pr-2">
                                    {classes.map((cls) => (
                                        <div key={cls.id} className="flex items-center justify-between p-3 bg-slate-50 rounded-lg border border-slate-200">
                                            <span className="font-medium text-slate-700">{cls.name}</span>
                                            <select
                                                value={cls.homeroom_teacher_id ?? ''}
                                                onChange={(e) => handleHomeroomSave(cls, e.target.value)}
                                                className="text-sm border-slate-300 rounded focus:ring-blue-500 w-48"
                                                style={{ padding: '4px 8px' }}
                                            >
                                                <option value="">-- Pilih Guru --</option>
                                                {teacherOptions.map((t) => (
                                                    <option key={t.id} value={t.id}>{t.name}</option>
                                                ))}
                                            </select>
                                        </div>
                                    ))}
                                </div>
                            </div>

                            {/* 2. Add Subject Assignment */}
                            <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                                <h2 className="text-lg font-semibold text-slate-900 mb-4 flex items-center gap-2">
                                    <BookOpen className="w-5 h-5 text-blue-600" />
                                    Tambah Penugasan Mapel
                                </h2>
                                <p className="text-sm text-slate-500 mb-4">Tugaskan guru untuk mengajar mapel di kelas tertentu.</p>
                                <form onSubmit={handleAssignmentSubmit} className="space-y-4">
                                    <div className="space-y-2">
                                        <label className="text-xs font-semibold text-slate-500 uppercase">Guru Pengajar</label>
                                        <select
                                            value={assignmentForm.teacher_id}
                                            onChange={(e) => setAssignmentForm((prev) => ({ ...prev, teacher_id: e.target.value }))}
                                            className="w-full border border-slate-300 rounded-lg px-3 py-2"
                                        >
                                            <option value="">Pilih Guru...</option>
                                            {teacherOptions.map((teacher) => (
                                                <option key={teacher.id} value={teacher.id}>{teacher.name}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="space-y-2">
                                        <label className="text-xs font-semibold text-slate-500 uppercase">Mata Pelajaran</label>
                                        <select
                                            value={assignmentForm.subject_id}
                                            onChange={(e) => setAssignmentForm((prev) => ({ ...prev, subject_id: e.target.value }))}
                                            className="w-full border border-slate-300 rounded-lg px-3 py-2"
                                        >
                                            <option value="">Pilih Mapel...</option>
                                            {subjects.map((subject) => (
                                                <option key={subject.id} value={subject.id}>{subject.name}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="space-y-2">
                                        <label className="text-xs font-semibold text-slate-500 uppercase">Target Kelas</label>
                                        <select
                                            value={assignmentForm.class_id}
                                            onChange={(e) => setAssignmentForm((prev) => ({ ...prev, class_id: e.target.value }))}
                                            className="w-full border border-slate-300 rounded-lg px-3 py-2"
                                        >
                                            <option value="">Pilih Kelas...</option>
                                            {classes.map((cls) => (
                                                <option key={cls.id} value={cls.id}>{cls.name}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <button
                                        type="submit"
                                        className="w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium"
                                    >
                                        + Tambah Penugasan
                                    </button>
                                </form>
                            </div>
                        </div>

                        <div className="bg-white rounded-xl shadow-sm border border-slate-200 overflow-x-auto">
                            <div className="p-4 border-b border-slate-100 flex justify-between items-center">
                                <h3 className="font-semibold text-slate-900">Riwayat Penugasan Mapel</h3>
                                <span className="text-xs px-2 py-1 bg-slate-100 rounded text-slate-600">{assignments.length} penugasan aktif</span>
                            </div>
                            <table className="min-w-full text-sm">
                                <thead>
                                    <tr className="text-left text-slate-500 border-b">
                                        <th className="py-3 px-6">Guru</th>
                                        <th className="py-3 px-6">Mapel</th>
                                        <th className="py-3 px-6">Kelas</th>
                                        <th className="py-3 px-6">Tahun Ajaran</th>
                                        <th className="py-3 px-6 text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {metaLoading ? (
                                        <tr>
                                            <td colSpan={5} className="py-8 text-center text-slate-500">Memuat data...</td>
                                        </tr>
                                    ) : assignments.length === 0 ? (
                                        <tr>
                                            <td colSpan={5} className="py-8 text-center text-slate-500">Belum ada penugasan mapel.</td>
                                        </tr>
                                    ) : (
                                        assignments.map((assignment) => (
                                            <tr key={assignment.id} className="border-b last:border-0 hover:bg-slate-50">
                                                <td className="py-3 px-6 text-slate-900 font-medium">{assignment.teacher?.name}</td>
                                                <td className="py-3 px-6 text-slate-600">{assignment.subject?.name}</td>
                                                <td className="py-3 px-6 text-slate-600 font-medium bg-slate-50 w-fit rounded">{assignment.class?.name}</td>
                                                <td className="py-3 px-6 text-slate-500 text-xs">{assignment.academic_year?.name ?? '-'}</td>
                                                <td className="py-3 px-6 text-center">
                                                    <button
                                                        onClick={() => handleDeleteAssignment(assignment.id)}
                                                        className="px-3 py-1 text-xs font-semibold rounded-lg border border-rose-200 text-rose-600 hover:bg-rose-50 hover:text-rose-700 transition-colors"
                                                    >
                                                        Hapus
                                                    </button>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )
            }


            {/* Modal for Add/Edit Teacher */}
            {
                showModal && (
                    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
                        <div className="bg-white rounded-xl shadow-xl max-w-lg w-full max-h-[90vh] overflow-y-auto">
                            <div className="p-6 border-b border-slate-200">
                                <h2 className="text-xl font-bold text-slate-900">
                                    {editingTeacher ? 'Edit Data Guru' : 'Tambah Guru Baru'}
                                </h2>
                            </div>
                            <form onSubmit={handleSubmit} className="p-6 space-y-4">
                                <div>
                                    <label className="block text-sm font-medium text-slate-700 mb-1">Nama Lengkap</label>
                                    <input
                                        type="text"
                                        required
                                        value={formData.name}
                                        onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                                        className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                    />
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-slate-700 mb-1">Email</label>
                                    <input
                                        type="email"
                                        required
                                        value={formData.email}
                                        onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                                        className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                    />
                                </div>
                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <label className="block text-sm font-medium text-slate-700 mb-1">NIP</label>
                                        <input
                                            type="text"
                                            value={formData.nip}
                                            onChange={(e) => setFormData({ ...formData, nip: e.target.value })}
                                            className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-slate-700 mb-1">No. HP</label>
                                        <input
                                            type="text"
                                            value={formData.phone}
                                            onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                                            className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                        />
                                    </div>
                                </div>

                                <div>
                                    <label className="block text-sm font-medium text-slate-700 mb-1">Jenis Kelamin</label>
                                    <select
                                        value={formData.gender}
                                        onChange={(e) => setFormData({ ...formData, gender: e.target.value as 'L' | 'P' })}
                                        className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                    >
                                        <option value="L">Laki-laki</option>
                                        <option value="P">Perempuan</option>
                                    </select>
                                </div>

                                <div>
                                    <label className="block text-sm font-medium text-slate-700 mb-1">
                                        Password {editingTeacher && '(Kosongkan jika tidak diubah)'}
                                    </label>
                                    <input
                                        type="password"
                                        minLength={6}
                                        required={!editingTeacher}
                                        value={formData.password}
                                        onChange={(e) => setFormData({ ...formData, password: e.target.value })}
                                        className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                        placeholder={editingTeacher ? '••••••' : 'Minimal 6 karakter'}
                                    />
                                </div>

                                <div className="flex justify-end gap-3 pt-4">
                                    <button
                                        type="button"
                                        onClick={() => setShowModal(false)}
                                        className="px-4 py-2 text-slate-700 hover:bg-slate-100 rounded-lg"
                                    >
                                        Batal
                                    </button>
                                    <button
                                        type="submit"
                                        className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
                                    >
                                        Simpan
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                )
            }
        </div >
    );
}
