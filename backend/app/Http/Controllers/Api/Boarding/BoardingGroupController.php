<?php

namespace App\Http\Controllers\Api\Boarding;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Boarding\BoardingGroupRequest;
use App\Http\Resources\BoardingGroupMemberResource;
use App\Http\Resources\BoardingGroupResource;
use App\Models\BoardingGroup;
use App\Models\BoardingGroupMember;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Kelompok binaan lintas kamar (§14) + keanggotaannya.
 */
class BoardingGroupController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = BoardingGroup::query()
            ->with(['musyrifEmployee:id,full_name,nip', 'dormitory:id,code,name'])
            ->withCount(['members as members_count' => fn (Builder $m) => $m->whereNull('left_date')])
            ->when($request->filled('dormitory_id'), fn (Builder $q) => $q->where('dormitory_id', (int) $request->input('dormitory_id')))
            ->when($request->filled('musyrif_employee_id'), fn (Builder $q) => $q->where('musyrif_employee_id', (int) $request->input('musyrif_employee_id')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->where(fn (Builder $w) => $w
                    ->where('code', 'like', "%$term%")
                    ->orWhere('name', 'like', "%$term%"));
            })
            ->orderBy('code');

        if ($request->input('all')) {
            return $this->successResponse(BoardingGroupResource::collection($query->get()), 'Daftar kelompok berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            BoardingGroupResource::collection($query->paginate($perPage)->appends($request->query())),
            'Daftar kelompok berhasil diambil',
        );
    }

    public function show(BoardingGroup $group): JsonResponse
    {
        $group->load(['musyrifEmployee:id,full_name,nip', 'dormitory:id,code,name'])
            ->setAttribute('members_count', $group->members()->whereNull('left_date')->count());

        return $this->successResponse(new BoardingGroupResource($group), 'Detail kelompok berhasil diambil');
    }

    public function store(BoardingGroupRequest $request): JsonResponse
    {
        $group = BoardingGroup::create($request->validated());

        return $this->createdResponse(
            new BoardingGroupResource($group->load(['musyrifEmployee:id,full_name,nip', 'dormitory:id,code,name'])),
            'Kelompok berhasil dibuat',
        );
    }

    public function update(BoardingGroupRequest $request, BoardingGroup $group): JsonResponse
    {
        $group->update($request->validated());

        return $this->successResponse(
            new BoardingGroupResource($group->refresh()->load(['musyrifEmployee:id,full_name,nip', 'dormitory:id,code,name'])),
            'Kelompok berhasil diperbarui',
        );
    }

    public function destroy(BoardingGroup $group): JsonResponse
    {
        $current = $group->members()->whereNull('left_date')->count();

        if ($current > 0) {
            throw ValidationException::withMessages([
                'group' => "Kelompok masih memiliki {$current} anggota aktif. Keluarkan anggotanya terlebih dahulu.",
            ]);
        }

        $group->delete();

        return $this->successResponse(null, 'Kelompok berhasil dihapus');
    }

    /** Anggota (aktif + riwayat). `?current=1` membatasi ke anggota aktif. */
    public function members(Request $request, BoardingGroup $group): JsonResponse
    {
        $members = $group->members()
            ->with('student:id,full_name,nis')
            ->when($request->boolean('current'), fn (Builder $q) => $q->whereNull('left_date'))
            ->orderByDesc('joined_date')
            ->orderBy('id')
            ->get();

        return $this->successResponse(
            BoardingGroupMemberResource::collection($members),
            'Daftar anggota kelompok berhasil diambil',
        );
    }

    /** Tambah santri ke kelompok — tolak duplikat anggota aktif. */
    public function attachMember(Request $request, BoardingGroup $group): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'joined_date' => ['nullable', 'date'],
        ]);

        $student = Student::findOrFail($data['student_id']);

        $duplicate = $group->members()->where('student_id', $student->id)->whereNull('left_date')->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'student_id' => "Santri {$student->full_name} sudah menjadi anggota aktif kelompok ini.",
            ]);
        }

        $member = BoardingGroupMember::create([
            'group_id' => $group->id,
            'student_id' => $student->id,
            'joined_date' => $data['joined_date'] ?? now()->toDateString(),
        ]);

        return $this->createdResponse(
            new BoardingGroupMemberResource($member->load('student:id,full_name,nis')),
            'Anggota berhasil ditambahkan',
        );
    }

    /** Keluarkan anggota: baris tetap ada dengan `left_date` sebagai riwayat. */
    public function detachMember(Request $request, BoardingGroup $group, BoardingGroupMember $member): JsonResponse
    {
        if ($member->group_id !== $group->id) {
            throw ValidationException::withMessages([
                'member' => 'Anggota ini bukan bagian dari kelompok tersebut.',
            ]);
        }

        $data = $request->validate(['left_date' => ['nullable', 'date']]);

        if (! $member->isCurrent()) {
            throw ValidationException::withMessages([
                'member' => 'Anggota ini sudah keluar dari kelompok.',
            ]);
        }

        $member->update(['left_date' => $data['left_date'] ?? now()->toDateString()]);

        return $this->successResponse(
            new BoardingGroupMemberResource($member->load('student:id,full_name,nis')),
            'Anggota berhasil dikeluarkan',
        );
    }
}
