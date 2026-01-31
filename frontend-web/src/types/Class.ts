export interface ClassModel {
    id: number;
    name: string;
    grade_level: number;
    academic_year_id: number;
    max_students: number;
    classroom?: string;
    is_active: boolean;
    homeroom_teacher_id?: number | null;
    homeroom_teacher?: string;
    total_students?: number;
}
