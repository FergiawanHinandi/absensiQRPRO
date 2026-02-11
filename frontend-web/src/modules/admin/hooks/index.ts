import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../../../lib/api';

export interface DailyReportStats {
    total_students: number;
    present: number;
    late: number;
    sick: number;
    permission: number;
    alpha: number;
    attendance_rate: number;
}

export interface ClassAttendance {
    class_id: number;
    class_name: string;
    total_students: number;
    present: number;
    late: number;
    sick: number;
    permission: number;
    alpha: number;
}

export interface ClassAttendanceSummary {
    date: string;
    classes: ClassAttendance[];
}

export interface TeacherAbsent {
    teacher_id: number;
    teacher_name: string;
    total_schedules: number;
    qr_generated: number;
    missing_qr: number;
}

export interface TeacherAbsenceData {
    date: string;
    total_teachers_scheduled: number;
    total_teachers_absent: number;
    teachers: TeacherAbsent[];
}

export interface StudentInfo {
    student_id: number;
    student_name: string;
    username: string;
    class_name: string;
    time?: string;
}

export interface LateAlphaData {
    date: string;
    late: {
        total: number;
        students: StudentInfo[];
    };
    alpha: {
        total: number;
        students: StudentInfo[];
    };
}

export interface AnomalyItem {
    type: string;
    message: string;
    severity: string;
    recorded_by?: string;
    status?: string;
    time?: string;
    scan_count?: number;
}

export interface AnomaliesData {
    date: string;
    total: number;
    items: AnomalyItem[];
}

export interface TeacherListItem {
    id: number;
    name: string;
    username: string;
    email: string | null;
    role_type: string;
    is_active: boolean;
    last_login_at?: string | null;
    total_schedules: number;
}

export interface TeacherListResponse {
    current_page: number;
    data: TeacherListItem[];
    total: number;
    last_page: number;
}

export interface StudentListItem {
    id: number;
    name: string;
    username: string;
    email: string | null;
    is_active: boolean;
    class_name?: string | null;
}

export interface StudentListResponse {
    total: number;
    students: StudentListItem[];
}

export interface ClassListItem {
    id: number;
    name: string;
    grade_level: number;
    homeroom_teacher?: string | null;
    is_active: boolean;
    total_students: number;
}

export interface ClassListResponse {
    total: number;
    classes: ClassListItem[];
}

export interface SubjectListItem {
    id: number;
    code: string;
    name: string;
    grade_level?: number | null;
    school_level: string;
    is_active: boolean;
}

export interface SubjectListResponse {
    total: number;
    subjects: SubjectListItem[];
}

export interface ScheduleListItem {
    id: number;
    class_id?: number | null;
    subject_id?: number | null;
    teacher_id?: number | null;
    academic_year_id?: number | null;
    day_of_week: number;
    start_time: string;
    end_time: string;
    room?: string | null;
    is_active: boolean;
    class_name?: string | null;
    subject_name?: string | null;
    teacher_name?: string | null;
}

export interface ScheduleListResponse {
    total: number;
    schedules: ScheduleListItem[];
}

export interface ParentListItem {
    id: number;
    name: string;
    username: string;
    email: string | null;
    is_active: boolean;
}

export interface ParentListResponse {
    total: number;
    parents: ParentListItem[];
}

export interface ReportListItem {
    id: number;
    report_type: string;
    report_date: string;
    period_start: string;
    period_end: string;
    attendance_rate: number;
    file_url?: string | null;
}

export interface ReportListResponse {
    total: number;
    reports: ReportListItem[];
}

export interface SchoolProfile {
    id: number;
    name: string;
    npsn: string;
    school_level: string;
    address?: string | null;
    phone?: string | null;
    email?: string | null;
    timezone?: string | null;
    logo_url?: string | null;
    latitude?: string | number | null;
    longitude?: string | number | null;
    radius_meters?: number | null;
    settings?: any;
    is_active?: boolean;
}

export interface AcademicYearItem {
    id: number;
    name: string;
    semester: string;
    start_date: string;
    end_date: string;
    is_active: boolean;
}

export interface AcademicYearResponse {
    total: number;
    years: AcademicYearItem[];
}

export interface TeacherAssignmentItem {
    id: number;
    teacher_id: number;
    subject_id: number;
    class_id: number;
    academic_year_id: number;
    teacher?: { name: string };
    subject?: { name: string };
    class?: { name: string };
    academic_year?: { name: string };
}

export interface TeacherAssignmentResponse {
    total: number;
    assignments: TeacherAssignmentItem[];
}

