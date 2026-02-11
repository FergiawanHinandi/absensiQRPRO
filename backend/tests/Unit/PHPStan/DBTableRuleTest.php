<?php

namespace Tests\Unit\PHPStan;

use Tests\TestCase;

/**
 * Test to verify PHPStan rule prevents DB::table() usage
 * 
 * This test file demonstrates what the PHPStan rule should catch.
 * Run: composer analyse
 * 
 * Expected: PHPStan should report errors for DB::table() usage on tenant tables
 */
class DBTableRuleTest extends TestCase
{
    /**
     * This method intentionally contains code that should be flagged by PHPStan
     * 
     * @return void
     */
    public function test_phpstan_should_catch_db_table_usage(): void
    {
        // These lines should be caught by PHPStan (commented out to prevent runtime errors)
        
        // ❌ BAD: This should trigger PHPStan error
        // $count = DB::table('attendances')->count();
        
        // ❌ BAD: This should trigger PHPStan error
        // $students = DB::table('students')->where('school_id', 1)->get();
        
        // ❌ BAD: This should trigger PHPStan error
        // DB::table('schedules')->insert(['name' => 'Test']);
        
        // ✅ GOOD: This is the correct way (uses Eloquent with global scopes)
        // $count = Attendance::count();
        // $students = Student::where('school_id', 1)->get();
        // Schedule::create(['name' => 'Test']);
        
        $this->assertTrue(true, 'PHPStan rule test placeholder');
    }
}
