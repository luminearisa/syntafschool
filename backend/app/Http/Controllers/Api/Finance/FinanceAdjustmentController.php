<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Finance\FinanceAdjustmentRequest;
use App\Http\Requests\Finance\FinanceWriteOffRequest;
use App\Http\Resources\FinanceAdjustmentResource;
use App\Models\FinanceAdjustment;
use App\Models\FinanceInvoice;
use App\Models\User;
use App\Services\Finance\FinanceAdjustmentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Adjustment / koreksi (§51): buat → ajukan → approve/reject → post. Posting
 * membuat jurnal BARU (tidak mengedit transaksi lama) antara account_id dan
 * contra_account_id, opsional menggerakkan saldo kas/bank konkret.
 *
 * Write-off piutang (§32–§34) memakai alur yang sama, tetapi haknya terpisah:
 * middleware rute hanya menjaga jalur umum (`finance.adjustment.*`), sedangkan
 * pemisahan "yang boleh menghapus piutang" (`finance.write_off.*`) dijaga di
 * sini berdasarkan `kind`. Tanpa pemeriksaan ini, jalur umum bisa dipakai untuk
 * write-off dan pemisahan pencatat/penyetuju (§3) bocor.
 */
class FinanceAdjustmentController extends BaseApiController
{
    private const CAN_REQUEST_WRITE_OFF = 'finance.write_off.request';

    private const CAN_APPROVE_WRITE_OFF = 'finance.write_off.approve';

    public function __construct(private readonly FinanceAdjustmentService $adjustments) {}

