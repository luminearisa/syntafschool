<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Finance\FinanceOperationDispatchRequest;
use App\Http\Resources\FinanceOperationResource;
use App\Models\FinanceOperation;
use App\Services\Finance\FinanceOperationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Register operasi Keuangan (§28, §29): pekerjaan berat yang di-queue dan
 * kegagalan operasional yang tercatat di jalur nyata.
 *
 * Endpoint ini tidak punya jalur ke angka. Tidak ada satu pun aksinya yang
 * mengubah invoice, pembayaran, atau jurnal — yang ada hanya memanggil
 * pekerjaan (yang kemudian mengerjakan dirinya lewat service yang sudah ada)
 * dan menjalankan ulang pekerjaan yang gagal.
 *
 * Kenapa daftar pekerjaan dan daftar kegagalan disatukan: keduanya menjawab
 * pertanyaan yang sama dari layar yang sama, dan memisahkannya berarti bendahara
 * harus menebak ke mana melihat ketika sesuatu tidak selesai.
 */
class FinanceOperationController extends BaseApiController
{
    public function __construct(private readonly FinanceOperationService $operations) {}

    /** GET /finance/operations — filter kind/status/name/referensi/tanggal + pencarian. */
    public function index(Request $request): JsonResponse
    {
        $query = FinanceOperation::query()
            ->with('createdBy')
            ->when($request->filled('kind'), fn (Builder $q) => $q->where('kind', $request->string('kind')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('name'), fn (Builder $q) => $q->where('name', $request->string('name')))
            ->when($request->filled('date_from'), fn (Builder $q) => $q->whereDate('queued_at', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->whereDate('queued_at', '<=', $request->input('date_to')))
            ->when($request->filled('reference_type'), fn (Builder $q) => $q->where('reference_type', $request->string('reference_type')))
            ->when($request->filled('reference_id'), fn (Builder $q) => $q->where('reference_id', (int) $request->input('reference_id')))
            ->when($request->boolean('retryable'), fn (Builder $q) => $q->where('retryable', true))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->where(fn (Builder $inner) => $inner
                    ->where('reference_number', 'like', "%$term%")
                    ->orWhere('name', 'like', "%$term%")
                    ->orWhere('error', 'like', "%$term%"));
            })
            ->latest('id');

        if ($request->boolean('all')) {
            return $this->successResponse(
                FinanceOperationResource::collection($query->get()),
                'Daftar operasi berhasil diambil',
            );
        }

        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));

        return $this->successResponse(
            FinanceOperationResource::collection($query->paginate($perPage)->appends($request->query()))
                ->additional(['meta' => [
                    'kinds' => array_keys(FinanceOperation::KIND_LABELS),
                    'kind_labels' => FinanceOperation::KIND_LABELS,
                    'statuses' => FinanceOperation::STATUSES,
                    'status_labels' => FinanceOperation::STATUS_LABELS,
                    'jobs' => array_keys(FinanceOperationService::JOB_CLASSES),
                    'job_labels' => FinanceOperation::JOB_LABELS,
                    'events' => array_keys(FinanceOperation::EVENT_LABELS),
                    'event_labels' => FinanceOperation::EVENT_LABELS,
                ]]),
            'Daftar operasi berhasil diambil',
        );
    }

    /** GET /finance/operations/{operation} — satu baris lengkap dengan payload & konteks. */
    public function show(FinanceOperation $operation): JsonResponse
    {
        return $this->successResponse(
            new FinanceOperationResource($operation->load('createdBy')),
            'Detail operasi berhasil diambil',
        );
    }

    /**
     * POST /finance/operations — panggil pekerjaan berat lewat antrian (§28).
     *
     * Nama pekerjaan dibatasi whitelist service; argumennya disimpan apa adanya
     * supaya pekerjaan yang sama bisa dijalankan ulang tanpa diketik ulang.
     */
    public function store(FinanceOperationDispatchRequest $request): JsonResponse
    {
        $operation = $this->operations->dispatch(
            (string) $request->validated('job'),
            (array) ($request->validated('payload') ?? []),
            $request->user(),
        );

        return $this->createdResponse(
            new FinanceOperationResource($operation),
            'Pekerjaan berhasil diantrekan',
        );
    }

    /**
     * POST /finance/operations/{operation}/retry — jalankan ulang dengan argumen
     * yang tersimpan (§29). Hanya pekerjaan (`job`) yang boleh; peristiwa gagal
     * dijelaskan service sebagai "ulangi aksi aslinya".
     */
    public function retry(Request $request, FinanceOperation $operation): JsonResponse
    {
        $operation = $this->operations->retry($operation, $request->user());

        return $this->successResponse(
            new FinanceOperationResource($operation),
            'Pekerjaan berhasil diantrekan ulang',
        );
    }
}
