import React, { useState, useEffect } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '../../components/ui/card';
import { apiClient } from '../../lib/api';
import { AlertTriangle, Users, TrendingUp, Download } from 'lucide-react';

interface RiskData {
  total_students_by_risk: {
    risk_counts: {
      critical: number;
      high: number;
      medium: number;
      low: number;
    };
    total_students: number;
    risk_percentages: {
      critical: number;
      high: number;
      medium: number;
      low: number;
    };
  };
  classes_with_high_risk: Array<{
    class_id: number;
    class_name: string;
    grade_level: string;
    total_students: number;
    critical_students: number;
    high_students: number;
    medium_students: number;
    low_students: number;
    high_risk_percentage: number;
    risk_distribution: {
      critical: number;
      high: number;
      medium: number;
      low: number;
    };
  }>;
  risk_trend_30_days: Array<{
    date: string;
    day_name: string;
    critical: number;
    high: number;
    medium: number;
    low: number;
    total: number;
  }>;
  critical_students: Array<{
    student_id: number;
    student_name: string;
    email: string;
    class_name: string;
    grade_level: string;
    attendance_stats: {
      present_days: number;
      total_days: number;
      alpha_days: number;
      sick_days: number;
      permit_days: number;
      attendance_percentage: number;
    };
    last_attendance_date: string;
    days_since_last_attendance: number;
    risk_level: string;
    action_required: string;
  }>;
  summary_stats: {
    total_active_students: number;
    total_classes: number;
    total_school_days: number;
    school_avg_attendance_rate: number;
    analysis_period: string;
    analysis_start_date: string;
    analysis_end_date: string;
  };
  generated_at: string;
}

