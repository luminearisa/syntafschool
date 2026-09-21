<?php

namespace App\Http\Controllers\Api;

use App\Models\Library;
use App\Models\LibraryInventoryLine;
use App\Models\LibraryInventorySession;
use App\Services\Library\Circulation\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stock opname: buat sesi → pindai → finalkan → laporan hasil.
 */
class LibraryInventoryController extends BaseApiController
{
    public function __construct(protected InventoryService $inventory)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->inventory->query($request)->paginate((int) $request->query('per_page', 15));

        return $this->successResponse($paginator, 'Daftar sesi stock opname berhasil diambil');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'library_id' => ['required', 'integer', 'exists:libraries,id'],
            'name' => ['required', 'string', 'max:150'],
            'location_id' => ['nullable', 'integer', 'exists:library_locations,id'],
            'started_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $library = Library::query()->findOrFail($validated['library_id']);

        $session = $this->inventory->create($library, $validated + ['created_by' => $request->user()?->id]);

        return $this->createdResponse($session, 'Sesi stock opname berhasil dibuat');
    }

    public function show(LibraryInventorySession $session): JsonResponse
    {
        return $this->successResponse(
            $this->inventory->summary($session->load('location')),
            'Detail sesi stock opname berhasil diambil'
        );
    }

    /**
     * Pindai satu kode (barcode/kode eksemplar/nomor inventaris).
     */
    public function scan(Request $request, LibraryInventorySession $session): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'condition_id' => ['nullable', 'integer', 'exists:library_collection_conditions,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $line = $this->inventory->scan($session, $validated['code'], $validated + ['scanned_by' => $request->user()?->id]);

        return $this->successResponse([
            'result' => $line->result,
            'scanned_code' => $line->scanned_code,
            'item_id' => $line->item_id,
            'is_unexpected' => $line->result === LibraryInventoryLine::RESULT_UNEXPECTED,
        ], match ($line->result) {
            LibraryInventoryLine::RESULT_UNEXPECTED => 'Kode di luar cakupan sesi',
            LibraryInventoryLine::RESULT_DAMAGED => 'Eksemplar rusak dicatat',
            default => 'Eksemplar ditemukan',
        });
    }

    /**
     * Finalisasi: hitung selisih dan simpan ringkasan.
     */
    public function finalize(Request $request, LibraryInventorySession $session): JsonResponse
    {
        $validated = $request->validate([
            'mark_missing_as_lost' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $summary = $this->inventory->finalize($session, $validated + ['finalized_by' => $request->user()?->id]);

        return $this->successResponse($summary, 'Stock opname berhasil difinalkan');
    }

    /**
     * Laporan hasil stock opname pada rentang tanggal.
     */
    public function report(Request $request): JsonResponse
    {
        $libraryId = $request->filled('library_id') ? (int) $request->query('library_id') : null;

        return $this->successResponse(
            $this->inventory->report($libraryId, $request->query('from'), $request->query('to')),
            'Laporan stock opname berhasil diambil'
        );
    }
}