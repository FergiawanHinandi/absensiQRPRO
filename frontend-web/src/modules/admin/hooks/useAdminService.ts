import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import * as adminService from '../../../services/adminService';
import showToast from '../../../utils/toast';

/**
 * React Query Hooks for School Admin Operations
 * Provides data fetching, caching, and mutations with optimistic updates
 */

// ================================================
// 1️⃣ DASHBOARD & REPORTS
// ================================================

export const useRiskOverview = () => {
    return useQuery({
        queryKey: ['admin', 'risk-overview'],
        queryFn: adminService.getRiskOverview,
        refetchInterval: 60000, // Refresh every minute
        staleTime: 30000,
    });
};

export const useDailyReport = (date?: string) => {
    return useQuery({
        queryKey: ['admin', 'daily-report', date],
        queryFn: () => adminService.getDailyReport(date),
        enabled: !!date,
        refetchInterval: 60000, // Refresh every minute
        staleTime: 30000,
    });
};

export const useMonthlyReport = (month?: string, year?: string) => {
    return useQuery({
        queryKey: ['admin', 'monthly-report', month, year],
        queryFn: () => adminService.getMonthlyReport(month, year),
        enabled: !!month && !!year,
    });
};

// ================================================
// 2️⃣ SUBJECT MANAGEMENT
// ================================================

export const useCreateSubject = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: adminService.createSubject,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['adminSubjects'] });
            showToast.success('Mata pelajaran berhasil ditambahkan');
        },
        onError: (error: any) => {
            showToast.error(error?.response?.data?.message || 'Gagal menambahkan mata pelajaran');
        },
    });
};

export const useUpdateSubject = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ id, data }: { id: number; data: any }) =>
            adminService.updateSubject(id, data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['adminSubjects'] });
            showToast.success('Mata pelajaran berhasil diperbarui');
        },
        onError: (error: any) => {
            showToast.error(error?.response?.data?.message || 'Gagal memperbarui mata pelajaran');
        },
    });
};

export const useDeleteSubject = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: adminService.deleteSubject,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['adminSubjects'] });
            showToast.success('Mata pelajaran berhasil dihapus');
        },
        onError: (error: any) => {
            showToast.error(error?.response?.data?.message || 'Gagal menghapus mata pelajaran');
        },
    });
};

// ================================================
// 3️⃣ TEACHER-SUBJECT ASSIGNMENT
// ================================================

export const useTeacherSubjects = () => {
    return useQuery({
        queryKey: ['admin', 'teacher-subjects'],
        queryFn: adminService.getTeacherSubjects,
        refetchInterval: 120000,
    });
};

export const useCreateTeacherSubject = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: adminService.createTeacherSubject,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['admin', 'teacher-subjects'] });
            showToast.success('Penugasan guru berhasil ditambahkan');
        },
        onError: (error: any) => {
            showToast.error(error?.response?.data?.message || 'Gagal menambahkan penugasan guru');
        },
    });
};

export const useDeleteTeacherSubject = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: adminService.deleteTeacherSubject,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['admin', 'teacher-subjects'] });
            showToast.success('Penugasan guru berhasil dihapus');
        },
        onError: (error: any) => {
            showToast.error(error?.response?.data?.message || 'Gagal menghapus penugasan guru');
        },
    });
};

// ================================================
// 4️⃣ SCHEDULE MANAGEMENT
// ================================================

export const useWeeklyScheduleByClass = (classId?: number) => {
    return useQuery({
        queryKey: ['admin', 'weekly-schedule', 'class', classId],
        queryFn: () => adminService.getWeeklyScheduleByClass(classId!),
        enabled: !!classId,
    });
};

export const useWeeklyScheduleByTeacher = (teacherId?: number) => {
    return useQuery({
        queryKey: ['admin', 'weekly-schedule', 'teacher', teacherId],
        queryFn: () => adminService.getWeeklyScheduleByTeacher(teacherId!),
        enabled: !!teacherId,
    });
};

export const useCreateSchedule = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: adminService.createSchedule,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['adminSchedules'] });
            queryClient.invalidateQueries({ queryKey: ['admin', 'weekly-schedule'] });
            showToast.success('Jadwal berhasil ditambahkan');
        },
        onError: (error: any) => {
            showToast.error(error?.response?.data?.message || 'Gagal menambahkan jadwal');
        },
    });
};

export const useUpdateSchedule = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ id, data }: { id: number; data: any }) =>
            adminService.updateSchedule(id, data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['adminSchedules'] });
            queryClient.invalidateQueries({ queryKey: ['admin', 'weekly-schedule'] });
            showToast.success('Jadwal berhasil diperbarui');
        },
        onError: (error: any) => {
            showToast.error(error?.response?.data?.message || 'Gagal memperbarui jadwal');
        },
    });
};

