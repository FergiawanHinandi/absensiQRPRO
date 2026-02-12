<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * RegionController — Indonesian administrative region lookup
 *
 * Provides hierarchical region data: Province → Regency → District → Village.
 * Used for school registration address selection.
 *
 * Requires 'regions' table. If table doesn't exist, returns empty results gracefully.
 * Region data can be seeded from: https://github.com/cahyadsn/wilayah
 */
class RegionController extends Controller
{
    /**
     * List all provinces.
     */
    public function provinces(): JsonResponse
    {
        $provinces = Cache::remember('regions:provinces', 86400, function () {
            if (! $this->tableExists()) {
                return [];
            }

            return DB::table('regions')
                ->where('level', 'province')
                ->orderBy('name')
                ->select('id', 'code', 'name')
                ->get();
        });

        return response()->json([
            'success' => true,
            'data' => $provinces,
        ]);
    }

    /**
     * List regencies/cities within a province.
     */
    public function regencies(string $provinceId): JsonResponse
    {
        $regencies = Cache::remember("regions:regencies:{$provinceId}", 86400, function () use ($provinceId) {
            if (! $this->tableExists()) {
                return [];
            }

            return DB::table('regions')
                ->where('level', 'regency')
                ->where('parent_id', $provinceId)
                ->orderBy('name')
                ->select('id', 'code', 'name')
                ->get();
        });

        return response()->json([
            'success' => true,
            'data' => $regencies,
        ]);
    }

    /**
     * List districts within a regency.
     */
    public function districts(string $regencyId): JsonResponse
    {
        $districts = Cache::remember("regions:districts:{$regencyId}", 86400, function () use ($regencyId) {
            if (! $this->tableExists()) {
                return [];
            }

            return DB::table('regions')
                ->where('level', 'district')
                ->where('parent_id', $regencyId)
                ->orderBy('name')
                ->select('id', 'code', 'name')
                ->get();
        });

        return response()->json([
            'success' => true,
            'data' => $districts,
        ]);
    }

    /**
     * List villages within a district.
     */
    public function villages(string $districtId): JsonResponse
    {
        $villages = Cache::remember("regions:villages:{$districtId}", 86400, function () use ($districtId) {
            if (! $this->tableExists()) {
                return [];
            }

            return DB::table('regions')
                ->where('level', 'village')
                ->where('parent_id', $districtId)
                ->orderBy('name')
                ->select('id', 'code', 'name')
                ->get();
        });

        return response()->json([
            'success' => true,
            'data' => $villages,
        ]);
    }

    /**
     * Check if regions table exists.
     */
    private function tableExists(): bool
    {
        return Cache::remember('regions:table_exists', 3600, function () {
            return \Schema::hasTable('regions');
        });
    }
}
