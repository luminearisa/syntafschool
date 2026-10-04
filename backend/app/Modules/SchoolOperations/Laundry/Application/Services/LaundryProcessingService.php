<?php

namespace App\Modules\SchoolOperations\Laundry\Application\Services;

use App\Models\User;
use App\Modules\SchoolOperations\Laundry\Domain\Events\LaundryQualityCheckFailed;
use App\Modules\SchoolOperations\Laundry\Domain\Events\LaundryQualityCheckPassed;
use App\Modules\SchoolOperations\Laundry\Domain\Exceptions\LaundryDomainException;
use App\Modules\SchoolOperations\Laundry\Domain\Support\LaundryMath;
use App\Modules\SchoolOperations\Laundry\Domain\Support\OrderStatus;
use App\Modules\SchoolOperations\Laundry\Models\LaundryBatch;
use App\Modules\SchoolOperations\Laundry\Models\LaundryBatchOrder;
use App\Modules\SchoolOperations\Laundry\Models\LaundryMachine;
use App\Modules\SchoolOperations\Laundry\Models\LaundryOrder;
use App\Modules\SchoolOperations\Laundry\Models\LaundryProcessingStep;
use App\Modules\SchoolOperations\Laundry\Models\LaundryQualityCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pemrosesan laundry (§32–§38, §42): antrean kanban, batch per tahap,
 * langkah individual, quality control, dan pengemasan.
 *
 * Rule 16: order yang sudah masuk pemrosesan tidak boleh hilang — semua
 * langkah/batch tercatat; Rule 27: order berbayar belum lunas ditolak masuk
 * batch kecuali override beralasan.
 */
class LaundryProcessingService
{
    public function __construct(
        private readonly LaundryOrderStateMachine $state,
        private readonly LaundryNotificationService $notifications,
        private readonly LaundryAuditService $audit,
    ) {
    }

    // =====================================================================
    // KANBAN (§32)
    // =====================================================================

    /** Papan antrean: order per tahap (Waiting/Washing/…/Ready). */
    public function kanban(?string $date = null): array
    {
        $columns = [
            'WAITING' => [OrderStatus::PAID, OrderStatus::QUEUED],
            'WASHING' => [OrderStatus::WASHING],
            'DRYING' => [OrderStatus::DRYING],
            'IRONING' => [OrderStatus::IRONING],
            'FOLDING' => [OrderStatus::FOLDING],
            'QUALITY_CHECK' => [OrderStatus::QUALITY_CHECK],
            'PACKAGING' => [OrderStatus::PACKAGED],
            'READY' => [OrderStatus::READY],
        ];

        $orders = LaundryOrder::query()
            ->with('student:id,full_name,nis', 'service:id,name,code')
            ->whereIn('status', array_merge(...array_values($columns)))
            ->when($date, fn ($q) => $q->whereDate('order_date', $date))
            ->orderBy('due_at')
            ->orderBy('id')
            ->get();

        $board = [];
        foreach ($columns as $column => $statuses) {
            $board[$column] = $orders->filter(fn (LaundryOrder $o) => in_array($o->status, $statuses, true))
                ->map(fn (LaundryOrder $o): array => [
                    'id' => (int) $o->id,
                    'order_number' => (string) $o->order_number,
                    'student_name' => $o->student?->full_name,
                    'nis' => $o->student?->nis,
                    'service' => $o->service?->name,
                    'net_weight' => $o->net_weight !== null ? LaundryMath::quantity($o->net_weight) : null,
                    'payment_status' => (string) $o->payment_status,
                    'due_at' => $o->due_at?->toIso8601String(),
                    'delayed' => $o->due_at !== null && $o->due_at->isPast(),
                ])->values()->all();
        }

        return $board;
    }

    // =====================================================================
    // BATCH (§33)
    // =====================================================================

