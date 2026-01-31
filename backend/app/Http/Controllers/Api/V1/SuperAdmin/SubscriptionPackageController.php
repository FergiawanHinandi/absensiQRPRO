<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscriptionPackageController extends Controller
{
    public function index()
    {
        $packages = DB::table('subscription_packages')
            ->orderBy('price', 'asc')
            ->get()
            ->map(function ($pkg) {
                $pkg->features = json_decode($pkg->features, true);
                $pkg->is_popular = (bool) $pkg->is_popular;
                $pkg->is_active = (bool) $pkg->is_active;

                return $pkg;
            });

        return response()->json(['success' => true, 'data' => $packages]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'price' => 'required|numeric',
            'billing_cycle' => 'required|in:monthly,yearly',
            'features' => 'required|array',
            'is_popular' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $id = DB::table('subscription_packages')->insertGetId([
            'name' => $validated['name'],
            'price' => $validated['price'],
            'billing_cycle' => $validated['billing_cycle'],
            'features' => json_encode($validated['features']),
            'is_popular' => $validated['is_popular'] ?? false,
            'is_active' => $validated['is_active'] ?? true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Package created', 'data' => ['id' => $id]]);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'string',
            'price' => 'numeric',
            'billing_cycle' => 'in:monthly,yearly',
            'features' => 'array',
            'is_popular' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $updateData = $validated;
        if (isset($validated['features'])) {
            $updateData['features'] = json_encode($validated['features']);
        }
        $updateData['updated_at'] = now();

        DB::table('subscription_packages')->where('id', $id)->update($updateData);

        return response()->json(['success' => true, 'message' => 'Package updated']);
    }

    public function destroy($id)
    {
        DB::table('subscription_packages')->where('id', $id)->delete();

        return response()->json(['success' => true, 'message' => 'Package deleted']);
    }
}
