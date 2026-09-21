<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Rbac\UserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query()->with('roles');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }

        if ($request->filled('role')) {
            $query->role($request->query('role'));
        }

        if ($request->has('filter') && isset($request->query('filter')['is_active'])) {
            $query->where('is_active', (bool) $request->query('filter')['is_active']);
        }

        $query->latest('id');

        if ($request->boolean('all')) {
            return $this->successResponse(
                UserResource::collection($query->get()),
                'Data pengguna berhasil diambil',
            );
        }

        $paginator = $query->paginate((int) $request->query('per_page', 15));

        return $this->successResponse(UserResource::collection($paginator), 'Data pengguna berhasil diambil');
    }

    public function show(User $user): JsonResponse
    {
        $user->load('roles', 'permissions');

        return $this->successResponse(new UserResource($user), 'Detail pengguna berhasil diambil');
    }

    public function store(UserRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::create([
                'name' => $request->validated('name'),
                'username' => $request->validated('username'),
                'email' => $request->validated('email'),
                'password' => Hash::make($request->validated('password')),
                'phone' => $request->validated('phone'),
                'is_active' => $request->boolean('is_active', true),
            ]);

            $user->syncRoles($request->validated('roles', []));

            return $user;
        });

        return $this->createdResponse(
            new UserResource($user->load('roles', 'permissions')),
            'Pengguna berhasil dibuat',
        );
    }

    public function update(UserRequest $request, User $user): JsonResponse
    {
        DB::transaction(function () use ($request, $user) {
            $data = [
                'name' => $request->validated('name'),
                'username' => $request->validated('username'),
                'email' => $request->validated('email'),
                'phone' => $request->validated('phone'),
                'is_active' => $request->boolean('is_active', true),
            ];

            if ($request->filled('password')) {
                $data['password'] = Hash::make($request->validated('password'));
            }

            $user->update($data);
            $user->syncRoles($request->validated('roles', []));
        });

        return $this->successResponse(
            new UserResource($user->refresh()->load('roles', 'permissions')),
            'Pengguna berhasil diperbarui',
        );
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($request->user()->id === $user->id) {
            return $this->errorResponse('Anda tidak dapat menghapus akun sendiri.', 422);
        }

        if ($user->hasRole('super-admin') && User::role('super-admin')->count() <= 1) {
            return $this->errorResponse('Minimal harus ada satu super-admin.', 422);
        }

        $user->delete();

        return $this->successResponse(null, 'Pengguna berhasil dihapus');
    }

    /**
     * Aktifkan / nonaktifkan pengguna.
     */
    public function toggleActive(Request $request, User $user): JsonResponse
    {
        if ($request->user()->id === $user->id) {
            return $this->errorResponse('Anda tidak dapat menonaktifkan akun sendiri.', 422);
        }

        $user->update(['is_active' => ! $user->is_active]);

        return $this->successResponse(
            new UserResource($user->refresh()->load('roles')),
            'Status pengguna berhasil diubah',
        );
    }
}
