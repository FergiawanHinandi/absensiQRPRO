import React, { useState } from 'react';
import { Clock, AlertTriangle, Download, Filter, Search, User } from 'lucide-react';
import { useLateAlpha } from '../../modules/admin/hooks';
import Loading from '../../components/common/Loading';
import ErrorMessage from '../../components/common/ErrorMessage';

const AdminLateAlpha: React.FC = () => {
    const { data, isLoading, error, refetch } = useLateAlpha();
    const [activeTab, setActiveTab] = useState<'late' | 'alpha'>('late');
    const [searchQuery, setSearchQuery] = useState('');

    if (isLoading) return <Loading text="Memuat data siswa..." />;
    if (error) return <ErrorMessage message="Gagal memuat data" onRetry={refetch} />;

    const filterStudents = (students: any[]) => {
        if (!searchQuery) return students;
        return students.filter(
            (student) =>
                student.student_name.toLowerCase().includes(searchQuery.toLowerCase()) ||
                student.class_name.toLowerCase().includes(searchQuery.toLowerCase()) ||
                student.username.toLowerCase().includes(searchQuery.toLowerCase())
        );
    };

    const lateStudents = filterStudents(data?.late?.students || []);
    const alphaStudents = filterStudents(data?.alpha?.students || []);

    return (
        <div className="p-6 space-y-6">
            {/* Header */}
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">Siswa Terlambat & Alfa</h1>
                    <p className="text-sm text-gray-600 mt-1">
                        Monitoring keterlambatan dan ketidakhadiran siswa - {data?.date}
                    </p>
                </div>
                <button className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                    <Download className="w-4 h-4" />
                    <span>Export</span>
                </button>
            </div>

            {/* Summary Cards */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div
                    className={`bg-white p-6 rounded-xl shadow-sm border-2 cursor-pointer transition-all ${activeTab === 'late' ? 'border-yellow-500' : 'border-gray-100 hover:border-yellow-200'
                        }`}
                    onClick={() => setActiveTab('late')}
                >
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-3">
                            <div className="p-3 bg-yellow-50 rounded-lg">
                                <Clock className="w-6 h-6 text-yellow-600" />
                            </div>
                            <div>
                                <p className="text-sm text-gray-600">Siswa Terlambat</p>
                                <p className="text-3xl font-bold text-yellow-600">{data?.late?.total || 0}</p>
                            </div>
                        </div>
                        {activeTab === 'late' && (
                            <div className="w-3 h-3 bg-yellow-500 rounded-full"></div>
                        )}
                    </div>
                </div>

                <div
                    className={`bg-white p-6 rounded-xl shadow-sm border-2 cursor-pointer transition-all ${activeTab === 'alpha' ? 'border-red-500' : 'border-gray-100 hover:border-red-200'
                        }`}
                    onClick={() => setActiveTab('alpha')}
                >
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-3">
                            <div className="p-3 bg-red-50 rounded-lg">
                                <AlertTriangle className="w-6 h-6 text-red-600" />
                            </div>
                            <div>
                                <p className="text-sm text-gray-600">Siswa Alfa (Belum Absen)</p>
                                <p className="text-3xl font-bold text-red-600">{data?.alpha?.total || 0}</p>
                            </div>
                        </div>
                        {activeTab === 'alpha' && (
                            <div className="w-3 h-3 bg-red-500 rounded-full"></div>
                        )}
                    </div>
                </div>
            </div>

            {/* Search & Filter */}
            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                <div className="flex flex-col md:flex-row gap-4">
                    <div className="flex-1 relative">
                        <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-400" />
                        <input
                            type="text"
                            placeholder="Cari nama siswa, kelas, atau username..."
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        />
                    </div>
                    <button className="flex items-center gap-2 px-4 py-2 bg-slate-100 text-slate-700 rounded-lg hover:bg-slate-200 transition-colors">
                        <Filter className="w-4 h-4" />
                        <span>Filter Kelas</span>
                    </button>
                </div>
            </div>

            {/* Students List */}
            <div className="bg-white rounded-xl shadow-sm border border-gray-100">
                <div className="p-6 border-b border-gray-200">
                    <h2 className="text-lg font-bold text-gray-900">
                        {activeTab === 'late' ? 'Daftar Siswa Terlambat' : 'Daftar Siswa Alfa'}
                    </h2>
                    <p className="text-sm text-gray-600 mt-1">
                        {activeTab === 'late'
                            ? `${lateStudents.length} siswa terlambat hari ini`
                            : `${alphaStudents.length} siswa belum melakukan absensi`}
                    </p>
                </div>

                <div className="divide-y divide-gray-200">
                    {activeTab === 'late' ? (
                        lateStudents.length > 0 ? (
                            lateStudents.map((student, index) => (
                                <div
                                    key={`${student.student_id}-late-${index}`}
                                    className="p-6 hover:bg-yellow-50 transition-colors"
                                >
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-4 flex-1">
                                            {/* Avatar */}
                                            <div className="w-12 h-12 rounded-full bg-gradient-to-br from-yellow-500 to-orange-600 flex items-center justify-center text-white font-bold text-lg flex-shrink-0">
                                                {student.student_name.substring(0, 2).toUpperCase()}
                                            </div>

                                            {/* Info */}
                                            <div className="flex-1">
                                                <h3 className="font-semibold text-gray-900 text-lg mb-1">
                                                    {student.student_name}
                                                </h3>
                                                <div className="flex flex-wrap gap-3 text-sm text-gray-600">
                                                    <span className="flex items-center gap-1">
                                                        <User className="w-4 h-4" />
                                                        {student.username}
                                                    </span>
                                                    <span>•</span>
                                                    <span className="font-medium text-blue-600">
                                                        {student.class_name}
                                                    </span>
                                                    {student.time && (
                                                        <>
                                                            <span>•</span>
                                                            <span className="flex items-center gap-1 text-yellow-700 font-medium">
                                                                <Clock className="w-4 h-4" />
                                                                Absen: {student.time}
                                                            </span>
                                                        </>
                                                    )}
                                                </div>
                                            </div>
                                        </div>

                                        {/* Badge */}
                                        <span className="px-4 py-2 bg-yellow-100 text-yellow-800 rounded-full text-sm font-semibold">
                                            Terlambat
                                        </span>
                                    </div>
                                </div>
                            ))
                        ) : (
                            <div className="p-12 text-center">
                                <div className="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <Clock className="w-8 h-8 text-green-600" />
                                </div>
                                <h3 className="text-lg font-semibold text-gray-900 mb-2">
                                    Tidak Ada Siswa Terlambat
                                </h3>
                                <p className="text-gray-600">
                                    {searchQuery
                                        ? 'Tidak ditemukan siswa dengan kriteria pencarian'
                                        : 'Semua siswa hadir tepat waktu hari ini'}
                                </p>
                            </div>
                        )
                    ) : alphaStudents.length > 0 ? (
                        alphaStudents.map((student, index) => (
                            <div
                                key={`${student.student_id}-alpha-${index}`}
                                className="p-6 hover:bg-red-50 transition-colors"
                            >
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-4 flex-1">
                                        {/* Avatar */}
                                        <div className="w-12 h-12 rounded-full bg-gradient-to-br from-red-500 to-pink-600 flex items-center justify-center text-white font-bold text-lg flex-shrink-0">
                                            {student.student_name.substring(0, 2).toUpperCase()}
                                        </div>

                                        {/* Info */}
                                        <div className="flex-1">
                                            <h3 className="font-semibold text-gray-900 text-lg mb-1">
                                                {student.student_name}
                                            </h3>
                                            <div className="flex flex-wrap gap-3 text-sm text-gray-600">
                                                <span className="flex items-center gap-1">
                                                    <User className="w-4 h-4" />
                                                    {student.username}
                                                </span>
                                                <span>•</span>
                                                <span className="font-medium text-blue-600">
                                                    {student.class_name}
                                                </span>
                                            </div>
                                            <div className="mt-2 flex items-start gap-2 p-2 bg-red-50 border border-red-200 rounded-lg">
                                                <AlertTriangle className="w-4 h-4 text-red-600 mt-0.5 flex-shrink-0" />
                                                <p className="text-xs text-red-800">
                                                    Siswa ini belum melakukan absensi hari ini. Segera hubungi wali kelas atau orang tua.
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Badge */}
                                    <span className="px-4 py-2 bg-red-100 text-red-800 rounded-full text-sm font-semibold">
                                        Alfa
                                    </span>
                                </div>
                            </div>
                        ))
                    ) : (
                        <div className="p-12 text-center">
                            <div className="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                <AlertTriangle className="w-8 h-8 text-green-600" />
                            </div>
                            <h3 className="text-lg font-semibold text-gray-900 mb-2">
                                Tidak Ada Siswa Alfa
                            </h3>
                            <p className="text-gray-600">
                                {searchQuery
                                    ? 'Tidak ditemukan siswa dengan kriteria pencarian'
                                    : 'Semua siswa sudah melakukan absensi hari ini'}
                            </p>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
};

export default AdminLateAlpha;
