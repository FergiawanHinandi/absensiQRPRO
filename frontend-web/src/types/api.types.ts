export interface User {
    id: number;
    name: string;
    username: string;
    email: string;
    role_type: 'student' | 'teacher' | 'homeroom_teacher' | 'admin' | 'school_admin' | 'super_admin';
    school_id: number;
    school?: {
        id: number;
        name: string;
        school_level: string;
    };
    roles: string[];
    permissions: string[];
}

export interface AuthResponse {
    token: string;
    user: User;
}

export interface Schedule {
    id: number;
    subject: {
        name: string;
    };
    class: {
        name: string;
    };
    start_time: string;
    end_time: string;
    room: string;
    students_count?: number;
}

export interface QrCodeData {
    id: number;
    token: string;
    qr_type: 'in' | 'out';
    valid_until: string;
    max_scans: number;
}

export interface StudentAttendance {
    id: number;
    name: string;
    username: string;
    status: 'present' | 'late' | 'sick' | 'permit' | 'alpha' | 'excused';
    check_in_time: string | null;
    is_manual: boolean;
}