const RiskOverview: React.FC = () => {
  const [riskData, setRiskData] = useState<RiskData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [selectedRiskLevel, setSelectedRiskLevel] = useState<string | null>(null);
  const [detailedStudents, setDetailedStudents] = useState<any[]>([]);

  useEffect(() => {
    fetchRiskOverview();
  }, []);

  const fetchRiskOverview = async () => {
    try {
      setLoading(true);
      const response = await apiClient.get('/admin/risk-overview/');
      setRiskData(response.data.data);
    } catch (err: any) {
      setError(err.response?.data?.message || 'Failed to load risk overview');
    } finally {
      setLoading(false);
    }
  };

  const fetchStudentDetails = async (riskLevel: string) => {
    try {
      const response = await apiClient.get(`/admin/risk-overview/students?risk_level=${riskLevel}&limit=50`);
      setDetailedStudents(response.data.data.students);
      setSelectedRiskLevel(riskLevel);
    } catch (err: any) {
      setError(err.response?.data?.message || 'Failed to load student details');
    }
  };

  const exportRiskData = async (format: 'excel' | 'pdf' | 'csv') => {
    try {
      const response = await apiClient.post('/admin/risk-overview/export', {
        format,
        include_details: true
      });

      // Handle download
      const downloadUrl = response.data.data.download_url;
      window.open(downloadUrl, '_blank');
    } catch (err: any) {
      setError(err.response?.data?.message || 'Failed to export data');
    }
  };

  const getRiskColor = (level: string) => {
    switch (level) {
      case 'critical': return 'text-red-600 bg-red-50 border-red-200';
      case 'high': return 'text-orange-600 bg-orange-50 border-orange-200';
      case 'medium': return 'text-yellow-600 bg-yellow-50 border-yellow-200';
      case 'low': return 'text-green-600 bg-green-50 border-green-200';
      default: return 'text-gray-600 bg-gray-50 border-gray-200';
    }
  };

  const getRiskLabel = (level: string) => {
    switch (level) {
      case 'critical': return 'Kritis';
      case 'high': return 'Tinggi';
      case 'medium': return 'Sedang';
      case 'low': return 'Rendah';
      default: return level;
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
        <span className="ml-2">Memuat analisis risiko...</span>
      </div>
    );
  }

  if (error) {
    return (
      <div className="bg-red-50 border border-red-200 rounded-md p-4">
        <div className="flex">
          <AlertTriangle className="h-5 w-5 text-red-400" />
          <div className="ml-3">
            <h3 className="text-sm font-medium text-red-800">Error</h3>
            <p className="text-sm text-red-700 mt-1">{error}</p>
            <button
              onClick={fetchRiskOverview}
              className="mt-2 text-sm bg-red-100 text-red-800 px-3 py-1 rounded hover:bg-red-200"
            >
              Coba Lagi
            </button>
          </div>
        </div>
      </div>
    );
  }

  if (!riskData) return null;

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex justify-between items-center">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Analisis Risiko Siswa</h1>
          <p className="text-gray-600">
            Analisis berdasarkan pola absensi {riskData.summary_stats.analysis_period} terakhir
          </p>
        </div>
        <div className="flex space-x-2">
          <button
            onClick={() => exportRiskData('excel')}
            className="flex items-center px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700"
          >
            <Download className="h-4 w-4 mr-2" />
            Export Excel
          </button>
          <button
            onClick={() => exportRiskData('pdf')}
            className="flex items-center px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700"
          >
            <Download className="h-4 w-4 mr-2" />
            Export PDF
          </button>
        </div>
      </div>

      {/* Summary Stats */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
        <Card>
          <CardContent className="p-6">
            <div className="flex items-center">
              <Users className="h-8 w-8 text-blue-600" />
              <div className="ml-4">
                <p className="text-sm font-medium text-gray-600">Total Siswa Aktif</p>
                <p className="text-2xl font-bold text-gray-900">{riskData.summary_stats.total_active_students}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent className="p-6">
            <div className="flex items-center">
              <TrendingUp className="h-8 w-8 text-green-600" />
              <div className="ml-4">
                <p className="text-sm font-medium text-gray-600">Rata-rata Kehadiran</p>
                <p className="text-2xl font-bold text-gray-900">
                  {riskData.summary_stats.school_avg_attendance_rate.toFixed(1)}%
                </p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent className="p-6">
            <div className="flex items-center">
              <AlertTriangle className="h-8 w-8 text-red-600" />
              <div className="ml-4">
                <p className="text-sm font-medium text-gray-600">Siswa Kritis</p>
                <p className="text-2xl font-bold text-red-600">
                  {riskData.total_students_by_risk.risk_counts.critical}
                </p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent className="p-6">
            <div className="flex items-center">
              <AlertTriangle className="h-8 w-8 text-orange-600" />
              <div className="ml-4">
                <p className="text-sm font-medium text-gray-600">Siswa Risiko Tinggi</p>
                <p className="text-2xl font-bold text-orange-600">
                  {riskData.total_students_by_risk.risk_counts.high}
                </p>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Risk Distribution */}
      <Card>
        <CardHeader>
          <CardTitle>Distribusi Tingkat Risiko</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
            {Object.entries(riskData.total_students_by_risk.risk_counts).map(([level, count]) => (
              <div
                key={level}
                className={`p-4 rounded-lg border cursor-pointer hover:shadow-md transition-shadow ${getRiskColor(level)}`}
                onClick={() => fetchStudentDetails(level)}
              >
                <div className="text-center">
                  <p className="text-2xl font-bold">{count}</p>
                  <p className="text-sm font-medium">{getRiskLabel(level)}</p>
                  <p className="text-xs">
                    {riskData.total_students_by_risk.risk_percentages[level as keyof typeof riskData.total_students_by_risk.risk_percentages].toFixed(1)}%
                  </p>
                </div>
              </div>
            ))}
          </div>
        </CardContent>
      </Card>

      {/* Classes with High Risk */}
      <Card>
        <CardHeader>
          <CardTitle>Kelas dengan Risiko Tinggi</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Kelas
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Total Siswa
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Kritis
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Tinggi
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    % Risiko Tinggi
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {riskData.classes_with_high_risk.slice(0, 10).map((classData) => (
                  <tr key={classData.class_id} className="hover:bg-gray-50">
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div>
                        <div className="text-sm font-medium text-gray-900">{classData.class_name}</div>
                        <div className="text-sm text-gray-500">{classData.grade_level}</div>
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                      {classData.total_students}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                        {classData.critical_students}
                      </span>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-orange-100 text-orange-800">
                        {classData.high_students}
                      </span>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                      {classData.high_risk_percentage.toFixed(1)}%
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>

      {/* Critical Students */}
      <Card>
        <CardHeader>
          <CardTitle>Siswa Kritis - Perlu Perhatian Segera</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Siswa
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Kelas
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Kehadiran
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Alpha
                  </th>
                  <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Tindakan Diperlukan
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {riskData.critical_students.slice(0, 10).map((student) => (
                  <tr key={student.student_id} className="hover:bg-gray-50">
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div>
                        <div className="text-sm font-medium text-gray-900">{student.student_name}</div>
                        <div className="text-sm text-gray-500">{student.email}</div>
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="text-sm text-gray-900">{student.class_name}</div>
                      <div className="text-sm text-gray-500">{student.grade_level}</div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="text-sm text-gray-900">
                        {student.attendance_stats.attendance_percentage.toFixed(1)}%
                      </div>
                      <div className="text-sm text-gray-500">
                        {student.attendance_stats.present_days}/{student.attendance_stats.total_days} hari
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                        {student.attendance_stats.alpha_days} hari
                      </span>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                      <div className="max-w-xs truncate" title={student.action_required}>
                        {student.action_required}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>

      {/* Student Details Modal */}
      {selectedRiskLevel && detailedStudents.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>
              Detail Siswa - Risiko {getRiskLabel(selectedRiskLevel)}
              <button
                onClick={() => setSelectedRiskLevel(null)}
                className="ml-4 text-sm text-gray-500 hover:text-gray-700"
              >
                Tutup
              </button>
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Nama Siswa
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Kelas
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Kehadiran
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Terakhir Hadir
                    </th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                  {detailedStudents.map((student) => (
                    <tr key={student.student_id} className="hover:bg-gray-50">
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm font-medium text-gray-900">{student.student_name}</div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        {student.class_name}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm text-gray-900">
                          {student.attendance_stats.attendance_percentage.toFixed(1)}%
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        {student.last_attendance_date || 'Tidak ada data'}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </CardContent>
        </Card>
      )}
    </div>
  );
};

export default RiskOverview;