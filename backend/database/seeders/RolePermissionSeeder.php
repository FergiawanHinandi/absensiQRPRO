<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeder.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions
        $permissions = [
            // System monitoring permissions
            'system:monitor',

            // Attendance permissions
            'attendance.scan',
            'attendance.manual_input',
            'attendance.approve',
            'attendance.export',
            'attendance.view_all',
            'attendance.view_own',
            'attendance.modify',

            // Student permissions
            'students.create',
            'students.update',
            'students.delete',
            'students.view',
            'students.import',
            'students.manage_class',

            // Class permissions
            'classes.create',
            'classes.update',
            'classes.delete',
            'classes.view',
            'classes.assign_students',
            'classes.assign_teachers',

            // Report permissions
            'reports.generate',
            'reports.export',
            'reports.view_all',
            'reports.approve',

            // School permissions
            'school.settings',
            'school.qr_generate',
            'school.academic_year',

            // User management
            'users.create',
            'users.update',
            'users.delete',
            'users.view',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'sanctum']);
        }

        // Create roles and assign permissions

        // Super Admin - All permissions
        $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'sanctum']);
        $superAdmin->givePermissionTo(Permission::all());

        // Admin - System monitoring & full school management
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'sanctum']);
        $admin->givePermissionTo([
            'system:monitor',
            'attendance.view_all',
            'attendance.export',
            'students.view',
            'classes.view',
            'users.view',
            'reports.generate',
            'reports.export',
        ]);

        // School Admin - Manage school
        $schoolAdmin = Role::firstOrCreate(['name' => 'school_admin', 'guard_name' => 'sanctum']);
        $schoolAdmin->givePermissionTo([
            'attendance.scan',
            'attendance.manual_input',
            'attendance.approve',
            'attendance.export',
            'attendance.view_all',
            'students.create',
            'students.update',
            'students.delete',
            'students.view',
            'students.import',
            'classes.create',
            'classes.update',
            'classes.delete',
            'classes.view',
            'classes.assign_students',
            'classes.assign_teachers',
            'reports.generate',
            'reports.export',
            'reports.view_all',
            'reports.approve',
            'school.settings',
            'school.qr_generate',
            'school.academic_year',
            'users.create',
            'users.update',
            'users.delete',
            'users.view',
        ]);

        // Principal - View & approve reports
        $principal = Role::firstOrCreate(['name' => 'principal', 'guard_name' => 'sanctum']);
        $principal->givePermissionTo([
            'attendance.view_all',
            'attendance.approve',
            'reports.generate',
            'reports.export',
            'reports.view_all',
            'reports.approve',
            'students.view',
            'classes.view',
        ]);

        // Vice Principal
        $vicePrincipal = Role::firstOrCreate(['name' => 'vice_principal', 'guard_name' => 'sanctum']);
        $vicePrincipal->givePermissionTo([
            'attendance.view_all',
            'reports.view_all',
            'students.view',
            'classes.view',
        ]);

        // Teacher - Scan QR & manual input
        $teacher = Role::firstOrCreate(['name' => 'teacher', 'guard_name' => 'sanctum']);
        $teacher->givePermissionTo([
            'attendance.scan',
            'attendance.manual_input',
            'attendance.view_own',
            'students.view',
            'classes.view',
            'school.qr_generate',
        ]);

        // Homeroom Teacher - Manage class
        $homeroomTeacher = Role::firstOrCreate(['name' => 'homeroom_teacher', 'guard_name' => 'sanctum']);
        $homeroomTeacher->givePermissionTo([
            'attendance.scan',
            'attendance.manual_input',
            'attendance.view_own',
            'students.view',
            'students.manage_class',
            'classes.view',
            'reports.generate',
            'reports.export',
            'school.qr_generate',
        ]);

        // Staff TU - Admin tasks
        $staff = Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'sanctum']);
        $staff->givePermissionTo([
            'students.create',
            'students.update',
            'students.view',
            'students.import',
            'attendance.export',
            'reports.generate',
            'reports.export',
        ]);

        // Student - View own attendance
        $student = Role::firstOrCreate(['name' => 'student', 'guard_name' => 'sanctum']);
        $student->givePermissionTo([
            'attendance.view_own',
        ]);

        // Parent - View child attendance (future)
        $parent = Role::firstOrCreate(['name' => 'parent', 'guard_name' => 'sanctum']);
        $parent->givePermissionTo([
            'attendance.view_own',
        ]);

        $this->command->info('Roles and permissions seeded successfully!');
    }
}
