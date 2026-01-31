<?php

namespace App\Console\Commands;

use App\Models\ClassModel;
use App\Models\School;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class GenerateStudents extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:generate-students {count : The number of students to generate} {class_name? : Specific class name (e.g. 1A)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate specific number of students for testing';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $count = (int) $this->argument('count');
        $className = $this->argument('class_name');

        $this->info("Generating {$count} students...");

        // Use Mongisidi School
        $school = School::where('name', 'like', '%Mongisidi%')->first();
        if (! $school) {
            $this->error('School SD Negeri Unggulan Mongisidi 1 not found. Run seeders first.');

            return;
        }

        $class = null;
        if ($className) {
            $class = ClassModel::where('school_id', $school->id)->where('name', $className)->first();
            if (! $class) {
                $this->error("Class {$className} not found.");

                return;
            }
        } else {
            // Pick random class if not specified
            $class = ClassModel::where('school_id', $school->id)->inRandomOrder()->first();
            if (! $class) {
                $this->error('No classes found in school.');

                return;
            }
        }

        $this->info("Target Class: {$class->name}");

        $created = 0;
        DB::beginTransaction();
        try {
            for ($i = 1; $i <= $count; $i++) {
                $nextNum = User::where('school_id', $school->id)->where('role_type', 'student')->count() + 1;
                $username = strtolower("siswa_{$class->name}_{$nextNum}");

                $student = User::create([
                    'school_id' => $school->id,
                    'name' => "Siswa Uji Coba {$nextNum}",
                    'username' => $username,
                    'email' => "{$username}@sdmongisidi.sch.id",
                    'password' => Hash::make('password'),
                    'role_type' => 'student',
                    'is_active' => true,
                ]);
                $student->assignRole('student');

                // Link to Class
                DB::table('class_students')->insert([
                    'class_id' => $class->id,
                    'student_id' => $student->id,
                    'status' => 'active',
                    'enrollment_date' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $created++;
            }
            DB::commit();
            $this->info("Successfully created {$created} students.");
            $this->info("Example Credential: {$username} / password");

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('Error: '.$e->getMessage());
        }
    }
}
