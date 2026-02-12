<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionPackageController extends Controller
{
    public function index(): JsonResponse
    {
        $packages = SubscriptionPackage::orderBy('price', 'asc')->get();

        return response()->json(['success' => true, 'data' => $packages]);
    }

    public function show(int $id): JsonResponse
    {
        $package = SubscriptionPackage::findOrFail($id);

        return response()->json(['success' => true, 'data' => $package]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'billing_cycle' => 'required|in:monthly,yearly',
            'features' => 'required|array',
            'is_popular' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        $package = SubscriptionPackage::create(array_merge($validated, [
            'is_popular' => $validated['is_popular'] ?? false,
            'is_active' => $validated['is_active'] ?? true,
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Package created.',
            'data' => $package,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $package = SubscriptionPackage::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'price' => 'sometimes|numeric|min:0',
            'billing_cycle' => 'sometimes|in:monthly,yearly',
            'features' => 'sometimes|array',
            'is_popular' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        $package->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Package updated.',
            'data' => $package->fresh(),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $package = SubscriptionPackage::findOrFail($id);
        $package->delete();

        return response()->json([
            'success' => true,
            'message' => 'Package deleted.',
        ]);
    }
}
