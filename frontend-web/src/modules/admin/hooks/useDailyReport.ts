import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/api';

export interface DailyStats {
    total_students: number;
    attendance_rate: number;
    present: number;
    late: number;
    sick: number;
    alpha: number;
}

export const useDailyReport = () => {
    return useQuery({
        queryKey: ['admin', 'reports', 'daily'],
        queryFn: async () => {
            // In a real app we'd fetch from API
            // For now we simulate or use the placeholder logic from the original component if API fails
            try {
                const response = await apiClient.get<DailyStats>('/reports/daily');
                return response.data;
            } catch (error) {
                // Fallback Mock Data as per original requirement during dev
                console.warn("Using mock data for daily report");
                return {
                    total_students: 120,
                    attendance_rate: 85,
                    present: 98,
                    late: 5,
                    sick: 2,
                    alpha: 15
                } as DailyStats;
            }
        }
    });
};