export const useDeleteSchedule = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: adminService.deleteSchedule,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['adminSchedules'] });
            queryClient.invalidateQueries({ queryKey: ['admin', 'weekly-schedule'] });
            showToast.success('Jadwal berhasil dihapus');
        },
        onError: (error: any) => {
            showToast.error(error?.response?.data?.message || 'Gagal menghapus jadwal');
        },
    });
};

export const useImportSchedules = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: adminService.importSchedules,
        onSuccess: (data) => {
            queryClient.invalidateQueries({ queryKey: ['adminSchedules'] });
            showToast.success(`Berhasil mengimpor ${data?.imported || 0} jadwal`);
        },
        onError: (error: any) => {
            showToast.error(error?.response?.data?.message || 'Gagal mengimpor jadwal');
        },
    });
};

// ================================================
// 5️⃣ ATTENDANCE SETTINGS
// ================================================

export const useAttendanceSettings = () => {
    return useQuery({
        queryKey: ['admin', 'attendance-settings'],
        queryFn: adminService.getAttendanceSettings,
    });
};

export const useUpdateAttendanceSettings = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: adminService.updateAttendanceSettings,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['admin', 'attendance-settings'] });
            showToast.success('Pengaturan absensi berhasil diperbarui');
        },
        onError: (error: any) => {
            showToast.error(error?.response?.data?.message || 'Gagal memperbarui pengaturan');
        },
    });
};

// ================================================
// 6️⃣ STUDENT CARD MANAGEMENT
// ================================================

export const useStudentCardProgress = () => {
    return useQuery({
        queryKey: ['admin', 'student-card-progress'],
        queryFn: adminService.getStudentCardProgress,
        refetchInterval: 60000,
    });
};

export const useGenerateStudentCard = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: adminService.generateStudentCard,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['admin', 'student-card-progress'] });
            showToast.success('Kartu siswa berhasil dibuat');
        },
        onError: (error: any) => {
            showToast.error(error?.response?.data?.message || 'Gagal membuat kartu siswa');
        },
    });
};

export const useBulkGenerateStudentCards = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: adminService.bulkGenerateStudentCards,
        onSuccess: (data) => {
            queryClient.invalidateQueries({ queryKey: ['admin', 'student-card-progress'] });
            showToast.success(`Berhasil membuat ${data?.generated || 0} kartu siswa`);
        },
        onError: (error: any) => {
            showToast.error(error?.response?.data?.message || 'Gagal membuat kartu siswa massal');
        },
    });
};

// ================================================
// 7️⃣ PHOTO REVIEW
// ================================================

export const usePendingPhotos = () => {
    return useQuery({
        queryKey: ['admin', 'pending-photos'],
        queryFn: adminService.getPendingPhotos,
        refetchInterval: 120000,
    });
};

export const useApprovePhoto = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: adminService.approveStudentPhoto,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['admin', 'pending-photos'] });
            showToast.success('Foto berhasil disetujui');
        },
        onError: (error: any) => {
            showToast.error(error?.response?.data?.message || 'Gagal menyetujui foto');
        },
    });
};

export const useRejectPhoto = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ studentId, reason }: { studentId: number; reason?: string }) =>
            adminService.rejectStudentPhoto(studentId, reason),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['admin', 'pending-photos'] });
            showToast.success('Foto berhasil ditolak');
        },
        onError: (error: any) => {
            showToast.error(error?.response?.data?.message || 'Gagal menolak foto');
        },
    });
};

// ================================================
// 8️⃣ NOTIFICATIONS & RISK LOGS
// ================================================

export const useNotificationLogs = (page = 1, limit = 20) => {
    return useQuery({
        queryKey: ['admin', 'notification-logs', page, limit],
        queryFn: () => adminService.getNotificationLogs(page, limit),
        refetchInterval: 60000,
    });
};

export const useRiskChanges = (days = 7) => {
    return useQuery({
        queryKey: ['admin', 'risk-changes', days],
        queryFn: () => adminService.getRiskChanges(days),
        refetchInterval: 120000,
    });
};

// ================================================
// 🔟 ADDITIONAL HELPERS
// ================================================

export const useAdminClasses = () => {
    return useQuery({
        queryKey: ['adminClasses'],
        queryFn: adminService.getClasses,
        refetchInterval: 120000,
    });
};

export const useAdminTeachers = () => {
    return useQuery({
        queryKey: ['adminTeachers'],
        queryFn: adminService.getTeachers,
        refetchInterval: 120000,
    });
};

export const useAdminStudents = () => {
    return useQuery({
        queryKey: ['adminStudents'],
        queryFn: adminService.getStudents,
        refetchInterval: 120000,
    });
};
