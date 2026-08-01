<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\ClassModel;
use App\Models\Device;
use App\Models\Permit;
use App\Models\School;
use App\Models\Subject;
use App\Models\TeacherRole;
use App\Models\User;
use App\Models\UserProfile;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class MongisidiSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Seed data untuk SD Negeri Unggulan Mongisidi 1
     * Dengan 12 Rombel (1A-6B) dan 5 Role Pengguna.
     */
    public function run(): void
    {
        // =================================================================
        // 1. PASTIKAN ROLE TERSEDIA
        // =================================================================
        $roleNames = ['super_admin', 'school_admin', 'principal', 'homeroom_teacher', 'teacher', 'student', 'parent'];
        foreach ($roleNames as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'sanctum']);
        }
        $this->command->info('✅ Roles ready.');

        // =================================================================
        // 2. SUPER ADMIN
        // =================================================================
        $superAdmin = User::firstOrCreate(
            ['email' => 'super@admin.com'],
            [
                'name' => 'Super Administrator',
                'username' => 'superadmin',
                'password' => Hash::make('password'),
                'role_type' => 'super_admin',
                'is_active' => true,
            ]
        );
        $superAdmin->assignRole('super_admin');
        $this->command->info('✅ Super Admin: superadmin / password');

        // =================================================================
        // 3. SEKOLAH: SD NEGERI UNGGULAN MONGISIDI 1
        // =================================================================
        $school = School::firstOrCreate(
            ['npsn' => '40302761'], // NPSN realistik untuk SD di Makassar
            [
                'name' => 'SD Negeri Unggulan Mongisidi 1',
                'npsn' => '40302761',
                'school_level' => 'SD',
                'address' => 'Jl. Mongisidi No. 1, Kec. Makassar, Kota Makassar, Sulawesi Selatan',
                'phone' => '0411-361234',
                'email' => 'admin@sdmongisidi1.sch.id',
                'timezone' => 'Asia/Makassar',
                'is_active' => true,
                'settings' => [
                    'attendance' => [
                        'qr_expiry_minutes' => 10,
                        'late_tolerance_minutes' => 15,
                        'qr_mode' => 'per_session',
                        'allow_manual_input' => true,
                        'location_required' => false,
                        'fingerprint_enabled' => true,
                        'rfid_enabled' => true,
                    ],
                    'school_profile' => [
                        'kepala_sekolah' => 'Hj. St. Hasnah, S.Pd., M.Pd.',
                        'nip_kepsek' => '196512311990032001',
                        'akreditasi' => 'A',
                        'website' => 'https://sdmongisidi1.sch.id',
                    ],
                ],
                'max_students' => 360,
                'max_teachers' => 30,
                'max_classes' => 12,
            ]
        );
        $this->command->info("✅ Sekolah: {$school->name}");

        // =================================================================
        // 4. TAHUN AJARAN AKTIF
        // =================================================================
        $academicYear = AcademicYear::firstOrCreate([
            'school_id' => $school->id,
            'name' => '2025/2026',
        ], [
            'start_date' => Carbon::parse('2025-07-14'),
            'end_date' => Carbon::parse('2026-06-20'),
            'semester' => '2',
            'is_active' => true,
        ]);
        $this->command->info('✅ Tahun Ajaran: 2025/2026');

        // =================================================================
        // 5. KEPALA SEKOLAH (Principal)
        // =================================================================
        $kepsek = User::firstOrCreate(
            ['email' => 'kepsek@sdmongisidi1.sch.id'],
            [
                'name' => 'Hj. St. Hasnah, S.Pd., M.Pd.',
                'username' => 'kepsek_mongisidi',
                'password' => Hash::make('password'),
                'role_type' => 'principal',
                'school_id' => $school->id,
                'is_active' => true,
            ]
        );
        $kepsek->assignRole('principal');
        UserProfile::updateOrCreate(['user_id' => $kepsek->id], [
            'full_name' => 'Hj. St. Hasnah, S.Pd., M.Pd.',
            'nip' => '196512311990032001',
            'gender' => 'female',
        ]);
        $this->command->info('✅ Kepala Sekolah: kepsek_mongisidi / password');

        // =================================================================
        // 6. OPERATOR SEKOLAH (School Admin)
        // =================================================================
        $operator = User::firstOrCreate(
            ['email' => 'operator@sdmongisidi1.sch.id'],
            [
                'name' => 'Andi Firmansyah, S.Kom.',
                'username' => 'operator_mongisidi',
                'password' => Hash::make('password'),
                'role_type' => 'school_admin',
                'school_id' => $school->id,
                'is_active' => true,
            ]
        );
        $operator->assignRole('school_admin');
        UserProfile::updateOrCreate(['user_id' => $operator->id], [
            'full_name' => 'Andi Firmansyah, S.Kom.',
            'nip' => '198503152010011012',
            'gender' => 'male',
        ]);
        $this->command->info('✅ Operator: operator_mongisidi / password');

        // =================================================================
        // 7. GURU & WALI KELAS (untuk 12 rombel)
        // =================================================================
        $teachersData = [
            // Wali Kelas untuk 12 rombel (1A - 6B)
            ['name' => 'Nurhayati, S.Pd.',               'username' => 'guru_nurhayati',   'wali' => '1A', 'gender' => 'female'],
            ['name' => 'Muhammad Akbar, S.Pd.',           'username' => 'guru_akbar',       'wali' => '1B', 'gender' => 'male'],
            ['name' => 'Sitti Rahmah, S.Pd.',             'username' => 'guru_rahmah',      'wali' => '2A', 'gender' => 'female'],
            ['name' => 'Hasanuddin, S.Pd.',               'username' => 'guru_hasan',       'wali' => '2B', 'gender' => 'male'],
            ['name' => 'Rosmini, S.Pd.',                  'username' => 'guru_rosmini',     'wali' => '3A', 'gender' => 'female'],
            ['name' => 'Abdul Rahman, S.Pd.',             'username' => 'guru_rahman',      'wali' => '3B', 'gender' => 'male'],
            ['name' => 'Dra. Hj. St. Maryam',             'username' => 'guru_maryam',      'wali' => '4A', 'gender' => 'female'],
            ['name' => 'Drs. Muhammad Yasin',             'username' => 'guru_yasin',       'wali' => '4B', 'gender' => 'male'],
            ['name' => 'Hasnawati, S.Pd.',                'username' => 'guru_hasnawati',   'wali' => '5A', 'gender' => 'female'],
            ['name' => 'Syamsuddin, S.Pd., M.Pd.',        'username' => 'guru_syamsuddin',  'wali' => '5B', 'gender' => 'male'],
            ['name' => 'Dra. Hj. Aminah',                 'username' => 'guru_aminah',      'wali' => '6A', 'gender' => 'female'],
            ['name' => 'Drs. H. M. Tahir, M.M.',          'username' => 'guru_tahir',       'wali' => '6B', 'gender' => 'male'],
            // Guru Mata Pelajaran
            ['name' => 'Rismawati, S.Pd.',                'username' => 'guru_pjok',        'wali' => null, 'gender' => 'female'],
            ['name' => 'Muh. Ilyas, S.Ag.',               'username' => 'guru_agama',       'wali' => null, 'gender' => 'male'],
            ['name' => 'Andi Tenri, S.Pd.',               'username' => 'guru_bahasa',      'wali' => null, 'gender' => 'female'],
            ['name' => 'Sri Wahyuni, S.Pd.',              'username' => 'guru_seni',        'wali' => null, 'gender' => 'female'],
        ];

        $teachers = [];
        $teacherIndex = 0;
        $nipBase = 19700101;

        foreach ($teachersData as $tData) {
            $teacher = User::firstOrCreate(
                ['username' => $tData['username']],
                [
                    'name' => $tData['name'],
                    'email' => "{$tData['username']}@sdmongisidi1.sch.id",
                    'password' => Hash::make('password'),
                    'role_type' => $tData['wali'] ? 'homeroom_teacher' : 'teacher',
                    'school_id' => $school->id,
                    'is_active' => true,
                ]
            );
            $teacher->assignRole($tData['wali'] ? 'homeroom_teacher' : 'teacher');
            if (!$tData['wali']) {
                $teacher->assignRole('teacher');
            }

            UserProfile::updateOrCreate(['user_id' => $teacher->id], [
                'full_name' => $tData['name'],
                'nip' => (string) ($nipBase + $teacherIndex),
                'gender' => $tData['gender'],
                // phone di-skip karena kolom encrypted butuh text, bukan varchar(20)
            ]);

            $teachers[] = $teacher;
            $teacherIndex++;
        }
        $this->command->info('✅ ' . count($teachers) . ' Guru & Wali Kelas created.');

        // =================================================================
        // 8. 12 ROMBEL (Kelas 1A - 6B)
        // =================================================================
        $classConfigs = [
            ['name' => '1A', 'grade' => 1, 'teacher_idx' => 0],
            ['name' => '1B', 'grade' => 1, 'teacher_idx' => 1],
            ['name' => '2A', 'grade' => 2, 'teacher_idx' => 2],
            ['name' => '2B', 'grade' => 2, 'teacher_idx' => 3],
            ['name' => '3A', 'grade' => 3, 'teacher_idx' => 4],
            ['name' => '3B', 'grade' => 3, 'teacher_idx' => 5],
            ['name' => '4A', 'grade' => 4, 'teacher_idx' => 6],
            ['name' => '4B', 'grade' => 4, 'teacher_idx' => 7],
            ['name' => '5A', 'grade' => 5, 'teacher_idx' => 8],
            ['name' => '5B', 'grade' => 5, 'teacher_idx' => 9],
            ['name' => '6A', 'grade' => 6, 'teacher_idx' => 10],
            ['name' => '6B', 'grade' => 6, 'teacher_idx' => 11],
        ];

        $classes = [];
        foreach ($classConfigs as $cConfig) {
            $homeroomTeacher = $teachers[$cConfig['teacher_idx']];

            $class = ClassModel::firstOrCreate(
                [
                    'school_id' => $school->id,
                    'academic_year_id' => $academicYear->id,
                    'name' => $cConfig['name'],
                ],
                [
                    'grade_level' => $cConfig['grade'],
                    'homeroom_teacher_id' => $homeroomTeacher->id,
                    'is_active' => true,
                ]
            );

            TeacherRole::updateOrCreate(
                [
                    'teacher_id' => $homeroomTeacher->id,
                    'academic_year_id' => $academicYear->id,
                ],
                [
                    'is_homeroom_teacher' => true,
                    'homeroom_class_id' => $class->id,
                ]
            );

            $classes[] = ['id' => $class->id, 'name' => $cConfig['name']];
        }
        $this->command->info('✅ 12 Rombel created: ' . implode(', ', array_column($classes, 'name')));

        // =================================================================
        // 9. MATA PELAJARAN (Kurikulum SD)
        // =================================================================
        $subjectsData = [
            ['name' => 'Pendidikan Agama Islam', 'code' => 'PAI'],
            ['name' => 'Pendidikan Pancasila', 'code' => 'PPKn'],
            ['name' => 'Bahasa Indonesia', 'code' => 'BINDO'],
            ['name' => 'Matematika', 'code' => 'MAT'],
            ['name' => 'Ilmu Pengetahuan Alam', 'code' => 'IPA'],
            ['name' => 'Ilmu Pengetahuan Sosial', 'code' => 'IPS'],
            ['name' => 'Seni Budaya', 'code' => 'SBdP'],
            ['name' => 'Pendidikan Jasmani', 'code' => 'PJOK'],
            ['name' => 'Bahasa Inggris', 'code' => 'BING'],
            ['name' => 'Muatan Lokal', 'code' => 'MULOK'],
        ];

        $subjects = [];
        foreach ($subjectsData as $sData) {
            $subjects[] = Subject::firstOrCreate([
                'school_id' => $school->id,
                'code' => $sData['code'],
            ], [
                'name' => $sData['name'],
                'school_level' => 'SD',
                'grade_level' => 1,
                'is_active' => true,
            ]);
        }
        $this->command->info('✅ ' . count($subjects) . ' Mata Pelajaran created.');

        // =================================================================
        // 10. SISWA (25 siswa per kelas = 300 siswa total)
        // =================================================================
        $students = [];
        foreach ($classes as $cls) {
            for ($i = 1; $i <= 25; $i++) {
                $nisn = str_pad(rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);
                $username = strtolower("siswa_{$cls['name']}_{$i}");

                $student = User::firstOrCreate(
                    ['username' => $username],
                    [
                        'name' => "Siswa {$cls['name']} No. {$i}",
                        'email' => "{$username}@sdmongisidi1.sch.id",
                        'password' => Hash::make('password'),
                        'role_type' => 'student',
                        'school_id' => $school->id,
                        'is_active' => true,
                    ]
                );
                $student->assignRole('student');

                UserProfile::updateOrCreate(['user_id' => $student->id], [
                    'full_name' => "Siswa {$cls['name']} No. {$i}",
                    'nisn' => $nisn,
                    'gender' => $i % 2 === 0 ? 'female' : 'male',
                ]);

                // Relasi student -> class
                DB::table('class_students')->updateOrInsert(
                    ['student_id' => $student->id, 'class_id' => $cls['id']],
                    [
                        'status' => 'active',
                        'enrollment_date' => $academicYear->start_date ?? now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                // Orang Tua
                $parentUsername = "ortu_{$cls['name']}_{$i}";
                $parent = User::firstOrCreate(
                    ['username' => $parentUsername],
                    [
                        'name' => "Orang Tua {$cls['name']} No. {$i}",
                        'email' => "{$parentUsername}@sdmongisidi1.sch.id",
                        'password' => Hash::make('password'),
                        'role_type' => 'parent',
                        'school_id' => $school->id,
                        'is_active' => true,
                    ]
                );
                $parent->assignRole('parent');

                DB::table('student_parents')->updateOrInsert(
                    ['student_id' => $student->id, 'parent_id' => $parent->id],
                    ['relationship' => $i % 2 === 0 ? 'Ibu' : 'Ayah', 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]
                );

                $students[] = $student;
            }
        }
        $this->command->info('✅ ' . count($students) . ' Siswa & Orang Tua created.');

        // =================================================================
        // 11. JADWAL PELAJARAN (Senin-Jumat)
        // =================================================================
        $days = [1, 2, 3, 4, 5]; // Senin-Jumat
        $subjectPool = $subjects;
        $teacherPool = $teachers; // Guru mapel: index 12-15

        foreach ($days as $dayIdx => $day) {
            foreach ($classes as $classIdx => $class) {
                // Set 1: 07:30 - 09:00 (Wali Kelas - Tematik/Matematika)
                $subject1 = $subjectPool[$classIdx % count($subjectPool)];
                DB::table('schedules')->updateOrInsert(
                    [
                        'school_id' => $school->id,
                        'academic_year_id' => $academicYear->id,
                        'class_id' => $class['id'],
                        'day_of_week' => $day,
                        'start_time' => '07:30:00',
                    ],
                    [
                        'subject_id' => $subject1->id,
                        'teacher_id' => $teachers[$classConfigs[$classIdx]['teacher_idx']]->id,
                        'end_time' => '09:00:00',
                        'room' => "Ruang {$class['name']}",
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                // Set 2: 09:30 - 11:00 (Guru Mapel)
                $subject2 = $subjectPool[($classIdx + $dayIdx + 1) % count($subjectPool)];
                $mapelTeacher = $teachers[12 + ($classIdx % 4)]; // Guru mapel (indeks 12-15)
                DB::table('schedules')->updateOrInsert(
                    [
                        'school_id' => $school->id,
                        'academic_year_id' => $academicYear->id,
                        'class_id' => $class['id'],
                        'day_of_week' => $day,
                        'start_time' => '09:30:00',
                    ],
                    [
                        'subject_id' => $subject2->id,
                        'teacher_id' => $mapelTeacher->id,
                        'end_time' => '11:00:00',
                        'room' => "Ruang {$class['name']}",
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
        $this->command->info('✅ Jadwal Pelajaran created (Senin-Jumat).');

        // =================================================================
        // 12. PERANGKAT (Solution X606-S)
        // =================================================================
        $device = Device::firstOrCreate(
            ['sn' => 'X606S-MGS-2024-001'],
            [
                'school_id' => $school->id,
                'name' => 'Mesin Absensi Fingerprint - SD Mongisidi 1',
                'type' => 'fingerprint_rfid',
                'model' => 'Solution X606-S',
                'ip_address' => '192.168.1.100',
                'port' => 8080,
                'location' => 'Ruang Guru SD Negeri Unggulan Mongisidi 1',
                'is_online' => false,
                'is_active' => true,
                'settings' => [
                    'push_protocol' => 'ADMS',
                    'sync_interval' => 30,
                    'timeout' => 10,
                ],
            ]
        );
        $this->command->info('✅ Device Solution X606-S registered.');

        // =================================================================
        // 13. CONTOH AUDIT LOG
        // =================================================================
        AuditLog::create([
            'user_id' => $operator->id,
            'school_id' => $school->id,
            'action' => 'seeder_executed',
            'description' => 'Database seeding untuk SD Negeri Unggulan Mongisidi 1 telah dijalankan.',
            'ip_address' => '127.0.0.1',
        ]);
        $this->command->info('✅ Audit log entry created.');

        // =================================================================
        // SUMMARY
        // =================================================================
        $this->command->info('');
        $this->command->info('╔══════════════════════════════════════════════╗');
        $this->command->info('║   SEEDER SD MONGISIDI 1 SELESAI!            ║');
        $this->command->info('╠══════════════════════════════════════════════╣');
        $this->command->info("║  Sekolah       : {$school->name}");
        $this->command->info('║  Total Guru    : ' . count($teachers));
        $this->command->info('║  Total Siswa   : ' . count($students));
        $this->command->info('║  Total Rombel  : 12 (1A-6B)');
        $this->command->info('║  Mapel         : ' . count($subjects));
        $this->command->info('╠══════════════════════════════════════════════╣');
        $this->command->info('║  AKUN LOGIN:                               ║');
        $this->command->info('║  Super Admin  : superadmin / password      ║');
        $this->command->info('║  Kepala Sekolah: kepsek_mongisidi / pass   ║');
        $this->command->info('║  Operator     : operator_mongisidi / pass  ║');
        $this->command->info('║  Guru/Wali    : guru_nurhayati / password  ║');
        $this->command->info('║  Siswa        : siswa_1a_1 / password      ║');
        $this->command->info('╚══════════════════════════════════════════════╝');
    }
}
