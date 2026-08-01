import { useQuery } from '@tanstack/react-query';
import { studentService } from '../services/studentService';

export function useStudentDashboard() {
  return useQuery({
    queryKey: ['student', 'dashboard'],
    queryFn: studentService.getDashboard,
  });
}

export function useAttendanceHistory() {
  return useQuery({
    queryKey: ['student', 'attendance-history'],
    queryFn: studentService.getHistory,
  });
}

export function useStudentSchedule() {
  return useQuery({
    queryKey: ['student', 'schedule'],
    queryFn: studentService.getSchedule,
  });
}

export function useStudentProfile() {
  return useQuery({
    queryKey: ['student', 'profile'],
    queryFn: studentService.getProfile,
  });
}
