import { apiClient } from '../lib/api';

export interface TeacherSchedule {
    id: number;
    class_name: string;
    subject_name: string;
    day_of_week: 'monday' | 'tuesday' | 'wednesday' | 'thursday' | 'friday' | 'saturday' | 'sunday';
    start_time: string;
    end_time: string;
    room?: string;
}

// Types
export type AttendanceStatus = 'present' | 'late' | 'sick' | 'permit' | 'alpha';

export interface Student {
    id: number;
    name: string;
    nis?: string;
    attendance_status?: AttendanceStatus;
    attendance_notes?: string;
}

export interface AttendancePayload {
    student_id: number;
    status: AttendanceStatus;
    notes?: string;
}

export const teacherService = {
    getSchedules: async (weekStartDate: string): Promise<TeacherSchedule[]> => {
        const response = await apiClient.get<{ data: TeacherSchedule[] }>(`/teacher/schedules`, {
            params: { week: weekStartDate }
        });
        // FE-05 FIX: interceptor sudah unwrap, response.data langsung berisi data array
        // response.data = data sebenarnya (sudah di-unwrap dari {success, data} → data)
        return (response.data as any)?.data ?? response.data ?? [];
    },

    getStudentsBySession: async (sessionId: number): Promise<Student[]> => {
        // GET /api/v1/teacher/schedules/{id}/attendance
        const response = await apiClient.get<{ data: any }>(`/teacher/schedules/${sessionId}/attendance`);
        // FE-05 FIX: response.data sudah di-unwrap → langsung akses .students
        const data = (response.data as any);
        return data?.students ?? data?.data?.students ?? [];
    },

    saveAttendance: async (sessionId: number, payload: AttendancePayload): Promise<void> => {
        // POST /api/v1/teacher/attendance/manual
        // Payload needs schedule_id
        await apiClient.post(`/teacher/attendance/manual`, {
            ...payload,
            schedule_id: sessionId
        });
    },

    saveBulkAttendance: async (sessionId: number, students: AttendancePayload[]): Promise<void> => {
        // POST /api/v1/teacher/attendance/manual/bulk
        await apiClient.post(`/teacher/attendance/manual/bulk`, {
            schedule_id: sessionId,
            attendances: students
        });
    }
};
