<?php

namespace App\Http\Controllers\Api\Ppdb;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Ppdb\PpdbFeeRequest;
use App\Http\Resources\PpdbFeeResource;
use App\Models\PpdbInvoiceItem;
use App\Models\PpdbRegistrationFee;
use App\Services\Ppdb\PpdbPeriodService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Biaya PPDB.
 *
 * Biaya boleh global (satu periode) atau discopkan ke program/gelombang, jadi
 * gelombang boleh memasang tarif berbeda. `meta` daftar membawa total biaya
 * aktif per periode agar panitia melihat tarif berjalan tanpa menghitung ulang
 * di sisi UI.
 */
class PpdbFeeController extends BaseApiController
{
    public function __construct(protected PpdbPeriodService $config)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->filtered($request)->with(['program', 'wave']);

        $fees = $this->config->paginated(
            $request,
            $this->config->sorted($query, $request, ['name', 'type', 'amount', 'payment_deadline', 'created_at'], 'name', 'asc')
                ->orderBy('id')
        );

        return $this->successResponse(
            PpdbFeeResource::collection($fees)->additional(['meta' => $this->totals($request)]),
            'Daftar biaya PPDB berhasil diambil'
        );
    }

    public function store(PpdbFeeRequest $request): JsonResponse
    {
        // Disegarkan agar nilai bawaan kolom (active, required) ikut terisi.
        $fee = PpdbRegistrationFee::create($request->validated())->refresh();

        return $this->createdResponse(
            new PpdbFeeResource($this->withRelations($fee)),
            'Biaya PPDB berhasil dibuat'
        );
    }

    public function show(PpdbRegistrationFee $fee): JsonResponse
    {
        return $this->successResponse(
            new PpdbFeeResource($this->withRelations($fee)),
            'Detail biaya PPDB berhasil diambil'
        );
    }

    public function update(PpdbFeeRequest $request, PpdbRegistrationFee $fee): JsonResponse
    {
        $fee->fill($request->validated());
        $fee->save();

        return $this->successResponse(
            new PpdbFeeResource($this->withRelations($fee)),
            'Biaya PPDB berhasil diperbarui'
        );
    }

    /**
     * Rincian tagihan menautkan `fee_id`; menghapus biaya yang sudah tertagih
     * membuat jejak penagihan tidak dapat ditelusuri.
     */
    public function destroy(PpdbRegistrationFee $fee): JsonResponse
    {
        $this->config->assertNotInUse(
            PpdbInvoiceItem::query()->where('fee_id', $fee->id)->count(),
            'Biaya',
            'rincian tagihan',
            'fee'
        );

        $fee->delete();

        return $this->successResponse(null, 'Biaya PPDB berhasil dihapus');
    }

    private function filtered(Request $request): Builder
    {
        $query = PpdbRegistrationFee::query();

        if ($request->filled('period_id')) {
            $query->where('period_id', $request->integer('period_id'));
        }

        if ($request->filled('type') && in_array($request->query('type'), PpdbRegistrationFee::TYPES, true)) {
            $query->where('type', (string) $request->query('type'));
        }

        if ($request->filled('program_id')) {
            $query->where('program_id', $request->integer('program_id'));
        }

        if ($request->filled('wave_id')) {
            $query->where('wave_id', $request->integer('wave_id'));
        }

        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }

        return $query;
    }

    /**
     * Total biaya aktif pada filter yang sama: seluruhnya, per periode, dan
     * per jenis — panitia melihat tarif berjalan tanpa menghitung ulang di UI.
     *
     * @return array<string, mixed>
     */
    private function totals(Request $request): array
    {
        $grouped = fn (string $column) => $this->filtered($request)->active()
            ->selectRaw("{$column}, SUM(amount) as total")
            ->groupBy($column)
            ->get()
            ->mapWithKeys(fn (PpdbRegistrationFee $row) => [(string) $row->{$column} => (float) $row->total])
            ->all();

        return [
            'total_amount' => (float) $this->filtered($request)->active()->sum('amount'),
            'by_period' => $grouped('period_id'),
            'by_type' => $grouped('type'),
        ];
    }

    private function withRelations(PpdbRegistrationFee $fee): PpdbRegistrationFee
    {
        return $fee->loadMissing(['period', 'program', 'wave']);
    }
}
