<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\QrService;

class QrCodeController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index(Request $request)
    {
        $scheduleId = $request->schedule_id;
        $qrId = $request->qr_id;
        $type = $request->type;

        $token = app(\App\Services\QrService::class)->generate([
            'schedule_id' => $scheduleId,
            'qr_id' => $qrId,
            'type' => $type
        ]);

        return response()->json([
            'token' => $token
        ]);
    }
}