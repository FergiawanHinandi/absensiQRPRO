import type { User } from './User';

export interface Student extends User {
    nis?: string;
    nisn?: string;
    class_id?: number | null;
    class_name?: string;
    gender?: 'male' | 'female' | 'L' | 'P';
    parent_name?: string;
    phone?: string;
    address?: string;
    student_class?: { id: number; name: string };
}
