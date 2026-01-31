import React, { useEffect, useMemo, useState } from 'react';
import { Calendar, Clock, Plus, Search, Edit } from 'lucide-react';
import { useLocation } from 'react-router-dom';
import Loading from '../../components/common/Loading';
import ErrorMessage from '../../components/common/ErrorMessage';
import { useAdminSchedules, useAdminClasses, useAdminSubjects } from '../../modules/admin/hooks';
import { apiClient } from '../../lib/api';
import showToast from '../../utils/toast';

const titleMap: Record<string, string> = {
    '/admin/schedules': 'Jadwal Pelajaran',
    '/admin/schedules/timing': 'Jam Masuk / Pulang',
    '/admin/schedules/holidays': 'Hari Libur Sekolah',
    '/admin/schedules/academic-calendar': 'Kalender Akademik',
};

const AdminSchedules: React.FC = () => {
    const location = useLocation();
    const { data, isLoading, error, refetch } = useAdminSchedules();
    const { data: classData } = useAdminClasses();
    const { data: subjectData } = useAdminSubjects();
    const title = titleMap[location.pathname] ?? 'Jadwal & Kalender';
    const isTiming = location.pathname.includes('/schedules/timing');
    const isHolidays = location.pathname.includes('/schedules/holidays');
    const isAcademicCalendar = location.pathname.includes('/schedules/academic-calendar');
    const [teachers, setTeachers] = useState<{ id: number; name: string }[]>([]);
    const [schoolSettings, setSchoolSettings] = useState<any>(null);
    const [searchText, setSearchText] = useState('');
    const [showModal, setShowModal] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const dayLabels: Record<number, string> = {
        0: 'minggu',
        1: 'senin',
        2: 'selasa',
        3: 'rabu',
        4: 'kamis',
        5: 'jumat',
        6: 'sabtu',
    };
    const [formState, setFormState] = useState({
        day_of_week: 1,
        start_time: '07:00',
        end_time: '09:00',
        class_id: '',
        subject_id: '',
        teacher_id: '',
        room: '',
        is_active: true,
    });

    useEffect(() => {
        const fetchTeachers = async () => {
            try {
                const response = await apiClient.get('/admin/teachers', { params: { per_page: 200 } });
                const list = response.data?.data?.data ?? [];
                setTeachers(list.map((item: { id: number; name: string }) => ({ id: item.id, name: item.name })));
            } catch (err) {
                console.error(err);
            }
        };

        fetchTeachers();
    }, []);

    useEffect(() => {
        const fetchSchoolSettings = async () => {
            try {
                const response = await apiClient.get('/admin/settings/profile');
                if (response.data?.success) {
                    setSchoolSettings(response.data.data?.settings ?? null);
                }
            } catch (err) {
                console.error(err);
            }
        };
        if (isHolidays || isAcademicCalendar || isTiming) {
            fetchSchoolSettings();
        }
    }, [isHolidays, isAcademicCalendar, isTiming]);

    const timingSummary = useMemo(() => {
        const list = data?.schedules ?? [];
        const grouped: Record<number, { day: string; earliest: string; latest: string; total: number }> = {};
        list.forEach((item) => {
            const dayNum: number = typeof item.day_of_week === 'string' ? Number(item.day_of_week) : item.day_of_week;
            const day = dayNum;
            if (!grouped[day]) {
                grouped[day] = { day: dayLabels[day] ?? String(day), earliest: item.start_time, latest: item.end_time, total: 1 };
                return;
            }
            grouped[day].total += 1;
            if (item.start_time < grouped[day].earliest) grouped[day].earliest = item.start_time;
            if (item.end_time > grouped[day].latest) grouped[day].latest = item.end_time;
        });
        return Object.values(grouped);
    }, [data?.schedules]);

    const filteredSchedules = useMemo(() => {
        const list = data?.schedules ?? [];
        const keyword = searchText.trim().toLowerCase();
        if (!keyword) return list;
        return list.filter((item) => {
            const dayNum: number = typeof item.day_of_week === 'string' ? Number(item.day_of_week) : item.day_of_week;
            const dayLabel = (dayLabels[dayNum] ?? String(dayNum)).toLowerCase();
            return dayLabel.includes(keyword)
                || (item.class_name ?? '').toLowerCase().includes(keyword)
                || (item.subject_name ?? '').toLowerCase().includes(keyword)
                || (item.teacher_name ?? '').toLowerCase().includes(keyword);
        });
    }, [data?.schedules, searchText]);

    if (isLoading) {
        return <Loading text="Memuat data jadwal..." />;
    }

    if (error) {
        return (
            <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
                <ErrorMessage message="Gagal memuat data jadwal" onRetry={() => refetch()} />
            </div>
        );
    }

    if (isTiming) {
        return (
            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
                            <Clock className="w-6 h-6 text-blue-600" />
                            {title}
                        </h1>
                        <p className="text-sm text-gray-500 mt-1">Ringkasan jam pelajaran dari jadwal aktif.</p>
                    </div>
                </div>

                <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead>
                            <tr className="text-left text-gray-500 border-b">
                                <th className="py-3 px-6">Hari</th>
                                <th className="py-3 px-6">Mulai</th>
                                <th className="py-3 px-6">Selesai</th>
                                <th className="py-3 px-6">Total Sesi</th>
                            </tr>
                        </thead>
                        <tbody>
                            {timingSummary.length ? (
                                timingSummary.map((item) => (
                                    <tr key={item.day} className="border-b last:border-0">
                                        <td className="py-3 px-6 text-gray-600 capitalize">{item.day}</td>
                                        <td className="py-3 px-6 text-gray-600">{item.earliest}</td>
                                        <td className="py-3 px-6 text-gray-600">{item.latest}</td>
                                        <td className="py-3 px-6 text-gray-600">{item.total}</td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td colSpan={4} className="py-10 text-center text-sm text-gray-500">
                                        Belum ada ringkasan jam pelajaran.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        );
    }

    if (isHolidays) {
        const holidays = schoolSettings?.holidays ?? [];
        return (
            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
                            <Calendar className="w-6 h-6 text-blue-600" />
                            {title}
                        </h1>
                        <p className="text-sm text-gray-500 mt-1">Daftar hari libur dari pengaturan sekolah.</p>
                    </div>
                </div>

                <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    {holidays.length ? (
                        <ul className="text-sm text-gray-700 space-y-2">
                            {holidays.map((item: { name: string; date: string }, index: number) => (
                                <li key={`${item.date ?? index}`} className="flex items-center justify-between border-b pb-2 last:border-0">
                                    <span>{item.name ?? 'Hari Libur'}</span>
                                    <span className="text-gray-500">{item.date ?? '-'}</span>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-sm text-gray-500">Belum ada data hari libur.</p>
                    )}
                </div>
            </div>
        );
    }

    if (isAcademicCalendar) {
        const calendar = schoolSettings?.academic_calendar ?? [];
        return (
            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
                            <Calendar className="w-6 h-6 text-blue-600" />
                            {title}
                        </h1>
                        <p className="text-sm text-gray-500 mt-1">Agenda akademik dari pengaturan sekolah.</p>
                    </div>
                </div>

                <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    {calendar.length ? (
                        <ul className="text-sm text-gray-700 space-y-2">
                            {calendar.map((item: { title: string; date: string }, index: number) => (
                                <li key={`${item.date ?? index}`} className="flex items-center justify-between border-b pb-2 last:border-0">
                                    <span>{item.title ?? 'Agenda'}</span>
                                    <span className="text-gray-500">{item.date ?? '-'}</span>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-sm text-gray-500">Belum ada agenda akademik.</p>
                    )}
                </div>
            </div>
        );
    }

    const resetForm = () => {
        setEditingId(null);
        setFormState({
            day_of_week: 1,
            start_time: '07:00',
            end_time: '09:00',
            class_id: '',
            subject_id: '',
            teacher_id: '',
            room: '',
            is_active: true,
        });
    };

    const handleOpenCreate = () => {
        resetForm();
        setShowModal(true);
    };

    interface ScheduleItem {
        id: number;
        day_of_week: string | number;
        start_time: string;
        end_time: string;
        class_id?: number | null;
        subject_id?: number | null;
        teacher_id?: number | null;
        room?: string | null;
        is_active: boolean;
        class_name?: string | null;
        subject_name?: string | null;
        teacher_name?: string | null;
    }

    const handleEdit = (item: ScheduleItem) => {
        setEditingId(item.id);
        setFormState({
            day_of_week: typeof item.day_of_week === 'string' ? Number(item.day_of_week) : item.day_of_week,
            start_time: item.start_time?.slice(0, 5) ?? '07:00',
            end_time: item.end_time?.slice(0, 5) ?? '09:00',
            class_id: item.class_id?.toString() ?? '',
            subject_id: item.subject_id?.toString() ?? '',
            teacher_id: item.teacher_id?.toString() ?? '',
            room: item.room ?? '',
            is_active: item.is_active,
        });
        setShowModal(true);
    };

    const handleSubmit = async (event: React.FormEvent) => {
        event.preventDefault();
        const payload = {
            day_of_week: Number(formState.day_of_week),
            start_time: formState.start_time,
            end_time: formState.end_time,
            class_id: Number(formState.class_id),
            subject_id: formState.subject_id ? Number(formState.subject_id) : null,
            teacher_id: Number(formState.teacher_id),
            room: formState.room || null,
            is_active: formState.is_active,
        };

        try {
            if (editingId) {
                await apiClient.put(`/admin/schedules/${editingId}`, payload);
            } else {
                await apiClient.post('/admin/schedules', payload);
            }
            await refetch();
            setShowModal(false);
            resetForm();
        } catch (err) {
            console.error(err);
            showToast.error('Gagal menyimpan jadwal.');
        }
    };

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
                        <Calendar className="w-6 h-6 text-blue-600" />
                        {title}
                    </h1>
                    <p className="text-sm text-gray-500 mt-1">Daftar jadwal pelajaran aktif.</p>
                </div>
                <div className="text-sm text-gray-600">Total: {data?.total ?? 0}</div>
            </div>

            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-4 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div className="relative w-full md:max-w-md">
                    <Search className="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" />
                    <input
                        value={searchText}
                        onChange={(event) => setSearchText(event.target.value)}
                        placeholder="Cari jadwal, kelas, guru"
                        className="w-full pl-9 pr-3 py-2 text-sm border border-gray-200 rounded-lg"
                    />
                </div>
                <button
                    onClick={handleOpenCreate}
                    className="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 rounded-lg"
                >
                    <Plus className="w-4 h-4" />
                    Tambah Jadwal
                </button>
            </div>

            {showModal && (
                <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <div className="flex items-center justify-between mb-4">
                        <h2 className="text-lg font-semibold text-gray-900">
                            {editingId ? 'Edit Jadwal' : 'Tambah Jadwal'}
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
                            <label className="text-sm font-medium text-gray-700">Hari</label>
                            <select
                                value={formState.day_of_week}
                                onChange={(event) => setFormState((prev) => ({ ...prev, day_of_week: Number(event.target.value) }))}
                                className="mt-1 w-full border border-gray-200 rounded-lg px-3 py-2"
                            >
                                <option value={1}>Senin</option>
                                <option value={2}>Selasa</option>
                                <option value={3}>Rabu</option>
                                <option value={4}>Kamis</option>
                                <option value={5}>Jumat</option>
                                <option value={6}>Sabtu</option>
                                <option value={0}>Minggu</option>
                            </select>
                        </div>
                        <div>
                            <label className="text-sm font-medium text-gray-700">Jam</label>
                            <div className="mt-1 flex gap-2">
                                <input
                                    type="time"
                                    value={formState.start_time}
                                    onChange={(event) => setFormState((prev) => ({ ...prev, start_time: event.target.value }))}
                                    className="w-full border border-gray-200 rounded-lg px-3 py-2"
                                />
                                <input
                                    type="time"
                                    value={formState.end_time}
                                    onChange={(event) => setFormState((prev) => ({ ...prev, end_time: event.target.value }))}
                                    className="w-full border border-gray-200 rounded-lg px-3 py-2"
                                />
                            </div>
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
                            <label className="text-sm font-medium text-gray-700">Mapel</label>
                            <select
                                value={formState.subject_id}
                                onChange={(event) => setFormState((prev) => ({ ...prev, subject_id: event.target.value }))}
                                className="mt-1 w-full border border-gray-200 rounded-lg px-3 py-2"
                            >
                                <option value="">Tanpa mapel</option>
                                {subjectData?.subjects?.map((item) => (
                                    <option key={item.id} value={item.id}>{item.name}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="text-sm font-medium text-gray-700">Guru</label>
                            <select
                                value={formState.teacher_id}
                                onChange={(event) => setFormState((prev) => ({ ...prev, teacher_id: event.target.value }))}
                                className="mt-1 w-full border border-gray-200 rounded-lg px-3 py-2"
                                required
                            >
                                <option value="">Pilih guru</option>
                                {teachers.map((item) => (
                                    <option key={item.id} value={item.id}>{item.name}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="text-sm font-medium text-gray-700">Ruang</label>
                            <input
                                value={formState.room}
                                onChange={(event) => setFormState((prev) => ({ ...prev, room: event.target.value }))}
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
                            <th className="py-3 px-6">Hari</th>
                            <th className="py-3 px-6">Jam</th>
                            <th className="py-3 px-6">Kelas</th>
                            <th className="py-3 px-6">Mapel</th>
                            <th className="py-3 px-6">Guru</th>
                            <th className="py-3 px-6">Ruang</th>
                            <th className="py-3 px-6">Status</th>
                            <th className="py-3 px-6">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        {filteredSchedules.length ? (
                            filteredSchedules.map((schedule) => (
                                <tr key={schedule.id} className="border-b last:border-0">
                                    <td className="py-3 px-6 text-gray-600 capitalize">{dayLabels[typeof schedule.day_of_week === 'string' ? Number(schedule.day_of_week) : schedule.day_of_week] ?? schedule.day_of_week}</td>
                                    <td className="py-3 px-6 text-gray-600">
                                        <div className="flex items-center gap-2">
                                            <Clock className="w-4 h-4 text-gray-400" />
                                            {schedule.start_time} - {schedule.end_time}
                                        </div>
                                    </td>
                                    <td className="py-3 px-6 text-gray-600">{schedule.class_name ?? '-'}</td>
                                    <td className="py-3 px-6 text-gray-600">{schedule.subject_name ?? '-'}</td>
                                    <td className="py-3 px-6 text-gray-600">{schedule.teacher_name ?? '-'}</td>
                                    <td className="py-3 px-6 text-gray-600">{schedule.room ?? '-'}</td>
                                    <td className="py-3 px-6">
                                        <span className={`text-xs font-semibold px-2 py-1 rounded-full ${schedule.is_active
                                            ? 'bg-green-50 text-green-700'
                                            : 'bg-gray-100 text-gray-600'
                                            }`}>
                                            {schedule.is_active ? 'Aktif' : 'Nonaktif'}
                                        </span>
                                    </td>
                                    <td className="py-3 px-6">
                                        <button
                                            onClick={() => handleEdit(schedule)}
                                            className="text-xs font-semibold px-3 py-1 rounded-full border border-blue-200 text-blue-700 hover:bg-blue-50"
                                        >
                                            <Edit className="w-3 h-3 inline mr-1" />
                                            Edit
                                        </button>
                                    </td>
                                </tr>
                            ))
                        ) : (
                            <tr>
                                <td colSpan={8} className="py-10 text-center text-sm text-gray-500">
                                    Belum ada data jadwal.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
};

export default AdminSchedules;
