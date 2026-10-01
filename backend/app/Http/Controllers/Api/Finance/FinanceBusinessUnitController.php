<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\FinanceBusinessUnit;
use App\Services\Finance\Accounting\BusinessUnitService;
use App\Support\Accounting\SumberModul;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Unit bisnis & unit bawaannya.
 *
 * Unit adalah pusat laba: dipakai untuk memisahkan laba rugi dan arus kas per
 * satuan usaha, tanpa memecah neraca (baris kas/bank & liabilitas selalu memakai
 * unit penampung).
 */
class FinanceBusinessUnitController extends BaseApiController
{
    public function __construct(private readonly BusinessUnitService $units) {}

    public function index(Request $request): JsonResponse
    {
        return $this->successResponse([
            'units' => $this->units->all($request->boolean('active_only')),
            'defaults' => $this->units->defaults(),
            'modules' => SumberModul::options(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:120'],
            'parent_code' => ['nullable', 'string', 'max:30'],
            'is_active' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->createdResponse($this->units->save($data), 'Unit bisnis dibuat.');
    }

    public function update(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'parent_code' => ['nullable', 'string', 'max:30'],
            'is_active' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->successResponse($this->units->save($data, $code), 'Unit bisnis diperbarui.');
    }

    public function toggle(Request $request, string $code): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $unit = FinanceBusinessUnit::query()->where('code', $code)->firstOrFail();

        return $this->successResponse($this->units->toggle($unit, (bool) $data['is_active']), 'Status unit diperbarui.');
    }

    public function setDefault(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sumber_modul' => ['required', 'string', 'max:48'],
            'business_unit_code' => ['nullable', 'string', 'max:30'],
        ]);

        $this->units->setDefault((string) $data['sumber_modul'], $data['business_unit_code'] ?? null);

        return $this->successResponse($this->units->defaults(), 'Unit bawaan modul disimpan.');
    }
}
