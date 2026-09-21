<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Library\LibraryMemberRequest;
use App\Http\Resources\LibraryMemberResource;
use App\Models\LibraryMember;
use App\Services\Library\Membership\MembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Keanggotaan perpustakaan.
 *
 * Entitas yang sudah ada ditautkan, tidak diduplikasi (Rule 11). Nomor
 * anggota dibangkitkan dari konfigurasi penomoran dan bersifat immutable.
 */
class LibraryMemberController extends BaseApiController
{
    public function __construct(protected MembershipService $members)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->members->query($request);

        if ($request->boolean('all')) {
            return $this->successResponse(
                LibraryMemberResource::collection($query->get()),
                'Daftar anggota berhasil diambil'
            );
        }

        return $this->successResponse(
            LibraryMemberResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar anggota berhasil diambil'
        );
    }

    public function store(LibraryMemberRequest $request): JsonResponse
    {
        $member = $this->members->register($request->validated());

        return $this->createdResponse(
            new LibraryMemberResource($member->load($this->relations())),
            'Anggota berhasil didaftarkan'
        );
    }

    public function show(LibraryMember $member): JsonResponse
    {
        return $this->successResponse(
            new LibraryMemberResource($member->load($this->relations())),
            'Detail anggota berhasil diambil'
        );
    }

    public function update(LibraryMemberRequest $request, LibraryMember $member): JsonResponse
    {
        $member = $this->members->update($member, $request->validated());

        return $this->successResponse(
            new LibraryMemberResource($member->load($this->relations())),
            'Data anggota berhasil diperbarui'
        );
    }

    public function destroy(LibraryMember $member): JsonResponse
    {
        $this->members->delete($member);

        return $this->successResponse(null, 'Anggota berhasil dihapus');
    }

    /**
     * Ubah status keanggotaan (aktif/suspend/block/inactive).
     */
    public function setStatus(Request $request, LibraryMember $member): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', LibraryMember::STATUSES)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $member = $this->members->setStatus($member, $validated['status'], $validated['reason'] ?? null);

        return $this->successResponse(
            new LibraryMemberResource($member->load($this->relations())),
            'Status keanggotaan berhasil diubah'
        );
    }

    /**
     * Perpanjang masa berlaku keanggotaan (§35).
     */
    public function renew(Request $request, LibraryMember $member): JsonResponse
    {
        $validated = $request->validate([
            'valid_until' => ['required', 'date', 'after:today'],
            'valid_from' => ['nullable', 'date'],
        ]);

        $member = $this->members->renew($member, $validated['valid_until'], $validated['valid_from'] ?? null);

        return $this->successResponse(
            new LibraryMemberResource($member->load($this->relations())),
            'Keanggotaan berhasil diperpanjang'
        );
    }

    /**
     * Evaluasi kedaluwarsa — mengubah status, tidak menghapus data (Rule 10).
     */
    public function evaluateExpirations(Request $request): JsonResponse
    {
        $libraryId = $request->filled('library_id') ? (int) $request->query('library_id') : null;

        $count = $this->members->evaluateExpirations($libraryId);

        return $this->successResponse(
            ['expired' => $count],
            "{$count} keanggotaan ditandai kedaluwarsa"
        );
    }

    /**
     * Ringkasan keanggotaan (dipakai dashboard).
     */
    public function summary(Request $request): JsonResponse
    {
        $libraryId = $request->filled('library_id') ? (int) $request->query('library_id') : null;

        return $this->successResponse(
            $this->members->summary($libraryId),
            'Ringkasan keanggotaan berhasil diambil'
        );
    }

    /**
     * @return list<string>
     */
    protected function relations(): array
    {
        return [
            'library', 'membershipType', 'student', 'employee', 'guardian', 'user',
            'cards' => fn ($q) => $q->where('status', 'active'),
        ];
    }
}