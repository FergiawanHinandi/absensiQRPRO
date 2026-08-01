import { apiClient } from '../../../lib/api';

export interface StudentDashboardData {
  today_status: {
    date: string;
    attendance: { status: string; time: string } | null;
    total_subjects: number;
  };
  monthly_summary: {
    month: string;
    attendance_rate: number;
    total_days: number;
    present_days: number;
    late_days: number;
    absent_days: number;
  };
}

export interface AttendanceRecord {
  date: string;
  time: string;
  status: string;
  subject: { name: string; code: string };
  teacher: string;
  schedule: { start_time: string; end_time: string };
}

export interface AttendanceHistoryData {
  period: { from: string; to: string };
  total_records: number;
  history: AttendanceRecord[];
}

export interface ScheduleItem {
  id: number;
  subject: { name: string; code: string };
  teacher: string;
  time: { start: string; end: string };
  room: string;
  status: 'upcoming' | 'ongoing' | 'completed';
}

export interface ScheduleData {
  date: string;
  day_name: string;
  total_subjects: number;
  schedule: ScheduleItem[];
}

export interface StudentProfile {
  name: string;
  username: string;
  email: string;
  phone: string;
  photo_url: string;
  class: { name: string; grade: string };
  school: string;
}

export const studentService = {
  getDashboard: async (): Promise<StudentDashboardData> => {
    const res = await apiClient.get('/student/dashboard');
    return res.data;
  },

  getHistory: async (): Promise<AttendanceHistoryData> => {
    const res = await apiClient.get('/student/attendance-history');
    return res.data;
  },

  getSchedule: async (): Promise<ScheduleData> => {
    const res = await apiClient.get('/student/today-schedule');
    return res.data;
  },

  getProfile: async (): Promise<StudentProfile> => {
    const res = await apiClient.get('/student/profile');
    return res.data;
  },
};
