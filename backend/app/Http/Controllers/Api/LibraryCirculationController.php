<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Library\LibraryLoanRequest;
use App\Http\Resources\LibraryLoanResource;
use App\Models\LibraryItem;
use App\Models\LibraryLoan;
use App\Models\LibraryMember;
use App\Services\Library\Circulation\CirculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Sirkulasi: peminjaman, pengembalian, perpanjangan, keterlambatan, dan
 * pencarian cepat untuk layar checkout/return (§7 & §8).
 */
class LibraryCirculationController extends BaseApiController
{
    public function __construct(protected CirculationService $circulation)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->circulation->query($request);

        if ($request->boolean('all')) {
            return $this->successResponse(
                LibraryLoanResource::collection($query->get()),
                'Daftar peminjaman berhasil diambil'
            );
        }

        return $this->successResponse(
            LibraryLoanResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar peminjaman berhasil diambil'
        );
    }

    public function show(LibraryLoan $loan): JsonResponse
    {
        return $this->successResponse(
            new LibraryLoanResource($loan->load(['member.student', 'member.employee', 'item', 'bibliography', 'renewals', 'fines'])),
            'Detail peminjaman berhasil diambil'
        );
    }

    /**
     * Cari cepat dari hasil pindai (barcode/kode/nomor anggota).
     */
    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:100']]);

        return $this->successResponse(
            $this->circulation->lookup($validated['code']),
            'Hasil pencarian kode'
        );
    }

    /**
     * Ringkasan cepat seorang anggota (pinjaman, terlambat, denda, reservasi).
     */
    public function memberSummary(LibraryMember $member): JsonResponse
    {
        return $this->successResponse(
            $this->circulation->memberSummary($member->load('membershipType')),
            'Ringkasan anggota berhasil diambil'
        );
    }

    /**
     * Checkout (§7).
     */
    public function checkout(LibraryLoanRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $userId = $request->user()?->id;

        $member = $this->resolveMember($validated);
        $item = $this->resolveItem($validated);

        $loan = $this->circulation->checkout($member, $item, [
            'borrowed_by' => $userId,
            'notes' => $validated['notes'] ?? null,
        ]);

        return $this->createdResponse(
            new LibraryLoanResource($loan->load(['member', 'item', 'bibliography'])),
            'Peminjaman berhasil dicatat'
        );
    }

    /**
     * Return (§7) — denda keterlambatan dihitung otomatis.
     */
    public function returnItem(Request $request, LibraryLoan $loan): JsonResponse
    {
        $validated = $request->validate([
            'condition_id' => ['nullable', 'integer', 'exists:library_collection_conditions,id'],
            'status' => ['nullable', 'string', 'in:available,damaged,lost,processing'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $loan = $this->circulation->returnItem($loan, $validated + ['returned_by' => $request->user()?->id]);

        return $this->successResponse(
            new LibraryLoanResource($loan->load(['member', 'item', 'bibliography', 'fines'])),
            'Pengembalian berhasil dicatat'
        );
    }

    /**
     * Perpanjangan pinjaman.
     */
    public function renew(Request $request, LibraryLoan $loan): JsonResponse
    {
        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);

        $loan = $this->circulation->renew($loan, $validated + ['renewed_by' => $request->user()?->id]);

        return $this->successResponse(
            new LibraryLoanResource($loan->load(['member', 'item', 'bibliography', 'renewals'])),
            'Pinjaman berhasil diperpanjang'
        );
    }

    /**
     * Tandai pinjaman terlambat + nilai denda (dipakai juga oleh scheduler).
     */
    public function markOverdue(Request $request): JsonResponse
    {
        $libraryId = $request->filled('library_id') ? (int) $request->query('library_id') : null;

        $count = $this->circulation->markOverdue($libraryId);

        return $this->successResponse(
            ['marked' => $count],
            "{$count} pinjaman ditandai terlambat"
        );
    }

    /**
     * Ringkasan sirkulasi + tren bulanan + koleksi populer (§2).
     */
    public function stats(Request $request): JsonResponse
    {
        $libraryId = $request->filled('library_id') ? (int) $request->query('library_id') : null;

        return $this->successResponse([
            'summary' => $this->circulation->summary($libraryId),
            'trend' => $this->circulation->monthlyTrend($libraryId, (int) $request->query('months', 6)),
            'popular' => $this->circulation->popularBooks($libraryId, (int) $request->query('limit', 5)),
        ], 'Statistik sirkulasi berhasil diambil');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function resolveMember(array $data): LibraryMember
    {
        if (! empty($data['member_id'])) {
            return LibraryMember::query()->with('membershipType')->findOrFail($data['member_id']);
        }

        $found = $this->circulation->lookup((string) $data['member_code']);

        if (($found['type'] ?? null) !== 'member') {
            throw ValidationException::withMessages([
                'member_code' => 'Kode anggota tidak dikenali.',
            ]);
        }

        return LibraryMember::query()->with('membershipType')->findOrFail($found['member']['id']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function resolveItem(array $data): LibraryItem
    {
        if (! empty($data['item_id'])) {
            return LibraryItem::query()->with('bibliography')->findOrFail($data['item_id']);
        }

        $found = $this->circulation->lookup((string) $data['item_code']);

        if (($found['type'] ?? null) !== 'item') {
            throw ValidationException::withMessages([
                'item_code' => 'Kode eksemplar tidak dikenali.',
            ]);
        }

        return LibraryItem::query()->with('bibliography')->findOrFail($found['item']['id']);
    }
}