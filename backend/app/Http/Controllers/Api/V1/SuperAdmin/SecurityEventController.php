<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SecurityEventController extends Controller
{
    public function index(Request $request)
    {
        // 10. Security Alert Viewer
        // Replay attacks, Invalid signatures, Device mismatch
        
        $query = DB::table('security_alerts')
            ->orderBy('created_at', 'desc');

        if ($request->has('type')) {
            $query->where('alert_type', $request->type); // e.g. replay_attack
        }

        $events = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $events
        ]);
    }
}
