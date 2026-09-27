<?php

namespace App\Http\Controllers\Api\Boarding;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Boarding\PermissionRejectRequest;
use App\Http\Requests\Boarding\PermissionRequest;
use App\Http\Resources\BoardingPermissionResource;
use App\Models\BoardingPermission;
use App\Services\Boarding\BoardingPermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Izin keluar santri (§27–§29): ajukan → (persetujuan bertingkat) →
 * check out gerbang → check in pulang (terlambat otomatis).
 *
 * Controller tipis: seluruh penjaga status, transaksi, dan notifikasi ada di
 * `BoardingPermissionService` supaya jalur non-HTTP (perintah expire) melewati
 * aturan yang sama.
 */
class BoardingPermissionController extends BaseApiController
{
    public function __construct(private readonly BoardingPermissionService $permissions)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->permissions->query($request, $request->user());

        if ($request->boolean('all')) {
            return $this->successResponse(
                BoardingPermissionResource::collection($query->get()),
                'Daftar izin keluar berhasil diambil',
            );
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            BoardingPermissionResource::collection($query->paginate($perPage)->appends($request->query())),
            'Daftar izin keluar berhasil diambil',
        );
    }

    public function show(Request $request, BoardingPermission $permission): JsonResponse
    {
        $this->permissions->assertAccessible($permission, $request->user());

        return $this->successResponse(
            new BoardingPermissionResource($this->permissions->withDetails($permission)),
            'Detail izin keluar berhasil diambil',
        );
    }

    public function store(PermissionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $submit = (bool) ($data['submit'] ?? false);
        unset($data['submit']);

        $permission = $this->permissions->create($data, $request->user(), $submit);

        return $this->createdResponse(new BoardingPermissionResource($permission), 'Pengajuan izin keluar berhasil disimpan');
    }

    public function submit(Request $request, BoardingPermission $permission): JsonResponse
    {
        $fresh = $this->permissions->submit($permission, $request->user());

        return $this->successResponse(new BoardingPermissionResource($fresh), 'Izin berhasil diajukan untuk persetujuan');
    }

    public function approve(Request $request, BoardingPermission $permission): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;

        $fresh = $this->permissions->approve($permission, $request->user(), $note);

        return $this->successResponse(
            new BoardingPermissionResource($fresh),
            $fresh->status === BoardingPermission::STATUS_APPROVED
                ? 'Izin keluar disetujui'
                : 'Langkah persetujuan dicatat — masih ada langkah berikutnya',
        );
    }

    public function reject(PermissionRejectRequest $request, BoardingPermission $permission): JsonResponse
    {
        $fresh = $this->permissions->reject($permission, $request->user(), (string) $request->validated('note'));

        return $this->successResponse(new BoardingPermissionResource($fresh), 'Izin keluar ditolak');
    }

    public function checkOut(Request $request, BoardingPermission $permission): JsonResponse
    {
        $params = $request->validate(['at' => ['nullable', 'date']]);

        $fresh = $this->permissions->checkOut($permission, $request->user(), $params['at'] ?? null);

        return $this->successResponse(new BoardingPermissionResource($fresh), 'Santri tercatat keluar');
    }

    public function checkIn(Request $request, BoardingPermission $permission): JsonResponse
    {
        $params = $request->validate(['at' => ['nullable', 'date']]);

        $fresh = $this->permissions->checkIn($permission, $request->user(), $params['at'] ?? null);

        return $this->successResponse(
            new BoardingPermissionResource($fresh),
            $fresh->late_return ? 'Santri kembali — tercatat TERLAMBAT' : 'Santri tercatat kembali',
        );
    }

    public function cancel(Request $request, BoardingPermission $permission): JsonResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;

        $fresh = $this->permissions->cancel($permission, $request->user(), $note);

        return $this->successResponse(new BoardingPermissionResource($fresh), 'Pengajuan izin dibatalkan');
    }
}
