<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Rbac\RoleRequest;
use App\Http\Resources\RoleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class RoleController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Role::query()->with('permissions')->withCount('users');

        if ($search = $request->query('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->boolean('all')) {
            return $this->successResponse(
                RoleResource::collection($query->orderBy('name')->get()),
                'Data role berhasil diambil',
            );
        }

        $paginator = $query->orderBy('name')->paginate((int) $request->query('per_page', 15));

        return $this->successResponse(RoleResource::collection($paginator), 'Data role berhasil diambil');
    }

    public function show(Role $role): JsonResponse
    {
        $role->load('permissions')->loadCount('users');

        return $this->successResponse(new RoleResource($role), 'Detail role berhasil diambil');
    }

    public function store(RoleRequest $request): JsonResponse
    {
        $role = Role::create([
            'name' => $request->validated('name'),
            'guard_name' => 'web',
        ]);

        $role->syncPermissions($request->validated('permissions', []));

        return $this->createdResponse(
            new RoleResource($role->load('permissions')),
            'Role berhasil dibuat',
        );
    }

    public function update(RoleRequest $request, Role $role): JsonResponse
    {
        if ($role->name === 'super-admin') {
            return $this->errorResponse('Role super-admin tidak dapat diubah.', 403);
        }

        $role->update(['name' => $request->validated('name')]);
        $role->syncPermissions($request->validated('permissions', []));

        return $this->successResponse(
            new RoleResource($role->load('permissions')),
            'Role berhasil diperbarui',
        );
    }

    public function destroy(Role $role): JsonResponse
    {
        if (in_array($role->name, ['super-admin', 'admin-sekolah'], true)) {
            return $this->errorResponse('Role bawaan sistem tidak dapat dihapus.', 403);
        }

        if ($role->users()->count() > 0) {
            return $this->errorResponse('Role masih digunakan oleh pengguna, tidak dapat dihapus.', 422);
        }

        $role->delete();

        return $this->successResponse(null, 'Role berhasil dihapus');
    }

    /**
     * Daftar permission dikelompokkan per modul.
     */
    public function permissions(): JsonResponse
    {
        $permissions = \Spatie\Permission\Models\Permission::query()->orderBy('name')->get();

        $grouped = $permissions->groupBy(function ($perm) {
            return explode('.', $perm->name)[0];
        })->map(fn ($items) => $items->pluck('name')->values());

        return $this->successResponse([
            'all' => $permissions->pluck('name'),
            'grouped' => $grouped,
        ], 'Data permission berhasil diambil');
    }
}
