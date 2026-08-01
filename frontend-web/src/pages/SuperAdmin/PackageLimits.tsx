import React, { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
    Package,
    Users,
    BookOpen,
    TrendingUp,
    Check,
    X,
    Loader2,
    Save,
} from 'lucide-react';
import { apiClient } from '../../lib/api';
import showToast from '../../utils/toast';

interface SchoolPackage {
    id: number;
    name: string;
    school_level: string;
    is_active: boolean;
    limits: {
        max_students: number;
        max_teachers: number;
        max_classes: number;
        current_students: number;
        current_teachers: number;
        current_classes: number;
    };
    package_type: string;
}

interface PackageOption {
    type: string;
    name: string;
    limits: {
        max_students: number;
        max_teachers: number;
        max_classes: number;
    };
    color: string;
}

const PACKAGE_OPTIONS: PackageOption[] = [
    {
        type: 'basic',
        name: 'Basic',
        limits: { max_students: 500, max_teachers: 50, max_classes: 20 },
        color: 'slate'
    },
    {
        type: 'pro',
        name: 'Pro',
        limits: { max_students: 1000, max_teachers: 100, max_classes: 40 },
        color: 'blue'
    },
    {
        type: 'premium',
        name: 'Premium',
        limits: { max_students: 999999, max_teachers: 999999, max_classes: 999999 },
        color: 'purple'
    },
];

const getUsagePercentage = (current: number, max: number) => {
    if (max === 999999) return 0; // Unlimited
    return Math.round((current / max) * 100);
};

const getUsageColor = (percentage: number) => {
    if (percentage >= 90) return 'bg-red-500';
    if (percentage >= 75) return 'bg-yellow-500';
    return 'bg-green-500';
};

const SchoolUsageStats: React.FC<{ school: SchoolPackage }> = ({ school }) => {
    const { data: usage, isLoading } = useQuery({
        queryKey: ['school-usage', school.id],
        queryFn: async () => {
            const res = await apiClient.get(`/super-admin/schools/${school.id}/usage`);
            return res.data.data;
        }
    });

    const stats = [
        {
            label: 'Siswa',
            current: usage ? usage.current_students : school.limits.current_students, // Fallback to list data
            max: school.limits.max_students,
            icon: Users,
            color: 'text-blue-600',
            isLoading: false // Students count is available in list
        },
        {
            label: 'Guru',
            current: usage?.current_teachers ?? 0,
            max: school.limits.max_teachers,
            icon: BookOpen,
            color: 'text-green-600',
            isLoading: isLoading
        },
        {
            label: 'Kelas',
            current: usage?.current_classes ?? 0,
            max: school.limits.max_classes,
            icon: TrendingUp,
            color: 'text-purple-600',
            isLoading: isLoading
        }
    ];

    return (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
            {stats.map((stat, idx) => (
                <div key={idx}>
                    <div className="flex items-center justify-between mb-2">
                        <div className="flex items-center gap-2">
                            <stat.icon className={`w-4 h-4 ${stat.color}`} />
                            <span className="text-sm font-medium text-slate-700">{stat.label}</span>
                        </div>
                        {stat.isLoading ? (
                            <div className="h-4 w-12 bg-slate-200 rounded animate-pulse"></div>
                        ) : (
                            <span className="text-sm text-slate-600">
                                {stat.current} / {stat.max === 999999 ? '∞' : stat.max}
                            </span>
                        )}
                    </div>

                    {stat.isLoading ? (
                        <div className="w-full bg-slate-100 rounded-full h-2 animate-pulse"></div>
                    ) : (
                        <div className="w-full bg-slate-200 rounded-full h-2">
                            <div
                                className={`h-2 rounded-full transition-all ${getUsageColor(
                                    getUsagePercentage(stat.current, stat.max)
                                )}`}
                                style={{
                                    width: `${getUsagePercentage(stat.current, stat.max)}%`,
                                }}
                            ></div>
                        </div>
                    )}

                    <p className="text-xs text-slate-500 mt-1">
                        {stat.isLoading ? (
                            <span className="inline-block h-3 w-20 bg-slate-200 rounded animate-pulse"></span>
                        ) : (
                            stat.max === 999999 ? 'Unlimited' : `${getUsagePercentage(stat.current, stat.max)}% terpakai`
                        )}
                    </p>
                </div>
            ))}
        </div>
    );
};

