<?php

namespace App\Http\Controllers\Api\Boarding;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Resources\Boarding\BoardingItemDepositItemResource;
use App\Http\Resources\Boarding\BoardingItemDepositResource;
use App\Models\BoardingItemDeposit;
use App\Models\BoardingItemDepositItem;
use App\Services\Boarding\BoardingItemDepositService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Titipan barang santri: CRUD titipan + pinjam/kembali barang + penutupan.
 */
class BoardingItemDepositController extends BaseApiController
{
    public function __construct(private readonly BoardingItemDepositService $deposits) {}

    public function index(Request $request): JsonResponse
    {
        $params = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'item_search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in([
                BoardingItemDeposit::STATUS_ACTIVE, BoardingItemDeposit::STATUS_RETURNED,
                BoardingItemDepositItem::STATUS_BORROWED, BoardingItemDepositItem::STATUS_IN_STORAGE,
            ])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $page = $this->deposits->list($params, $request->user());

        return $this->successResponse(
            BoardingItemDepositResource::collection($page),
            'Daftar titipan barang berhasil diambil',
        );
    }

    public function show(BoardingItemDeposit $deposit): JsonResponse
    {
        return $this->successResponse(
            new BoardingItemDepositResource($this->deposits->show($deposit, request()->user())),
            'Detail titipan barang berhasil diambil',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'deposited_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.item_type' => ['nullable', 'string', Rule::in(BoardingItemDepositItem::TYPES)],
            'items.*.item_name' => ['required', 'string', 'max:150'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ], [], [
            'items.*.item_name' => 'nama barang',
        ]);

        $deposit = $this->deposits->store($data, $request->user());

        return $this->createdResponse(new BoardingItemDepositResource($deposit), 'Titipan barang berhasil dicatat');
    }

    public function update(Request $request, BoardingItemDeposit $deposit): JsonResponse
    {
        $data = $request->validate([
            'deposited_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! $deposit->isActive()) {
            return $this->errorResponse('Titipan ini sudah ditutup, tidak dapat diubah lagi.', 422);
        }

        $deposit->update(array_filter([
            'deposited_at' => $data['deposited_at'] ?? null,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $deposit->notes,
        ], fn ($v): bool => $v !== null));

        return $this->successResponse(
            new BoardingItemDepositResource($this->deposits->show($deposit, $request->user())),
            'Titipan barang diperbarui',
        );
    }

    public function destroy(BoardingItemDeposit $deposit): JsonResponse
    {
        $this->deposits->destroy($deposit, request()->user());

        return $this->successResponse(null, 'Titipan barang dihapus');
    }

    public function storeItem(Request $request, BoardingItemDeposit $deposit): JsonResponse
    {
        $data = $request->validate([
            'item_type' => ['nullable', 'string', Rule::in(BoardingItemDepositItem::TYPES)],
            'item_name' => ['required', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $item = $this->deposits->addItem($deposit, $data, $request->user());

        return $this->createdResponse(new BoardingItemDepositItemResource($item), 'Barang ditambahkan ke titipan');
    }

    public function updateItem(Request $request, BoardingItemDeposit $deposit, BoardingItemDepositItem $item): JsonResponse
    {
        $data = $request->validate([
            'item_type' => ['nullable', 'string', Rule::in(BoardingItemDepositItem::TYPES)],
            'item_name' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->successResponse(
            new BoardingItemDepositItemResource($this->deposits->updateItem($deposit, $item, $data, $request->user())),
            'Barang diperbarui',
        );
    }

    public function destroyItem(BoardingItemDeposit $deposit, BoardingItemDepositItem $item): JsonResponse
    {
        $this->deposits->removeItem($deposit, $item, request()->user());

        return $this->successResponse(null, 'Barang dihapus dari titipan');
    }

    /** Pinjamkan barang ke santri. */
    public function borrow(Request $request, BoardingItemDeposit $deposit, BoardingItemDepositItem $item): JsonResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);

        return $this->successResponse(
            new BoardingItemDepositItemResource($this->deposits->borrow($deposit, $item, $data['notes'] ?? null, $request->user())),
            'Barang dipinjamkan',
        );
    }

    /** Terima kembali barang yang dipinjam. */
    public function returnItem(Request $request, BoardingItemDeposit $deposit, BoardingItemDepositItem $item): JsonResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);

        return $this->successResponse(
            new BoardingItemDepositItemResource($this->deposits->returnItem($deposit, $item, $data['notes'] ?? null, $request->user())),
            'Barang kembali ke gudang titipan',
        );
    }

    /** Tutup titipan: seluruh barang diserahkan permanen ke santri. */
    public function finish(BoardingItemDeposit $deposit): JsonResponse
    {
        return $this->successResponse(
            new BoardingItemDepositResource($this->deposits->finish($deposit, request()->user())),
            'Titipan ditutup — seluruh barang diserahkan permanen',
        );
    }
}
