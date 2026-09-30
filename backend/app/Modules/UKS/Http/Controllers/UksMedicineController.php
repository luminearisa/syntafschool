<?php

namespace App\Modules\UKS\Http\Controllers;

use App\Modules\UKS\Application\Services\MedicineStockService;
use App\Modules\UKS\Domain\Enums\StockDirection;
use App\Modules\UKS\Domain\Enums\StockReason;
use App\Modules\UKS\Models\UksMedicine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UksMedicineController extends UksController
{
    public function __construct(private readonly MedicineStockService $stock)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = UksMedicine::query();

        if ($search = trim((string) $request->string('search'))) {
            $safe = '%'.addcslashes(mb_substr($search, 0, 60), '%_\\').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $safe)->orWhere('code', 'like', $safe));
        }
        if ($request->boolean('low_stock')) {
            $query->whereColumn('stock', '<=', 'min_stock');
        }
        if ($request->boolean('active_only', true) && ! $request->has('include_inactive')) {
            $query->where('is_active', true);
        }

        $page = $query->orderBy('name')->paginate($this->perPage($request));

        return $this->successResponse($page, 'Daftar obat UKS.');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'unique:uks_medicines,code'],
            'name' => ['required', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:60'],
            'unit' => ['nullable', 'string', 'max:20'],
            'min_stock' => ['nullable', 'numeric', 'gte:0'],
            'expiry_date' => ['nullable', 'date'],
            'storage_location' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'initial_stock' => ['nullable', 'numeric', 'gte:0'],
        ]);

        return $this->createdResponse($this->stock->create($data, $request->user()), 'Obat ditambahkan.');
    }

    public function update(Request $request, UksMedicine $medicine): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:60'],
            'unit' => ['nullable', 'string', 'max:20'],
            'min_stock' => ['nullable', 'numeric', 'gte:0'],
            'expiry_date' => ['nullable', 'date'],
            'storage_location' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return $this->successResponse($this->stock->update($medicine, $data), 'Obat diperbarui.');
    }

    public function movement(Request $request, UksMedicine $medicine): JsonResponse
    {
        $data = $request->validate([
            'direction' => ['required', Rule::enum(StockDirection::class)],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', Rule::enum(StockReason::class)],
            'notes' => ['nullable', 'string', 'max:500'],
            'reference' => ['nullable', 'string', 'max:80'],
        ]);

        $movement = $this->stock->recordMovement(
            $medicine,
            StockDirection::from($data['direction']),
            (float) $data['quantity'],
            StockReason::from($data['reason']),
            $request->user(),
            $data['notes'] ?? null,
            $data['reference'] ?? null,
        );

        return $this->createdResponse($movement->load('medicine:id,name,stock,unit'), 'Mutasi stok dicatat.');
    }

    public function movements(Request $request, UksMedicine $medicine): JsonResponse
    {
        $page = $medicine->movements()->with('recordedBy:id,name')->orderByDesc('id')->paginate($this->perPage($request));

        return $this->successResponse($page, 'Riwayat mutasi stok.');
    }
}
