import { useState, useEffect, useCallback } from 'react';
import { QRCodeCanvas } from 'qrcode.react';
import { Clock, Users, RefreshCw, AlertCircle, CheckCircle, XCircle } from 'lucide-react';
import { teacherAttendanceApi } from '../../modules/teacher/services/attendanceApi';

interface Session {
  id: number;
  subject_name: string;
  class_name: string;
  start_time: string;
  end_time: string;
  is_active: boolean;
}

interface QRData {
  qr_payload: string;
  expires_at: string;
  session_id: number;
}

interface AttendanceRecord {
  id: number;
  student_id: number;
  student_name: string;
  status: string;
  check_in_time: string;
}

export default function AttendanceQR() {
  // State
  const [sessions, setSessions] = useState<Session[]>([]);
  const [selectedSession, setSelectedSession] = useState<Session | null>(null);
  const [qrData, setQrData] = useState<QRData | null>(null);
  const [attendances, setAttendances] = useState<AttendanceRecord[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [timeLeft, setTimeLeft] = useState<number>(0);
  const [isExpired, setIsExpired] = useState(false);

  // Fetch today's sessions
  const fetchSessions = useCallback(async () => {
    try {
      setLoading(true);
      const response = await teacherAttendanceApi.getTodaySessions();
      setSessions(response.data.sessions || []);
      setError(null);
    } catch (err: any) {
      setError(err.response?.data?.message || 'Gagal memuat sesi hari ini');
    } finally {
      setLoading(false);
    }
  }, []);

  // Generate QR Code
  const generateQR = useCallback(async (sessionId: number) => {
    try {
      setLoading(true);
      setError(null);

      const response = await teacherAttendanceApi.generateQR(sessionId);
      setQrData(response.data);
      setIsExpired(false);

      // Calculate time left
      const expiresAt = new Date(response.data.expires_at).getTime();
      const now = Date.now();
      setTimeLeft(Math.floor((expiresAt - now) / 1000));

    } catch (err: any) {
      setError(err.response?.data?.message || 'Gagal generate QR code');
      setQrData(null);
    } finally {
      setLoading(false);
    }
  }, []);

  // Fetch live attendances
  const fetchLiveAttendances = useCallback(async (sessionId: number) => {
    try {
      const response = await teacherAttendanceApi.getLiveAttendances(sessionId);
      setAttendances(response.data.attendances || []);
    } catch (err) {
      console.error('Failed to fetch live attendances:', err);
    }
  }, []);

  // Handle session selection
  const handleSelectSession = useCallback((session: Session) => {
    setSelectedSession(session);
    setQrData(null);
    setAttendances([]);
    setError(null);
  }, []);

  // Handle generate QR
  const handleGenerateQR = useCallback(() => {
    if (selectedSession) {
      generateQR(selectedSession.id);
    }
  }, [selectedSession, generateQR]);

  // Auto refresh QR every 55 seconds
  useEffect(() => {
    if (!qrData || !selectedSession) return;

    const interval = setInterval(() => {
      if (timeLeft <= 5) {
        // Auto regenerate when about to expire
        generateQR(selectedSession.id);
      }
    }, 55000); // 55 seconds

    return () => clearInterval(interval);
  }, [qrData, selectedSession, timeLeft, generateQR]);

  // Countdown timer
  useEffect(() => {
    if (timeLeft <= 0) {
      setIsExpired(true);
      return;
    }

    const timer = setInterval(() => {
      setTimeLeft((prev) => {
        if (prev <= 1) {
          setIsExpired(true);
          return 0;
        }
        return prev - 1;
      });
    }, 1000);

    return () => clearInterval(timer);
  }, [timeLeft]);

  // Poll live attendances every 3 seconds
  useEffect(() => {
    if (!selectedSession || !qrData) return;

    // Initial fetch
    fetchLiveAttendances(selectedSession.id);

    // Poll every 3 seconds
    const interval = setInterval(() => {
      fetchLiveAttendances(selectedSession.id);
    }, 3000);

    return () => clearInterval(interval);
  }, [selectedSession, qrData, fetchLiveAttendances]);

  // Load sessions on mount
  useEffect(() => {
    fetchSessions();
  }, [fetchSessions]);

  // Format time
  const formatTime = (seconds: number) => {
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${mins}:${secs.toString().padStart(2, '0')}`;
  };

  return (
    <div className="min-h-screen bg-gray-50 p-6">
      <div className="max-w-7xl mx-auto">
        {/* Header */}
        <div className="mb-6">
          <h1 className="text-3xl font-bold text-gray-900">Absensi QR Code</h1>
          <p className="text-gray-600 mt-1">Generate QR code untuk absensi siswa</p>
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          {/* Left Column - Session Selection */}
          <div className="lg:col-span-1">
            <div className="bg-white rounded-lg shadow-sm p-6">
              <h2 className="text-lg font-semibold text-gray-900 mb-4">
                Pilih Sesi Hari Ini
              </h2>

              {loading && !sessions.length && (
                <div className="text-center py-8">
                  <RefreshCw className="w-8 h-8 animate-spin text-blue-500 mx-auto mb-2" />
                  <p className="text-gray-600">Memuat sesi...</p>
                </div>
              )}

              {!loading && sessions.length === 0 && (
                <div className="text-center py-8">
                  <AlertCircle className="w-12 h-12 text-gray-400 mx-auto mb-2" />
                  <p className="text-gray-600">Tidak ada sesi hari ini</p>
                </div>
              )}

              <div className="space-y-3">
                {sessions.map((session) => (
                  <button
                    key={session.id}
                    onClick={() => handleSelectSession(session)}
                    className={`w-full text-left p-4 rounded-lg border-2 transition-all ${selectedSession?.id === session.id
                      ? 'border-blue-500 bg-blue-50'
                      : 'border-gray-200 hover:border-blue-300 bg-white'
                      }`}
                  >
                    <div className="font-semibold text-gray-900">
                      {session.subject_name}
                    </div>
                    <div className="text-sm text-gray-600 mt-1">
                      {session.class_name}
                    </div>
                    <div className="flex items-center text-sm text-gray-500 mt-2">
                      <Clock className="w-4 h-4 mr-1" />
                      {session.start_time} - {session.end_time}
                    </div>
                    {session.is_active && (
                      <span className="inline-block mt-2 px-2 py-1 text-xs font-medium text-green-700 bg-green-100 rounded">
                        Aktif
                      </span>
                    )}
                  </button>
                ))}
              </div>
            </div>
          </div>

          {/* Right Column - QR Code & Attendances */}
          <div className="lg:col-span-2 space-y-6">
            {/* QR Code Section */}
            <div className="bg-white rounded-lg shadow-sm p-6">
              {!selectedSession && (
                <div className="text-center py-12">
                  <AlertCircle className="w-16 h-16 text-gray-400 mx-auto mb-4" />
                  <p className="text-gray-600 text-lg">
                    Pilih sesi untuk generate QR code
                  </p>
                </div>
              )}

              {selectedSession && !qrData && (
                <div className="text-center py-12">
                  <button
                    onClick={handleGenerateQR}
                    disabled={loading}
                    className="px-8 py-4 bg-blue-600 text-white rounded-lg font-semibold hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                  >
                    {loading ? (
                      <span className="flex items-center">
                        <RefreshCw className="w-5 h-5 animate-spin mr-2" />
                        Generating...
                      </span>
                    ) : (
                      'Generate QR Code'
                    )}
                  </button>
                </div>
              )}

              {selectedSession && qrData && (
                <div className="text-center">
                  <div className="mb-4">
                    <h3 className="text-xl font-semibold text-gray-900">
                      {selectedSession.subject_name}
                    </h3>
                    <p className="text-gray-600">{selectedSession.class_name}</p>
                  </div>

                  {/* QR Code */}
                  <div className={`inline-block p-6 bg-white rounded-lg border-4 ${isExpired ? 'border-red-500' : 'border-blue-500'
                    }`}>
                    {isExpired ? (
                      <div className="w-64 h-64 flex items-center justify-center bg-gray-100 rounded">
                        <div className="text-center">
                          <XCircle className="w-16 h-16 text-red-500 mx-auto mb-2" />
                          <p className="text-red-600 font-semibold">QR Expired</p>
                          <button
                            onClick={handleGenerateQR}
                            className="mt-4 px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700"
                          >
                            Generate Ulang
                          </button>
                        </div>
                      </div>
                    ) : (
                      <QRCodeCanvas
                        value={qrData.qr_payload}
                        size={256}
                        level="H"
                        includeMargin={true}
                      />
                    )}
                  </div>

                  {/* Timer */}
                  {!isExpired && (
                    <div className="mt-4">
                      <div className="inline-flex items-center px-4 py-2 bg-blue-100 text-blue-700 rounded-full">
                        <Clock className="w-5 h-5 mr-2" />
                        <span className="font-semibold">
                          Berlaku: {formatTime(timeLeft)}
                        </span>
                      </div>
                    </div>
                  )}

                  {/* Refresh Button */}
                  <button
                    onClick={handleGenerateQR}
                    disabled={loading}
                    className="mt-4 px-6 py-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                  >
                    <RefreshCw className={`w-5 h-5 inline mr-2 ${loading ? 'animate-spin' : ''}`} />
                    Refresh QR
                  </button>
                </div>
              )}

              {error && (
                <div className="mt-4 p-4 bg-red-50 border border-red-200 rounded-lg">
                  <div className="flex items-start">
                    <AlertCircle className="w-5 h-5 text-red-600 mr-2 flex-shrink-0 mt-0.5" />
                    <p className="text-red-700">{error}</p>
                  </div>
                </div>
              )}
            </div>

            {/* Live Attendances */}
            {selectedSession && qrData && (
              <div className="bg-white rounded-lg shadow-sm p-6">
                <div className="flex items-center justify-between mb-4">
                  <h3 className="text-lg font-semibold text-gray-900">
                    Siswa Hadir (Real-time)
                  </h3>
                  <div className="flex items-center px-4 py-2 bg-green-100 text-green-700 rounded-full">
                    <Users className="w-5 h-5 mr-2" />
                    <span className="font-semibold">{attendances.length} Siswa</span>
                  </div>
                </div>

                {attendances.length === 0 ? (
                  <div className="text-center py-8 text-gray-500">
                    <Users className="w-12 h-12 text-gray-400 mx-auto mb-2" />
                    <p>Belum ada siswa yang absen</p>
                  </div>
                ) : (
                  <div className="space-y-2 max-h-96 overflow-y-auto">
                    {attendances.map((attendance) => (
                      <div
                        key={attendance.id}
                        className="flex items-center justify-between p-3 bg-gray-50 rounded-lg"
                      >
                        <div className="flex items-center">
                          <CheckCircle className="w-5 h-5 text-green-500 mr-3" />
                          <div>
                            <div className="font-medium text-gray-900">
                              {attendance.student_name}
                            </div>
                            <div className="text-sm text-gray-500">
                              {attendance.check_in_time}
                            </div>
                          </div>
                        </div>
                        <span className={`px-3 py-1 text-xs font-medium rounded-full ${attendance.status === 'present'
                          ? 'bg-green-100 text-green-700'
                          : attendance.status === 'late'
                            ? 'bg-yellow-100 text-yellow-700'
                            : 'bg-gray-100 text-gray-700'
                          }`}>
                          {attendance.status === 'present' ? 'Hadir' :
                            attendance.status === 'late' ? 'Terlambat' :
                              attendance.status}
                        </span>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
