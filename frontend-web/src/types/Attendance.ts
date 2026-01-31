export type AttendanceStatus = 'present' | 'late' | 'sick' | 'permit' | 'alpha' | 'excused';

export interface Attendance {
    id: number;
    user_id: number;
    user_name?: string; // Often joined
    school_id: number;
    check_in_time: string | null;
    check_out_time: string | null;
    status: AttendanceStatus;
    work_hours?: string;
    is_late?: boolean;
    date?: string;
    proof_file_url?: string | null;
    notes?: string | null;
}
