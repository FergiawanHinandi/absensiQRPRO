<?php

namespace App\Traits;

use App\Models\ClassModel;
use App\Models\School;
use App\Models\User;

trait HasSchoolLimits
{
    /**
     * Check if school has reached its limit for a specific resource
     */
    protected function checkSchoolLimit($schoolId, $type)
    {
        $school = School::find($schoolId);
        if (! $school) {
            return false;
        }

        // Premium has no limits
        if ($school->package_type === 'premium') {
            return true;
        }

        $limit = 0;
        $current = 0;

        switch ($type) {
            case 'students':
                $limit = $school->max_students;
                $current = User::where('school_id', $schoolId)->where('role_type', 'student')->count();
                break;
            case 'teachers':
                $limit = $school->max_teachers;
                $current = User::where('school_id', $schoolId)->whereIn('role_type', ['teacher', 'homeroom_teacher'])->count();
                break;
            case 'classes':
                $limit = $school->max_classes;
                $current = ClassModel::where('school_id', $schoolId)->count();
                break;
        }

        if ($limit > 0 && $current >= $limit) {
            return false;
        }

        return true;
    }
}
