import React, { useState, useEffect } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '../../components/ui/card';
import { Button } from '../../components/ui/Button';
import { Badge } from '../../components/ui/badge';
import { Alert, AlertDescription } from '../../components/ui/alert';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { 
  CreditCard, 
  RefreshCw, 
  XCircle, 
  Eye, 
  AlertTriangle,
  CheckCircle,
  Clock,
  User
} from 'lucide-react';
import { useAuthStore } from '../../store/authStore';
import api from '../../lib/api';

interface Student {
  id: number;
  name: string;
  student_id: string;
  email: string;
  is_active: boolean;
}

interface StudentCard {
  card_id: number;
  card_number: string;
  expires_at: string;
  generated_at: string;
  is_expired: boolean;
  days_until_expiry: number;
}

interface CardStatus {
  has_active_card: boolean;
  active_card: StudentCard | null;
  total_cards_generated: number;
  last_generated: string | null;
  card_history: Array<{
    card_id: number;
    card_number: string;
    generated_at: string;
    deactivated_at: string | null;
    is_active: boolean;
    deactivation_reason: string | null;
  }>;
}

const StudentCardManagement: React.FC = () => {
  const { user } = useAuthStore();
  const [students, setStudents] = useState<Student[]>([]);
  const [selectedStudent, setSelectedStudent] = useState<Student | null>(null);
  const [cardStatus, setCardStatus] = useState<CardStatus | null>(null);
  const [loading, setLoading] = useState(false);
  const [actionLoading, setActionLoading] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  // Check if user is school admin
  const isSchoolAdmin = user?.role_type === 'school_admin';

  useEffect(() => {
    fetchStudents();
  }, []);

  const fetchStudents = async () => {
    try {
      setLoading(true);
      const response = await api.get('/api/v1/admin/students');
      setStudents(response.data.data || []);
    } catch (err: any) {
      setError('Gagal memuat data siswa');
    } finally {
      setLoading(false);
    }
  };

  const fetchCardStatus = async (studentId: number) => {
    try {
      setLoading(true);
      const response = await api.get(`/api/v1/admin/students/${studentId}/card-status`);
      setCardStatus(response.data.data);
    } catch (err: any) {
      setError('Gagal memuat status kartu siswa');
    } finally {
      setLoading(false);
    }
  };

  const handleStudentSelect = (student: Student) => {
    setSelectedStudent(student);
    setCardStatus(null);
    setError(null);
    setSuccess(null);
    fetchCardStatus(student.id);
  };

  const handleGenerateCard = async () => {
    if (!selectedStudent || !isSchoolAdmin) return;

    try {
      setActionLoading('generate');
      const response = await api.post(`/api/v1/admin/students/${selectedStudent.id}/generate-card`);
      
      setSuccess('Kartu QR berhasil dibuat');
      fetchCardStatus(selectedStudent.id);
    } catch (err: any) {
      setError(err.response?.data?.message || 'Gagal membuat kartu QR');
    } finally {
      setActionLoading(null);
    }
  };

  const handleRegenerateCard = async () => {
    if (!selectedStudent || !isSchoolAdmin) return;

    try {
      setActionLoading('regenerate');
      const response = await api.post(`/api/v1/admin/students/${selectedStudent.id}/regenerate-card`);
      
      setSuccess('Kartu QR berhasil dibuat ulang');
      fetchCardStatus(selectedStudent.id);
    } catch (err: any) {
      setError(err.response?.data?.message || 'Gagal membuat ulang kartu QR');
    } finally {
      setActionLoading(null);
    }
  };

  const handleDeactivateCard = async () => {
    if (!selectedStudent || !isSchoolAdmin) return;

    if (!confirm('Apakah Anda yakin ingin menonaktifkan kartu ini?')) return;

    try {
      setActionLoading('deactivate');
      const response = await api.post(`/api/v1/admin/students/${selectedStudent.id}/deactivate-card`);
      
      setSuccess('Kartu QR berhasil dinonaktifkan');
      fetchCardStatus(selectedStudent.id);
    } catch (err: any) {
      setError(err.response?.data?.message || 'Gagal menonaktifkan kartu QR');
    } finally {
      setActionLoading(null);
    }
  };

  const getCardStatusBadge = (card: StudentCard | null) => {
    if (!card) return <Badge variant="secondary">Tidak Ada Kartu</Badge>;
    
    if (card.is_expired) {
      return <Badge variant="destructive">Kedaluwarsa</Badge>;
    }
    
    if (card.days_until_expiry <= 30) {
      return <Badge variant="outline" className="border-yellow-500 text-yellow-600">
        Akan Kedaluwarsa ({card.days_until_expiry} hari)
      </Badge>;
    }
    
    return <Badge variant="default" className="bg-green-500">Aktif</Badge>;
  };

  if (!isSchoolAdmin) {
    return (
      <div className="p-6">
        <Alert>
          <AlertTriangle className="h-4 w-4" />
          <AlertDescription>
            Hanya Admin Sekolah yang dapat mengelola kartu QR siswa.
          </AlertDescription>
        </Alert>
      </div>
    );
  }

  return (
    <div className="p-6 space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">Manajemen Kartu QR Siswa</h1>
          <p className="text-gray-600">Kelola kartu QR untuk absensi siswa</p>
        </div>
      </div>

      {error && (
        <Alert variant="destructive">
          <AlertTriangle className="h-4 w-4" />
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}

      {success && (
        <Alert className="border-green-500 bg-green-50">
          <CheckCircle className="h-4 w-4 text-green-600" />
          <AlertDescription className="text-green-800">{success}</AlertDescription>
        </Alert>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Student List */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <User className="h-5 w-5" />
              Daftar Siswa
            </CardTitle>
          </CardHeader>
          <CardContent>
            {loading && !selectedStudent ? (
              <div className="text-center py-4">Memuat data siswa...</div>
            ) : (
              <div className="space-y-2 max-h-96 overflow-y-auto">
                {students.map((student) => (
                  <div
                    key={student.id}
                    className={`p-3 border rounded-lg cursor-pointer transition-colors ${
                      selectedStudent?.id === student.id
                        ? 'border-blue-500 bg-blue-50'
                        : 'border-gray-200 hover:border-gray-300'
                    }`}
                    onClick={() => handleStudentSelect(student)}
                  >
                    <div className="flex justify-between items-center">
                      <div>
                        <p className="font-medium">{student.name}</p>
                        <p className="text-sm text-gray-600">NIS: {student.student_id}</p>
                      </div>
                      <Badge variant={student.is_active ? "default" : "secondary"}>
                        {student.is_active ? 'Aktif' : 'Nonaktif'}
                      </Badge>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>

        {/* Card Management */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <CreditCard className="h-5 w-5" />
              Manajemen Kartu QR
            </CardTitle>
          </CardHeader>
          <CardContent>
            {!selectedStudent ? (
              <div className="text-center py-8 text-gray-500">
                Pilih siswa untuk mengelola kartu QR
              </div>
            ) : loading ? (
              <div className="text-center py-8">Memuat status kartu...</div>
            ) : cardStatus ? (
              <div className="space-y-4">
                {/* Student Info */}
                <div className="p-3 bg-gray-50 rounded-lg">
                  <h3 className="font-medium">{selectedStudent.name}</h3>
                  <p className="text-sm text-gray-600">NIS: {selectedStudent.student_id}</p>
                </div>

                {/* Card Status */}
                <div className="space-y-3">
                  <div className="flex items-center justify-between">
                    <span className="font-medium">Status Kartu:</span>
                    {getCardStatusBadge(cardStatus.active_card)}
                  </div>

                  {cardStatus.active_card && (
                    <div className="space-y-2 text-sm">
                      <div className="flex justify-between">
                        <span>Nomor Kartu:</span>
                        <span className="font-mono">{cardStatus.active_card.card_number}</span>
                      </div>
                      <div className="flex justify-between">
                        <span>Dibuat:</span>
                        <span>{new Date(cardStatus.active_card.generated_at).toLocaleDateString('id-ID')}</span>
                      </div>
                      <div className="flex justify-between">
                        <span>Kedaluwarsa:</span>
                        <span>{new Date(cardStatus.active_card.expires_at).toLocaleDateString('id-ID')}</span>
                      </div>
                    </div>
                  )}

                  <div className="flex justify-between text-sm">
                    <span>Total Kartu Dibuat:</span>
                    <span>{cardStatus.total_cards_generated}</span>
                  </div>
                </div>

                {/* Action Buttons */}
                <div className="space-y-2 pt-4 border-t">
                  {!cardStatus.has_active_card ? (
                    <Button
                      onClick={handleGenerateCard}
                      disabled={actionLoading === 'generate'}
                      className="w-full"
                    >
                      {actionLoading === 'generate' ? (
                        <>
                          <RefreshCw className="h-4 w-4 mr-2 animate-spin" />
                          Membuat Kartu...
                        </>
                      ) : (
                        <>
                          <CreditCard className="h-4 w-4 mr-2" />
                          Buat Kartu QR
                        </>
                      )}
                    </Button>
                  ) : (
                    <div className="space-y-2">
                      <Button
                        onClick={handleRegenerateCard}
                        disabled={actionLoading === 'regenerate'}
                        variant="outline"
                        className="w-full"
                      >
                        {actionLoading === 'regenerate' ? (
                          <>
                            <RefreshCw className="h-4 w-4 mr-2 animate-spin" />
                            Membuat Ulang...
                          </>
                        ) : (
                          <>
                            <RefreshCw className="h-4 w-4 mr-2" />
                            Buat Ulang Kartu
                          </>
                        )}
                      </Button>
                      
                      <Button
                        onClick={handleDeactivateCard}
                        disabled={actionLoading === 'deactivate'}
                        variant="destructive"
                        className="w-full"
                      >
                        {actionLoading === 'deactivate' ? (
                          <>
                            <RefreshCw className="h-4 w-4 mr-2 animate-spin" />
                            Menonaktifkan...
                          </>
                        ) : (
                          <>
                            <XCircle className="h-4 w-4 mr-2" />
                            Nonaktifkan Kartu
                          </>
                        )}
                      </Button>
                    </div>
                  )}
                </div>

                {/* Card History */}
                {cardStatus.card_history.length > 0 && (
                  <div className="pt-4 border-t">
                    <h4 className="font-medium mb-2">Riwayat Kartu</h4>
                    <div className="space-y-2 max-h-32 overflow-y-auto">
                      {cardStatus.card_history.map((card) => (
                        <div key={card.card_id} className="flex justify-between items-center text-sm p-2 bg-gray-50 rounded">
                          <div>
                            <span className="font-mono">{card.card_number}</span>
                            <div className="text-xs text-gray-600">
                              {new Date(card.generated_at).toLocaleDateString('id-ID')}
                            </div>
                          </div>
                          <Badge variant={card.is_active ? "default" : "secondary"} className="text-xs">
                            {card.is_active ? 'Aktif' : 'Nonaktif'}
                          </Badge>
                        </div>
                      ))}
                    </div>
                  </div>
                )}
              </div>
            ) : null}
          </CardContent>
        </Card>
      </div>
    </div>
  );
};

export default StudentCardManagement;