// Hook for daily report
export const useDailyReport = (date?: string) => {
    return useQuery({
        queryKey: ['dailyReport', date],
        queryFn: async () => {
            const params = date ? { date } : {};
            const response = await apiClient.get('/admin/reports/daily', { params });
            return response.data.data as DailyReportStats;
        },
        refetchInterval: 60000,
    });
};

// Hook for class attendance summary
export const useClassAttendanceSummary = (date?: string) => {
    return useQuery({
        queryKey: ['classAttendance', date],
        queryFn: async () => {
            const params = date ? { date } : {};
            const response = await apiClient.get('/admin/dashboard/class-attendance', { params });
            return response.data.data as ClassAttendanceSummary;
        },
        refetchInterval: 60000,
    });
};

// Hook for teacher absence
export const useTeacherAbsence = (date?: string) => {
    return useQuery({
        queryKey: ['teacherAbsent', date],
        queryFn: async () => {
            const params = date ? { date } : {};
            const response = await apiClient.get('/admin/dashboard/teacher-absent', { params });
            return response.data.data as TeacherAbsenceData;
        },
        refetchInterval: 60000,
    });
};

// Hook for late and alpha students
export const useLateAlpha = (date?: string) => {
    return useQuery({
        queryKey: ['lateAlpha', date],
        queryFn: async () => {
            const params = date ? { date } : {};
            const response = await apiClient.get('/admin/dashboard/late-alpha', { params });
            return response.data.data as LateAlphaData;
        },
        refetchInterval: 60000,
    });
};

// Hook for attendance anomalies
export const useAttendanceAnomalies = (date?: string) => {
    return useQuery({
        queryKey: ['anomalies', date],
        queryFn: async () => {
            const params = date ? { date } : {};
            const response = await apiClient.get('/admin/dashboard/anomalies', { params });
            return response.data.data as AnomaliesData;
        },
        refetchInterval: 120000,
    });
};

export const useAdminTeachers = () => {
    return useQuery({
        queryKey: ['adminTeachers'],
        queryFn: async () => {
            const response = await apiClient.get('/admin/teachers');
            return response.data.data as TeacherListResponse;
        },
        refetchInterval: 120000,
    });
};

export const useAdminStudents = () => {
    return useQuery({
        queryKey: ['adminStudents'],
        queryFn: async () => {
            const response = await apiClient.get('/admin/students');
            return response.data.data as StudentListResponse;
        },
        refetchInterval: 120000,
    });
};

export const useAdminClasses = () => {
    return useQuery({
        queryKey: ['adminClasses'],
        queryFn: async () => {
            const response = await apiClient.get('/admin/classes');
            return response.data.data as ClassListResponse;
        },
        refetchInterval: 120000,
    });
};

export const useAdminSubjects = () => {
    return useQuery({
        queryKey: ['adminSubjects'],
        queryFn: async () => {
            const response = await apiClient.get('/admin/subjects');
            return response.data.data as SubjectListResponse;
        },
        refetchInterval: 120000,
    });
};

export const useAdminSchedules = () => {
    return useQuery({
        queryKey: ['adminSchedules'],
        queryFn: async () => {
            const response = await apiClient.get('/admin/schedules');
            return response.data.data as ScheduleListResponse;
        },
        refetchInterval: 120000,
    });
};

export const useAdminParents = () => {
    return useQuery({
        queryKey: ['adminParents'],
        queryFn: async () => {
            const response = await apiClient.get('/admin/parents');
            return response.data.data as ParentListResponse;
        },
        refetchInterval: 120000,
    });
};

export const useAdminReports = () => {
    return useQuery({
        queryKey: ['adminReports'],
        queryFn: async () => {
            const response = await apiClient.get('/admin/reports');
            return response.data.data as ReportListResponse;
        },
        refetchInterval: 300000,
    });
};

export const useSchoolProfile = () => {
    return useQuery({
        queryKey: ['adminSchoolProfile'],
        queryFn: async () => {
            const response = await apiClient.get('/admin/settings/profile');
            return response.data.data as SchoolProfile;
        },
        refetchInterval: 300000,
    });
};

export const useAcademicYears = () => {
    return useQuery({
        queryKey: ['adminAcademicYears'],
        queryFn: async () => {
            const response = await apiClient.get('/admin/settings/academic-year');
            return response.data.data as AcademicYearResponse;
        },
        refetchInterval: 300000,
    });
};

export const useTeacherAssignments = () => {
    return useQuery({
        queryKey: ['adminTeacherAssignments'],
        queryFn: async () => {
            const response = await apiClient.get('/admin/teachers/assignments');
            return response.data.data as TeacherAssignmentResponse;
        },
        refetchInterval: 120000,
    });
};
