import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
    Search,
    Plus,
    User,
    Mail,
    School,
    MoreVertical,
    Edit,
    Upload,
    History,
    Key
} from 'lucide-react';
import { useLocation, Link } from 'react-router-dom';
import { QRCodeSVG } from 'qrcode.react';
import { apiClient } from '../../lib/api';
import { AxiosError } from 'axios';
import type { Student } from '../../types/Student';
import showToast from '../../utils/toast';



interface Class {
    id: number;
    name: string;
}

interface PlacementStudent {
    id: number;
    name: string;
    username: string;
    nisn?: string | null;
    email: string | null;
    class_id?: number | null;
    class_name?: string | null;
}

interface MutationItem {
    id: number;
    student_id: number;
    student_name: string;
    username: string;
    class_name: string;
    status: 'moved' | 'graduated' | 'dropped';
    updated_at: string;
}

export default function AdminStudents() {
    const location = useLocation();
    const [students, setStudents] = useState<Student[]>([]);
    const [classes, setClasses] = useState<Class[]>([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [filterClass, setFilterClass] = useState('');
    const [placementStudents, setPlacementStudents] = useState<PlacementStudent[]>([]);
    const [mutationHistory, setMutationHistory] = useState<MutationItem[]>([]);
    const [placementEdits, setPlacementEdits] = useState<Record<number, string>>({});
    const [schoolProfile, setSchoolProfile] = useState<{ name?: string; npsn?: string } | null>(null);
    const [showModal, setShowModal] = useState(false);
    const [editingStudent, setEditingStudent] = useState<Student | null>(null);
    const [importing, setImporting] = useState(false);
    const fileInputRef = useRef<HTMLInputElement | null>(null);

    const isPlacement = location.pathname.includes('/placement');
    const isCards = location.pathname.includes('/cards');
    const isMutation = location.pathname.includes('/mutation');

    const pageTitle = useMemo(() => {
        if (isPlacement) return 'Penempatan Kelas';
        if (isCards) return 'Kartu Pelajar & QR';
        if (isMutation) return 'Mutasi / Alumni';
        return 'Manajemen Siswa';
    }, [isPlacement, isCards, isMutation]);

    // Form State
    const [formData, setFormData] = useState({
        name: '',
        email: '',
        nis: '',
        nisn: '',
        gender: 'L',
        class_id: '',
        password: '',
    });

    useEffect(() => {
        fetchClasses();
    }, []);

    useEffect(() => {
        if (isPlacement) {
            fetchPlacements();
            return;
        }
        if (isMutation) {
            fetchMutationData();
            return;
        }
        if (isCards) {
            fetchCards();
            return;
        }
        fetchStudents();
    }, [search, filterClass, isPlacement, isMutation, isCards]);

    useEffect(() => {
        const fetchProfile = async () => {
            try {
                const response = await apiClient.get('/admin/settings/profile');
                if (response.data?.success) {
                    setSchoolProfile({
                        name: response.data.data?.name,
                        npsn: response.data.data?.npsn,
                    });
                }
            } catch (error) {
                console.error('Failed to fetch school profile:', error);
            }
        };

        if (isCards) {
            fetchProfile();
        }
    }, [isCards]);

    const fetchClasses = async () => {
        try {
            const response = await apiClient.get('/admin/classes');
            if (response.data.success) {
                const payload = response.data.data;
                const list = Array.isArray(payload)
                    ? payload
                    : (payload?.classes ?? []);
                setClasses(list);
            }
        } catch (error) {
            console.error('Failed to fetch classes:', error);
        }
    };

    const fetchStudents = async () => {
        try {
            setLoading(true);
            const response = await apiClient.get('/admin/students', {
                params: {
                    search: search || undefined,
                    class_id: filterClass || undefined
                },
            });
            if (response.data.success) {
                const payload = response.data.data;
                const list = Array.isArray(payload)
                    ? payload
                    : (payload?.data ?? []);
                setStudents(list);
            }
        } catch (error) {
            console.error('Failed to fetch students:', error);
        } finally {
            setLoading(false);
        }
    };

    const fetchPlacements = async () => {
        try {
            setLoading(true);
            const response = await apiClient.get('/admin/students/placement', {
                params: {
                    search: search || undefined,
                    class_id: filterClass || undefined,
                },
            });
            if (response.data.success) {
                const payload = response.data.data;
                setPlacementStudents(payload?.students ?? []);
            }
        } catch (error) {
            console.error('Failed to fetch placements:', error);
        } finally {
            setLoading(false);
        }
    };

    const fetchMutationHistory = async () => {
        const response = await apiClient.get('/admin/students/mutations', {
            params: {
                search: search || undefined,
            },
        });
        if (response.data.success) {
            const payload = response.data.data;
            setMutationHistory(payload?.mutations ?? []);
        }
    };

    const fetchMutationData = async () => {
        try {
            setLoading(true);
            await Promise.all([fetchPlacements(), fetchMutationHistory()]);
        } catch (error) {
            console.error('Failed to fetch mutation data:', error);
        } finally {
            setLoading(false);
        }
    };

    const fetchCards = async () => {
        await fetchPlacements();
    };

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        try {
            if (editingStudent) {
                await apiClient.put(`/admin/students/${editingStudent.id}`, formData);
                showToast.success('Siswa berhasil diperbarui');
            } else {
                await apiClient.post('/admin/students', formData);
                showToast.success('Siswa berhasil ditambahkan');
            }
            setShowModal(false);
            setEditingStudent(null);
            fetchStudents();
        } catch (err) {
            const error = err as AxiosError<{ message: string }>;
            console.error('Failed to save student:', error);
            showToast.error(error.response?.data?.message || 'Gagal menyimpan data siswa');
        }
    };

    const handleEdit = (student: Student) => {
        setEditingStudent(student);
        setFormData({
            name: student.name,
            email: student.email || '',
            nis: student.nis || '',
            nisn: student.nisn || '',
            gender: student.gender || 'L',
            class_id: student.class_id?.toString() || student.student_class?.id?.toString() || '',
            password: '', // Leave blank unless changing
        });
        setShowModal(true);
    };

    const openModal = () => {
        setEditingStudent(null);
        setFormData({
            name: '',
            email: '',
            nis: '',
            nisn: '',
            gender: 'L',
            class_id: classes.length > 0 ? classes[0].id.toString() : '',
            password: '',
        });
        setShowModal(true);
    };

    const handleImportClick = () => {
        fileInputRef.current?.click();
    };

    const handlePlacementChange = (studentId: number, classId: string) => {
        setPlacementEdits((prev) => ({
            ...prev,
            [studentId]: classId,
        }));
    };

    const handlePlacementSave = async (studentId: number) => {
        const selectedClass = placementEdits[studentId];
        if (!selectedClass) return;
        try {
            await apiClient.patch(`/admin/students/${studentId}/placement`, {
                class_id: Number(selectedClass),
            });
            await fetchPlacements();
        } catch (err) {
            const error = err as AxiosError<{ message: string }>;
            console.error('Failed to update placement:', error);
            showToast.error(error.response?.data?.message || 'Gagal memperbarui penempatan kelas');
        }
    };

    const handleMutationUpdate = async (studentId: number, status: 'moved' | 'graduated' | 'dropped') => {
        try {
            await apiClient.patch(`/admin/students/${studentId}/mutation`, { status });
            await fetchMutationData();
        } catch (err) {
            const error = err as AxiosError<{ message: string }>;
            console.error('Failed to update mutation:', error);
            showToast.error(error.response?.data?.message || 'Gagal memperbarui status mutasi');
        }
    };

    const handleCopyToken = async (token: string) => {
        try {
            await navigator.clipboard.writeText(token);
            showToast.success('Token berhasil disalin');
        } catch (error) {
            console.error('Failed to copy token:', error);
            showToast.error('Gagal menyalin token');
        }
    };

    const handlePrintCards = () => {
        window.print();
    };

    const statusLabel = (status: MutationItem['status']) => {
        if (status === 'graduated') return 'Lulus';
        if (status === 'dropped') return 'Dropout';
        return 'Pindah';
    };

    if (isPlacement) {
        return (
            <div className="min-h-screen bg-slate-50 p-6">
                <div className="mb-6">
                    <h1 className="text-2xl font-bold text-slate-900 mb-2">{pageTitle}</h1>
                    <p className="text-slate-600">Atur penempatan kelas siswa berdasarkan data kelas aktif.</p>
                </div>

                <div className="bg-white rounded-lg shadow-sm border border-slate-200 p-4 mb-6">
                    <div className="flex flex-col md:flex-row gap-4 justify-between items-center">
                        <div className="flex flex-1 gap-4 w-full">
                            <div className="relative flex-1 max-w-md">
                                <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-slate-400" />
                                <input
                                    type="text"
                                    placeholder="Cari nama atau username..."
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    className="w-full pl-10 pr-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                />
                            </div>
                            <div className="relative w-48">
                                <School className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-slate-400" />
                                <select
                                    value={filterClass}
                                    onChange={(e) => setFilterClass(e.target.value)}
                                    className="w-full pl-10 pr-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 appearance-none bg-white"
                                >
                                    <option value="">Semua Kelas</option>
                                    {classes.map(cls => (
                                        <option key={cls.id} value={cls.id}>{cls.name}</option>
                                    ))}
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div className="bg-white rounded-xl shadow-sm border border-slate-200 overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead>
                            <tr className="text-left text-slate-500 border-b">
                                <th className="py-3 px-6">Siswa</th>
                                <th className="py-3 px-6">Username</th>
                                <th className="py-3 px-6">Kelas Saat Ini</th>
                                <th className="py-3 px-6">Ubah Kelas</th>
                                <th className="py-3 px-6">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr>
                                    <td colSpan={5} className="py-8 text-center text-slate-500">Memuat data...</td>
                                </tr>
                            ) : placementStudents.length === 0 ? (
                                <tr>
                                    <td colSpan={5} className="py-8 text-center text-slate-500">Belum ada data penempatan.</td>
                                </tr>
                            ) : (
                                placementStudents.map((student) => (
                                    <tr key={student.id} className="border-b last:border-0">
                                        <td className="py-3 px-6 text-slate-900 font-medium">{student.name}</td>
                                        <td className="py-3 px-6 text-slate-600">{student.username}</td>
                                        <td className="py-3 px-6 text-slate-600">{student.class_name ?? '-'}</td>
                                        <td className="py-3 px-6">
                                            <select
                                                value={placementEdits[student.id] ?? (student.class_id?.toString() ?? '')}
                                                onChange={(e) => handlePlacementChange(student.id, e.target.value)}
                                                className="w-full border border-slate-300 rounded-lg px-3 py-2"
                                            >
                                                <option value="">Pilih Kelas</option>
                                                {classes.map(cls => (
                                                    <option key={cls.id} value={cls.id}>{cls.name}</option>
                                                ))}
                                            </select>
                                        </td>
                                        <td className="py-3 px-6">
                                            <button
                                                onClick={() => handlePlacementSave(student.id)}
                                                className="px-3 py-1.5 text-xs font-semibold rounded-lg bg-blue-600 text-white hover:bg-blue-700"
                                            >
                                                Simpan
                                            </button>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        );
    }

    if (isCards) {
        return (
            <div className="min-h-screen bg-slate-50 p-6">
                <style>{`
                    @media print {
                        body { background: #fff !important; }
                        .print-hidden { display: none !important; }
                        .print-area { display: block !important; }
                        .print-card { break-inside: avoid; page-break-inside: avoid; }
                    }
                `}</style>
                <div className="mb-6">
                    <h1 className="text-2xl font-bold text-slate-900 mb-2">{pageTitle}</h1>
                    <p className="text-slate-600">QR statik berisi NISN & nama siswa, tidak berubah sampai siswa lulus.</p>
                </div>

                <div className="bg-white rounded-lg shadow-sm border border-slate-200 p-4 mb-6 print-hidden">
                    <div className="flex flex-col md:flex-row gap-4 justify-between items-center">
                        <div className="flex flex-1 gap-4 w-full">
                            <div className="relative flex-1 max-w-md">
                                <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-slate-400" />
                                <input
                                    type="text"
                                    placeholder="Cari nama atau username..."
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    className="w-full pl-10 pr-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                />
                            </div>
                            <div className="relative w-48">
                                <School className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-slate-400" />
                                <select
                                    value={filterClass}
                                    onChange={(e) => setFilterClass(e.target.value)}
                                    className="w-full pl-10 pr-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 appearance-none bg-white"
                                >
                                    <option value="">Semua Kelas</option>
                                    {classes.map(cls => (
                                        <option key={cls.id} value={cls.id}>{cls.name}</option>
                                    ))}
                                </select>
                            </div>
                        </div>
                        <button
                            onClick={handlePrintCards}
                            className="px-4 py-2 text-sm font-semibold text-white bg-blue-600 rounded-lg hover:bg-blue-700"
                        >
                            Print Kartu
                        </button>
                    </div>
                </div>

                {loading ? (
                    <div className="bg-white rounded-lg border border-slate-200 p-6 text-center text-slate-500">Memuat data...</div>
                ) : placementStudents.length === 0 ? (
                    <div className="bg-white rounded-lg border border-slate-200 p-6 text-center text-slate-500">Belum ada data siswa.</div>
                ) : (
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 print-area">
                        {placementStudents.map((student) => (
                            <div key={student.id} className="space-y-3 print-card">
                                <div className="bg-[#1E2B4F] rounded-2xl p-5 text-white relative overflow-hidden">
                                    <div className="absolute inset-0 opacity-20 bg-gradient-to-br from-blue-400 to-transparent" />
                                    <div className="relative z-10">
                                        <div className="flex items-start justify-between">
                                            <div className="flex items-center gap-3">
                                                <div className="w-10 h-10 rounded-lg bg-white/20 flex items-center justify-center text-lg font-bold">
                                                    {student.name.charAt(0)}
                                                </div>
                                                <div>
                                                    <div className="text-xs uppercase tracking-widest text-blue-200">Kartu Pelajar</div>
                                                    <div className="font-semibold">{schoolProfile?.name ?? 'SMP Negeri 1'}</div>
                                                    <div className="text-xs text-blue-200">NPSN: {schoolProfile?.npsn ?? '-'}</div>
                                                </div>
                                            </div>
                                            <div className="text-green-300">●</div>
                                        </div>

                                        <div className="mt-6 flex items-center justify-between gap-4">
                                            <div className="flex-1">
                                                <div className="text-lg font-bold">{student.name}</div>
                                                <div className="text-sm text-blue-200">NISN: {student.nisn ?? '-'}</div>
                                                <div className="text-sm text-blue-200">Kelas: {student.class_name ?? '-'}</div>
                                            </div>
                                            <div className="bg-white rounded-lg p-2">
                                                <QRCodeSVG
                                                    value={JSON.stringify({
                                                        nisn: student.nisn ?? null,
                                                        name: student.name,
                                                        username: student.username,
                                                    })}
                                                    size={72}
                                                />
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div className="bg-white rounded-2xl border border-slate-200 p-4">
                                    <div className="text-sm font-semibold text-slate-900 mb-2">Aturan Penggunaan</div>
                                    <ol className="text-xs text-slate-600 space-y-1 list-decimal list-inside">
                                        <li>Kartu dibawa setiap hari dan ditunjukkan saat diminta.</li>
                                        <li>QR hanya untuk identifikasi siswa, tidak boleh dipindahtangankan.</li>
                                        <li>Jika kartu hilang, segera lapor ke wali kelas.</li>
                                        <li>Kartu berlaku selama siswa aktif di sekolah.</li>
                                    </ol>
                                    <div className="mt-3 text-[10px] text-slate-400">ID: {student.username}</div>
                                </div>

                                <button
                                    onClick={() => handleCopyToken(student.nisn ?? student.username)}
                                    className="print-hidden w-full px-3 py-2 text-xs font-semibold text-blue-700 border border-blue-200 rounded-lg hover:bg-blue-50"
                                >
                                    Salin Token
                                </button>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        );
    }

    if (isMutation) {
        return (
            <div className="min-h-screen bg-slate-50 p-6">
                <div className="mb-6">
                    <h1 className="text-2xl font-bold text-slate-900 mb-2">{pageTitle}</h1>
                    <p className="text-slate-600">Kelola status mutasi, alumni, atau siswa keluar.</p>
                </div>

                <div className="bg-white rounded-lg shadow-sm border border-slate-200 p-4 mb-6">
                    <div className="flex flex-col md:flex-row gap-4 justify-between items-center">
                        <div className="flex flex-1 gap-4 w-full">
                            <div className="relative flex-1 max-w-md">
                                <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-slate-400" />
                                <input
                                    type="text"
                                    placeholder="Cari nama atau username..."
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    className="w-full pl-10 pr-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <div className="bg-white rounded-xl shadow-sm border border-slate-200 overflow-x-auto mb-6">
                    <table className="min-w-full text-sm">
                        <thead>
                            <tr className="text-left text-slate-500 border-b">
                                <th className="py-3 px-6">Siswa Aktif</th>
                                <th className="py-3 px-6">Kelas</th>
                                <th className="py-3 px-6">Aksi Mutasi</th>
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr>
                                    <td colSpan={3} className="py-8 text-center text-slate-500">Memuat data...</td>
                                </tr>
                            ) : placementStudents.length === 0 ? (
                                <tr>
                                    <td colSpan={3} className="py-8 text-center text-slate-500">Belum ada siswa aktif.</td>
                                </tr>
                            ) : (
                                placementStudents.map((student) => (
                                    <tr key={student.id} className="border-b last:border-0">
                                        <td className="py-3 px-6 text-slate-900 font-medium">{student.name}</td>
                                        <td className="py-3 px-6 text-slate-600">{student.class_name ?? '-'}</td>
                                        <td className="py-3 px-6">
                                            <div className="flex gap-2">
                                                <button
                                                    onClick={() => handleMutationUpdate(student.id, 'moved')}
                                                    className="px-3 py-1.5 text-xs font-semibold rounded-lg border border-amber-200 text-amber-700 hover:bg-amber-50"
                                                >
                                                    Pindah
                                                </button>
                                                <button
                                                    onClick={() => handleMutationUpdate(student.id, 'graduated')}
                                                    className="px-3 py-1.5 text-xs font-semibold rounded-lg border border-green-200 text-green-700 hover:bg-green-50"
                                                >
                                                    Lulus
                                                </button>
                                                <button
                                                    onClick={() => handleMutationUpdate(student.id, 'dropped')}
                                                    className="px-3 py-1.5 text-xs font-semibold rounded-lg border border-rose-200 text-rose-700 hover:bg-rose-50"
                                                >
                                                    Dropout
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                <div className="bg-white rounded-xl shadow-sm border border-slate-200 overflow-x-auto">
                    <div className="px-6 py-4 border-b border-slate-200 flex items-center gap-2 text-slate-700 font-semibold">
                        <History className="w-4 h-4" />
                        Riwayat Mutasi
                    </div>
                    <table className="min-w-full text-sm">
                        <thead>
                            <tr className="text-left text-slate-500 border-b">
                                <th className="py-3 px-6">Siswa</th>
                                <th className="py-3 px-6">Kelas</th>
                                <th className="py-3 px-6">Status</th>
                                <th className="py-3 px-6">Tanggal</th>
                            </tr>
                        </thead>
                        <tbody>
                            {mutationHistory.length === 0 ? (
                                <tr>
                                    <td colSpan={4} className="py-8 text-center text-slate-500">Belum ada riwayat mutasi.</td>
                                </tr>
                            ) : (
                                mutationHistory.map((item) => (
                                    <tr key={item.id} className="border-b last:border-0">
                                        <td className="py-3 px-6 text-slate-900">{item.student_name}</td>
                                        <td className="py-3 px-6 text-slate-600">{item.class_name}</td>
                                        <td className="py-3 px-6">
                                            <span className="text-xs font-semibold px-2 py-1 rounded-full bg-slate-100 text-slate-600">
                                                {statusLabel(item.status)}
                                            </span>
                                        </td>
                                        <td className="py-3 px-6 text-slate-600">{item.updated_at}</td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        );
    }

    const handleImportFile = async (event: React.ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];
        if (!file) return;

        setImporting(true);
        try {
            const formData = new FormData();
            formData.append('file', file);

            const response = await apiClient.post('/admin/students/import', formData, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });

            if (response.data.success) {
                const info = response.data.data;
                showToast.success(`Import selesai. Berhasil: menerima ${info.created}, dilewati ${info.skipped}.`);
                fetchStudents();
            }
        } catch (err) {
            const error = err as AxiosError<{ message: string }>;
            console.error('Import failed:', error);
            showToast.error(error.response?.data?.message || 'Gagal import siswa');
        } finally {
            setImporting(false);
            if (fileInputRef.current) {
                fileInputRef.current.value = '';
            }
        }
    };

    return (
        <div className="min-h-screen bg-slate-50 p-6">
            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 mb-2">{pageTitle}</h1>
                <p className="text-slate-600">Kelola data siswa, penempatan kelas, dan informasi akademik</p>
            </div>

            {/* Toolbar */}
            <div className="bg-white rounded-lg shadow-sm border border-slate-200 p-4 mb-6">
                <div className="flex flex-col md:flex-row gap-4 justify-between items-center">
                    <div className="flex flex-1 gap-4 w-full">
                        {/* Search */}
                        <div className="relative flex-1 max-w-md">
                            <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-slate-400" />
                            <input
                                type="text"
                                placeholder="Cari nama, NIS, atau NISN..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="w-full pl-10 pr-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                            />
                        </div>

                        {/* Class Filter */}
                        <div className="relative w-48">
                            <School className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-slate-400" />
                            <select
                                value={filterClass}
                                onChange={(e) => setFilterClass(e.target.value)}
                                className="w-full pl-10 pr-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 appearance-none bg-white"
                            >
                                <option value="">Semua Kelas</option>
                                {classes.map(cls => (
                                    <option key={cls.id} value={cls.id}>{cls.name}</option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <button
                        onClick={openModal}
                        className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors whitespace-nowrap"
                    >
                        <Plus className="w-5 h-5" />
                        <span>Tambah Siswa</span>
                    </button>
                    <button
                        onClick={handleImportClick}
                        disabled={importing}
                        className="flex items-center gap-2 px-4 py-2 border border-blue-200 text-blue-600 rounded-lg hover:bg-blue-50 transition-colors whitespace-nowrap"
                    >
                        <Upload className="w-5 h-5" />
                        <span>{importing ? 'Mengimpor...' : 'Import Siswa'}</span>
                    </button>
                </div>
                <input
                    ref={fileInputRef}
                    type="file"
                    accept=".csv"
                    className="hidden"
                    onChange={handleImportFile}
                />
            </div>

            {/* Students Grid */}
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                {loading ? (
                    <div className="col-span-full flex justify-center py-12">
                        <div className="w-8 h-8 border-4 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
                    </div>
                ) : students.length === 0 ? (
                    <div className="col-span-full text-center py-12 text-slate-500 bg-white rounded-lg border border-slate-200">
                        {filterClass ? 'Tidak ada siswa di kelas ini' : 'Belum ada data siswa'}
                    </div>
                ) : (
                    students.map((student) => (
                        <div key={student.id} className="bg-white rounded-xl shadow-sm border border-slate-200 p-6 hover:shadow-md transition-shadow">
                            <div className="flex justify-between items-start mb-4">
                                <div className="flex items-center gap-4">
                                    <div className="w-12 h-12 rounded-full bg-purple-100 flex items-center justify-center text-purple-600 font-bold text-lg">
                                        {student.name.charAt(0)}
                                    </div>
                                    <div>
                                        <h3 className="font-bold text-slate-900">{student.name}</h3>
                                        <div className="flex items-center gap-2 text-sm text-slate-500">
                                            <span className="font-mono bg-slate-100 px-1.5 py-0.5 rounded text-xs">{student.nis}</span>
                                        </div>
                                    </div>
                                </div>
                                <div className="relative group">
                                    <button className="p-2 hover:bg-slate-100 rounded-full">
                                        <MoreVertical className="w-5 h-5 text-slate-400" />
                                    </button>
                                    <div className="absolute right-0 mt-1 w-48 bg-white border border-slate-200 rounded-lg shadow-lg hidden group-hover:block z-10">
                                        <button
                                            onClick={() => handleEdit(student)}
                                            className="w-full text-left px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 flex items-center gap-2"
                                        >
                                            <Edit className="w-4 h-4" /> Edit Data
                                        </button>
                                        <Link
                                            to={`/admin/accounts/generate?search=${encodeURIComponent(student.name)}&tab=student`}
                                            className="w-full text-left px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 flex items-center gap-2"
                                        >
                                            <Key className="w-4 h-4" /> Kelola Akun
                                        </Link>
                                    </div>
                                </div>
                            </div>

                            <div className="space-y-2 mb-4">
                                <div className="flex items-center gap-2 text-sm text-slate-600">
                                    <School className="w-4 h-4" />
                                    <span>{student.student_class?.name || 'Belum masuk kelas'}</span>
                                </div>
                                <div className="flex items-center gap-2 text-sm text-slate-600">
                                    <Mail className="w-4 h-4" />
                                    <span>{student.email}</span>
                                </div>
                                <div className="flex items-center gap-2 text-sm text-slate-600">
                                    <User className="w-4 h-4" />
                                    <span>{student.gender === 'L' ? 'Laki-laki' : 'Perempuan'}</span>
                                </div>
                            </div>

                            {/* Status footer if needed */}
                        </div>
                    ))
                )}
            </div>

            {/* Modal */}
            {showModal && (
                <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
                    <div className="bg-white rounded-xl shadow-xl max-w-lg w-full max-h-[90vh] overflow-y-auto">
                        <div className="p-6 border-b border-slate-200">
                            <h2 className="text-xl font-bold text-slate-900">
                                {editingStudent ? 'Edit Data Siswa' : 'Tambah Siswa Baru'}
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
                                    <label className="block text-sm font-medium text-slate-700 mb-1">NIS (Username)</label>
                                    <input
                                        type="text"
                                        required
                                        value={formData.nis}
                                        onChange={(e) => setFormData({ ...formData, nis: e.target.value })}
                                        className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                    />
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-slate-700 mb-1">NISN</label>
                                    <input
                                        type="text"
                                        value={formData.nisn}
                                        onChange={(e) => setFormData({ ...formData, nisn: e.target.value })}
                                        className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                    />
                                </div>
                            </div>

                            <div className="grid grid-cols-2 gap-4">
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
                                    <label className="block text-sm font-medium text-slate-700 mb-1">Kelas</label>
                                    <select
                                        required
                                        value={formData.class_id}
                                        onChange={(e) => setFormData({ ...formData, class_id: e.target.value })}
                                        className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                    >
                                        <option value="">Pilih Kelas</option>
                                        {classes.map(cls => (
                                            <option key={cls.id} value={cls.id}>{cls.name}</option>
                                        ))}
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-slate-700 mb-1">
                                    Password {editingStudent && '(Kosongkan jika tidak diubah)'}
                                </label>
                                <input
                                    type="password"
                                    minLength={6}
                                    required={!editingStudent}
                                    value={formData.password}
                                    onChange={(e) => setFormData({ ...formData, password: e.target.value })}
                                    className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                    placeholder={editingStudent ? '••••••' : 'Minimal 6 karakter'}
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
            )}
        </div>
    );
}
