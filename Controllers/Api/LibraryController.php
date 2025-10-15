<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class LibraryController extends Controller
{
    /**
     * Get all construction stages
     */
    public function getConstructionStages(): JsonResponse
    {
        try {
            $data = DB::table('construction_stages_library')->get();
            return response()->json($data);
        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'error' => 'Произошла ошибка сервера',
            ], 500);
        }
    }

    /**
     * Get all control points
     */
    public function getControlPointsLibrary(): JsonResponse
    {
        try {
            $data = DB::table('control_points_library')->get();
            return response()->json($data);
        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'error' => 'Произошла ошибка сервера',
            ], 500);
        }
    }

    /**
     * Get all districts
     */
    public function getDistricts(): JsonResponse
    {
        try {
            $data = DB::table('districts_library')->get();
            return response()->json($data);
        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'error' => 'Произошла ошибка сервера',
            ], 500);
        }
    }

    /**
     * Get all FNO levels
     */
    public function getFnoLevels(): JsonResponse
    {
        try {
            $data = DB::table('fno_level_library')->get();
            return response()->json($data);
        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'error' => 'Произошла ошибка сервера',
            ], 500);
        }
    }

    /**
     * Get all FNO data
     */
    public function getFnoLibrary(): JsonResponse
    {
        try {
            $data = DB::table('fno_library')->get();
            return response()->json($data);
        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'error' => 'Произошла ошибка сервера',
            ], 500);
        }
    }

    /**
     * Get all regions
     */
    public function getRegions(): JsonResponse
    {
        try {
            $data = DB::table('regions_library')->get();
            return response()->json($data);
        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'error' => 'Произошла ошибка сервера',
            ], 500);
        }
    }

    /**
     * Get all OKS statuses
     */
    public function getOksStatuses(): JsonResponse
    {
        try {
            $data = DB::table('oks_status_library')->get();
            return response()->json($data);
        } catch (\Throwable $th) {
            report($th);

            return response()->json([
                'error' => 'Произошла ошибка сервера',
            ], 500);
        }
    }
}
