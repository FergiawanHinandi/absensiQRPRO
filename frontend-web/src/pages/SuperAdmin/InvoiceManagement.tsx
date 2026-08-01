import React, { useEffect, useState, useRef } from 'react';
import {
    FileText,
    Filter,
    Search,
    CheckCircle,
    Clock,
    XCircle,
    Plus,
    X,
    Building2,
    Save,
    Printer
} from 'lucide-react';
import { apiClient } from '../../lib/api';
import showToast from '../../utils/toast';
import { useReactToPrint } from 'react-to-print';

interface Invoice {
    id: number;
    transaction_id: string;
    school_id: number;
    school?: {
        id: number;
        name: string;
        address?: string; // Additional info for invoice
        package_type?: string;
    };
    amount: number;
    description: string;
    status: string;
    created_at: string;
    payment_date: string | null;
}

interface SchoolOption {
    id: number;
    name: string;
}

// Komponen Invoice Print Template
const InvoiceTemplate = React.forwardRef<HTMLDivElement, { invoice: Invoice | null }>((props, ref) => {
    const { invoice } = props;
    if (!invoice) return null;

    return (
        <div ref={ref} className="bg-white p-8 max-w-4xl mx-auto text-slate-900 font-sans print:p-0">
            {/* Header */}
            <div className="flex justify-between items-start mb-8 border-b pb-6">
                <div>
                    <h1 className="text-2xl font-bold text-blue-600 mb-1">AbsensiQR<span className="text-slate-900">Pro</span></h1>
                    <p className="text-sm text-slate-500">Platform Absensi Digital Terintegrasi</p>
                    <div className="mt-4 text-sm text-slate-600">
                        <p className="font-semibold">Diterbitkan Oleh:</p>
                        <p>PT. Absensi Digital Indonesia</p>
                        <p>Jl. Teknologi No. 10, Jakarta Selatan</p>
                        <p>support@absensiqrpro.com | 0812-3456-7890</p>
                    </div>
                </div>
                <div className="text-right">
                    <h2 className="text-3xl font-bold text-slate-200 uppercase tracking-widest mb-2">INVOICE</h2>
                    <p className="font-semibold text-lg text-slate-700">#{invoice.transaction_id}</p>
                    <div className="mt-4 text-sm">
                        <p className="text-slate-500">Tanggal Terbit</p>
                        <p className="font-medium">{new Date(invoice.created_at).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' })}</p>
                    </div>
                    <div className="mt-2 text-sm">
                        <p className="text-slate-500">Jatuh Tempo</p>
                        <p className="font-medium text-red-600">7 Hari setelah tanggal terbit</p>
                    </div>
                </div>
            </div>

            {/* Bill To */}
            <div className="mb-8">
                <p className="text-slate-500 text-sm font-semibold uppercase tracking-wider mb-2">Ditujukan Kepada:</p>
                <div className="bg-slate-50 p-4 rounded-lg border border-slate-200">
                    <h3 className="text-lg font-bold text-slate-800">{invoice.school?.name}</h3>
                    <p className="text-slate-600">{invoice.school?.address || 'Alamat Sekolah Belum Dilengkapi'}</p>
                    <p className="text-slate-600 mt-1">ID Pelanggan: SCH-{invoice.school_id.toString().padStart(4, '0')}</p>
                </div>
            </div>

            {/* Items Table */}
            <table className="w-full mb-8">
                <thead>
                    <tr className="bg-slate-100 border-b border-slate-200">
                        <th className="text-left py-3 px-4 font-semibold text-slate-700">Deskripsi Layanan</th>
                        <th className="text-right py-3 px-4 font-semibold text-slate-700 w-48">Jumlah</th>
                    </tr>
                </thead>
                <tbody>
                    <tr className="border-b border-slate-100">
                        <td className="py-4 px-4 align-top">
                            <p className="font-medium text-slate-800 text-lg mb-1">{invoice.description}</p>
                            <p className="text-slate-500 text-sm">Paket Langganan: {invoice.school?.package_type || 'Custom'}</p>
                        </td>
                        <td className="py-4 px-4 text-right font-medium text-lg">
                            {new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR' }).format(invoice.amount)}
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td className="pt-4 px-4 text-right font-bold text-slate-700">Total Tagihan</td>
                        <td className="pt-4 px-4 text-right font-bold text-2xl text-blue-600">
                            {new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR' }).format(invoice.amount)}
                        </td>
                    </tr>
                </tfoot>
            </table>

            {/* Payment Info */}
            <div className="flex gap-8 mb-8">
                <div className="flex-1 bg-blue-50 p-6 rounded-lg border border-blue-100">
                    <h4 className="font-bold text-blue-800 mb-3 flex items-center gap-2">
                        <CheckCircle className="w-5 h-5" />
                        Metode Pembayaran
                    </h4>
                    <p className="text-sm text-blue-700 mb-4">
                        Mohon lakukan transfer ke rekening berikut:
                    </p>
                    <div className="space-y-3">
                        <div className="bg-white p-3 rounded border border-blue-100">
                            <p className="text-xs text-slate-500 uppercase">Bank BCA</p>
                            <p className="font-mono text-lg font-bold text-slate-800">123-456-7890</p>
                            <p className="text-sm text-slate-600">a.n PT ABSENSI DIGITAL</p>
                        </div>
                        <div className="bg-white p-3 rounded border border-blue-100">
                            <p className="text-xs text-slate-500 uppercase">Bank Mandiri</p>
                            <p className="font-mono text-lg font-bold text-slate-800">123-000-456-7890</p>
                            <p className="text-sm text-slate-600">a.n PT ABSENSI DIGITAL</p>
                        </div>
                    </div>
                </div>
                <div className="flex-1 text-sm text-slate-600">
                    <h4 className="font-bold text-slate-800 mb-2">Instruksi Pembayaran:</h4>
                    <ol className="list-decimal pl-4 space-y-1">
                        <li>Pastikan jumlah transfer sesuai hingga digit terakhir.</li>
                        <li>Cantumkan <strong>No. Invoice (#{invoice.transaction_id})</strong> pada berita transfer.</li>
                        <li>Kirim bukti transfer ke WhatsApp admin kami: <strong>0812-3456-7890</strong>.</li>
                        <li>Akun akan aktif otomatis maksimal 1x24 jam setelah verifikasi.</li>
                    </ol>

                    <div className="mt-6 p-3 bg-yellow-50 text-yellow-800 rounded border border-yellow-200 text-xs">
                        <strong>Perhatian:</strong> Keterlambatan pembayaran lebih dari 7 hari dapat mengakibatkan pembekuan akses sementara.
                    </div>
                </div>
            </div>

            {/* Footer */}
            <div className="text-center text-xs text-slate-500 pt-8 border-t border-slate-200">
                <p>Terima kasih telah berlangganan AbsensiQRPro.</p>
                <p>Dokumen ini adalah bukti tagihan yang sah dan diterbitkan secara komputerisasi.</p>
            </div>
        </div>
    );
});


export const InvoiceManagement: React.FC = () => {
    const [invoices, setInvoices] = useState<Invoice[]>([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [filterStatus, setFilterStatus] = useState<string>('pending');

    // Create Invoice State
    const [showCreateModal, setShowCreateModal] = useState(false);
    const [schools, setSchools] = useState<SchoolOption[]>([]);
    const [packages, setPackages] = useState<any[]>([]); // New Package State
    const [formData, setFormData] = useState({
        school_id: '',
        package_id: '', // New field
        description: '',
        amount: '',
    });
    const [submitting, setSubmitting] = useState(false);

    // Print State
    const [showPrintModal, setShowPrintModal] = useState(false);
    const [selectedInvoice, setSelectedInvoice] = useState<Invoice | null>(null);
    const printComponentRef = useRef<HTMLDivElement>(null);

    // React To Print Hook - Pindahkan useReactToPrint ke sini
    const handlePrint = useReactToPrint({
        contentRef: printComponentRef,
        documentTitle: `Invoice-${selectedInvoice?.transaction_id || 'Document'}`,
        onAfterPrint: () => {},
    });

    useEffect(() => {
        fetchInvoices();
    }, [search, filterStatus]);

    useEffect(() => {
        if (showCreateModal) {
            fetchSchools();
            fetchPackages();
        }
    }, [showCreateModal]);

    const fetchInvoices = async () => {
        try {
            setLoading(true);
            // FE-03 FIX: interceptor sudah auto-unwrap response, tidak perlu .data.data.data
            const response = await apiClient.get('/super-admin/billing/payments', {
                params: {
                    search: search || undefined,
                    status: filterStatus === 'all' ? undefined : filterStatus,
                },
            });
            // response.data sekarang = payload data sebenarnya (sudah di-unwrap)
            const payload = response.data as any;
            const items = payload?.data ?? payload ?? [];
            setInvoices(Array.isArray(items) ? items : []);
        } catch (error) {
            console.error('Failed to fetch invoices:', error);
        } finally {
            setLoading(false);
        }
    };

    const fetchSchools = async () => {
        try {
            // FE-03 FIX: response.data sudah di-unwrap oleh interceptor
            const response = await apiClient.get('/super-admin/schools');
            const payload = response.data as any;
            const items = payload?.data ?? payload ?? [];
            setSchools(Array.isArray(items) ? items : []);
        } catch (error) {
            console.error('Failed to fetch schools options');
        }
    };

    const fetchPackages = async () => {
        try {
            // FE-03 FIX: response.data sudah di-unwrap oleh interceptor
            const response = await apiClient.get('/super-admin/billing/packages');
            const payload = response.data as any;
            setPackages(Array.isArray(payload) ? payload : payload?.data ?? []);
        } catch (error) {
            console.error('Failed to fetch packages');
        }
    };

    // Handle Package Change Logic
    const handlePackageChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
        const pkgId = e.target.value;
        const pkg = packages.find(p => p.id === Number(pkgId));

        if (pkg) {
            setFormData({
                ...formData,
                package_id: pkgId,
                amount: pkg.price.toString(),
                description: `Berlangganan Paket ${pkg.name} (${pkg.billing_cycle})`
            });
        } else {
            setFormData({
                ...formData,
                package_id: '',
                amount: '',
                description: ''
            });
        }
    };

    const handleCreateInvoice = async (e: React.FormEvent) => {
        e.preventDefault();
        try {
            setSubmitting(true);
            await apiClient.post('/super-admin/billing/payments', {
                school_id: formData.school_id,
                package_id: formData.package_id || null,
                description: formData.description,
                amount: parseFloat(formData.amount),
                status: 'pending'
            });

            setShowCreateModal(false);
            setFormData({ school_id: '', package_id: '', description: '', amount: '' });
            fetchInvoices(); // Refresh list
            showToast.success('Invoice berhasil dibuat!');
        } catch (error) {
            showToast.error('Gagal membuat invoice. Pastikan semua field terisi.');
        } finally {
            setSubmitting(false);
        }
    };

    const handleMarkAsPaid = async (id: number) => {
        if (!confirm('Tandai invoice ini sebagai LUNAS?')) return;

        try {
            await apiClient.patch(`/super-admin/billing/payments/${id}/status`, {
                status: 'paid',
                payment_date: new Date().toISOString()
            });
            fetchInvoices(); // Refresh list
            showToast.success('Status pembayaran berhasil diupdate');
        } catch (error) {
            showToast.error('Gagal mengupdate status pembayaran.');
        }
    };

    const handleOpenPrint = (invoice: Invoice) => {
        setSelectedInvoice(invoice);
        setShowPrintModal(true);
    };

    const formatCurrency = (amount: number) => {
        return new Intl.NumberFormat('id-ID', {
            style: 'currency',
            currency: 'IDR',
            minimumFractionDigits: 0,
        }).format(amount);
    };

    const getStatusBadge = (status: string) => {
        switch (status) {
            case 'paid':
                return (
                    <span className="inline-flex items-center gap-1 px-3 py-1 bg-green-100 text-green-800 rounded-full text-sm font-semibold">
                        <CheckCircle className="w-4 h-4" />
                        Lunas
                    </span>
                );
            case 'pending':
                return (
                    <span className="inline-flex items-center gap-1 px-3 py-1 bg-yellow-100 text-yellow-800 rounded-full text-sm font-semibold">
                        <Clock className="w-4 h-4" />
                        Pending
                    </span>
                );
            case 'failed':
                return (
                    <span className="inline-flex items-center gap-1 px-3 py-1 bg-red-100 text-red-800 rounded-full text-sm font-semibold">
                        <XCircle className="w-4 h-4" />
                        Gagal
                    </span>
                );
            default:
                return null;
        }
    };

    return (
        <div className="min-h-screen bg-slate-50 p-6">
            {/* Header */}
            <div className="mb-6 flex justify-between items-center">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 mb-2">Invoice Management</h1>
                    <p className="text-slate-600">Kelola dan monitor semua tagihan sekolah</p>
                </div>
                <button
                    onClick={() => setShowCreateModal(true)}
                    className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors shadow-sm"
                >
                    <Plus className="w-5 h-5" />
                    <span>Buat Invoice</span>
                </button>
            </div>

            {/* Actions Bar */}
            <div className="bg-white rounded-lg shadow-sm border border-slate-200 p-4 mb-6">
                <div className="flex flex-col md:flex-row gap-4">
                    {/* Search */}
                    <div className="flex-1 relative">
                        <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-slate-400" />
                        <input
                            type="text"
                            placeholder="Cari invoice atau sekolah..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="w-full pl-10 pr-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        />
                    </div>

                    {/* Status Filter */}
                    <div className="flex items-center gap-2">
                        <Filter className="w-5 h-5 text-slate-500" />
                        <div className="flex gap-2">
                            {['all', 'pending', 'paid', 'failed'].map((status) => (
                                <button
                                    key={status}
                                    onClick={() => setFilterStatus(status)}
                                    className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${filterStatus === status
                                        ? 'bg-blue-600 text-white'
                                        : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
                                        }`}
                                >
                                    {status === 'all' ? 'Semua' : status === 'paid' ? 'Lunas' : status === 'pending' ? 'Pending' : 'Gagal'}
                                </button>
                            ))}
                        </div>
                    </div>
                </div>
            </div>

            {/* Summary Cards */}
            <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div className="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
                    <div className="flex items-center gap-3">
                        <div className="p-3 bg-blue-50 rounded-lg">
                            <FileText className="w-6 h-6 text-blue-600" />
                        </div>
                        <div>
                            <p className="text-sm text-slate-600">Total Invoice</p>
                            <p className="text-2xl font-bold text-slate-900">{invoices.length}</p>
                        </div>
                    </div>
                </div>

                <div className="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
                    <div className="flex items-center gap-3">
                        <div className="p-3 bg-yellow-50 rounded-lg">
                            <Clock className="w-6 h-6 text-yellow-600" />
                        </div>
                        <div>
                            <p className="text-sm text-slate-600">Pending</p>
                            <p className="text-2xl font-bold text-yellow-600">
                                {invoices.filter(i => i.status === 'pending').length}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            {/* Invoices List */}
            <div className="bg-white rounded-lg shadow-sm border border-slate-200">
                <div className="p-6 border-b border-slate-200">
                    <h2 className="text-lg font-bold text-slate-900">Daftar Invoice</h2>
                </div>

                <div className="divide-y divide-slate-200">
                    {loading ? (
                        <div className="flex justify-center py-12">
                            <div className="w-8 h-8 border-4 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
                        </div>
                    ) : invoices.length === 0 ? (
                        <div className="p-12 text-center text-slate-500">
                            Tidak ada invoice ditemukan
                        </div>
                    ) : (
                        invoices.map((invoice) => (
                            <div key={invoice.id} className="p-6 hover:bg-slate-50 transition-colors">
                                <div className="flex items-center justify-between gap-6">
                                    {/* Invoice Info */}
                                    <div className="flex-1">
                                        <div className="flex items-center gap-3 mb-3">
                                            <h3 className="font-bold text-slate-900 text-lg">{invoice.transaction_id}</h3>
                                            {getStatusBadge(invoice.status)}
                                        </div>

                                        <div className="grid grid-cols-1 md:grid-cols-4 gap-4 text-sm">
                                            <div>
                                                <p className="text-slate-600 mb-1">Sekolah</p>
                                                <p className="font-medium text-slate-900">{invoice.school?.name || 'Unknown'}</p>
                                                <p className="text-xs text-slate-500">{invoice.school?.package_type}</p>
                                            </div>
                                            <div>
                                                <p className="text-slate-600 mb-1">Deskripsi</p>
                                                <p className="font-medium text-slate-900">{invoice.description}</p>
                                            </div>
                                            <div>
                                                <p className="text-slate-600 mb-1">Jumlah</p>
                                                <p className="font-semibold text-slate-900">{formatCurrency(invoice.amount)}</p>
                                            </div>
                                            <div>
                                                <p className="text-slate-600 mb-1">Tanggal</p>
                                                <p className="font-medium text-slate-900">
                                                    {new Date(invoice.created_at).toLocaleDateString('id-ID')}
                                                </p>
                                            </div>
                                        </div>

                                        {invoice.payment_date && (
                                            <div className="mt-2 text-xs text-green-600">
                                                Dibayar: {new Date(invoice.payment_date).toLocaleDateString('id-ID')}
                                            </div>
                                        )}
                                    </div>

                                    {/* Actions */}
                                    <div className="flex gap-2">
                                        {invoice.status === 'pending' && (
                                            <button
                                                onClick={() => handleMarkAsPaid(invoice.id)}
                                                className="px-3 py-1 bg-green-600 text-white rounded text-sm hover:bg-green-700 transition-colors whitespace-nowrap"
                                                title="Tandai Sudah Bayar"
                                            >
                                                Mark Paid
                                            </button>
                                        )}
                                        <button
                                            onClick={() => handleOpenPrint(invoice)}
                                            className="p-2 hover:bg-slate-100 rounded-lg transition-colors text-slate-600"
                                            title="Print Invoice"
                                        >
                                            <Printer className="w-5 h-5" />
                                        </button>
                                    </div>
                                </div>
                            </div>
                        ))
                    )}
                </div>
            </div>

            {/* Create Invoice Modal */}
            {showCreateModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
                    <div className="bg-white rounded-xl shadow-xl w-full max-w-lg overflow-hidden animate-in fade-in zoom-in duration-200">
                        <div className="flex items-center justify-between p-6 border-b border-slate-200">
                            <h2 className="text-xl font-bold text-slate-900">Buat Tagihan Baru</h2>
                            <button
                                onClick={() => setShowCreateModal(false)}
                                className="p-2 hover:bg-slate-100 rounded-lg transition-colors"
                            >
                                <X className="w-5 h-5 text-slate-500" />
                            </button>
                        </div>

                        <form onSubmit={handleCreateInvoice} className="p-6">
                            <div className="space-y-4">
                                {/* School Select */}
                                <div>
                                    <label className="block text-sm font-medium text-slate-700 mb-1">
                                        Pilih Sekolah
                                    </label>
                                    <div className="relative">
                                        <Building2 className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-slate-400" />
                                        <select
                                            required
                                            value={formData.school_id}
                                            onChange={(e) => setFormData({ ...formData, school_id: e.target.value })}
                                            className="w-full pl-10 pr-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                        >
                                            <option value="">-- Pilih Sekolah --</option>
                                            {schools.map(school => (
                                                <option key={school.id} value={school.id}>
                                                    {school.name}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                </div>

                                {/* Package Select (Optional) */}
                                <div>
                                    <label className="block text-sm font-medium text-slate-700 mb-1">
                                        Pilih Paket (Opsional)
                                    </label>
                                    <div className="relative">
                                        <CheckCircle className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-slate-400" />
                                        <select
                                            value={formData.package_id}
                                            onChange={handlePackageChange}
                                            className="w-full pl-10 pr-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                        >
                                            <option value="">-- Manual Input / Custom --</option>
                                            {packages.map(pkg => (
                                                <option key={pkg.id} value={pkg.id}>
                                                    {pkg.name} - {new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR' }).format(pkg.price)}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                </div>

                                {/* Amount */}
                                <div>
                                    <label className="block text-sm font-medium text-slate-700 mb-1">
                                        Nominal Tagihan (Rp)
                                    </label>
                                    <input
                                        type="number"
                                        required
                                        min="0"
                                        value={formData.amount}
                                        onChange={(e) => setFormData({ ...formData, amount: e.target.value })}
                                        className="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                        placeholder="Contoh: 1500000"
                                    />
                                </div>

                                {/* Description */}
                                <div>
                                    <label className="block text-sm font-medium text-slate-700 mb-1">
                                        Deskripsi / Keterangan
                                    </label>
                                    <textarea
                                        required
                                        rows={3}
                                        value={formData.description}
                                        onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                                        className="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                        placeholder="Contoh: Pembayaran Langganan Paket Pro Periode 2026-2027"
                                    />
                                </div>
                            </div>

                            <div className="flex justify-end gap-3 mt-8">
                                <button
                                    type="button"
                                    onClick={() => setShowCreateModal(false)}
                                    className="px-4 py-2 text-slate-700 hover:bg-slate-100 rounded-lg font-medium"
                                >
                                    Batal
                                </button>
                                <button
                                    type="submit"
                                    disabled={submitting}
                                    className="flex items-center gap-2 px-6 py-2 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 disabled:opacity-50"
                                >
                                    {submitting ? (
                                        <div className="w-5 h-5 border-2 border-white border-t-transparent rounded-full animate-spin" />
                                    ) : (
                                        <>
                                            <Save className="w-4 h-4" />
                                            Simpan Invoice
                                        </>
                                    )}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Print Invoice Modal Overlay */}
            {showPrintModal && selectedInvoice && (
                <div className="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/75 backdrop-blur-sm overflow-y-auto">
                    <div className="relative w-full max-w-4xl my-8">
                        {/* Close & Print Buttons */}
                        <div className="flex justify-end gap-3 mb-4 sticky top-0 z-10">
                            <button
                                onClick={() => setShowPrintModal(false)}
                                className="bg-white text-slate-700 px-4 py-2 rounded-lg shadow hover:bg-slate-100 font-medium transition-colors"
                            >
                                Tutup
                            </button>
                            <button
                                onClick={() => handlePrint && handlePrint()}
                                className="bg-blue-600 text-white px-6 py-2 rounded-lg shadow hover:bg-blue-700 font-medium flex items-center gap-2 transition-colors"
                            >
                                <Printer className="w-5 h-5" />
                                Cetak Invoice
                            </button>
                        </div>

                        {/* Invoice Paper Preview */}
                        <div className="shadow-2xl rounded-lg overflow-hidden">
                            <InvoiceTemplate ref={printComponentRef} invoice={selectedInvoice} />
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};
