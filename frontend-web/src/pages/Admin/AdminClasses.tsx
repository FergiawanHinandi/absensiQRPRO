import React, { useMemo, useState } from 'react';
import { School, Plus, Search, Edit, Trash2, CheckCircle, XCircle } from 'lucide-react';
import { useLocation } from 'react-router-dom';
import Loading from '../../components/common/Loading';
import ErrorMessage from '../../components/common/ErrorMessage';
import { useAdminClasses, useAcademicYears, useAdminTeachers } from '../../modules/admin/hooks';
import { apiClient } from '../../lib/api';
import type { ClassModel } from '../../types/Class';
import showToast from '../../utils/toast';

const titleMap: Record<string, string> = {
    '/admin/classes': 'Daftar Kelas',
    '/admin/classes/homeroom': 'Wali Kelas',
};

const AdminClasses: React.FC = () => {
    const location = useLocation();
    const { data, isLoading, error, refetch } = useAdminClasses();
    const { data: academicYears } = useAcademicYears();
    const { data: teachers } = useAdminTeachers();
    const title = titleMap[location.pathname] ?? 'Manajemen Kelas';
    const [searchText, setSearchText] = useState('');
    const [showModal, setShowModal] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [formState, setFormState] = useState({
        name: '',
        grade_level: 1,
        academic_year_id: '',
        homeroom_teacher_id: '',
        max_students: 40,
        classroom: '',
        is_active: true,
    });

    const filteredClasses = useMemo(() => {
        const list = data?.classes ?? [];
        const keyword = searchText.trim().toLowerCase();
        if (!keyword) return list;
        return list.filter((item) =>
            item.name.toLowerCase().includes(keyword)
            || String(item.grade_level).includes(keyword)
            || (item.homeroom_teacher ?? '').toLowerCase().includes(keyword)
        );
    }, [data?.classes, searchText]);

    const resetForm = () => {
        setEditingId(null);
        setFormState({
            name: '',
            grade_level: 1,
            academic_year_id: '',
            homeroom_teacher_id: '',
            max_students: 40,
            classroom: '',
            is_active: true,
        });
    };

    const handleOpenCreate = () => {
        resetForm();
        setShowModal(true);
    };

    const handleEdit = (item: ClassModel) => {
        setEditingId(item.id);
        setFormState({
            name: item.name,
            grade_level: item.grade_level,
            academic_year_id: item.academic_year_id ? String(item.academic_year_id) : '',
            homeroom_teacher_id: item.homeroom_teacher_id ? String(item.homeroom_teacher_id) : '',
            max_students: item.max_students ?? 40,
            classroom: item.classroom ?? '',
            is_active: item.is_active,
        });
        setShowModal(true);
    };

    const handleSubmit = async (event: React.FormEvent) => {
        event.preventDefault();
        const payload = {
            name: formState.name,
            grade_level: Number(formState.grade_level),
            academic_year_id: formState.academic_year_id || null,
            homeroom_teacher_id: formState.homeroom_teacher_id || null,
            max_students: Number(formState.max_students),
            classroom: formState.classroom || null,
            is_active: formState.is_active,
        };

        try {
            if (editingId) {
                await apiClient.put(`/admin/classes/${editingId}`, payload);
            } else {
                await apiClient.post('/admin/classes', payload);
            }
            await refetch();
            setShowModal(false);
            resetForm();
        } catch (err) {
            console.error(err);
            showToast.error('Gagal menyimpan data kelas.');
        }
    };

    const handleToggleStatus = async (item: ClassModel) => {
        if (!window.confirm(`Ubah status kelas ${item.name}?`)) return;
        try {
            await apiClient.patch(`/admin/classes/${item.id}/status`, {
                is_active: !item.is_active,
            });
            await refetch();
        } catch (err) {
            console.error(err);
            showToast.error('Gagal mengubah status kelas.');
        }
    };

    const handleDelete = async (item: ClassModel) => {
        if (!window.confirm(`Hapus kelas ${item.name}?`)) return;
        try {
            await apiClient.delete(`/admin/classes/${item.id}`);
            await refetch();
        } catch (err) {
            console.error(err);
            showToast.error('Gagal menghapus kelas.');
        }
    };

    if (isLoading) {
        return <Loading text="Memuat data kelas..." />;
    }

    if (error) {
        return (
            <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
                <ErrorMessage message="Gagal memuat data kelas" onRetry={() => refetch()} />
            </div>
        );
    }

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
                        <School className="w-6 h-6 text-blue-600" />
                        {title}
                    </h1>
                    <p className="text-sm text-gray-500 mt-1">Daftar kelas dan wali kelas.</p>
                </div>
                <div className="text-sm text-gray-600">Total: {data?.total ?? 0}</div>
            </div>

            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-4 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div className="relative w-full md:max-w-md">
                    <Search className="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" />
                    <input
                        value={searchText}
                        onChange={(event) => setSearchText(event.target.value)}
                        placeholder="Cari kelas atau wali kelas"
                        className="w-full pl-9 pr-3 py-2 text-sm border border-gray-200 rounded-lg"
                    />
                </div>
                <button
                    onClick={handleOpenCreate}
                    className="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 rounded-lg"
                >
                    <Plus className="w-4 h-4" />
                    Tambah Kelas
                </button>
            </div>

            {showModal && (
                <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <div className="flex items-center justify-between mb-4">
                        <h2 className="text-lg font-semibold text-gray-900">
                            {editingId ? 'Edit Kelas' : 'Tambah Kelas'}
                        </h2>
                        <button
                            onClick={() => {
                                setShowModal(false);
                                resetForm();
                            }}
                            className="text-sm text-gray-500 hover:text-gray-700"
                        >
                            Tutup
                        </button>
                    </div>
                    <form onSubmit={handleSubmit} className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label className="text-sm font-medium text-gray-700">Nama Kelas</label>
                            <input
                                value={formState.name}
                                onChange={(event) => setFormState((prev) => ({ ...prev, name: event.target.value }))}
                                className="mt-1 w-full border border-gray-200 rounded-lg px-3 py-2"
                                required
                            />
                        </div>
                        <div>
                            <label className="text-sm font-medium text-gray-700">Tingkat</label>
                            <input
                                type="number"
                                min={1}
                                max={12}
                                value={formState.grade_level}
                                onChange={(event) => setFormState((prev) => ({ ...prev, grade_level: Number(event.target.value) }))}
                                className="mt-1 w-full border border-gray-200 rounded-lg px-3 py-2"
                                required
                            />
                        </div>
                        <div>
                            <label className="text-sm font-medium text-gray-700">Tahun Ajaran</label>
                            <select
                                value={formState.academic_year_id}
                                onChange={(event) => setFormState((prev) => ({ ...prev, academic_year_id: event.target.value }))}
                                className="mt-1 w-full border border-gray-200 rounded-lg px-3 py-2"
                            >
                                <option value="">Aktif (Default)</option>
                                {academicYears?.years?.map((year) => (
                                    <option key={year.id} value={year.id}>{year.name} - Sem {year.semester}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="text-sm font-medium text-gray-700">Wali Kelas</label>
                            <select
                                value={formState.homeroom_teacher_id}
                                onChange={(event) => setFormState((prev) => ({ ...prev, homeroom_teacher_id: event.target.value }))}
                                className="mt-1 w-full border border-gray-200 rounded-lg px-3 py-2"
                            >
                                <option value="">Pilih Guru Wali Kelas</option>
                                {teachers?.data?.map((teacher: any) => (
                                    <option key={teacher.id!} value={teacher.id}>{teacher.name}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="text-sm font-medium text-gray-700">Maks Siswa</label>
                            <input
                                type="number"
                                min={1}
                                max={100}
                                value={formState.max_students}
                                onChange={(event) => setFormState((prev) => ({ ...prev, max_students: Number(event.target.value) }))}
                                className="mt-1 w-full border border-gray-200 rounded-lg px-3 py-2"
                            />
                        </div>
                        <div>
                            <label className="text-sm font-medium text-gray-700">Ruang Kelas</label>
                            <input
                                value={formState.classroom}
                                onChange={(event) => setFormState((prev) => ({ ...prev, classroom: event.target.value }))}
                                className="mt-1 w-full border border-gray-200 rounded-lg px-3 py-2"
                            />
                        </div>
                        <div className="md:col-span-2 flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={formState.is_active}
                                onChange={(event) => setFormState((prev) => ({ ...prev, is_active: event.target.checked }))}
                            />
                            <span className="text-sm text-gray-700">Aktif</span>
                        </div>
                        <div className="md:col-span-2 flex justify-end gap-3">
                            <button
                                type="button"
                                onClick={() => {
                                    setShowModal(false);
                                    resetForm();
                                }}
                                className="px-4 py-2 text-sm font-semibold text-gray-600 border border-gray-200 rounded-lg"
                            >
                                Batal
                            </button>
                            <button
                                type="submit"
                                className="px-4 py-2 text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 rounded-lg"
                            >
                                Simpan
                            </button>
                        </div>
                    </form>
                </div>
            )}

            <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto">
                <table className="min-w-full text-sm">
                    <thead>
                        <tr className="text-left text-gray-500 border-b">
                            <th className="py-3 px-6">Kelas</th>
                            <th className="py-3 px-6">Tingkat</th>
                            <th className="py-3 px-6">Wali Kelas</th>
                            <th className="py-3 px-6">Jumlah Siswa</th>
                            <th className="py-3 px-6">Status</th>
                            <th className="py-3 px-6">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        {filteredClasses.length ? (
                            filteredClasses.map((item) => (
                                <tr key={item.id} className="border-b last:border-0">
                                    <td className="py-3 px-6 font-medium text-gray-900">{item.name}</td>
                                    <td className="py-3 px-6 text-gray-600">{item.grade_level}</td>
                                    <td className="py-3 px-6 text-gray-600">{item.homeroom_teacher ?? '-'}</td>
                                    <td className="py-3 px-6 text-gray-600">{item.total_students}</td>
                                    <td className="py-3 px-6">
                                        <span className={`text-xs font-semibold px-2 py-1 rounded-full ${item.is_active
                                            ? 'bg-green-50 text-green-700'
                                            : 'bg-gray-100 text-gray-600'
                                            }`}>
                                            {item.is_active ? 'Aktif' : 'Nonaktif'}
                                        </span>
                                    </td>
                                    <td className="py-3 px-6">
                                        <div className="flex items-center gap-2">
                                            <button
                                                onClick={() => handleEdit(item as ClassModel)}
                                                className="text-xs font-semibold px-3 py-1 rounded-full border border-blue-200 text-blue-700 hover:bg-blue-50"
                                            >
                                                <Edit className="w-3 h-3 inline mr-1" />
                                                Edit
                                            </button>
                                            <button
                                                onClick={() => handleToggleStatus(item as ClassModel)}
                                                className={`text-xs font-semibold px-3 py-1 rounded-full border ${item.is_active
                                                    ? 'border-red-200 text-red-600 hover:bg-red-50'
                                                    : 'border-green-200 text-green-700 hover:bg-green-50'
                                                    }`}
                                            >
                                                {item.is_active ? (
                                                    <><XCircle className="w-3 h-3 inline mr-1" />Nonaktif</>
                                                ) : (
                                                    <><CheckCircle className="w-3 h-3 inline mr-1" />Aktif</>
                                                )}
                                            </button>
                                            <button
                                                onClick={() => handleDelete(item as ClassModel)}
                                                className="text-xs font-semibold px-3 py-1 rounded-full border border-red-200 text-red-600 hover:bg-red-50"
                                            >
                                                <Trash2 className="w-3 h-3 inline mr-1" />Hapus
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))
                        ) : (
                            <tr>
                                <td colSpan={6} className="py-10 text-center text-sm text-gray-500">
                                    Belum ada data kelas.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
};

export default AdminClasses;
