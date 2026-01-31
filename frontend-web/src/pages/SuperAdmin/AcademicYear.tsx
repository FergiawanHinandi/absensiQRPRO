import React, { useEffect, useState } from 'react';
import { Send, BarChart2, Info } from 'lucide-react';
import { apiClient } from '../../lib/api';
import showToast from '../../utils/toast';

interface AcademicYearStat {
    name: string;
    adoption_count: number;
    total_instances: number;
}

export const AcademicYear: React.FC = () => {
    const [stats, setStats] = useState<AcademicYearStat[]>([]);
    const [loading, setLoading] = useState(true);
    const [deploying, setDeploying] = useState(false);

    const [form, setForm] = useState({
        name: '',
        start_date: '',
        end_date: '',
        semester: '1'
    });

    useEffect(() => {
        fetchStats();
    }, []);

    const fetchStats = async () => {
        try {
            setLoading(true);
            const response = await apiClient.get('/super-admin/config/academic-years');
            if (response.data.success) {
                setStats(response.data.data);
            }
        } catch (error) {
            console.error('Failed to fetch stats:', error);
        } finally {
            setLoading(false);
        }
    };

    const handleDeploy = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!confirm(`Deploy Tahun Ajaran ${form.name} ke SEMUA sekolah?\nIni akan menambahkan data tahun ajaran baru di setiap sekolah yang belum memilikinya.`)) {
            return;
        }

        try {
            setDeploying(true);
            const response = await apiClient.post('/super-admin/config/academic-years/deploy', form);
            if (response.data.success) {
                showToast.success(response.data.message);
                fetchStats();
                setForm({ name: '', start_date: '', end_date: '', semester: '1' });
            }
        } catch (error: any) {
            showToast.error(error.response?.data?.message || 'Deploy failed');
        } finally {
            setDeploying(false);
        }
    };

    return (
        <div className="min-h-screen bg-slate-50 p-6">
            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 mb-2">Global Academic Year</h1>
                <p className="text-slate-600">Kelola dan deploy tahun ajaran ke seluruh ekosistem sekolah</p>
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* Deploy Form */}
                <div className="lg:col-span-1">
                    <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                        <div className="flex items-center gap-2 mb-4 text-blue-600">
                            <Send className="w-5 h-5" />
                            <h2 className="font-bold text-lg">Deploy New Period</h2>
                        </div>
                        <p className="text-sm text-slate-500 mb-6">
                            Buat periode tahun ajaran baru secara massal untuk semua sekolah yang terdaftar.
                        </p>

                        <form onSubmit={handleDeploy} className="space-y-4">
                            <div>
                                <label className="block text-sm font-medium text-slate-700 mb-1">Nama Periode</label>
                                <input
                                    type="text"
                                    placeholder="e.g. 2025/2026"
                                    value={form.name}
                                    onChange={e => setForm({ ...form, name: e.target.value })}
                                    pattern="\d{4}/\d{4}"
                                    className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                    required
                                />
                                <p className="text-xs text-slate-400 mt-1">Format: YYYY/YYYY</p>
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-sm font-medium text-slate-700 mb-1">Mulai</label>
                                    <input
                                        type="date"
                                        value={form.start_date}
                                        onChange={e => setForm({ ...form, start_date: e.target.value })}
                                        className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                        required
                                    />
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-slate-700 mb-1">Selesai</label>
                                    <input
                                        type="date"
                                        value={form.end_date}
                                        onChange={e => setForm({ ...form, end_date: e.target.value })}
                                        className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                        required
                                    />
                                </div>
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-slate-700 mb-1">Semester</label>
                                <div className="flex gap-4">
                                    <label className="flex items-center gap-2 cursor-pointer">
                                        <input
                                            type="radio"
                                            name="semester"
                                            value="1"
                                            checked={form.semester === '1'}
                                            onChange={e => setForm({ ...form, semester: e.target.value })}
                                            className="text-blue-600 focus:ring-blue-500"
                                        />
                                        <span className="text-sm">Ganjil (1)</span>
                                    </label>
                                    <label className="flex items-center gap-2 cursor-pointer">
                                        <input
                                            type="radio"
                                            name="semester"
                                            value="2"
                                            checked={form.semester === '2'}
                                            onChange={e => setForm({ ...form, semester: e.target.value })}
                                            className="text-blue-600 focus:ring-blue-500"
                                        />
                                        <span className="text-sm">Genap (2)</span>
                                    </label>
                                </div>
                            </div>

                            <div className="pt-2">
                                <button
                                    type="submit"
                                    disabled={deploying}
                                    className={`w-full py-2.5 rounded-lg font-medium text-white flex items-center justify-center gap-2
                                        ${deploying ? 'bg-blue-400 cursor-not-allowed' : 'bg-blue-600 hover:bg-blue-700'}`}
                                >
                                    {deploying ? 'Deploying...' : 'Deploy to All Schools'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                {/* Stats */}
                <div className="lg:col-span-2">
                    <div className="bg-white rounded-xl shadow-sm border border-slate-200">
                        <div className="p-6 border-b border-slate-200 flex justify-between items-center">
                            <h2 className="font-bold text-lg text-slate-900 flex items-center gap-2">
                                <BarChart2 className="w-5 h-5 text-slate-500" />
                                Statistics & Adoption
                            </h2>
                        </div>

                        <div className="p-6">
                            {loading ? (
                                <div className="flex justify-center py-8">
                                    <div className="w-8 h-8 border-4 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
                                </div>
                            ) : stats.length === 0 ? (
                                <div className="text-center py-8 text-slate-500 bg-slate-50 rounded-lg border border-dashed border-slate-300">
                                    Belum ada data tahun ajaran terdistribusi.
                                </div>
                            ) : (
                                <div className="space-y-4">
                                    {stats.map((stat, idx) => (
                                        <div key={idx} className="flex items-center justify-between p-4 bg-slate-50 rounded-lg border border-slate-100">
                                            <div className="flex items-center gap-4">
                                                <div className="p-3 bg-white rounded-lg border border-slate-200 text-blue-600 font-bold">
                                                    {stat.name}
                                                </div>
                                                <div>
                                                    <h3 className="font-semibold text-slate-900">Periode {stat.name}</h3>
                                                    <p className="text-xs text-slate-500">Terdeteksi di {stat.adoption_count} sekolah</p>
                                                </div>
                                            </div>
                                            <div className="text-right">
                                                <span className="text-2xl font-bold text-slate-800">{stat.adoption_count}</span>
                                                <span className="text-xs text-slate-500 block">Sekolah Menggunakan</span>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}

                            <div className="mt-6 flex gap-3 p-4 bg-blue-50 text-blue-800 text-sm rounded-lg items-start">
                                <Info className="w-5 h-5 flex-shrink-0 mt-0.5" />
                                <p>
                                    <strong>Catatan:</strong> Fitur deploy ini hanya menambahkan reference data. Aktivasi tahun ajaran tetap dilakukan oleh masing-masing Admin Sekolah sesuai kebutuhan lokal mereka.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
};
