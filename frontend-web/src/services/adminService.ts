import { apiClient } from '../lib/api';

/**
 * School Admin Service Layer
 * Centralized API calls for all school admin operations
 */

// ================================================
// 1️⃣ DASHBOARD & REPORTS
// ================================================

export const getDailyReport = async (date?: string) => {
    const params = date ? { date } : {};
    const response = await apiClient.get('/admin/reports/daily', { params });
    return response.data.data;
};

export const getRiskOverview = async () => {
    const response = await apiClient.get('/admin/risk-overview');
    return response.data.data;
};

export const getMonthlyReport = async (month?: string, year?: string) => {
    const params: any = {};
    if (month) params.month = month;
    if (year) params.year = year;
    const response = await apiClient.get('/admin/reports/monthly', { params });
    return response.data.data;
};

// ================================================
// 2️⃣ SUBJECT MANAGEMENT
// ================================================

export const getSubjects = async () => {
    const response = await apiClient.get('/admin/subjects');
    return response.data.data;
};

export const createSubject = async (data: {
    code: string;
    name: string;
    grade_level?: number;
    school_level: string;
}) => {
    const response = await apiClient.post('/admin/subjects', data);
    return response.data.data;
};

export const updateSubject = async (id: number, data: any) => {
    const response = await apiClient.put(`/admin/subjects/${id}`, data);
    return response.data.data;
};

export const deleteSubject = async (id: number) => {
    const response = await apiClient.delete(`/admin/subjects/${id}`);
    return response.data;
};

// ================================================
// 3️⃣ TEACHER-SUBJECT ASSIGNMENT
// ================================================

export const getTeacherSubjects = async () => {
    const response = await apiClient.get('/admin/teacher-subjects');
    return response.data.data;
};

export const createTeacherSubject = async (data: {
    teacher_id: number;
    subject_id: number;
    class_id?: number;
}) => {
    const response = await apiClient.post('/admin/teacher-subjects', data);
    return response.data.data;
};

export const deleteTeacherSubject = async (id: number) => {
    const response = await apiClient.delete(`/admin/teacher-subjects/${id}`);
    return response.data;
};

// ================================================
// 4️⃣ SCHEDULE MANAGEMENT
// ================================================

export const getSchedules = async () => {
    const response = await apiClient.get('/admin/schedules');
    return response.data.data;
};

export const getWeeklyScheduleByClass = async (classId: number) => {
    const response = await apiClient.get(`/admin/classes/${classId}/weekly-schedule`);
    return response.data.data;
};

export const getWeeklyScheduleByTeacher = async (teacherId: number) => {
    const response = await apiClient.get(`/admin/teachers/${teacherId}/weekly-schedule`);
    return response.data.data;
};

export const createSchedule = async (data: {
    class_id: number;
    subject_id: number;
    teacher_id: number;
    day_of_week: number;
    start_time: string;
    end_time: string;
    room?: string;
}) => {
    const response = await apiClient.post('/admin/schedules', data);
    return response.data.data;
};

export const updateSchedule = async (id: number, data: any) => {
    const response = await apiClient.put(`/admin/schedules/${id}`, data);
    return response.data.data;
};

export const deleteSchedule = async (id: number) => {
    const response = await apiClient.delete(`/admin/schedules/${id}`);
    return response.data;
};

export const importSchedules = async (file: File) => {
    const formData = new FormData();
    formData.append('file', file);
    const response = await apiClient.post('/admin/schedules/import', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
    });
    return response.data.data;
};

// ================================================
// 5️⃣ ATTENDANCE SETTINGS
// ================================================

export const getAttendanceSettings = async () => {
    const response = await apiClient.get('/admin/attendance-settings');
    return response.data.data;
};

export const updateAttendanceSettings = async (data: {
    check_in_start?: string;
    check_in_end?: string;
    late_threshold_minutes?: number;
    qr_validity_minutes?: number;
}) => {
    const response = await apiClient.post('/admin/attendance-settings', data);
    return response.data.data;
};

