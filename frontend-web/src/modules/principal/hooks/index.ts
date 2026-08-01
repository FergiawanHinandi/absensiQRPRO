import { useQuery } from '@tanstack/react-query';
import { principalService } from '../services/principalService';

export function useAttendanceOverview(range = '7d') {
  return useQuery({
    queryKey: ['principal', 'attendance-overview', range],
    queryFn: () => principalService.getAttendanceOverview(range),
  });
}

export function useClassPerformance() {
  return useQuery({
    queryKey: ['principal', 'class-performance'],
    queryFn: principalService.getClassPerformance,
  });
}

export function useRiskStudents(limit = 50) {
  return useQuery({
    queryKey: ['principal', 'risk-students', limit],
    queryFn: () => principalService.getRiskStudents(limit),
  });
}
