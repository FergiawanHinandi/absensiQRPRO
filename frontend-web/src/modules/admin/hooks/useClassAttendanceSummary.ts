import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/api';

export interface ClassAttendanceSummary {
    class_id: number;
    class_name: string;
    grade_level: number;
    total_students: number;
    present: number;
    late: number;
    sick: number;
    permit: number;
    excused: number;
    absent: number;
    alpha: number;
}

export interface ClassAttendanceResponse {
    date: string;
    classes: ClassAttendanceSummary[];
}

export const useClassAttendanceSummary = () => {
    return useQuery({
        queryKey: ['admin', 'dashboard', 'class-attendance'],
        queryFn: async () => {
            const response = await apiClient.get<ClassAttendanceResponse>('/admin/dashboard/class-attendance');
            return response.data;
        },
    });
};
