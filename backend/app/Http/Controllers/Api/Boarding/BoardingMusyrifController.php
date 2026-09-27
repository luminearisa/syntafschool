<?php

namespace App\Http\Controllers\Api\Boarding;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Boarding\BoardingMusyrifRequest;
use App\Http\Resources\BoardingMusyrifResource;
use App\Models\BoardingMusyrif;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Penugasan pengasuh/musyrif (§8) — satu pegawai pada satu area.
 */
class BoardingMusyrifController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = BoardingMusyrif::query()
            ->with([
                'employee:id,full_name,nip',
                'dormitory:id,code,name',
                'building:id,code,name',
                'room:id,code,name',
                'assignedBy:id,name',
            ])
            ->when($request->filled('employee_id'), fn (Builder $q) => $q->where('employee_id', (int) $request->input('employee_id')))
            ->when($request->filled('dormitory_id'), fn (Builder $q) => $q->where('dormitory_id', (int) $request->input('dormitory_id')))
            ->when($request->filled('building_id'), fn (Builder $q) => $q->where('building_id', (int) $request->input('building_id')))
            ->when($request->filled('room_id'), fn (Builder $q) => $q->where('room_id', (int) $request->input('room_id')))
            ->when($request->filled('position'), fn (Builder $q) => $q->where('position', $request->string('position')->toString()))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->boolean('running'), fn (Builder $q, bool $v) => $v ? $q->runningOn($request->date('date')?->toDateString()) : $q)
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->whereHas('employee', fn (Builder $e) => $e->where('full_name', 'like', "%$term%"));
            })
            ->orderByDesc('status')
            ->orderBy('employee_id');

        if ($request->input('all')) {
            return $this->successResponse(BoardingMusyrifResource::collection($query->get()), 'Daftar pengasuh berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            BoardingMusyrifResource::collection($query->paginate($perPage)->appends($request->query())),
            'Daftar pengasuh berhasil diambil',
        );
    }

    public function show(BoardingMusyrif $musyrif): JsonResponse
    {
        $musyrif->load([
            'employee:id,full_name,nip',
            'dormitory:id,code,name',
            'building:id,code,name',
            'room:id,code,name',
            'assignedBy:id,name',
        ]);

        return $this->successResponse(new BoardingMusyrifResource($musyrif), 'Detail pengasuh berhasil diambil');
    }

    public function store(BoardingMusyrifRequest $request): JsonResponse
    {
        $musyrif = BoardingMusyrif::create([
            ...$request->validated(),
            'assigned_by' => $request->user()->id,
        ]);

        return $this->createdResponse(
            new BoardingMusyrifResource($musyrif->load($this->loads())),
            'Penugasan pengasuh berhasil dibuat',
        );
    }

    public function update(BoardingMusyrifRequest $request, BoardingMusyrif $musyrif): JsonResponse
    {
        $musyrif->update($request->validated());

        return $this->successResponse(
            new BoardingMusyrifResource($musyrif->refresh()->load($this->loads())),
            'Penugasan pengasuh berhasil diperbarui',
        );
    }

    public function destroy(BoardingMusyrif $musyrif): JsonResponse
    {
        $musyrif->delete();

        return $this->successResponse(null, 'Penugasan pengasuh berhasil dihapus');
    }

    /** Area binaan satu pegawai — dipakai scope pengasuh dan halaman "kelompok binaan". */
    public function byEmployee(Request $request, int $employeeId): JsonResponse
    {
        $query = BoardingMusyrif::query()
            ->where('employee_id', $employeeId)
            ->with($this->loads())
            ->orderByDesc('id');

        return $this->successResponse(
            BoardingMusyrifResource::collection($query->get()),
            'Area binaan berhasil diambil',
        );
    }

    /** @return list<string> */
    private function loads(): array
    {
        return [
            'employee:id,full_name,nip',
            'dormitory:id,code,name',
            'building:id,code,name',
            'room:id,code,name',
            'assignedBy:id,name',
        ];
    }
}
