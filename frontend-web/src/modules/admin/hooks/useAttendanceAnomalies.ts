import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/api';

export interface AttendanceAnomalyItem {
    type: 'manual_override' | 'multiple_scans';
    message: string;
    attendance_id?: number;
    student_id?: number;
    recorded_by?: string | null;
    status?: string;
    time?: string | null;
    scan_count?: number;
}

export interface AttendanceAnomalyResponse {
    date: string;
    total: number;
    items: AttendanceAnomalyItem[];
}

export const useAttendanceAnomalies = () => {
    return useQuery({
        queryKey: ['admin', 'dashboard', 'anomalies'],
        queryFn: async () => {
            const response = await apiClient.get<AttendanceAnomalyResponse>('/admin/dashboard/anomalies');
            return response.data;
        },
    });
};
