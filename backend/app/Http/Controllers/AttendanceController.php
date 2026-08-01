<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttendanceScanRequest;
use App\Services\AttendanceService;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function scan(AttendanceScanRequest $request, AttendanceService $service)
    {
        return $service->recordByTeacherScan($request->user(), $request->validated());
    }
}