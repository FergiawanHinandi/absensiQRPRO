<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Sanctum Token Abilities Configuration
    |--------------------------------------------------------------------------
    |
    | Define role-based abilities for Sanctum tokens. Each token will be
    | granted only the abilities specific to the user's role, following
    | the principle of least privilege.
    |
    */

    'role_abilities' => [
        'student' => [
            // Attendance
            'attendance:scan',           // Scan QR codes for attendance
            'attendance:view_own',       // View own attendance history
            
            // Student-specific
            'student:view_profile',      // View own student profile
            'student:view_qr_card',      // View own QR card
            
            // Permissions (izin)
            'permission:create',         // Create leave/permission requests
            'permission:view_own',       // View own permission requests
        ],

        'teacher' => [
            // Attendance
            'attendance:scan',           // Scan QR codes (teacher mode)
            'attendance:view_class',     // View class attendance data
            'attendance:manual',         // Submit manual attendance
            
            // Teacher-specific
            'teacher:view_dashboard',    // View teacher dashboard
            'teacher:view_profile',      // View own profile
            'teacher:view_students',     // View assigned students
            'teacher:view_schedules',    // View teaching schedules
            
            // QR Management
            'qr:generate',               // Generate QR codes for class
            'qr:close',                  // Close QR code session
            
            // Permissions
            'permission:view',           // View permission requests
            'permission:approve',        // Approve/reject permissions
            
            // Reports
            'report:export',             // Export class reports
        ],

        'homeroom_teacher' => [
            // All teacher abilities
            'attendance:scan',
            'attendance:view_class',
            'attendance:manual',
            'teacher:view_dashboard',
            'teacher:view_profile',
            'teacher:view_students',
            'teacher:view_schedules',
            'qr:generate',
            'qr:close',
            'permission:view',
            'permission:approve',
            'report:export',
            
            // Additional homeroom abilities
            'homeroom:manage_students',  // Manage homeroom students
            'homeroom:view_summary',     // View homeroom summary
        ],

        'parent' => [
            'parent:view_children',      // View assigned children
            'parent:view_attendance',    // View children's attendance
        ],

        'admin' => [
            // Full admin access
            'attendance:manage',         // Full attendance management
            'attendance:view_all',       // View all attendance records
            
            // User management
            'user:manage',               // CRUD users
            'teacher:manage',            // CRUD teachers
            'student:manage',            // CRUD students
            
            // Class management
            'class:manage',              // CRUD classes
            'schedule:manage',           // CRUD schedules
            
            // Reports
            'report:view_all',           // View all reports
            'report:export',             // Export reports
            'report:daily',              // Daily reports
            
            // Dashboard
            'dashboard:admin',           // Admin dashboard access
            
            // Settings
            'school:manage',             // Manage school settings
            'academic_year:manage',      // Manage academic years
            
            // Parent management
            'parent:manage',             // CRUD parents
            
            // System monitoring (EXPLICIT - no wildcard fallback)
            'system:monitor',            // Monitor system health, queue, security
        ],

        'school_admin' => [
            // Same as admin
            'attendance:manage',
            'attendance:view_all',
            'user:manage',
            'teacher:manage',
            'student:manage',
            'class:manage',
            'schedule:manage',
            'report:view_all',
            'report:export',
            'report:daily',
            'dashboard:admin',
            'school:manage',
            'academic_year:manage',
            'parent:manage',
            
            // Additional school admin abilities
            'subscription:manage',       // Manage subscriptions
            'billing:view',              // View billing info
            
            // System monitoring (EXPLICIT - no wildcard fallback)
            'system:monitor',            // Monitor system health, queue, security
        ],

        'super_admin' => [
            '*',                         // Full access to all endpoints
            'system:monitor',            // EXPLICIT: System health monitoring (required even with wildcard)
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ability Descriptions
    |--------------------------------------------------------------------------
    |
    | Human-readable descriptions for each ability, useful for UI display
    | and documentation purposes.
    |
    */

    'descriptions' => [
        'attendance:scan' => 'Scan QR codes for attendance',
        'attendance:view_own' => 'View own attendance history',
        'attendance:view_class' => 'View class attendance data',
        'attendance:view_all' => 'View all attendance records',
        'attendance:manage' => 'Full attendance management',
        'attendance:manual' => 'Submit manual attendance',
        
        'student:view_profile' => 'View own student profile',
        'student:view_qr_card' => 'View own QR card',
        'student:manage' => 'Manage student records',
        
        'teacher:view_dashboard' => 'View teacher dashboard',
        'teacher:view_profile' => 'View own profile',
        'teacher:view_students' => 'View assigned students',
        'teacher:view_schedules' => 'View teaching schedules',
        'teacher:manage' => 'Manage teacher records',
        
        'qr:generate' => 'Generate QR codes',
        'qr:close' => 'Close QR code sessions',
        
        'permission:create' => 'Create leave requests',
        'permission:view_own' => 'View own permissions',
        'permission:view' => 'View permission requests',
        'permission:approve' => 'Approve/reject permissions',
        
        'report:export' => 'Export reports',
        'report:view_all' => 'View all reports',
        'report:daily' => 'Access daily reports',
        
        'user:manage' => 'Manage users',
        'class:manage' => 'Manage classes',
        'schedule:manage' => 'Manage schedules',
        'dashboard:admin' => 'Access admin dashboard',
        'school:manage' => 'Manage school settings',
        'academic_year:manage' => 'Manage academic years',
        'parent:manage' => 'Manage parent records',
        'parent:view_children' => 'View assigned children',
        'parent:view_attendance' => 'View children attendance',
        'homeroom:manage_students' => 'Manage homeroom students',
        'homeroom:view_summary' => 'View homeroom summary',
        'subscription:manage' => 'Manage subscriptions',
        'billing:view' => 'View billing information',
        'system:monitor' => 'Monitor system health, queue status, and security events',
    ],
];