export const PackageLimits: React.FC = () => {
    const [schools, setSchools] = useState<SchoolPackage[]>([]);
    const [loading, setLoading] = useState(true);

    // Modal states
    const [upgradeModal, setUpgradeModal] = useState<{ open: boolean; school: SchoolPackage | null }>({
        open: false,
        school: null
    });
    const [editLimitModal, setEditLimitModal] = useState<{ open: boolean; school: SchoolPackage | null }>({
        open: false,
        school: null
    });

    // Form states
    const [selectedPackage, setSelectedPackage] = useState<string>('');
    const [customLimits, setCustomLimits] = useState({
        max_students: 0,
        max_teachers: 0,
        max_classes: 0
    });
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        fetchSchools();
    }, []);

    const fetchSchools = async () => {
        try {
            setLoading(true);
            const response = await apiClient.get('/super-admin/schools');
            if (response.data.success) {
                // Map data with default limits
                const schoolsWithLimits = response.data.data.data.map((school: any) => ({
                    ...school,
                    limits: {
                        max_students: school.max_students || 500,
                        max_teachers: school.max_teachers || 50,
                        max_classes: school.max_classes || 20,
                        current_students: school.users_count || 0,
                        // TODO: Ambil data dari API GET /super-admin/schools/{id}/stats
                        current_teachers: school.teachers_count || 0,
                        current_classes: school.classes_count || 0,
                    },
                    package_type: school.package_type || 'basic',
                }));
                setSchools(schoolsWithLimits);
            }
        } catch (error) {
            console.error('Failed to fetch schools:', error);
        } finally {
            setLoading(false);
        }
    };

    const handleUpgradePackage = async () => {
        if (!upgradeModal.school || !selectedPackage) return;

        try {
            setSubmitting(true);
            const packageOption = PACKAGE_OPTIONS.find(p => p.type === selectedPackage);

            const response = await apiClient.put(`/super-admin/schools/${upgradeModal.school.id}`, {
                package_type: selectedPackage,
                max_students: packageOption?.limits.max_students,
                max_teachers: packageOption?.limits.max_teachers,
                max_classes: packageOption?.limits.max_classes,
            });

            if (response.data.success) {
                showToast.success(`Paket berhasil diupgrade ke ${packageOption?.name}!`);
                setUpgradeModal({ open: false, school: null });
                fetchSchools();
            }
        } catch (error: any) {
            console.error('Failed to upgrade package:', error);
            showToast.error(error.response?.data?.message || 'Gagal upgrade paket');
        } finally {
            setSubmitting(false);
        }
    };

    const handleEditLimit = async () => {
        if (!editLimitModal.school) return;

        try {
            setSubmitting(true);
            const response = await apiClient.put(`/super-admin/schools/${editLimitModal.school.id}`, {
                max_students: customLimits.max_students,
                max_teachers: customLimits.max_teachers,
                max_classes: customLimits.max_classes,
                package_type: 'custom', // Mark as custom package
            });

            if (response.data.success) {
                showToast.success('Limit berhasil diupdate!');
                setEditLimitModal({ open: false, school: null });
                fetchSchools();
            }
        } catch (error: any) {
            console.error('Failed to edit limit:', error);
            showToast.error(error.response?.data?.message || 'Gagal edit limit');
        } finally {
            setSubmitting(false);
        }
    };

    const openUpgradeModal = (school: SchoolPackage) => {
        setSelectedPackage(school.package_type);
        setUpgradeModal({ open: true, school });
    };

    const openEditLimitModal = (school: SchoolPackage) => {
        setCustomLimits({
            max_students: school.limits.max_students,
            max_teachers: school.limits.max_teachers,
            max_classes: school.limits.max_classes,
        });
        setEditLimitModal({ open: true, school });
    };

    const getPackageColor = (packageType: string) => {
        switch (packageType) {
            case 'premium':
                return 'bg-purple-50 text-purple-700 border-purple-200';
            case 'pro':
                return 'bg-blue-50 text-blue-700 border-blue-200';
            case 'custom':
                return 'bg-orange-50 text-orange-700 border-orange-200';
            default:
                return 'bg-slate-50 text-slate-700 border-slate-200';
        }
    };

    return (
        <div className="min-h-screen bg-slate-50 p-6">
            {/* Header */}
            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 mb-2">Paket & Limit Sekolah</h1>
                <p className="text-slate-600">Kelola paket berlangganan dan limit kapasitas sekolah</p>
            </div>

            {/* Package Types Info */}
            <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div className="bg-white border-2 border-slate-200 rounded-xl p-4">
                    <div className="flex items-center gap-3 mb-2">
                        <Package className="w-5 h-5 text-slate-600" />
                        <h3 className="font-semibold text-slate-900">Basic</h3>
                    </div>
                    <p className="text-sm text-slate-600">500 siswa • 50 guru • 20 kelas</p>
                </div>
                <div className="bg-white border-2 border-blue-200 rounded-xl p-4">
                    <div className="flex items-center gap-3 mb-2">
                        <Package className="w-5 h-5 text-blue-600" />
                        <h3 className="font-semibold text-blue-900">Pro</h3>
                    </div>
                    <p className="text-sm text-blue-600">1000 siswa • 100 guru • 40 kelas</p>
                </div>
                <div className="bg-white border-2 border-purple-200 rounded-xl p-4">
                    <div className="flex items-center gap-3 mb-2">
                        <Package className="w-5 h-5 text-purple-600" />
                        <h3 className="font-semibold text-purple-900">Premium</h3>
                    </div>
                    <p className="text-sm text-purple-600">Unlimited • Unlimited • Unlimited</p>
                </div>
                <div className="bg-white border-2 border-orange-200 rounded-xl p-4">
                    <div className="flex items-center gap-3 mb-2">
                        <Package className="w-5 h-5 text-orange-600" />
                        <h3 className="font-semibold text-orange-900">Custom</h3>
                    </div>
                    <p className="text-sm text-orange-600">Limit disesuaikan</p>
                </div>
            </div>

            {/* Schools List */}
            <div className="space-y-4">
                {loading ? (
                    <div className="flex justify-center py-12">
                        <div className="w-8 h-8 border-4 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
                    </div>
                ) : schools.length === 0 ? (
                    <div className="text-center py-12 text-slate-500">
                        Tidak ada data sekolah
                    </div>
                ) : (
                    schools.map((school) => (
                        <div
                            key={school.id}
                            className="bg-white rounded-xl shadow-sm border border-slate-200 p-6"
                        >
                            {/* School Header */}
                            <div className="flex items-start justify-between mb-6">
                                <div>
                                    <h3 className="text-lg font-bold text-slate-900 mb-1">{school.name}</h3>
                                    <p className="text-sm text-slate-600">{school.school_level}</p>
                                </div>
                                <span className={`px-3 py-1 rounded-full text-sm font-medium border ${getPackageColor(school.package_type)}`}>
                                    {school.package_type.toUpperCase()}
                                </span>
                            </div>

                            {/* Usage Stats */}
                            <SchoolUsageStats school={school} />

                            {/* Actions */}
                            <div className="mt-6 pt-6 border-t border-slate-200 flex gap-2">
                                <button
                                    onClick={() => openUpgradeModal(school)}
                                    className="px-4 py-2 bg-blue-50 text-blue-700 rounded-lg hover:bg-blue-100 transition-colors text-sm font-medium"
                                >
                                    Upgrade Paket
                                </button>
                                <button
                                    onClick={() => openEditLimitModal(school)}
                                    className="px-4 py-2 bg-slate-100 text-slate-700 rounded-lg hover:bg-slate-200 transition-colors text-sm font-medium"
                                >
                                    Edit Limit Custom
                                </button>
                            </div>
                        </div>
                    ))
                )}
            </div>

            {/* Upgrade Package Modal */}
            {upgradeModal.open && upgradeModal.school && (
                <div className="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4">
                    <div className="bg-white rounded-2xl shadow-2xl w-full max-w-md">
                        {/* Header */}
                        <div className="flex items-center justify-between p-6 border-b border-slate-200">
                            <h3 className="text-lg font-bold text-slate-900">Upgrade Paket</h3>
                            <button
                                onClick={() => setUpgradeModal({ open: false, school: null })}
                                className="text-slate-400 hover:text-slate-600"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        {/* Content */}
                        <div className="p-6 space-y-4">
                            <div>
                                <p className="text-sm text-slate-600 mb-4">
                                    Sekolah: <span className="font-semibold text-slate-900">{upgradeModal.school.name}</span>
                                </p>
                                <p className="text-sm text-slate-600 mb-4">
                                    Paket saat ini: <span className="font-semibold text-slate-900">{upgradeModal.school.package_type.toUpperCase()}</span>
                                </p>
                            </div>

                            <div className="space-y-2">
                                <label className="text-sm font-medium text-slate-700">Pilih Paket Baru:</label>
                                {PACKAGE_OPTIONS.map((pkg) => (
                                    <button
                                        key={pkg.type}
                                        onClick={() => setSelectedPackage(pkg.type)}
                                        className={`w-full p-4 rounded-lg border-2 transition-all text-left ${selectedPackage === pkg.type
                                            ? `border-${pkg.color}-500 bg-${pkg.color}-50`
                                            : 'border-slate-200 hover:border-slate-300'
                                            }`}
                                    >
                                        <div className="flex items-center justify-between">
                                            <div>
                                                <h4 className="font-semibold text-slate-900">{pkg.name}</h4>
                                                <p className="text-sm text-slate-600">
                                                    {pkg.limits.max_students === 999999 ? 'Unlimited' : `${pkg.limits.max_students} siswa`} •{' '}
                                                    {pkg.limits.max_teachers === 999999 ? 'Unlimited' : `${pkg.limits.max_teachers} guru`} •{' '}
                                                    {pkg.limits.max_classes === 999999 ? 'Unlimited' : `${pkg.limits.max_classes} kelas`}
                                                </p>
                                            </div>
                                            {selectedPackage === pkg.type && (
                                                <Check className="w-5 h-5 text-blue-600" />
                                            )}
                                        </div>
                                    </button>
                                ))}
                            </div>
                        </div>

                        {/* Footer */}
                        <div className="flex gap-3 p-6 border-t border-slate-200">
                            <button
                                onClick={() => setUpgradeModal({ open: false, school: null })}
                                className="flex-1 px-4 py-2 border border-slate-300 text-slate-700 rounded-lg hover:bg-slate-50 transition-colors"
                            >
                                Batal
                            </button>
                            <button
                                onClick={handleUpgradePackage}
                                disabled={submitting || !selectedPackage}
                                className="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
                            >
                                {submitting ? (
                                    <>
                                        <Loader2 className="w-4 h-4 animate-spin" />
                                        Menyimpan...
                                    </>
                                ) : (
                                    <>
                                        <Save className="w-4 h-4" />
                                        Simpan
                                    </>
                                )}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Edit Limit Modal */}
            {editLimitModal.open && editLimitModal.school && (
                <div className="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4">
                    <div className="bg-white rounded-2xl shadow-2xl w-full max-w-md">
                        {/* Header */}
                        <div className="flex items-center justify-between p-6 border-b border-slate-200">
                            <h3 className="text-lg font-bold text-slate-900">Edit Limit Custom</h3>
                            <button
                                onClick={() => setEditLimitModal({ open: false, school: null })}
                                className="text-slate-400 hover:text-slate-600"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        {/* Content */}
                        <div className="p-6 space-y-4">
                            <div>
                                <p className="text-sm text-slate-600 mb-4">
                                    Sekolah: <span className="font-semibold text-slate-900">{editLimitModal.school.name}</span>
                                </p>
                            </div>

                            <div className="space-y-4">
                                <div>
                                    <label className="block text-sm font-medium text-slate-700 mb-2">
                                        Maksimal Siswa
                                    </label>
                                    <input
                                        type="number"
                                        value={customLimits.max_students}
                                        onChange={(e) => setCustomLimits({ ...customLimits, max_students: parseInt(e.target.value) || 0 })}
                                        className="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                        min="0"
                                    />
                                </div>

                                <div>
                                    <label className="block text-sm font-medium text-slate-700 mb-2">
                                        Maksimal Guru
                                    </label>
                                    <input
                                        type="number"
                                        value={customLimits.max_teachers}
                                        onChange={(e) => setCustomLimits({ ...customLimits, max_teachers: parseInt(e.target.value) || 0 })}
                                        className="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                        min="0"
                                    />
                                </div>

                                <div>
                                    <label className="block text-sm font-medium text-slate-700 mb-2">
                                        Maksimal Kelas
                                    </label>
                                    <input
                                        type="number"
                                        value={customLimits.max_classes}
                                        onChange={(e) => setCustomLimits({ ...customLimits, max_classes: parseInt(e.target.value) || 0 })}
                                        className="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                        min="0"
                                    />
                                </div>
                            </div>
                        </div>

                        {/* Footer */}
                        <div className="flex gap-3 p-6 border-t border-slate-200">
                            <button
                                onClick={() => setEditLimitModal({ open: false, school: null })}
                                className="flex-1 px-4 py-2 border border-slate-300 text-slate-700 rounded-lg hover:bg-slate-50 transition-colors"
                            >
                                Batal
                            </button>
                            <button
                                onClick={handleEditLimit}
                                disabled={submitting}
                                className="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
                            >
                                {submitting ? (
                                    <>
                                        <Loader2 className="w-4 h-4 animate-spin" />
                                        Menyimpan...
                                    </>
                                ) : (
                                    <>
                                        <Save className="w-4 h-4" />
                                        Simpan
                                    </>
                                )}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};
