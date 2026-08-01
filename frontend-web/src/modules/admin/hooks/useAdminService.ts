import { useMutation, useQueryClient } from "@tanstack/react-query";
import * as adminService from "../../../services/adminService";
import showToast from "../../../utils/toast";

/**
 * React Query Mutation Hooks for School Admin Operations
 * Query hooks are in ./index.ts
 */

// ================================================
// SUBJECT MANAGEMENT
// ================================================

export const useCreateSubject = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: adminService.createSubject,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["adminSubjects"] });
      showToast.success("Mata pelajaran berhasil ditambahkan");
    },
    onError: (error: any) => {
      showToast.error(
        error?.response?.data?.message || "Gagal menambahkan mata pelajaran",
      );
    },
  });
};

export const useUpdateSubject = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, data }: { id: number; data: any }) =>
      adminService.updateSubject(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["adminSubjects"] });
      showToast.success("Mata pelajaran berhasil diperbarui");
    },
    onError: (error: any) => {
      showToast.error(
        error?.response?.data?.message || "Gagal memperbarui mata pelajaran",
      );
    },
  });
};

export const useDeleteSubject = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: adminService.deleteSubject,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["adminSubjects"] });
      showToast.success("Mata pelajaran berhasil dihapus");
    },
    onError: (error: any) => {
      showToast.error(
        error?.response?.data?.message || "Gagal menghapus mata pelajaran",
      );
    },
  });
};

// ================================================
// TEACHER-SUBJECT ASSIGNMENT
// ================================================

export const useTeacherSubjects = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: adminService.createTeacherSubject,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["adminTeacherAssignments"] });
      showToast.success("Penugasan guru berhasil ditambahkan");
    },
    onError: (error: any) => {
      showToast.error(
        error?.response?.data?.message || "Gagal menambahkan penugasan guru",
      );
    },
  });
};

export const useCreateTeacherSubject = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: adminService.createTeacherSubject,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["adminTeacherAssignments"] });
      showToast.success("Penugasan guru berhasil ditambahkan");
    },
    onError: (error: any) => {
      showToast.error(
        error?.response?.data?.message || "Gagal menambahkan penugasan guru",
      );
    },
  });
};

export const useDeleteTeacherSubject = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: adminService.deleteTeacherSubject,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["adminTeacherAssignments"] });
      showToast.success("Penugasan guru berhasil dihapus");
    },
    onError: (error: any) => {
      showToast.error(
        error?.response?.data?.message || "Gagal menghapus penugasan guru",
      );
    },
  });
};

// ================================================
// SCHEDULE MANAGEMENT
// ================================================

export const useWeeklyScheduleByClass = (classId?: number) => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => adminService.getWeeklyScheduleByClass(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["adminSchedules"] });
    },
  });
};

export const useWeeklyScheduleByTeacher = (teacherId?: number) => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => adminService.getWeeklyScheduleByTeacher(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["adminSchedules"] });
    },
  });
};

export const useCreateSchedule = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: adminService.createSchedule,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["adminSchedules"] });
      showToast.success("Jadwal berhasil ditambahkan");
    },
    onError: (error: any) => {
      showToast.error(
        error?.response?.data?.message || "Gagal menambahkan jadwal",
      );
    },
  });
};

export const useUpdateSchedule = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, data }: { id: number; data: any }) =>
      adminService.updateSchedule(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["adminSchedules"] });
      showToast.success("Jadwal berhasil diperbarui");
    },
    onError: (error: any) => {
      showToast.error(
        error?.response?.data?.message || "Gagal memperbarui jadwal",
      );
    },
  });
};

export const useDeleteSchedule = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: adminService.deleteSchedule,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["adminSchedules"] });
      showToast.success("Jadwal berhasil dihapus");
    },
    onError: (error: any) => {
      showToast.error(
        error?.response?.data?.message || "Gagal menghapus jadwal",
      );
    },
  });
};

export const useImportSchedules = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: adminService.importSchedules,
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ["adminSchedules"] });
      showToast.success(`Berhasil mengimpor ${data?.imported || 0} jadwal`);
    },
    onError: (error: any) => {
      showToast.error(
        error?.response?.data?.message || "Gagal mengimpor jadwal",
      );
    },
  });
};

// ================================================
// ATTENDANCE SETTINGS
// ================================================

export const useUpdateAttendanceSettings = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: adminService.updateAttendanceSettings,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["adminAttendanceSettings"] });
      showToast.success("Pengaturan absensi berhasil diperbarui");
    },
    onError: (error: any) => {
      showToast.error(
        error?.response?.data?.message || "Gagal memperbarui pengaturan",
      );
    },
  });
};

// ================================================
// STUDENT CARD MANAGEMENT
// ================================================

export const useGenerateStudentCard = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: adminService.generateStudentCard,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["adminStudentCards"] });
      showToast.success("Kartu siswa berhasil dibuat");
    },
    onError: (error: any) => {
      showToast.error(
        error?.response?.data?.message || "Gagal membuat kartu siswa",
      );
    },
  });
};

export const useBulkGenerateStudentCards = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: adminService.bulkGenerateStudentCards,
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ["adminStudentCards"] });
      showToast.success(`Berhasil membuat ${data?.generated || 0} kartu siswa`);
    },
    onError: (error: any) => {
      showToast.error(
        error?.response?.data?.message || "Gagal membuat kartu siswa massal",
      );
    },
  });
};

// ================================================
// PHOTO REVIEW
// ================================================

export const useApprovePhoto = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: adminService.approveStudentPhoto,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["adminPendingPhotos"] });
      showToast.success("Foto berhasil disetujui");
    },
    onError: (error: any) => {
      showToast.error(
        error?.response?.data?.message || "Gagal menyetujui foto",
      );
    },
  });
};

export const useRejectPhoto = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({
      studentId,
      reason,
    }: {
      studentId: number;
      reason?: string;
    }) => adminService.rejectStudentPhoto(studentId, reason),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["adminPendingPhotos"] });
      showToast.success("Foto berhasil ditolak");
    },
    onError: (error: any) => {
      showToast.error(error?.response?.data?.message || "Gagal menolak foto");
    },
  });
};

// ================================================
// DASHBOARD & REPORTS (Mutations)
// ================================================

export const useRiskOverview = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: adminService.getRiskOverview,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["adminRiskOverview"] });
    },
  });
};

export const useDailyReport = (date?: string) => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => adminService.getDailyReport(date),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["adminDailyReport"] });
    },
  });
};

export const useMonthlyReport = (month?: string, year?: string) => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => adminService.getMonthlyReport(month, year),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["adminMonthlyReport"] });
    },
  });
};

// ================================================
// NOTIFICATIONS & RISK LOGS
// ================================================

export const useNotificationLogs = (page = 1, limit = 20) => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => adminService.getNotificationLogs(page, limit),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["adminNotificationLogs"] });
    },
  });
};

export const useRiskChanges = (days = 7) => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => adminService.getRiskChanges(days),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["adminRiskChanges"] });
    },
  });
};

// ================================================
// CLASSES & TEACHERS (Additional)
// ================================================

export const useAdminClasses = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: adminService.getClasses,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["adminClasses"] });
    },
  });
};

export const useAdminTeachers = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: adminService.getTeachers,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["adminTeachers"] });
    },
  });
};

export const useAdminStudents = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: adminService.getStudents,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["adminStudents"] });
    },
  });
};
