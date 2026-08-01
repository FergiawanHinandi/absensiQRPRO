import { apiClient } from '../../../lib/api';

export interface AttendanceOverview {
  school_summary: {
    total_students: number;
    total_classes: number;
    attendance_rate: number;
    present_today: number;
    late_today: number;
    absent_today: number;
  };
  monthly_trend: Array<{
    date: string;
    attendance_rate: number;
    total_students: number;
    present: number;
    late: number;
    absent: number;
  }>;
  class_breakdown: Array<{
    class_id: number;
    class_name: string;
    total_students: number;
    attendance_rate: number;
    present: number;
    late: number;
    absent: number;
  }>;
}

export interface ClassPerformance {
  performance_ranking: Array<{
    class_id: number;
    class_name: string;
    grade_level: string;
    total_students: number;
    attendance_rate: number;
    rank: number;
    trend: string;
  }>;
  grade_comparison: Array<{
    grade_name: string;
    grade_level: number;
    total_classes: number;
    avg_attendance_rate: number;
  }>;
}

export interface RiskStudentsData {
  high_risk_students: Array<{
    student_id: number;
    student_name: string;
    class_name: string;
    attendance_rate: number;
    absent_days: number;
    late_days: number;
    risk_level: string;
    last_attendance: string;
  }>;
  risk_summary: {
    total_students: number;
    high_risk_count: number;
    medium_risk_count: number;
    low_risk_count: number;
    critical_threshold: number;
  };
  class_risk_breakdown: Array<{
    class_id: number;
    class_name: string;
    total_students: number;
    high_risk_students: number;
    risk_percentage: number;
  }>;
}

export const principalService = {
  getAttendanceOverview: async (range = '7d'): Promise<AttendanceOverview> => {
    const res = await apiClient.get(`/principal/attendance-overview?range=${range}`);
    return res.data;
  },

  getClassPerformance: async (): Promise<ClassPerformance> => {
    const res = await apiClient.get('/principal/class-performance');
    return res.data;
  },

  getRiskStudents: async (limit = 50): Promise<RiskStudentsData> => {
    const res = await apiClient.get(`/principal/risk-students?limit=${limit}`);
    return res.data;
  },
};
