import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/api';

// --- Types ---
interface TeacherScheduleResponse {
    schedules: any[]; // Replace 'any' with explicit Schedule type later if needed
}

interface HomeroomStatsResponse {
    is_homeroom: boolean;
    class_name?: string;
    total_students?: number;
    present_today?: number;
    absent_today?: number;
}

// --- Hooks ---

export const useTeacherSchedule = (date: string) => {
    return useQuery({
        queryKey: ['teacher', 'schedules', date],
        queryFn: async () => {
            // For now, handling generic response matching the Dashboard expectation
            // In real implementation, pass date as query param: ?date=${date}
            const response = await apiClient.get<TeacherScheduleResponse>('/teacher/schedules/today', {
                params: { date }
            });
            return response.data; // Should return object with { schedules: [...] }
        }
    });
};

export const useHomeroomStats = () => {
    return useQuery({
        queryKey: ['teacher', 'homeroom-stats'],
        queryFn: async () => {
            const response = await apiClient.get<HomeroomStatsResponse>('/teacher/homeroom/summary');
            return response.data;
        }
    });
};

export const useAtRiskStudents = () => {
    return useQuery({
        queryKey: ['teacher', 'at-risk'],
        queryFn: async () => {
            return [];
        }
    });
};