    public function index(Request $request): JsonResponse
    {
        $query = FinanceAdjustment::query()
            ->with(['account', 'contraAccount', 'costCenter', 'invoice'])
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('kind'), fn (Builder $q) => $q->where('kind', $request->string('kind')))
            ->when($request->filled('invoice_id'), fn (Builder $q) => $q->where('invoice_id', (int) $request->input('invoice_id')))
            ->when($request->filled('account_id'), fn (Builder $q) => $q->where('account_id', (int) $request->input('account_id')))
            ->when($request->filled('cost_center_id'), fn (Builder $q) => $q->where('cost_center_id', (int) $request->input('cost_center_id')))
            ->when($request->filled('direction'), fn (Builder $q) => $q->where('direction', $request->string('direction')))
            ->when($request->filled('date_from'), fn (Builder $q) => $q->whereDate('adjustment_date', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->whereDate('adjustment_date', '<=', $request->input('date_to')))
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = trim((string) $request->input('search'));
                $q->where(fn (Builder $inner) => $inner
                    ->where('adjustment_number', 'like', "%$term%")
                    ->orWhere('reason', 'like', "%$term%"));
            })
            ->orderByDesc('id');

        if ($request->boolean('all')) {
            return $this->successResponse(FinanceAdjustmentResource::collection($query->get()), 'Daftar adjustment berhasil diambil');
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 15)));

        return $this->successResponse(
            FinanceAdjustmentResource::collection($query->paginate($perPage)->appends($request->query()))
                ->additional(['meta' => [
                    'statuses' => FinanceAdjustment::STATUSES,
                    'status_labels' => FinanceAdjustment::STATUS_LABELS,
                    'kinds' => FinanceAdjustment::KINDS,
                    'kind_labels' => FinanceAdjustment::KIND_LABELS,
                ]]),
            'Daftar adjustment berhasil diambil',
        );
    }

    public function show(FinanceAdjustment $adjustment): JsonResponse
    {
        $adjustment->load(['account', 'contraAccount', 'costCenter', 'invoice', 'requestedBy', 'approvedBy']);

        return $this->successResponse(new FinanceAdjustmentResource($adjustment), 'Detail adjustment berhasil diambil');
    }

    public function store(FinanceAdjustmentRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (($data['kind'] ?? FinanceAdjustment::KIND_GENERAL) === FinanceAdjustment::KIND_WRITE_OFF) {
            if ($denied = $this->denyWithout($request->user(), self::CAN_REQUEST_WRITE_OFF)) {
                return $denied;
            }
        }

        $adjustment = $this->adjustments->create($data, $request->user());

        return $this->createdResponse(
            new FinanceAdjustmentResource($adjustment->load(['account', 'contraAccount', 'invoice'])),
            'Adjustment berhasil dibuat',
        );
    }

    /**
     * Write-off piutang (§32) dari sisi invoice. Otomatis diajukan supaya
     * approver berbeda dari peminta (§3); jurnalnya baru terbentuk saat post.
     */
    public function writeOff(FinanceWriteOffRequest $request, FinanceInvoice $invoice): JsonResponse
    {
        if ($denied = $this->denyWithout($request->user(), self::CAN_REQUEST_WRITE_OFF)) {
            return $denied;
        }

        $adjustment = $this->adjustments->createWriteOff([
            'invoice_id' => $invoice->id,
            'amount' => (float) $request->input('amount'),
            'reason' => (string) $request->input('reason'),
            'adjustment_date' => $request->input('adjustment_date'),
            'cost_center_id' => $request->input('cost_center_id'),
            'submit' => true,
        ], $request->user());

        return $this->createdResponse(
            new FinanceAdjustmentResource($adjustment->load(['account', 'contraAccount', 'invoice'])),
            'Permintaan write-off piutang berhasil diajukan',
        );
    }

    public function submit(Request $request, FinanceAdjustment $adjustment): JsonResponse
    {
        if ($denied = $this->denyWriteOff($request->user(), $adjustment, self::CAN_REQUEST_WRITE_OFF)) {
            return $denied;
        }

        $updated = $this->adjustments->submit($adjustment, $request->user());

        return $this->successResponse(new FinanceAdjustmentResource($updated), 'Adjustment diajukan');
    }

    public function approve(Request $request, FinanceAdjustment $adjustment): JsonResponse
    {
        if ($denied = $this->denyWriteOff($request->user(), $adjustment, self::CAN_APPROVE_WRITE_OFF)) {
            return $denied;
        }

        $updated = $this->adjustments->approve($adjustment, $request->user());

        return $this->successResponse(new FinanceAdjustmentResource($updated), 'Adjustment disetujui');
    }

    public function reject(Request $request, FinanceAdjustment $adjustment): JsonResponse
    {
        if ($denied = $this->denyWriteOff($request->user(), $adjustment, self::CAN_APPROVE_WRITE_OFF)) {
            return $denied;
        }

        $data = $request->validate(['reject_reason' => ['required', 'string', 'max:500']]);

        $updated = $this->adjustments->reject($adjustment, (string) $data['reject_reason'], $request->user());

        return $this->successResponse(new FinanceAdjustmentResource($updated), 'Adjustment ditolak');
    }

    /** Posting adjustment yang sudah disetujui: jurnal koreksi baru (§51). */
    public function post(Request $request, FinanceAdjustment $adjustment): JsonResponse
    {
        if ($denied = $this->denyWriteOff($request->user(), $adjustment, self::CAN_APPROVE_WRITE_OFF)) {
            return $denied;
        }

        $updated = $this->adjustments->post($adjustment, $request->user());

        return $this->successResponse(
            new FinanceAdjustmentResource($updated->load(['account', 'contraAccount'])),
            'Adjustment diposting',
        );
    }

    /**
     * Write-off menuntut hak `finance.write_off.*` sendiri; adjustment umum
     * lanjut tanpa pemeriksaan. `null` = boleh dilanjutkan.
     */
    private function denyWriteOff(?User $user, FinanceAdjustment $adjustment, string $permission): ?JsonResponse
    {
        return $adjustment->kind === FinanceAdjustment::KIND_WRITE_OFF
            ? $this->denyWithout($user, $permission)
            : null;
    }

    private function denyWithout(?User $user, string $permission): ?JsonResponse
    {
        if ($user?->hasAnyPermission([$permission])) {
            return null;
        }

        return $this->errorResponse(
            'Write-off piutang memerlukan izin '.str_replace('finance.', 'finance:', $permission).'.',
            403,
        );
    }
}
