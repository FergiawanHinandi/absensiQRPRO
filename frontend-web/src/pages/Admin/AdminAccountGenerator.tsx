import React, { useState, useEffect } from 'react';
import { useSearchParams } from 'react-router-dom';
import {
    RefreshCw,
    Search,
    Filter,
    CheckCircle,
    XCircle,
    Key,
    FileSpreadsheet
} from 'lucide-react';
import { apiClient } from '../../lib/api';
import showToast from '../../utils/toast';

type AccountType = 'teacher' | 'student' | 'parent';

interface UserAccount {
    id: number;
    name: string;
    identifier: string; // NIP or NIS
    class_name?: string; // For students
    username: string;
    has_account: boolean;
    status: 'active' | 'inactive';
    generated_password?: string; // Only shown after generation
}

export const AdminAccountGenerator: React.FC = () => {
    const [searchParams] = useSearchParams();
    const initialTab = (searchParams.get('tab') as AccountType) || 'student';
    const initialSearch = searchParams.get('search') || '';

    const [activeTab, setActiveTab] = useState<AccountType>(initialTab);
    const [isLoading, setIsLoading] = useState(false);
    const [data, setData] = useState<UserAccount[]>([]);
    const [searchQuery, setSearchQuery] = useState(initialSearch);
    // const [generatedCount, setGeneratedCount] = useState(0);

    useEffect(() => {
        fetchData();
    }, [activeTab]);

    const fetchData = async () => {
        setIsLoading(true);
        try {
            const response = await apiClient.get(`/admin/${activeTab}s`);
            const items: UserAccount[] = (response.data?.data ?? response.data ?? []).map((item: any) => ({
                id: item.id,
                name: item.name,
                identifier: item.nip || item.nis || item.identifier || '',
                class_name: item.class_name,
                username: item.username || '',
                has_account: !!item.has_account,
                status: item.status || 'active',
            }));
            setData(items);
        } catch (error: any) {
            console.error('Error fetching data', error);
            showToast.error(error?.response?.data?.message || 'Gagal memuat data');
        } finally {
            setIsLoading(false);
        }
    };

    const generateSecurePassword = (length = 12): string => {
        const charset = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%';
        const values = new Uint32Array(length);
        crypto.getRandomValues(values);
        return Array.from(values, (v) => charset[v % charset.length]).join('');
    };

    const handleGenerateValues = async () => {
        const pendingUsers = data.filter(u => !u.has_account);
        if (pendingUsers.length === 0) {
            showToast.warning('Semua akun sudah di-generate');
            return;
        }
        if (!window.confirm(`Apakah Anda yakin ingin men-generate akun untuk ${pendingUsers.length} data yang belum memiliki akun?`)) return;

        setIsLoading(true);
        try {
            const payload = pendingUsers.map(u => ({
                id: u.id,
                type: activeTab,
                password: generateSecurePassword(),
            }));
            const response = await apiClient.post('/admin/accounts/generate', { accounts: payload, type: activeTab });
            const generated = response.data?.data ?? response.data ?? [];

            setData(prev => prev.map(item => {
                const gen = generated.find((g: any) => g.id === item.id);
                if (gen) {
                    return { ...item, has_account: true, generated_password: gen.password || gen.generated_password };
                }
                return item;
            }));
            showToast.success('Berhasil generate akun!');
        } catch (error: any) {
            console.error('Error generating accounts', error);
            showToast.error(error?.response?.data?.message || 'Gagal generate akun');
        } finally {
            setIsLoading(false);
        }
    };

    const handleResetPassword = async (id: number) => {
        const newPass = generateSecurePassword();
        try {
            await apiClient.post(`/admin/accounts/${id}/reset-password`, { password: newPass, type: activeTab });
            setData(prev => prev.map(item =>
                item.id === id ? { ...item, generated_password: newPass } : item
            ));
            showToast.success('Password berhasil di-reset');
        } catch (error: any) {
            console.error('Error resetting password', error);
            showToast.error(error?.response?.data?.message || 'Gagal reset password');
        }
    };

    const filteredData = data.filter(item =>
        item.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
        item.identifier.includes(searchQuery)
    );

    return (
        <div className="space-y-6">
            {/* Page Header */}
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-bold text-slate-800">Manajemen & Generate Akun</h1>
                    <p className="text-slate-500">Kelola dan generate akun pengguna sekolah dalam bentuk tabel</p>
                </div>
                <div className="flex gap-2">
                    <button className="flex items-center gap-2 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors shadow-sm">
                        <FileSpreadsheet className="w-4 h-4" />
                        <span>Export Excel</span>
                    </button>
                    <button
                        onClick={handleGenerateValues}
                        className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors shadow-sm"
                    >
                        <RefreshCw className="w-4 h-4" />
                        <span>Generate Massal</span>
                    </button>
                </div>
            </div>

            {/* Tabs */}
            <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-1 flex gap-1 w-fit">
                {(['student', 'teacher', 'parent'] as AccountType[]).map((tab) => (
                    <button
                        key={tab}
                        onClick={() => setActiveTab(tab)}
                        className={`px-4 py-2 text-sm font-medium rounded-lg transition-all ${activeTab === tab
                            ? 'bg-blue-50 text-blue-700 shadow-sm'
                            : 'text-slate-500 hover:text-slate-700 hover:bg-slate-100'
                            }`}
                    >
                        {tab === 'student' ? 'Siswa' : tab === 'teacher' ? 'Guru' : 'Orang Tua'}
                    </button>
                ))}
            </div>

            {/* Table Container */}
            <div className="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden flex flex-col">
                {/* Toolbar */}
                <div className="p-4 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-slate-50/50">
                    <div className="relative max-w-md w-full">
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                        <input
                            type="text"
                            placeholder="Cari berdasarkan nama atau ID..."
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            className="w-full pl-10 pr-4 py-2 rounded-lg border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500"
                        />
                    </div>
                    <div className="flex items-center gap-2">
                        <div className="flex items-center gap-2 text-sm text-slate-600 bg-white px-3 py-1.5 rounded-lg border border-slate-200">
                            <Filter className="w-4 h-4" />
                            <span>Filter: Semua Status</span>
                        </div>
                    </div>
                </div>

                {/* Excel-like Table */}
                <div className="overflow-x-auto">
                    <table className="w-full border-collapse text-sm">
                        <thead>
                            <tr className="bg-slate-100 border-b border-slate-300">
                                <th className="px-4 py-3 text-left font-semibold text-slate-700 border-r border-slate-300 w-16">No</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-700 border-r border-slate-300">
                                    {activeTab === 'student' ? 'NIS' : 'NIP / ID'}
                                </th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-700 border-r border-slate-300 min-w-[200px]">Nama Lengkap</th>
                                {activeTab === 'student' && (
                                    <th className="px-4 py-3 text-left font-semibold text-slate-700 border-r border-slate-300">Kelas</th>
                                )}
                                <th className="px-4 py-3 text-left font-semibold text-slate-700 border-r border-slate-300">Username</th>
                                <th className="px-4 py-3 text-left font-semibold text-slate-700 border-r border-slate-300">Password</th>
                                <th className="px-4 py-3 text-center font-semibold text-slate-700 border-r border-slate-300">Status Akun</th>
                                <th className="px-4 py-3 text-center font-semibold text-slate-700">Aksi</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-200">
                            {isLoading ? (
                                <tr>
                                    <td colSpan={8} className="p-8 text-center text-slate-500">Memuat data...</td>
                                </tr>
                            ) : filteredData.length === 0 ? (
                                <tr>
                                    <td colSpan={8} className="p-8 text-center text-slate-500">Data tidak ditemukan</td>
                                </tr>
                            ) : (
                                filteredData.map((row, index) => (
                                    <tr key={row.id} className="hover:bg-blue-50/30 transition-colors group">
                                        <td className="px-4 py-2 border-r border-slate-200 text-slate-500 text-center">{index + 1}</td>
                                        <td className="px-4 py-2 border-r border-slate-200 font-mono text-slate-600">{row.identifier}</td>
                                        <td className="px-4 py-2 border-r border-slate-200 font-medium text-slate-800">{row.name}</td>
                                        {activeTab === 'student' && (
                                            <td className="px-4 py-2 border-r border-slate-200 text-slate-600">{row.class_name}</td>
                                        )}
                                        <td className="px-4 py-2 border-r border-slate-200 text-slate-600 bg-slate-50/50">{row.username}</td>
                                        <td className="px-4 py-2 border-r border-slate-200 font-mono text-slate-600 bg-yellow-50/50">
                                            {row.generated_password ? (
                                                <span className="text-green-600 font-bold px-2 py-0.5 bg-green-100 rounded border border-green-200">
                                                    {row.generated_password}
                                                </span>
                                            ) : (
                                                <span className="text-slate-400 italic text-xs">Tersembunyi</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-2 border-r border-slate-200 text-center">
                                            {row.has_account ? (
                                                <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-green-100 text-green-700 border border-green-200">
                                                    <CheckCircle className="w-3 h-3" />
                                                    Aktif
                                                </span>
                                            ) : (
                                                <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-slate-100 text-slate-600 border border-slate-200">
                                                    <XCircle className="w-3 h-3" />
                                                    Belum Ada
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-2 text-center">
                                            <button
                                                onClick={() => handleResetPassword(row.id)}
                                                className="p-1.5 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors border border-transparent hover:border-blue-200"
                                                title={row.has_account ? "Reset Password" : "Generate Local"}
                                            >
                                                <Key className="w-4 h-4" />
                                            </button>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Pagination (Static for now) */}
                {!isLoading && filteredData.length > 0 && (
                    <div className="p-4 border-t border-slate-200 bg-slate-50 flex justify-between items-center text-sm text-slate-500">
                        <p>Menampilkan {filteredData.length} data</p>
                        <div className="flex gap-2">
                            <button className="px-3 py-1 border border-slate-300 rounded hover:bg-white disabled:opacity-50" disabled>Sebelumnya</button>
                            <button className="px-3 py-1 border border-slate-300 rounded hover:bg-white">Selanjutnya</button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
};
