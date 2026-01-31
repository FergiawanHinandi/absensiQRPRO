export type RoleType = 'super_admin' | 'school_admin' | 'principal' | 'teacher' | 'homeroom_teacher' | 'student' | 'parent';

export interface UserSchool {
    id: number;
    name: string;
    school_level: string;
}

export interface User {
    id: number;
    name: string;
    username: string;
    email: string;
    role_type: RoleType;
    school_id: number;
    school?: UserSchool;
    roles: string[];
    permissions: string[];
}

export interface AuthResponse {
    token: string;
    user: User;
}

export interface LoginCredentials {
    username: string;
    password: string;
}
