<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Creating sample attendance data...\n";

// 2024 data
DB::table('attendances')->insert([
    'school_id' => 1,
    'student_id' => 1,
    'class_id' => 1,
    'attendance_date' => '2024-06-15',
    'status' => 'present',
    'check_in_time' => '07:30:00',
    'created_at' => now(),
    'updated_at' => now(),
]);

DB::table('attendances')->insert([
    'school_id' => 1,
    'student_id' => 2,
    'class_id' => 1,
    'attendance_date' => '2024-06-15',
    'status' => 'present',
    'check_in_time' => '07:35:00',
    'created_at' => now(),
    'updated_at' => now(),
]);

DB::table('attendances')->insert([
    'school_id' => 1,
    'student_id' => 3,
    'class_id' => 1,
    'attendance_date' => '2024-12-20',
    'status' => 'late',
    'check_in_time' => '08:10:00',
    'created_at' => now(),
    'updated_at' => now(),
]);

// 2025 data
DB::table('attendances')->insert([
    'school_id' => 1,
    'student_id' => 1,
    'class_id' => 1,
    'attendance_date' => '2025-03-10',
    'status' => 'late',
    'check_in_time' => '08:15:00',
    'created_at' => now(),
    'updated_at' => now(),
]);

DB::table('attendances')->insert([
    'school_id' => 1,
    'student_id' => 2,
    'class_id' => 1,
    'attendance_date' => '2025-03-10',
    'status' => 'present',
    'check_in_time' => '07:25:00',
    'created_at' => now(),
    'updated_at' => now(),
]);

echo "✅ Sample data created:\n";
echo "   - 3 records for 2024\n";
echo "   - 2 records for 2025\n";

$count2024 = DB::table('attendances')->whereYear('attendance_date', 2024)->count();
$count2025 = DB::table('attendances')->whereYear('attendance_date', 2025)->count();

echo "\nVerification:\n";
echo "   - 2024: {$count2024} records\n";
echo "   - 2025: {$count2025} records\n";