    public function createBatch(array $data, ?User $actor = null): LaundryBatch
    {
        $stage = strtoupper((string) ($data['stage'] ?? ''));
        if (OrderStatus::stageStatus($stage) === null) {
            throw ValidationException::withMessages([
                'stage' => 'Tahap batch harus salah satu dari: '.implode(', ', (array) config('laundry.processing_stages', [])),
            ]);
        }

        $machine = ! empty($data['laundry_machine_id'])
            ? LaundryMachine::query()->find((int) $data['laundry_machine_id'])
            : null;

        if ($machine !== null && $machine->status !== 'OPERATIONAL') {
            throw ValidationException::withMessages([
                'laundry_machine_id' => "Mesin {$machine->code} berstatus {$machine->status}.",
            ]);
        }

        $batch = LaundryBatch::create([
            'batch_number' => 'BATCH-TMP',
            'stage' => $stage,
            'laundry_machine_id' => $machine?->id,
            'operator_id' => $data['operator_id'] ?? $actor?->id,
            'status' => 'PLANNED',
            'program' => $data['program'] ?? null,
            'temperature_c' => $data['temperature_c'] ?? null,
            'detergent' => $data['detergent'] ?? null,
            'drying_method' => $data['drying_method'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $actor?->id,
        ]);

        $batch->update([
            'batch_number' => sprintf('BATCH-%s-%s-%04d', $stage, now()->format('Ymd'), $batch->id),
        ]);

        $this->audit->record('BATCH_CREATED', $batch, $actor, [], ['stage' => $stage, 'machine' => $machine?->code]);

        return $batch->fresh();
    }

    /**
     * Masukkan order ke batch. Syarat: order PAID/QUEUED atau sedang pada
     * tahap tepat sebelum stage batch; pembayaran beres (Rule 27).
     */
    public function addOrders(LaundryBatch $batch, array $orderIds, ?User $actor = null): LaundryBatch
    {
        if ($batch->status !== 'PLANNED') {
            throw new LaundryDomainException("Batch {$batch->batch_number} sudah berjalan/selesai.");
        }

        $orders = LaundryOrder::query()->whereIn('id', array_map('intval', $orderIds))->get();
        if ($orders->count() !== count(array_unique(array_map('intval', $orderIds)))) {
            throw ValidationException::withMessages(['order_ids' => 'Sebagian order tidak ditemukan.']);
        }

        $expectedFrom = $this->expectedStatusBefore($batch->stage);

        foreach ($orders as $order) {
            if (! $order->isProcessable()) {
                throw new LaundryDomainException(
                    "Order {$order->order_number} belum dibayar — tidak boleh masuk pemrosesan (Rule 27)."
                );
            }
            if (! in_array($order->status, $expectedFrom, true)) {
                throw new LaundryDomainException(
                    "Order {$order->order_number} berstatus {$order->status}, diharapkan ".implode('/', $expectedFrom)." untuk batch {$batch->stage}."
                );
            }

            LaundryBatchOrder::firstOrCreate(
                ['laundry_batch_id' => $batch->id, 'laundry_order_id' => $order->id],
                ['added_at' => now(), 'added_by' => $actor?->id]
            );
        }

        $this->refreshBatchTotals($batch);

        return $batch;
    }

    /** Mulai batch: order berpindah ke status tahap batch, step IN_PROGRESS. */
    public function startBatch(LaundryBatch $batch, ?User $actor = null): LaundryBatch
    {
        if ($batch->status !== 'PLANNED') {
            throw new LaundryDomainException("Batch {$batch->batch_number} tidak dalam status PLANNED.");
        }

        return DB::transaction(function () use ($batch, $actor): LaundryBatch {
            $batch->status = 'IN_PROGRESS';
            $batch->started_at = now();
            if ($actor !== null && $batch->operator_id === null) {
                $batch->operator_id = $actor->id;
            }
            $batch->save();

            $target = OrderStatus::stageStatus((string) $batch->stage);

            foreach ($batch->orders as $order) {
                if (! $order->isProcessable()) {
                    throw new LaundryDomainException("Order {$order->order_number} belum dibayar (Rule 27).");
                }

                // Order PAID perlu masuk antrean dulu sebelum tahap berjalan.
                if ($order->status === OrderStatus::PAID) {
                    $this->state->transitionTo($order, OrderStatus::QUEUED, $actor, null, $batch->batch_number);
                }
                $this->state->transitionTo($order, $target, $actor, null, $batch->batch_number);

                LaundryProcessingStep::create([
                    'laundry_order_id' => $order->id,
                    'step_type' => $batch->stage,
                    'laundry_batch_id' => $batch->id,
                    'laundry_machine_id' => $batch->laundry_machine_id,
                    'operator_id' => $batch->operator_id,
                    'status' => 'IN_PROGRESS',
                    'started_at' => now(),
                    'program' => $batch->program,
                    'temperature_c' => $batch->temperature_c,
                    'detergent' => $batch->detergent,
                    'drying_method' => $batch->drying_method,
                ]);
            }

            $this->audit->record('BATCH_STARTED', $batch, $actor, [], [
                'orders' => $batch->orders->count(), 'weight' => (float) $batch->total_weight,
            ]);

            // §45: kabari wali bahwa laundry sedang diproses (sekali per order).
            foreach ($batch->orders as $order) {
                $this->notifications->notifyOrder($order, 'PROCESSING');
            }

            return $batch->fresh('orders');
        });
    }

    /** Selesaikan batch: step COMPLETED, order maju ke tahap berikutnya. */
    public function completeBatch(LaundryBatch $batch, ?User $actor = null): LaundryBatch
    {
        if ($batch->status !== 'IN_PROGRESS') {
            throw new LaundryDomainException("Batch {$batch->batch_number} tidak sedang berjalan.");
        }

        return DB::transaction(function () use ($batch, $actor): LaundryBatch {
            $batch->status = 'COMPLETED';
            $batch->completed_at = now();
            $batch->save();

            $next = OrderStatus::nextAfterStage((string) $batch->stage);

            LaundryProcessingStep::query()
                ->where('laundry_batch_id', $batch->id)
                ->where('status', 'IN_PROGRESS')
                ->update(['status' => 'COMPLETED', 'ended_at' => now(), 'updated_at' => now()]);

            if ($next !== null) {
                foreach ($batch->orders as $order) {
                    $this->state->transitionTo($order, $next, $actor, null, $batch->batch_number);
                }
            }

            $this->audit->record('BATCH_COMPLETED', $batch, $actor, [], ['next_status' => $next]);

            return $batch->fresh('orders');
        });
    }

    // =====================================================================
    // LANGKAH INDIVIDUAL (§35–§37)
    // =====================================================================

    /**
     * Catat/mulai/selesaikan satu langkah tanpa batch (mesin tunggal/order
     * satuan). `action`: start|complete.
     */
    public function recordStep(LaundryOrder $order, string $stepType, string $action, array $data = [], ?User $actor = null): LaundryProcessingStep
    {
        $stepType = strtoupper($stepType);
        if (! in_array($stepType, ['WASHING', 'DRYING', 'IRONING', 'FOLDING', 'PACKAGING'], true)) {
            throw ValidationException::withMessages(['step_type' => 'Jenis langkah tidak dikenal.']);
        }

        if (! $order->isProcessable() && $stepType !== 'PACKAGING') {
            throw new LaundryDomainException("Order {$order->order_number} belum dibayar (Rule 27).");
        }

        $step = LaundryProcessingStep::query()
            ->where('laundry_order_id', $order->id)
            ->where('step_type', $stepType)
            ->where('status', '!=', 'COMPLETED')
            ->orderByDesc('id')
            ->first();

        if ($action === 'start') {
            $target = OrderStatus::stageStatus($stepType);
            if ($target !== null && $order->status !== $target && OrderStatus::can((string) $order->status, $target)) {
                if ($order->status === OrderStatus::PAID) {
                    $this->state->transitionTo($order, OrderStatus::QUEUED, $actor);
                }
                $this->state->transitionTo($order, $target, $actor, null, 'step:'.$stepType);
            }

            $step = LaundryProcessingStep::create([
                'laundry_order_id' => $order->id,
                'step_type' => $stepType,
                'laundry_machine_id' => $data['laundry_machine_id'] ?? null,
                'operator_id' => $data['operator_id'] ?? $actor?->id,
                'status' => 'IN_PROGRESS',
                'started_at' => now(),
                'program' => $data['program'] ?? null,
                'temperature_c' => $data['temperature_c'] ?? null,
                'detergent' => $data['detergent'] ?? null,
                'drying_method' => $data['drying_method'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
        } elseif ($action === 'complete') {
            if ($step === null) {
                throw new LaundryDomainException("Langkah {$stepType} untuk order {$order->order_number} belum dimulai.");
            }

            $step->update([
                'status' => 'COMPLETED',
                'ended_at' => now(),
                'item_count' => $data['item_count'] ?? $step->item_count,
                'notes' => $data['notes'] ?? $step->notes,
            ]);

            $next = OrderStatus::nextAfterStage($stepType);
            if ($next !== null && $order->status === $stepType && OrderStatus::can((string) $order->status, $next)) {
                $this->state->transitionTo($order, $next, $actor, null, 'step:'.$stepType);
            }
        } else {
            throw ValidationException::withMessages(['action' => 'Aksi langkah harus start atau complete.']);
        }

        return $step;
    }

    // =====================================================================
    // QUALITY CONTROL (§38)
    // =====================================================================

    /**
     * Catat hasil QC. PASSED membuka jalan ke pengemasan; FAILED/REWORK
     * mengembalikan order ke tahap perbaikan dan dipublikasikan sebagai event.
     */
    public function qualityCheck(LaundryOrder $order, array $data, ?User $actor = null): LaundryQualityCheck
    {
        if ($order->status !== OrderStatus::QUALITY_CHECK) {
            throw new LaundryDomainException("Order {$order->order_number} tidak berada pada tahap QC ({$order->status}).");
        }

        $result = strtoupper((string) ($data['result'] ?? ''));
        if (! in_array($result, (array) config('laundry.qc_results', ['PASSED', 'FAILED', 'REWORK']), true)) {
            throw ValidationException::withMessages(['result' => 'Hasil QC harus PASSED, FAILED, atau REWORK.']);
        }

        $checklist = [];
        foreach ((array) config('laundry.qc_checklist', []) as $key => $label) {
            $checklist[$key] = (bool) ($data['checklist'][$key] ?? false);
        }

        $qc = LaundryQualityCheck::create([
            'laundry_order_id' => $order->id,
            'checklist' => $checklist,
            'result' => $result,
            'notes' => $data['notes'] ?? null,
            'rework_target_status' => $result === 'FAILED' ? OrderStatus::WASHING
                : ($result === 'REWORK' ? OrderStatus::IRONING : null),
            'checked_by' => $actor?->id,
            'checked_at' => now(),
        ]);

        if ($result === 'PASSED') {
            event(new LaundryQualityCheckPassed((int) $order->id, [
                'order_number' => $order->order_number, 'qc_id' => (int) $qc->id,
            ]));
        } else {
            $target = (string) $qc->rework_target_status;
            $this->state->transitionTo($order, $target, $actor, "QC {$result}: ".($data['notes'] ?? 'perlu perbaikan'), 'qc#'.$qc->id);

            event(new LaundryQualityCheckFailed((int) $order->id, [
                'order_number' => $order->order_number, 'result' => $result, 'qc_id' => (int) $qc->id,
            ]));
        }

        $this->audit->record('QC_RECORDED', $order, $actor, [], ['result' => $result, 'qc_id' => (int) $qc->id]);

        return $qc;
    }

    // =====================================================================
    // PACKAGING (§42)
    // =====================================================================

    /** QC lulus → lipat → kemas → label → PACKAGED. */
    public function package(LaundryOrder $order, array $data = [], ?User $actor = null): LaundryOrder
    {
        if ($order->status !== OrderStatus::QUALITY_CHECK) {
            throw new LaundryDomainException("Pengemasan hanya dari status QUALITY_CHECK (saat ini {$order->status}).");
        }

        $passed = LaundryQualityCheck::query()
            ->where('laundry_order_id', $order->id)
            ->where('result', 'PASSED')
            ->exists();

        if (! $passed) {
            throw new LaundryDomainException("Order {$order->order_number} belum lulus QC — tidak dapat dikemas.");
        }

        return DB::transaction(function () use ($order, $data, $actor): LaundryOrder {
            LaundryProcessingStep::create([
                'laundry_order_id' => $order->id,
                'step_type' => 'PACKAGING',
                'operator_id' => $actor?->id,
                'status' => 'COMPLETED',
                'started_at' => now(),
                'ended_at' => now(),
                'item_count' => $order->item_count,
                'notes' => $data['notes'] ?? null,
            ]);

            if (! empty($data['laundry_bag_id']) && (int) $data['laundry_bag_id'] !== (int) $order->laundry_bag_id) {
                $order->update(['laundry_bag_id' => (int) $data['laundry_bag_id']]);
            }

            $this->state->transitionTo($order, OrderStatus::PACKAGED, $actor, null, $order->order_number);

            return $order;
        });
    }

    // =====================================================================
    // INTERNAL
    // =====================================================================

    /** Status order yang boleh masuk ke batch satu tahap (termasuk rework). */
    private function expectedStatusBefore(string $stage): array
    {
        return match ($stage) {
            OrderStatus::WASHING => [OrderStatus::PAID, OrderStatus::QUEUED, OrderStatus::WASHING],
            OrderStatus::DRYING => [OrderStatus::WASHING, OrderStatus::DRYING],
            OrderStatus::IRONING => [OrderStatus::DRYING, OrderStatus::IRONING],
            OrderStatus::FOLDING => [OrderStatus::IRONING, OrderStatus::FOLDING],
            default => [OrderStatus::PAID, OrderStatus::QUEUED],
        };
    }

    private function refreshBatchTotals(LaundryBatch $batch): void
    {
        $orders = LaundryOrder::query()
            ->whereIn('id', LaundryBatchOrder::query()->where('laundry_batch_id', $batch->id)->pluck('laundry_order_id'))
            ->get();

        $batch->update([
            'total_orders' => $orders->count(),
            'total_weight' => LaundryMath::quantity($orders->sum(fn (LaundryOrder $o) => (float) ($o->net_weight ?? 0))),
        ]);
    }
}
