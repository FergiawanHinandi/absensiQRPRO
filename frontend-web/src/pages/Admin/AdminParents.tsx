import React from 'react';
import { Baby, Key } from 'lucide-react';
import { useLocation, Link } from 'react-router-dom';
import Loading from '../../components/common/Loading';
import ErrorMessage from '../../components/common/ErrorMessage';
import { useAdminParents } from '../../modules/admin/hooks';

const titleMap: Record<string, string> = {
    '/admin/parents': 'Akun Orang Tua',
    '/admin/parents/relations': 'Relasi Orang Tua ↔ Siswa',
    '/admin/parents/notifications': 'Hak Akses Notifikasi',
};

const AdminParents: React.FC = () => {
    const location = useLocation();
    const { data, isLoading, error, refetch } = useAdminParents();
    const title = titleMap[location.pathname] ?? 'Orang Tua';
    const isRelations = location.pathname.includes('/parents/relations');
    const isNotifications = location.pathname.includes('/parents/notifications');

    if (isLoading) {
        return <Loading text="Memuat data orang tua..." />;
    }

    if (error) {
        return (
            <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
                <ErrorMessage message="Gagal memuat data orang tua" onRetry={() => refetch()} />
            </div>
        );
    }

    if (isRelations) {
        return (
            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
                            <Baby className="w-6 h-6 text-blue-600" />
                            {title}
                        </h1>
                        <p className="text-sm text-gray-500 mt-1">Relasi orang tua dan siswa berdasarkan akun terdaftar.</p>
                    </div>
                    <div className="text-sm text-gray-600">Total: {data?.total ?? 0}</div>
                </div>

                <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead>
                            <tr className="text-left text-gray-500 border-b">
                                <th className="py-3 px-6">Nama Orang Tua</th>
                                <th className="py-3 px-6">Username</th>
                                <th className="py-3 px-6">Email</th>
                                <th className="py-3 px-6">Relasi Siswa</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data?.parents?.length ? (
                                data.parents.map((parent) => (
                                    <tr key={parent.id} className="border-b last:border-0">
                                        <td className="py-3 px-6 font-medium text-gray-900">{parent.name}</td>
                                        <td className="py-3 px-6 text-gray-600">{parent.username}</td>
                                        <td className="py-3 px-6 text-gray-600">{parent.email ?? '-'}</td>
                                        <td className="py-3 px-6 text-gray-600">-</td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td colSpan={4} className="py-10 text-center text-sm text-gray-500">
                                        Belum ada data relasi.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        );
    }

    if (isNotifications) {
        return (
            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
                            <Baby className="w-6 h-6 text-blue-600" />
                            {title}
                        </h1>
                        <p className="text-sm text-gray-500 mt-1">Status notifikasi untuk akun orang tua.</p>
                    </div>
                    <div className="text-sm text-gray-600">Total: {data?.total ?? 0}</div>
                </div>

                <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead>
                            <tr className="text-left text-gray-500 border-b">
                                <th className="py-3 px-6">Nama</th>
                                <th className="py-3 px-6">Email</th>
                                <th className="py-3 px-6">Status</th>
                                <th className="py-3 px-6">Notifikasi</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data?.parents?.length ? (
                                data.parents.map((parent) => (
                                    <tr key={parent.id} className="border-b last:border-0">
                                        <td className="py-3 px-6 font-medium text-gray-900">{parent.name}</td>
                                        <td className="py-3 px-6 text-gray-600">{parent.email ?? '-'}</td>
                                        <td className="py-3 px-6">
                                            <span className={`text-xs font-semibold px-2 py-1 rounded-full ${parent.is_active
                                                ? 'bg-green-50 text-green-700'
                                                : 'bg-gray-100 text-gray-600'
                                                }`}>
                                                {parent.is_active ? 'Aktif' : 'Nonaktif'}
                                            </span>
                                        </td>
                                        <td className="py-3 px-6 text-gray-600">-</td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td colSpan={4} className="py-10 text-center text-sm text-gray-500">
                                        Belum ada data notifikasi.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
                        <Baby className="w-6 h-6 text-blue-600" />
                        {title}
                    </h1>
                    <p className="text-sm text-gray-500 mt-1">Daftar akun orang tua terdaftar.</p>
                </div>
                <div className="text-sm text-gray-600">Total: {data?.total ?? 0}</div>
            </div>

            <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto">
                <table className="min-w-full text-sm">
                    <thead>
                        <tr className="text-left text-gray-500 border-b">
                            <th className="py-3 px-6">Nama</th>
                            <th className="py-3 px-6">Username</th>
                            <th className="py-3 px-6">Email</th>
                            <th className="py-3 px-6">Status</th>
                            <th className="py-3 px-6 text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        {data?.parents?.length ? (
                            data.parents.map((parent) => (
                                <tr key={parent.id} className="border-b last:border-0">
                                    <td className="py-3 px-6 font-medium text-gray-900">{parent.name}</td>
                                    <td className="py-3 px-6 text-gray-600">{parent.username}</td>
                                    <td className="py-3 px-6 text-gray-600">{parent.email ?? '-'}</td>
                                    <td className="py-3 px-6">
                                        <span className={`text-xs font-semibold px-2 py-1 rounded-full ${parent.is_active
                                            ? 'bg-green-50 text-green-700'
                                            : 'bg-gray-100 text-gray-600'
                                            }`}>
                                            {parent.is_active ? 'Aktif' : 'Nonaktif'}
                                        </span>
                                    </td>
                                    <td className="py-3 px-6 text-center">
                                        <Link
                                            to={`/admin/accounts/generate?search=${encodeURIComponent(parent.name)}&tab=parent`}
                                            className="p-1.5 inline-block bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 hover:text-blue-600 rounded-lg transition-colors"
                                            title="Kelola Akun"
                                        >
                                            <Key className="w-4 h-4" />
                                        </Link>
                                    </td>
                                </tr>
                            ))
                        ) : (
                            <tr>
                                <td colSpan={4} className="py-10 text-center text-sm text-gray-500">
                                    Belum ada data orang tua.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
};

export default AdminParents;
