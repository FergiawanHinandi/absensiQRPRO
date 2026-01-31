import type { User } from './User';

export interface Teacher extends User {
    nip?: string;
    phone?: string;
    gender?: 'male' | 'female' | 'L' | 'P';
    address?: string;
    teacher_roles?: { class: { name: string } }[];
    teacher_subjects?: { subject: { name: string }, class: { name: string } }[];
}
