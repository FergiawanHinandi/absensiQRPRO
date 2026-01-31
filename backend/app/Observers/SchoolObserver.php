<?php

namespace App\Observers;

use App\Models\School;

class SchoolObserver
{
    /**
     * Handle the School "created" event.
     */
    public function created(School $school): void
    {
        \App\Models\AuditLog::create([
            'user_id' => auth()->id(),
            'school_id' => $school->id,
            'action' => 'create_school',
            'description' => "Membuat sekolah baru: {$school->name}",
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * Handle the School "updated" event.
     */
    public function updated(School $school): void
    {
        if ($school->wasChanged()) {
            \App\Models\AuditLog::create([
                'user_id' => auth()->id(),
                'school_id' => $school->id,
                'action' => 'update_school',
                'description' => "Memperbarui data sekolah: {$school->name}",
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        }
        if ($school->wasChanged('settings')) {
            \App\Helpers\SchoolSettingsHelper::clearCache($school->id);
        }
    }

    /**
     * Handle the School "deleted" event.
     */
    public function deleted(School $school): void
    {
        \App\Models\AuditLog::create([
            'user_id' => auth()->id(),
            'school_id' => null, // Set null because school is deleted
            'action' => 'delete_school',
            'description' => "Menghapus sekolah (ID: {$school->id}): {$school->name}",
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
