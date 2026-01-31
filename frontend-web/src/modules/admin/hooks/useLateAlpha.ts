import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/api';

export interface LateAlphaStudent {
    student_id: number;
    student_name: string;
    username: string;
    class_name: string;
    grade_level: number;
    check_in_time?: string | null;
}

export interface LateAlphaResponse {
    date: string;
    late: {
        total: number;
        students: LateAlphaStudent[];
    };
    alpha: {
        total: number;
        students: LateAlphaStudent[];
    };
}

export const useLateAlpha = () => {
    return useQuery({
        queryKey: ['admin', 'dashboard', 'late-alpha'],
        queryFn: async () => {
            const response = await apiClient.get<LateAlphaResponse>('/admin/dashboard/late-alpha');
            return response.data;
        },
    });
};
