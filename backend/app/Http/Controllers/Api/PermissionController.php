<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\PermissionResource;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Permission;

class PermissionController extends BaseApiController
{
    /**
     * Daftar semua permission, dikelompokkan per modul.
     */
    public function index(): JsonResponse
    {
        $permissions = Permission::query()->orderBy('name')->get();

        $grouped = $permissions
            ->groupBy(fn ($perm) => explode('.', $perm->name)[0])
            ->map(fn ($items) => $items->pluck('name')->values());

        return $this->successResponse([
            'items' => PermissionResource::collection($permissions),
            'grouped' => $grouped,
        ], 'Data permission berhasil diambil');
    }
}
