import React, { useEffect, useState } from 'react';
import {
    CheckCircle,
    XCircle,
    Clock,
    Filter,
} from 'lucide-react';
import { apiClient } from '../../lib/api';
import showToast from '../../utils/toast';

interface School {
    id: number;
    name: string;
    address: string;
    school_level: string;
    is_active: boolean;
    created_at: string;
    activation_date?: string;
}

export const SchoolActivation: React.FC = () => {
    const [schools, setSchools] = useState<School[]>([]);
    const [loading, setLoading] = useState(true);
    const [filter, setFilter] = useState<'all' | 'active' | 'inactive'>('all');

    useEffect(() => {
        fetchSchools();
    }, [filter]);

    const fetchSchools = async () => {
        try {
            setLoading(true);
            const response = await apiClient.get('/super-admin/schools');
            if (response.data.success) {
                let filteredSchools = response.data.data.data;

                if (filter === 'active') {
                    filteredSchools = filteredSchools.filter((s: School) => s.is_active);
                } else if (filter === 'inactive') {
                    filteredSchools = filteredSchools.filter((s: School) => !s.is_active);
                }

                setSchools(filteredSchools);
            }
        } catch (error) {
            console.error('Failed to fetch schools:', error);
        } finally {
            setLoading(false);
        }
    };

    const handleActivate = async (schoolId: number) => {
        if (!confirm('Aktifkan sekolah ini?')) return;

        try {
            await apiClient.post(`/super-admin/schools/${schoolId}/activate`);
            showToast.success('Sekolah berhasil diaktifkan');
            fetchSchools();
        } catch (error) {
            console.error('Failed to activate school:', error);
        }
    };

    const handleDeactivate = async (schoolId: number) => {
        if (!confirm('Nonaktifkan sekolah ini? Sekolah tidak akan bisa mengakses sistem.')) return;

        try {
            await apiClient.post(`/super-admin/schools/${schoolId}/deactivate`);
            showToast.success('Sekolah berhasil dinonaktifkan');
            fetchSchools();
        } catch (error) {
            console.error('Failed to deactivate school:', error);
        }
    };

    return (
        <div className="min-h-screen bg-slate-50 p-6">
            {/* Header */}
            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 mb-2">Aktivasi Sekolah</h1>
                <p className="text-slate-600">Kelola status aktivasi sekolah dalam platform</p>
            </div>

            {/* Filters */}
            <div className="bg-white rounded-lg shadow-sm border border-slate-200 p-4 mb-6">
                <div className="flex items-center gap-4">
                    <Filter className="w-5 h-5 text-slate-500" />
                    <div className="flex gap-2">
                        <button
                            onClick={() => setFilter('all')}
                            className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${filter === 'all'
                                ? 'bg-blue-600 text-white'
                                : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
                                }`}
                        >
                            Semua ({schools.length})
                        </button>
                        <button
                            onClick={() => setFilter('active')}
                            className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${filter === 'active'
                                ? 'bg-green-600 text-white'
                                : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
                                }`}
                        >
                            Aktif
                        </button>
                        <button
                            onClick={() => setFilter('inactive')}
                            className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${filter === 'inactive'
                                ? 'bg-red-600 text-white'
                                : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
                                }`}
                        >
                            Nonaktif
                        </button>
                    </div>
                </div>
            </div>

            {/* Schools Grid */}
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                {loading ? (
                    <div className="col-span-full flex justify-center py-12">
                        <div className="w-8 h-8 border-4 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
                    </div>
                ) : schools.length === 0 ? (
                    <div className="col-span-full text-center py-12 text-slate-500">
                        Tidak ada sekolah dengan filter ini
                    </div>
                ) : (
                    schools.map((school) => (
                        <div
                            key={school.id}
                            className="bg-white rounded-xl shadow-sm border border-slate-200 p-6 hover:shadow-md transition-shadow"
                        >
                            {/* School Header */}
                            <div className="flex items-start justify-between mb-4">
                                <div className="flex-1">
                                    <h3 className="font-bold text-slate-900 mb-1">{school.name}</h3>
                                    <p className="text-sm text-slate-600">{school.school_level}</p>
                                </div>
                                {school.is_active ? (
                                    <CheckCircle className="w-6 h-6 text-green-500" />
                                ) : (
                                    <XCircle className="w-6 h-6 text-red-500" />
                                )}
                            </div>

                            {/* School Info */}
                            <div className="space-y-2 mb-4">
                                <p className="text-sm text-slate-600">{school.address}</p>
                                <div className="flex items-center gap-2 text-xs text-slate-500">
                                    <Clock className="w-4 h-4" />
                                    <span>Terdaftar: {new Date(school.created_at).toLocaleDateString('id-ID')}</span>
                                </div>
                            </div>

                            {/* Status Badge */}
                            <div className="mb-4">
                                {school.is_active ? (
                                    <span className="inline-flex items-center gap-2 px-3 py-1 bg-green-50 text-green-700 text-sm font-medium rounded-full">
                                        <CheckCircle className="w-4 h-4" />
                                        Aktif
                                    </span>
                                ) : (
                                    <span className="inline-flex items-center gap-2 px-3 py-1 bg-red-50 text-red-700 text-sm font-medium rounded-full">
                                        <XCircle className="w-4 h-4" />
                                        Nonaktif
                                    </span>
                                )}
                            </div>

                            {/* Actions */}
                            <div className="flex gap-2">
                                {school.is_active ? (
                                    <button
                                        onClick={() => handleDeactivate(school.id)}
                                        className="flex-1 px-4 py-2 bg-red-50 text-red-700 rounded-lg hover:bg-red-100 transition-colors text-sm font-medium"
                                    >
                                        Nonaktifkan
                                    </button>
                                ) : (
                                    <button
                                        onClick={() => handleActivate(school.id)}
                                        className="flex-1 px-4 py-2 bg-green-50 text-green-700 rounded-lg hover:bg-green-100 transition-colors text-sm font-medium"
                                    >
                                        Aktifkan
                                    </button>
                                )}
                            </div>
                        </div>
                    ))
                )}
            </div>
        </div>
    );
};
