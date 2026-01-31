import React, { useEffect, useState } from 'react';
import { Package, Star, Plus, Edit, Trash2, X } from 'lucide-react';
import { apiClient } from '../../lib/api';

interface PackageFeatures {
    max_students: number;
    max_teachers: number;
    max_classes: number;
    storage_gb: number;
    support: string;
}

interface SubscriptionPackage {
    id: number;
    name: string;
    price: string | number;
    billing_cycle: string;
    features: PackageFeatures;
    is_popular: boolean;
    is_active: boolean;
}

const DEFAULT_FEATURES: PackageFeatures = {
    max_students: 100,
    max_teachers: 10,
    max_classes: 5,
    storage_gb: 1,
    support: 'Email'
};

export const SubscriptionPackages: React.FC = () => {
    const [packages, setPackages] = useState<SubscriptionPackage[]>([]);
    const [loading, setLoading] = useState(true);
    const [showModal, setShowModal] = useState(false);
    const [editingPkg, setEditingPkg] = useState<SubscriptionPackage | null>(null);

    const [formData, setFormData] = useState({
        name: '',
        price: '',
        billing_cycle: 'monthly',
        features: { ...DEFAULT_FEATURES },
        is_popular: false,
        is_active: true
    });

    useEffect(() => {
        fetchPackages();
    }, []);

    const fetchPackages = async () => {
        try {
            const response = await apiClient.get('/super-admin/billing/packages');
            if (response.data.success) {
                setPackages(response.data.data);
            }
        } catch (error) {
            console.error('Failed to fetch packages:', error);
        } finally {
            setLoading(false);
        }
    };

    const formatCurrency = (amount: string | number) => {
        return new Intl.NumberFormat('id-ID', {
            style: 'currency',
            currency: 'IDR',
            minimumFractionDigits: 0,
        }).format(Number(amount));
    };

    const handleEdit = (pkg: SubscriptionPackage) => {
        setEditingPkg(pkg);
        setFormData({
            name: pkg.name,
            price: pkg.price.toString(),
            billing_cycle: pkg.billing_cycle,
            features: { ...pkg.features },
            is_popular: pkg.is_popular,
            is_active: pkg.is_active
        });
        setShowModal(true);
    };

    const handleDelete = async (id: number) => {
        if (!confirm('Hapus paket ini?')) return;
        try {
            await apiClient.delete(`/super-admin/billing/packages/${id}`);
            fetchPackages();
        } catch (error) {
            console.error('Failed to delete package:', error);
        }
    };

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        try {
            const payload = {
                ...formData,
                price: Number(formData.price),
                features: formData.features
            };

            if (editingPkg) {
                await apiClient.put(`/super-admin/billing/packages/${editingPkg.id}`, payload);
            } else {
                await apiClient.post('/super-admin/billing/packages', payload);
            }
            setShowModal(false);
            setEditingPkg(null);
            setFormData({
                name: '',
                price: '',
                billing_cycle: 'monthly',
                features: { ...DEFAULT_FEATURES },
                is_popular: false,
                is_active: true
            });
            fetchPackages();
        } catch (error) {
            console.error('Failed to save package:', error);
        }
    };

    return (
        <div className="min-h-screen bg-slate-50 p-6">
            <div className="flex justify-between items-center mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 mb-1">Manajemen Paket Berlangganan</h1>
                    <p className="text-slate-600">Kelola master paket harga dan fitur</p>
                </div>
                <button
                    onClick={() => { setEditingPkg(null); setShowModal(true); }}
                    className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition"
                >
                    <Plus className="w-4 h-4" /> Tambah Paket
                </button>
            </div>

            {loading ? (
                <div className="flex justify-center p-12"><div className="w-8 h-8 border-4 border-blue-600 border-t-transparent rounded-full animate-spin"></div></div>
            ) : (
                <div className="grid grid-cols-1 gap-6">
                    {/* Table View */}
                    <div className="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 border-b border-slate-200 text-left text-slate-500 font-medium">
                                <tr>
                                    <th className="px-6 py-4">Nama Paket</th>
                                    <th className="px-6 py-4">Harga</th>
                                    <th className="px-6 py-4">Siklus</th>
                                    <th className="px-6 py-4">Limitasi User</th>
                                    <th className="px-6 py-4">Status</th>
                                    <th className="px-6 py-4 text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {packages.map((pkg) => (
                                    <tr key={pkg.id} className="hover:bg-slate-50">
                                        <td className="px-6 py-4 font-medium text-slate-900">
                                            <div className="flex items-center gap-2">
                                                <div className="p-2 bg-blue-50 text-blue-600 rounded-lg">
                                                    <Package className="w-4 h-4" />
                                                </div>
                                                {pkg.name}
                                                {pkg.is_popular && <Star className="w-3 h-3 text-amber-500 fill-amber-500" />}
                                            </div>
                                        </td>
                                        <td className="px-6 py-4 font-mono text-slate-600">{formatCurrency(pkg.price)}</td>
                                        <td className="px-6 py-4 capitalize">{pkg.billing_cycle}</td>
                                        <td className="px-6 py-4 text-slate-500">
                                            <div className="flex flex-col gap-1 text-xs">
                                                <span>Siswa: {pkg.features.max_students === -1 ? 'Unlimited' : pkg.features.max_students}</span>
                                                <span>Guru: {pkg.features.max_teachers === -1 ? 'Unlimited' : pkg.features.max_teachers}</span>
                                            </div>
                                        </td>
                                        <td className="px-6 py-4">
                                            <span className={`px-2 py-1 rounded-full text-xs font-semibold ${pkg.is_active ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-500'}`}>
                                                {pkg.is_active ? 'Active' : 'Inactive'}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4 text-right">
                                            <div className="flex justify-end gap-2">
                                                <button onClick={() => handleEdit(pkg)} className="p-1.5 hover:bg-slate-100 text-blue-600 rounded">
                                                    <Edit className="w-4 h-4" />
                                                </button>
                                                <button onClick={() => handleDelete(pkg.id)} className="p-1.5 hover:bg-slate-100 text-red-600 rounded">
                                                    <Trash2 className="w-4 h-4" />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {/* Modal Form */}
            {showModal && (
                <div className="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
                    <div className="bg-white rounded-xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
                        <div className="flex justify-between items-center p-6 border-b border-slate-100">
                            <h2 className="text-xl font-bold">{editingPkg ? 'Edit Paket' : 'Buat Paket Baru'}</h2>
                            <button onClick={() => setShowModal(false)} className="text-slate-400 hover:text-slate-600">
                                <X className="w-6 h-6" />
                            </button>
                        </div>
                        <form onSubmit={handleSubmit} className="p-6 space-y-6">
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-sm font-medium text-slate-700 mb-1">Nama Paket</label>
                                    <input
                                        type="text" required
                                        className="w-full px-3 py-2 border border-slate-300 rounded-lg"
                                        value={formData.name}
                                        onChange={e => setFormData({ ...formData, name: e.target.value })}
                                    />
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-slate-700 mb-1">Harga (IDR)</label>
                                    <input
                                        type="number" required
                                        className="w-full px-3 py-2 border border-slate-300 rounded-lg"
                                        value={formData.price}
                                        onChange={e => setFormData({ ...formData, price: e.target.value })}
                                    />
                                </div>
                                <div>
                                    <label className="block text-sm font-medium text-slate-700 mb-1">Siklus Tagihan</label>
                                    <select
                                        className="w-full px-3 py-2 border border-slate-300 rounded-lg"
                                        value={formData.billing_cycle}
                                        onChange={e => setFormData({ ...formData, billing_cycle: e.target.value })}
                                    >
                                        <option value="monthly">Bulanan</option>
                                        <option value="yearly">Tahunan</option>
                                    </select>
                                </div>
                                <div className="flex items-center gap-4 pt-6">
                                    <label className="flex items-center gap-2 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            checked={formData.is_active}
                                            onChange={e => setFormData({ ...formData, is_active: e.target.checked })}
                                        />
                                        <span className="text-sm">Aktif</span>
                                    </label>
                                    <label className="flex items-center gap-2 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            checked={formData.is_popular}
                                            onChange={e => setFormData({ ...formData, is_popular: e.target.checked })}
                                        />
                                        <span className="text-sm">Populer</span>
                                    </label>
                                </div>
                            </div>

                            <div className="border-t border-slate-100 pt-4">
                                <h3 className="font-semibold text-slate-800 mb-3">Fitur & Batasan (-1 untuk Unlimited)</h3>
                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <label className="block text-xs font-medium text-slate-500 mb-1">Max Siswa</label>
                                        <input
                                            type="number" required
                                            className="w-full px-3 py-2 border border-slate-300 rounded-lg"
                                            value={formData.features.max_students}
                                            onChange={e => setFormData({ ...formData, features: { ...formData.features, max_students: Number(e.target.value) } })}
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-medium text-slate-500 mb-1">Max Guru</label>
                                        <input
                                            type="number" required
                                            className="w-full px-3 py-2 border border-slate-300 rounded-lg"
                                            value={formData.features.max_teachers}
                                            onChange={e => setFormData({ ...formData, features: { ...formData.features, max_teachers: Number(e.target.value) } })}
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-medium text-slate-500 mb-1">Max Kelas</label>
                                        <input
                                            type="number" required
                                            className="w-full px-3 py-2 border border-slate-300 rounded-lg"
                                            value={formData.features.max_classes}
                                            onChange={e => setFormData({ ...formData, features: { ...formData.features, max_classes: Number(e.target.value) } })}
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-medium text-slate-500 mb-1">Storage (GB)</label>
                                        <input
                                            type="number" required
                                            className="w-full px-3 py-2 border border-slate-300 rounded-lg"
                                            value={formData.features.storage_gb}
                                            onChange={e => setFormData({ ...formData, features: { ...formData.features, storage_gb: Number(e.target.value) } })}
                                        />
                                    </div>
                                    <div className="col-span-2">
                                        <label className="block text-xs font-medium text-slate-500 mb-1">Dukungan</label>
                                        <input
                                            type="text" required
                                            className="w-full px-3 py-2 border border-slate-300 rounded-lg"
                                            value={formData.features.support}
                                            onChange={e => setFormData({ ...formData, features: { ...formData.features, support: e.target.value } })}
                                        />
                                    </div>
                                </div>
                            </div>

                            <div className="flex justify-end gap-3 pt-4 border-t border-slate-100">
                                <button type="button" onClick={() => setShowModal(false)} className="px-4 py-2 text-slate-600 hover:bg-slate-50 rounded-lg">Batal</button>
                                <button type="submit" className="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Simpan Paket</button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
};
