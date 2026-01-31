import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/api';

export interface TeacherAbsenceItem {
    teacher_id: number;
    teacher_name: string;
    total_schedules: number;
    qr_generated: number;
    missing_qr: number;
}

export interface TeacherAbsenceResponse {
    date: string;
    total_teachers_scheduled: number;
    total_teachers_absent: number;
    teachers: TeacherAbsenceItem[];
}

export const useTeacherAbsence = () => {
    return useQuery({
        queryKey: ['admin', 'dashboard', 'teacher-absent'],
        queryFn: async () => {
            const response = await apiClient.get<TeacherAbsenceResponse>('/admin/dashboard/teacher-absent');
            return response.data;
        },
    });
};
