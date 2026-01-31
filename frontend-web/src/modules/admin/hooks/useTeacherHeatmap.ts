import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/api';

// ============================
// Types
// ============================

export interface HeatmapPoint {
  lat: number;
  lng: number;
  count: number;
  students_scanned: number;
  teacher_ids: number[];
  teacher_count: number;
  first_time: string;
  last_time: string;
  distance_from_school: number;
  outside_zone: boolean;
}

export interface SchoolInfo {
  id: number;
  name: string;
  latitude: number;
  longitude: number;
  radius_meters: number;
}

export interface HeatmapData {
  points: HeatmapPoint[];
  school: SchoolInfo;
  date_range: {
    start: string;
    end: string;
  };
  stats: {
    total_points: number;
    total_clusters: number;
    outside_zone_count: number;
  };
}

export interface ClusterDetail {
  teacher_id: number;
  teacher_name: string;
  scan_count: number;
  students_scanned: number;
  times: string[];
  first_scan: string;
  last_scan: string;
}

export interface ClusterDetailsResponse {
  location: { lat: number; lng: number };
  teachers: ClusterDetail[];
  total_scans: number;
  total_students: number;
}

export interface TeacherScanSummary {
  teacher_id: number;
  teacher_name: string;
  total_scans: number;
  total_students: number;
  first_scan: string;
  last_scan: string;
  avg_distance_from_school: number;
  has_outside_zone_scans: boolean;
}

export interface HeatmapParams {
  teacher_id?: number;
  date?: string;
  range?: '7d' | '14d' | '30d';
  school_id?: number; // For super admin
}

// ============================
// API Functions
// ============================

const fetchHeatmapData = async (params: HeatmapParams): Promise<HeatmapData> => {
  const { data } = await apiClient.get('/admin/security-dashboard/teacher-heatmap', { params });
  return data.data;
};

const fetchClusterDetails = async (
  lat: number,
  lng: number,
  params: Omit<HeatmapParams, 'teacher_id'>
): Promise<ClusterDetailsResponse> => {
  const { data } = await apiClient.get('/admin/security-dashboard/teacher-heatmap/cluster-details', {
    params: { lat, lng, ...params },
  });
  return data.data;
};

const fetchTeacherSummary = async (params: Omit<HeatmapParams, 'teacher_id'>): Promise<TeacherScanSummary[]> => {
  const { data } = await apiClient.get('/admin/security-dashboard/teacher-heatmap/teachers', { params });
  return data.data.teachers;
};

const fetchAnomalies = async (params: Omit<HeatmapParams, 'teacher_id'>): Promise<{
  anomalies: HeatmapPoint[];
  school: SchoolInfo;
  stats: { total_outside_zone: number; affected_teachers: number };
}> => {
  const { data } = await apiClient.get('/admin/security-dashboard/teacher-heatmap/anomalies', { params });
  return data.data;
};

// ============================
// React Query Hooks
// ============================

export const useHeatmapData = (params: HeatmapParams) => {
  return useQuery({
    queryKey: ['teacher-heatmap', params],
    queryFn: () => fetchHeatmapData(params),
    staleTime: 1000 * 60 * 5, // 5 minutes
    enabled: true,
  });
};

export const useClusterDetails = (
  lat: number | null,
  lng: number | null,
  params: Omit<HeatmapParams, 'teacher_id'>
) => {
  return useQuery({
    queryKey: ['cluster-details', lat, lng, params],
    queryFn: () => fetchClusterDetails(lat!, lng!, params),
    enabled: lat !== null && lng !== null,
    staleTime: 1000 * 60 * 2,
  });
};

export const useTeacherSummary = (params: Omit<HeatmapParams, 'teacher_id'>) => {
  return useQuery({
    queryKey: ['teacher-heatmap-summary', params],
    queryFn: () => fetchTeacherSummary(params),
    staleTime: 1000 * 60 * 5,
  });
};

export const useHeatmapAnomalies = (params: Omit<HeatmapParams, 'teacher_id'>) => {
  return useQuery({
    queryKey: ['teacher-heatmap-anomalies', params],
    queryFn: () => fetchAnomalies(params),
    staleTime: 1000 * 60 * 5,
  });
};
