import { apiClient } from '../../../lib/api';

export interface TodaySessionsResponse {
  success: boolean;
  data: {
    sessions: Array<{
      id: number;
      subject_name: string;
      class_name: string;
      start_time: string;
      end_time: string;
      is_active: boolean;
    }>;
  };
}

export interface GenerateQRResponse {
  success: boolean;
  data: {
    qr_payload: string;
    expires_at: string;
    session_id: number;
  };
}

export interface LiveAttendancesResponse {
  success: boolean;
  data: {
    attendances: Array<{
      id: number;
      student_id: number;
      student_name: string;
      status: string;
      check_in_time: string;
    }>;
    total: number;
  };
}

export const teacherAttendanceApi = {
  /**
   * Get today's teaching sessions
   */
  getTodaySessions: async (): Promise<TodaySessionsResponse> => {
    const response = await apiClient.get('/teacher/attendance/today-sessions');
    return response.data;
  },

  /**
   * Generate QR code for attendance session
   */
  generateQR: async (sessionId: number): Promise<GenerateQRResponse> => {
    const response = await apiClient.post('/teacher/attendance/generate', {
      session_id: sessionId,
    });
    return response.data;
  },

  /**
   * Get live attendances for a session
   */
  getLiveAttendances: async (sessionId: number): Promise<LiveAttendancesResponse> => {
    const response = await apiClient.get(`/teacher/attendance/${sessionId}/live`);
    return response.data;
  },

  /**
   * Manual attendance input
   */
  manualAttendance: async (data: {
    session_id: number;
    student_id: number;
    status: string;
    notes?: string;
  }) => {
    const response = await apiClient.post('/teacher/attendance/manual', data);
    return response.data;
  },

  /**
   * Get attendance history
   */
  getHistory: async (params: {
    class_id?: number;
    date_from?: string;
    date_to?: string;
    page?: number;
  }) => {
    const response = await apiClient.get('/teacher/attendance/history', { params });
    return response.data;
  },
};
