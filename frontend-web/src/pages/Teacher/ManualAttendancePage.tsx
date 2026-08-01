import React, { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { teacherService } from '../../services/teacherService';
import type { AttendancePayload, AttendanceStatus } from '../../services/teacherService';
import { Save, ArrowLeft, CheckCircle, AlertCircle, Loader2 } from 'lucide-react';
import { toast } from 'react-hot-toast';
import { SkeletonTable } from '../../components/ui/LoadingStates';
import { EmptyState } from '../../components/ui/EmptyStates';

const ManualAttendancePage: React.FC = () => {
  // We expect sessionId to be in the URL path: /teacher/attendance/manual/:sessionId
  const { sessionId } = useParams<{ sessionId: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const id = Number(sessionId);

  // Local state for form data
  const [formData, setFormData] = useState<Record<number, AttendancePayload>>({});
  const [isSubmitting, setIsSubmitting] = useState(false);

  // Fetch students
  const { data: students, isLoading, isError, error } = useQuery({
    queryKey: ['session-students', id],
    queryFn: () => teacherService.getStudentsBySession(id),
    enabled: !!id,
  });

  // Initialize local state when students data is loaded
  useEffect(() => {
    if (students) {
      const initialData: Record<number, AttendancePayload> = {};
      students.forEach(student => {
        initialData[student.id] = {
          student_id: student.id,
          status: student.attendance_status || 'present', // Default to present
          notes: student.attendance_notes || ''
        };
      });
      setFormData(initialData);
    }
  }, [students]);

  // Handle input changes
  const handleStatusChange = (studentId: number, status: AttendanceStatus) => {
    setFormData(prev => ({
      ...prev,
      [studentId]: { ...prev[studentId], status }
    }));
  };

  const handleNotesChange = (studentId: number, notes: string) => {
    setFormData(prev => ({
      ...prev,
      [studentId]: { ...prev[studentId], notes }
    }));
  };

  // Single Save Mutation
  const saveSingleMutation = useMutation({
    mutationFn: (payload: AttendancePayload) => teacherService.saveAttendance(id, payload),
    onSuccess: () => {
      toast.success('Absensi berhasil disimpan');
      queryClient.invalidateQueries({ queryKey: ['session-students', id] });
    },
    onError: (err: any) => {
      toast.error(err.message || 'Gagal menyimpan absensi');
    }
  });

  // Bulk Save Mutation
  const saveBulkMutation = useMutation({
    mutationFn: (payloads: AttendancePayload[]) => teacherService.saveBulkAttendance(id, payloads),
    onSuccess: () => {
      toast.success('Semua data absensi berhasil disimpan');
      queryClient.invalidateQueries({ queryKey: ['session-students', id] });
      setIsSubmitting(false);
      navigate(-1); // Go back after success
    },
    onError: (err: any) => {
      toast.error(err.message || 'Gagal menyimpan data absensi');
      setIsSubmitting(false);
    }
  });

  const handleSaveSingle = (studentId: number) => {
    if (!formData[studentId]) return;
    saveSingleMutation.mutate(formData[studentId]);
  };

  const handleSaveAll = () => {
    const payloads = Object.values(formData);
    if (payloads.length === 0) return;

    if (confirm('Apakah Anda yakin ingin menyimpan absensi untuk semua siswa?')) {
      setIsSubmitting(true);
      saveBulkMutation.mutate(payloads);
    }
  };

  if (isLoading) {
    return (
      <div className="max-w-5xl mx-auto p-4">
        <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden p-6">
          <SkeletonTable rows={5} cols={4} />
        </div>
      </div>
    );
  }

  if (isError) {
    return (
      <div className="flex flex-col items-center justify-center min-h-[50vh] text-center p-4">
        <AlertCircle className="w-12 h-12 text-red-500 mb-2" />
        <h3 className="text-lg font-bold text-slate-800">Gagal Memuat Data</h3>
        <p className="text-slate-500 mb-4">{error instanceof Error ? error.message : 'Terjadi kesalahan'}</p>
        <button
          onClick={() => window.location.reload()}
          className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
        >
          Coba Lagi
        </button>
      </div>
    );
  }

  return (
    <div className="space-y-6 max-w-5xl mx-auto pb-20">
      {/* Header */}
      <div className="flex items-center gap-4 bg-white p-4 rounded-xl border border-slate-200 shadow-sm sticky top-0 z-10">
        <button
          onClick={() => navigate(-1)}
          className="p-2 hover:bg-slate-100 rounded-full transition-colors"
        >
          <ArrowLeft className="w-5 h-5 text-slate-600" />
        </button>
        <div>
          <h1 className="text-xl font-bold text-slate-800">Absensi Manual</h1>
          {/* Placeholder for Schedule Info if available */}
          <p className="text-sm text-slate-500">Session ID: {id}</p>
        </div>
        <div className="ml-auto">
          <button
            onClick={handleSaveAll}
            disabled={isSubmitting}
            className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors font-medium shadow-sm"
          >
            {isSubmitting ? (
              <Loader2 className="w-4 h-4 animate-spin" />
            ) : (
              <Save className="w-4 h-4" />
            )}
            Simpan Semua
          </button>
        </div>
      </div>

      {/* Student List Table */}
      <div className="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead className="bg-slate-50 border-b border-slate-200">
              <tr>
                <th className="px-6 py-4 font-semibold text-slate-700 w-16">No</th>
                <th className="px-6 py-4 font-semibold text-slate-700">Nama Siswa</th>
                <th className="px-6 py-4 font-semibold text-slate-700 w-48">Status</th>
                <th className="px-6 py-4 font-semibold text-slate-700">Catatan</th>
                <th className="px-6 py-4 font-semibold text-slate-700 w-24 text-center">Aksi</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {students?.map((student, index) => (
                <tr key={student.id} className="hover:bg-slate-50 transition-colors">
                  <td className="px-6 py-4 text-slate-500">{index + 1}</td>
                  <td className="px-6 py-4">
                    <div>
                      <p className="font-medium text-slate-800">{student.name}</p>
                      <p className="text-xs text-slate-500">{student.nis || 'No NIS'}</p>
                    </div>
                  </td>
                  <td className="px-6 py-4">
                    <select
                      value={formData[student.id]?.status || 'present'}
                      onChange={(e) => handleStatusChange(student.id, e.target.value as AttendanceStatus)}
                      className={`w-full px-3 py-2 rounded-lg border text-sm font-medium focus:outline-none focus:ring-2 focus:ring-opacity-50 transition-colors appearance-none cursor-pointer
                                                ${formData[student.id]?.status === 'present' ? 'bg-emerald-50 border-emerald-200 text-emerald-700 focus:ring-emerald-500' : ''}
                                                ${formData[student.id]?.status === 'sick' ? 'bg-amber-50 border-amber-200 text-amber-700 focus:ring-amber-500' : ''}
                                                ${formData[student.id]?.status === 'permit' ? 'bg-blue-50 border-blue-200 text-blue-700 focus:ring-blue-500' : ''}
                                                ${formData[student.id]?.status === 'alpha' ? 'bg-red-50 border-red-200 text-red-700 focus:ring-red-500' : ''}
                                                ${formData[student.id]?.status === 'late' ? 'bg-orange-50 border-orange-200 text-orange-700 focus:ring-orange-500' : ''}
                                            `}
                    >
                      <option value="present">Hadir</option>
                      <option value="late">Terlambat</option>
                      <option value="sick">Sakit</option>
                      <option value="permit">Izin</option>
                      <option value="alpha">Alpha</option>
                    </select>
                  </td>
                  <td className="px-6 py-4">
                    <input
                      type="text"
                      value={formData[student.id]?.notes || ''}
                      onChange={(e) => handleNotesChange(student.id, e.target.value)}
                      placeholder="Tambahkan catatan..."
                      className="w-full px-3 py-2 rounded-lg border border-slate-200 text-slate-700 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500 outline-none transition-all placeholder:text-slate-400"
                    />
                  </td>
                  <td className="px-6 py-4 text-center">
                    <button
                      onClick={() => handleSaveSingle(student.id)}
                      disabled={saveSingleMutation.isPending}
                      className="p-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors disabled:opacity-50"
                      title="Simpan"
                    >
                      {saveSingleMutation.isPending ? (
                        <Loader2 className="w-5 h-5 animate-spin" />
                      ) : (
                        <CheckCircle className="w-5 h-5" />
                      )}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* Empty State */}
        {students?.length === 0 && (
          <EmptyState
            preset="no-students"
            title="Tidak Ada Siswa dalam Sesi Ini"
            description="Belum ada siswa terdaftar pada sesi yang dipilih."
            size="sm"
          />
        )}
      </div>
    </div>
  );
};

export default ManualAttendancePage;