export const getQrModeSettings = async () => {
    const response = await apiClient.get('/admin/attendance-settings/qr-mode');
    return response.data.data;
};

export const updateQrModeSettings = async (data: {
    qr_expiry_seconds: number;
    qr_regeneration_cooldown: number;
}) => {
    const response = await apiClient.post('/admin/attendance-settings/qr-mode', data);
    return response.data;
};

export const getOverrideSettings = async () => {
    const response = await apiClient.get('/admin/attendance-settings/override');
    return response.data.data;
};

export const updateOverrideSettings = async (data: {
    allow_teacher_override: boolean;
    require_override_reason: boolean;
}) => {
    const response = await apiClient.post('/admin/attendance-settings/override', data);
    return response.data;
};

export const getToleranceSettings = async () => {
    const response = await apiClient.get('/admin/attendance-settings/tolerance');
    return response.data.data;
};

export const updateToleranceSettings = async (data: {
    late_tolerance_minutes: number;
    early_check_in_allowed: boolean;
}) => {
    const response = await apiClient.post('/admin/attendance-settings/tolerance', data);
    return response.data;
};

// ================================================
// 6️⃣ STUDENT CARD MANAGEMENT
// ================================================

export const getStudentCardProgress = async () => {
    const response = await apiClient.get('/admin/student-cards/progress');
    return response.data.data;
};

export const generateStudentCard = async (studentId: number) => {
    const response = await apiClient.post(`/admin/student-cards/${studentId}/generate`);
    return response.data.data;
};

export const bulkGenerateStudentCards = async (classId: number) => {
    const response = await apiClient.post('/admin/student-cards/bulk-generate', {
        class_id: classId,
    });
    return response.data.data;
};

export const getStudentCardStatus = async (studentId: number) => {
    const response = await apiClient.get(`/admin/student-cards/${studentId}/status`);
    return response.data.data;
};

// ================================================
// 7️⃣ PHOTO REVIEW
// ================================================

export const getPendingPhotos = async () => {
    const response = await apiClient.get('/admin/students/photos/pending');
    return response.data.data;
};

export const approveStudentPhoto = async (studentId: number) => {
    const response = await apiClient.post(`/admin/students/${studentId}/photos/approve`);
    return response.data.data;
};

export const rejectStudentPhoto = async (studentId: number, reason?: string) => {
    const response = await apiClient.post(`/admin/students/${studentId}/photos/reject`, {
        reason,
    });
    return response.data.data;
};

// ================================================
// 8️⃣ REPORT EXPORTS
// ================================================

export const exportReportPDF = async (params: {
    report_type: string;
    start_date?: string;
    end_date?: string;
    class_id?: number;
}) => {
    const response = await apiClient.post('/admin/reports/export-pdf', params, {
        responseType: 'blob',
    });
    return response.data;
};

export const exportReportExcel = async (params: {
    report_type: string;
    start_date?: string;
    end_date?: string;
    class_id?: number;
}) => {
    const response = await apiClient.post('/admin/reports/export-excel', params, {
        responseType: 'blob',
    });
    return response.data;
};

// ================================================
// 9️⃣ NOTIFICATIONS & RISK LOGS
// ================================================

export const getNotificationLogs = async (page = 1, limit = 20) => {
    const response = await apiClient.get('/admin/notifications/logs', {
        params: { page, limit },
    });
    return response.data.data;
};

export const getRiskChanges = async (days = 7) => {
    const response = await apiClient.get('/admin/risk/changes', {
        params: { days },
    });
    return response.data.data;
};

// ================================================
// 🔟 ADDITIONAL HELPERS
// ================================================

export const getClasses = async () => {
    const response = await apiClient.get('/admin/classes');
    return response.data.data;
};

export const getTeachers = async () => {
    const response = await apiClient.get('/admin/teachers');
    return response.data.data;
};

export const getStudents = async () => {
    const response = await apiClient.get('/admin/students');
    return response.data.data;
};
