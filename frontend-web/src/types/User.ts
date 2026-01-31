export type UserRole = 'student' | 'teacher' | 'homeroom_teacher' | 'admin' | 'school_admin' | 'super_admin' | 'parent';

export interface User {
    id: number;
    name: string;
    username: string;
    email: string | null;
    role_type: UserRole;
    school_id: number;
    school?: {
        id: number;
        name: string;
        school_level: string;
    };
    is_active?: boolean;
    roles?: string[];
    permissions?: string[];
}
