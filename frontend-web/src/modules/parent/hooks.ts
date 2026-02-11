import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../../lib/api';

/**
 * Parent Module API Hooks
 *
 * RULES:
 * 1. ALL data comes from API - no client-side calculations
 * 2. Status (present/late/absent) is determined by backend
 * 3. Attendance rates are pre-calculated by backend
 * 4. Error messages come from API response
 */

// API Response Types (match backend)
interface StudentInfo {
    id: number;
    name: string;
    class_name: string;
    nis: string;
    photo_url: string | null;
    school_name: string;
    grade_level: string;
}

interface TodayAttendance {
    date: string;
    status: 'present' | 'late' | 'absent' | 'sick' | 'permit' | 'alpha';
    status_label: string;  // Human-readable from backend
    check_in: string | null;
    check_out: string | null;
    location: string | null;
    is_late: boolean;  // Determined by backend, not client
}

interface AttendanceHistoryItem {
    date: string;
    status: 'present' | 'late' | 'absent' | 'sick' | 'permit' | 'alpha';
    status_label: string;
    check_in: string | null;
    check_out: string | null;
    subject_name: string | null;
}

interface AttendanceSummary {
    total_days: number;
    present_count: number;
    late_count: number;
    absent_count: number;
    sick_count: number;
    permit_count: number;
    attendance_rate: number;  // Pre-calculated by backend
    attendance_rate_formatted: string;  // "95.5%"
}

/**
 * Fetch student info for parent's child
 */
export const useStudentInfo = (studentId?: number) => {
    return useQuery<StudentInfo>({
        queryKey: ['parent', 'student', studentId],
        queryFn: async () => {
            const endpoint = studentId
                ? `/parent/children/${studentId}`
                : '/parent/children/primary';
            const response = await apiClient.get<{ data: StudentInfo }>(endpoint);
            return response.data.data;
        },
        staleTime: 5 * 60 * 1000, // 5 minutes
    });
};

/**
 * Fetch today's attendance status
 * Status is determined by backend based on:
 * - Check-in time vs schedule start time
 * - School's late threshold configuration
 * - Leave/permission records
 */
export const useTodayAttendance = (studentId?: number) => {
    return useQuery<TodayAttendance | null>({
        queryKey: ['parent', 'attendance', 'today', studentId],
        queryFn: async () => {
            const params = studentId ? { student_id: studentId } : {};
            const response = await apiClient.get<{ data: TodayAttendance | null }>(
                '/parent/attendance/today',
                { params }
            );
            return response.data.data;
        },
        staleTime: 60 * 1000, // 1 minute - refresh frequently for today's data
    });
};

/**
 * Fetch attendance history
 * All status calculations done by backend
 */
export const useAttendanceHistory = (
    studentId?: number,
    options?: { limit?: number; startDate?: string; endDate?: string }
) => {
    return useQuery<AttendanceHistoryItem[]>({
        queryKey: ['parent', 'attendance', 'history', studentId, options],
        queryFn: async () => {
            const params = {
                student_id: studentId,
                limit: options?.limit ?? 30,
                start_date: options?.startDate,
                end_date: options?.endDate,
            };
            const response = await apiClient.get<{ data: AttendanceHistoryItem[] }>(
                '/parent/attendance/history',
                { params }
            );
            return response.data.data;
        },
        staleTime: 5 * 60 * 1000,
    });
};

/**
 * Fetch attendance summary/statistics
 * All rates and percentages pre-calculated by backend
 */
export const useAttendanceSummary = (studentId?: number, period?: 'week' | 'month' | 'semester') => {
    return useQuery<AttendanceSummary>({
        queryKey: ['parent', 'attendance', 'summary', studentId, period],
        queryFn: async () => {
            const params = {
                student_id: studentId,
                period: period ?? 'month',
            };
            const response = await apiClient.get<{ data: AttendanceSummary }>(
                '/parent/attendance/summary',
                { params }
            );
            return response.data.data;
        },
        staleTime: 5 * 60 * 1000,
    });
};
