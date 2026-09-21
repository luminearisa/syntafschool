<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\LibraryFineResource;
use App\Models\LibraryFine;
use App\Models\LibraryMember;
use App\Services\Library\Circulation\FineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Denda & pembayaran.
 *
 * Tidak ada sistem akuntansi baru: pembayaran memakai master
 * `payment_methods`, dan pembebasan (waiver) disertai alasan.
 */
class LibraryFineController extends BaseApiController
{
    public function __construct(protected FineService $fines)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->fines->query($request);

        if ($request->boolean('all')) {
            return $this->successResponse(
                LibraryFineResource::collection($query->get()),
                'Daftar denda berhasil diambil'
            );
        }

        return $this->successResponse(
            LibraryFineResource::collection($query->paginate((int) $request->query('per_page', 15))),
            'Daftar denda berhasil diambil'
        );
    }

    public function show(LibraryFine $fine): JsonResponse
    {
        return $this->successResponse(
            new LibraryFineResource($fine->load(['member', 'loan', 'item', 'payments.paymentMethod'])),
            'Detail denda berhasil diambil'
        );
    }

    /**
     * Denda manual (rusak, hilang, lain-lain).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'member_id' => ['required', 'integer', 'exists:library_members,id'],
            'type' => ['nullable', 'string', 'in:'.implode(',', LibraryFine::TYPES)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'loan_id' => ['nullable', 'integer', 'exists:library_loans,id'],
            'item_id' => ['nullable', 'integer', 'exists:library_items,id'],
            'assessed_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $member = LibraryMember::query()->findOrFail($validated['member_id']);

        $fine = $this->fines->assess($member, $validated + ['assessed_by' => $request->user()?->id]);

        return $this->createdResponse(
            new LibraryFineResource($fine->load(['member', 'item', 'loan'])),
            'Denda berhasil dicatat'
        );
    }

    /**
     * Catat pembayaran (boleh sebagian).
     */
    public function pay(Request $request, LibraryFine $fine): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_at' => ['nullable', 'date'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $fine = $this->fines->pay($fine, $validated + ['received_by' => $request->user()?->id]);

        return $this->successResponse(
            new LibraryFineResource($fine->load(['member', 'payments.paymentMethod'])),
            'Pembayaran denda berhasil dicatat'
        );
    }

    /**
     * Bebaskan denda (sebagian/seluruh) beserta alasan.
     */
    public function waive(Request $request, LibraryFine $fine): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $fine = $this->fines->waive($fine, $validated['reason'], isset($validated['amount']) ? (float) $validated['amount'] : null);

        activity('library')
            ->event('FINE_WAIVED_BY')
            ->performedOn($fine)
            ->withProperties(['by' => $request->user()?->id])
            ->log('Denda dibebaskan oleh petugas');

        return $this->successResponse(
            new LibraryFineResource($fine->load(['member', 'payments'])),
            'Denda berhasil dibebaskan'
        );
    }

    /**
     * Ringkasan denda (dashboard & laporan).
     */
    public function summary(Request $request): JsonResponse
    {
        $libraryId = $request->filled('library_id') ? (int) $request->query('library_id') : null;

        return $this->successResponse($this->fines->summary($libraryId), 'Ringkasan denda berhasil diambil');
    }
}