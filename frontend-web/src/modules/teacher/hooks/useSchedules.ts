import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/api';
import type { Schedule } from '../../../types/api.types';

export const useSchedules = () => {
    return useQuery({
        queryKey: ['teacher', 'schedules', 'today'],
        queryFn: async () => {
            const response = await apiClient.get<{ schedules: Schedule[] }>('/teacher/schedules/today');
            return response.data.schedules;
        }
    });
};
