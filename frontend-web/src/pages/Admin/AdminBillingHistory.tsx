import React, { useState, useEffect } from 'react';
import { CreditCard, Download, Calendar, Filter } from 'lucide-react';
import { apiClient } from '../../../lib/api';
import showToast from '../../../utils/toast';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';

interface Invoice {
    id: number;
    invoice_number: string;
    school_name: string;
    package_name: string;
    amount: number;
    status: 'paid' | 'pending' | 'overdue' | 'cancelled';
    due_date: string;
    paid_date: string | null;
    created_at: string;
}

const AdminBillingHistory: React.FC = () => {
    const [invoices, setInvoices] = useState<Invoice[]>([]);
    const [loading, setLoading] = useState(true);
    const [filter, setFilter] = useState<'all' | 'paid' | 'pending' | 'overdue'>('all');

    useEffect(() => {
        fetchInvoices();
    }, [filter]);

    const fetchInvoices = async () => {
        try {
            setLoading(true);
            const params = filter !== 'all' ? { status: filter } : {};
            const response = await apiClient.get('/admin/billing/invoices', { params });
            setInvoices(response.data.data || []);
        } catch (error) {
            if (import.meta.env.DEV) {
                console.error('Failed to fetch invoices:', error);
            }
            showToast.error('Gagal memuat riwayat tagihan');
        } finally {
            setLoading(false);
        }
    };

    const getStatusBadge = (status: string) => {
        switch (status) {
            case 'paid': return 'bg-green-100 text-green-700 border-green-200';
            case 'pending': return 'bg-yellow-100 text-yellow-700 border-yellow-200';
            case 'overdue': return 'bg-red-100 text-red-700 border-red-200';
            case 'cancelled': return 'bg-slate-100 text-slate-500 border-slate-200';
            default: return 'bg-slate-100 text-slate-500';
        }
    };

    const formatCurrency = (amount: number) => {
        return new Intl.NumberFormat('id-ID', {
            style: 'currency',
            currency: 'IDR',
            minimumFractionDigits: 0
        }).format(amount);
    };

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900">Riwayat Tagihan</h1>
                    <p className="text-sm text-slate-500 mt-1">Lihat riwayat tagihan dan pembayaran langganan</p>
                </div>
                <div className="flex items-center gap-2">
                    <select
                        value={filter}
                        onChange={(e) => setFilter(e.target.value as typeof filter)}
                        className="px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                        <option value="all">Semua Status</option>
                        <option value="paid">Lunas</option>
                        <option value="pending">Menunggu</option>
                        <option value="overdue">Terlambat</option>
                    </select>
                </div>
            </div>

            {loading ? (
                <div className="text-center py-12 text-slate-500">Memuat data...</div>
            ) : invoices.length === 0 ? (
                <div className="text-center py-12 bg-white rounded-xl border border-slate-200">
                    <CreditCard className="w-12 h-12 mx-auto text-slate-300 mb-3" />
                    <p className="text-slate-500">Belum ada riwayat tagihan</p>
                </div>
            ) : (
                <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-slate-50 border-b border-slate-200">
                                <tr>
                                    <th className="text-left px-4 py-3 font-semibold text-slate-600">No. Invoice</th>
                                    <th className="text-left px-4 py-3 font-semibold text-slate-600">Sekolah</th>
                                    <th className="text-left px-4 py-3 font-semibold text-slate-600">Paket</th>
                                    <th className="text-left px-4 py-3 font-semibold text-slate-600">Jumlah</th>
                                    <th className="text-left px-4 py-3 font-semibold text-slate-600">Status</th>
                                    <th className="text-left px-4 py-3 font-semibold text-slate-600">Jatuh Tempo</th>
                                    <th className="text-left px-4 py-3 font-semibold text-slate-600">Dibayar</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {invoices.map((invoice) => (
                                    <tr key={invoice.id} className="hover:bg-slate-50 transition-colors">
                                        <td className="px-4 py-3 font-mono text-xs text-slate-700">{invoice.invoice_number}</td>
                                        <td className="px-4 py-3 text-slate-900 font-medium">{invoice.school_name}</td>
                                        <td className="px-4 py-3 text-slate-600">{invoice.package_name}</td>
                                        <td className="px-4 py-3 text-slate-900 font-semibold">{formatCurrency(invoice.amount)}</td>
                                        <td className="px-4 py-3">
                                            <span className={`inline-block px-2 py-0.5 rounded text-xs font-medium border ${getStatusBadge(invoice.status)}`}>
                                                {invoice.status === 'paid' ? 'Lunas' : invoice.status === 'pending' ? 'Menunggu' : invoice.status === 'overdue' ? 'Terlambat' : 'Dibatalkan'}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-slate-600">
                                            {format(new Date(invoice.due_date), 'dd MMM yyyy', { locale: id })}
                                        </td>
                                        <td className="px-4 py-3 text-slate-600">
                                            {invoice.paid_date ? format(new Date(invoice.paid_date), 'dd MMM yyyy', { locale: id }) : '-'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </div>
    );
};

export default AdminBillingHistory;
