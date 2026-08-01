<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToSchool;

class AttendanceScanRequest extends Model
{
    use BelongsToSchool; // Multi-tenancy: otomatis filter berdasarkan school_id

    protected $table = 'attendance_scan_requests';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'school_id',
        'user_id',
        'qr_token',
        'lat',
        'lng',
        'accuracy',
        'device_id',
        'enqueue_response_ms',
        'status',
        'error_message',
        'processed_attendance_id',
    ];

    public function attendance()
    {
        return $this->belongsTo(Attendance::class, 'processed_attendance_id');
    }
}
