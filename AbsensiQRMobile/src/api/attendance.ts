/**
 * Attendance API
 * 
 * Uses the secure SSL-pinned client for MITM protection.
 * Falls back to regular fetch in development mode.
 */

import { secureApi } from './secureClient';

export interface ScanPayload {
  qr_token: string;
  lat?: number;
  lng?: number;
}

export interface AttendanceRecord {
  id: number;
  student_name: string;
  class: string;
  subject: string;
  time: string;
  status: string;
}

export interface AttendanceApiResponse<T> {
  success: boolean;
  message: string;
  data: T;
}

export const attendanceApi = {
  /**
   * Submit attendance via QR code scan
   * Protected by SSL pinning in production
   */
  scan: async (payload: ScanPayload): Promise<AttendanceApiResponse<AttendanceRecord>> => {
    const response = await secureApi.post<AttendanceApiResponse<AttendanceRecord>>(
      '/v1/attendance/scan',
      payload
    );
    return response.data;
  },

  /**
   * Get attendance history for the current user
   * Protected by SSL pinning in production
   */
  getHistory: async (): Promise<AttendanceApiResponse<AttendanceRecord[]>> => {
    const response = await secureApi.get<AttendanceApiResponse<AttendanceRecord[]>>(
      '/v1/attendance/history'
    );
    return response.data;
  },

  /**
   * Get today's attendance status
   */
  getTodayStatus: async (): Promise<AttendanceApiResponse<{
    checked_in: boolean;
    check_in_time?: string;
    checked_out: boolean;
    check_out_time?: string;
  }>> => {
    const response = await secureApi.get<AttendanceApiResponse<{
      checked_in: boolean;
      check_in_time?: string;
      checked_out: boolean;
      check_out_time?: string;
    }>>('/v1/attendance/today');
    return response.data;
  },
};

export default attendanceApi;

