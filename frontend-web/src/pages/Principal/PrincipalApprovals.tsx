import React, { useEffect, useState } from 'react';
import { apiClient } from '../../lib/api';
import { ShieldCheck, AlertTriangle, CheckCircle2, XCircle, Clock, Users } from 'lucide-react';
import { SkeletonTable } from '../../components/ui/LoadingStates';
import { EmptyState } from '../../components/ui/EmptyStates';

interface PendingApproval {
  id: number;
  student: { id: number; name: string };
  class: { id: number; name: string } | null;
  type: 'sick' | 'permit';
  start_date: string;
  end_date: string;
  reason: string;
  created_at: string;
}

// A3-H7 FIX: Halaman ini sebelumnya menggunakan useRiskStudents() yang SALAH.
// Seharusnya menampilkan daftar izin PENDING, bukan data risiko siswa.
// Diperbaiki agar mengambil data dari endpoint /api/v1/principal/approvals
const PrincipalApprovals: React.FC = () => {
  const [approvals, setApprovals] = useState<PendingApproval[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [processing, setProcessing] = useState<number | null>(null);
  const [successMsg, setSuccessMsg] = useState<string | null>(null);

  const fetchApprovals = async () => {
    try {
      setLoading(true);
      setError(null);
      const res = await apiClient.get('/principal/approvals');
      const payload = res.data as any;
      const items = payload?.data ?? payload ?? [];
      setApprovals(Array.isArray(items) ? items : []);
    } catch (err: any) {
      setError(err?.response?.data?.message ?? 'Gagal memuat data persetujuan.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchApprovals();
  }, []);

  const handleApprove = async (id: number) => {
    try {
      setProcessing(id);
      await apiClient.post(`/principal/approvals/${id}/approve`);
      setSuccessMsg('Permohonan berhasil disetujui.');
      setApprovals(prev => prev.filter(a => a.id !== id));
    } catch {
      setError('Gagal menyetujui permohonan.');
    } finally {
      setProcessing(null);
      setTimeout(() => setSuccessMsg(null), 3000);
    }
  };

  const handleReject = async (id: number) => {
    const reason = window.prompt('Masukkan alasan penolakan (min 10 karakter):');
    if (!reason || reason.trim().length < 10) {
      alert('Alasan minimal 10 karakter.');
      return;
    }
    try {
      setProcessing(id);
      await apiClient.post(`/principal/approvals/${id}/reject`, { reason: reason.trim() });
      setSuccessMsg('Permohonan berhasil ditolak.');
      setApprovals(prev => prev.filter(a => a.id !== id));
    } catch {
      setError('Gagal menolak permohonan.');
    } finally {
      setProcessing(null);
      setTimeout(() => setSuccessMsg(null), 3000);
    }
  };

  if (loading) {
    return (
      <div className="p-6">
        <SkeletonTable rows={5} cols={6} />
      </div>
    );
  }

  return (
    <div className="p-6 space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-slate-900 flex items-center gap-2">
          <ShieldCheck className="w-6 h-6 text-blue-600" />
          Approval &amp; Tindak Lanjut
        </h1>
        <p className="text-sm text-slate-500">Persetujuan izin sakit/dispensasi siswa yang menunggu konfirmasi</p>
      </div>

      {/* Notifikasi */}
      {error && (
        <div className="bg-red-50 border border-red-200 rounded-xl p-4 text-red-700 text-sm flex items-center gap-2">
          <AlertTriangle className="w-4 h-4 flex-shrink-0" />
          {error}
        </div>
      )}
      {successMsg && (
        <div className="bg-green-50 border border-green-200 rounded-xl p-4 text-green-700 text-sm flex items-center gap-2">
          <CheckCircle2 className="w-4 h-4 flex-shrink-0" />
          {successMsg}
        </div>
      )}

      {/* Summary */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-4 text-center col-span-2">
          <p className="text-3xl font-bold text-blue-600">{approvals.length}</p>
          <p className="text-xs text-slate-500 mt-1">Total Menunggu Persetujuan</p>
        </div>
        <div className="bg-amber-50 rounded-xl border border-amber-200 p-4 text-center">
          <p className="text-2xl font-bold text-amber-600">
            {approvals.filter(a => a.type === 'sick').length}
          </p>
          <p className="text-xs text-amber-500">Sakit</p>
        </div>
        <div className="bg-blue-50 rounded-xl border border-blue-200 p-4 text-center">
          <p className="text-2xl font-bold text-blue-600">
            {approvals.filter(a => a.type === 'permit').length}
          </p>
          <p className="text-xs text-blue-500">Izin / Dispensasi</p>
        </div>
      </div>

      {/* Approvals Table */}
      <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
        <h2 className="text-lg font-semibold text-slate-800 mb-4 flex items-center gap-2">
          <Clock className="w-5 h-5 text-amber-500" />
          Permohonan Menunggu ({approvals.length})
        </h2>

        {approvals.length === 0 ? (
          <EmptyState
            icon={CheckCircle2}
            title="Semua Permohonan Sudah Diproses! 🎉"
            description="Tidak ada permohonan izin yang menunggu konfirmasi."
            size="md"
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-slate-400 text-xs uppercase border-b border-slate-100">
                  <th className="pb-3">Siswa</th>
                  <th className="pb-3">Kelas</th>
                  <th className="pb-3 text-center">Tipe</th>
                  <th className="pb-3">Periode</th>
                  <th className="pb-3">Alasan</th>
                  <th className="pb-3 text-center">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-50">
                {approvals.map((a) => (
                  <tr key={a.id} className="hover:bg-slate-50">
                    <td className="py-3 font-medium text-slate-800">{a.student?.name ?? '—'}</td>
                    <td className="py-3 text-slate-500">
                      <span className="flex items-center gap-1">
                        <Users className="w-3.5 h-3.5" />
                        {a.class?.name ?? 'N/A'}
                      </span>
                    </td>
                    <td className="py-3 text-center">
                      <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${a.type === 'sick' ? 'bg-amber-100 text-amber-700' : 'bg-blue-100 text-blue-700'
                        }`}>
                        {a.type === 'sick' ? 'Sakit' : 'Izin'}
                      </span>
                    </td>
                    <td className="py-3 text-slate-500 text-xs">{a.start_date} → {a.end_date}</td>
                    <td className="py-3 text-slate-600 max-w-[200px] truncate">{a.reason}</td>
                    <td className="py-3 text-center">
                      <div className="flex items-center justify-center gap-2">
                        <button
                          onClick={() => handleApprove(a.id)}
                          disabled={processing === a.id}
                          className="p-1.5 rounded-lg bg-green-50 hover:bg-green-100 text-green-600 transition-colors disabled:opacity-50"
                          title="Setujui"
                        >
                          <CheckCircle2 className="w-4 h-4" />
                        </button>
                        <button
                          onClick={() => handleReject(a.id)}
                          disabled={processing === a.id}
                          className="p-1.5 rounded-lg bg-red-50 hover:bg-red-100 text-red-600 transition-colors disabled:opacity-50"
                          title="Tolak"
                        >
                          <XCircle className="w-4 h-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
};

export default PrincipalApprovals;
