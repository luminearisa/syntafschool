<?php

namespace App\Modules\SchoolOperations\Laundry\Application\Services;

use App\Models\User;
use App\Modules\SchoolOperations\Laundry\Domain\Contracts\BoardingProviderInterface;
use App\Modules\SchoolOperations\Laundry\Domain\Contracts\RfidProviderInterface;
use App\Modules\SchoolOperations\Laundry\Domain\Contracts\StudentProviderInterface;
use App\Modules\SchoolOperations\Laundry\Domain\Contracts\WalletProviderInterface;
use App\Modules\SchoolOperations\Laundry\Domain\Events\LaundryOrderCreated;
use App\Modules\SchoolOperations\Laundry\Domain\Events\LaundryQuotaAllocated;
use App\Modules\SchoolOperations\Laundry\Domain\Exceptions\LaundryDomainException;
use App\Modules\SchoolOperations\Laundry\Domain\Support\LaundryMath;
use App\Modules\SchoolOperations\Laundry\Domain\Support\OrderStatus;
use App\Modules\SchoolOperations\Laundry\Models\LaundryBag;
use App\Modules\SchoolOperations\Laundry\Models\LaundryEntitlement;
use App\Modules\SchoolOperations\Laundry\Models\LaundryLabel;
use App\Modules\SchoolOperations\Laundry\Models\LaundryOrder;
use App\Modules\SchoolOperations\Laundry\Models\LaundryOrderAllocation;
use App\Modules\SchoolOperations\Laundry\Models\LaundryOrderItem;
use App\Modules\SchoolOperations\Laundry\Models\LaundryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Siklus hidup order laundry (§9–§12, §29–§31, §56, §57).
 *
 * Alur: create (DRAFT) → receive → weigh → calculate (split free/paid +
 * snapshot harga) → pay bila ada porsi berbayar → processing → … → completed.
 * Semua transisi status lewat state machine; semua operasi sensitif diaudit.
 */
class LaundryOrderService
{
    public function __construct(
        private readonly StudentProviderInterface $students,
        private readonly BoardingProviderInterface $boarding,
        private readonly WalletProviderInterface $wallet,
        private readonly RfidProviderInterface $rfid,
        private readonly LaundryEntitlementService $entitlements,
        private readonly LaundryPricingService $pricing,
        private readonly LaundryPaymentService $payments,
        private readonly LaundryOrderStateMachine $state,
        private readonly LaundryNotificationService $notifications,
        private readonly LaundryAuditService $audit,
    ) {
    }

    // =====================================================================
    // IDENTIFIKASI (§17, §29, §70)
    // =====================================================================

    /**
     * Tap RFID/QR di meja penerimaan: identitas santri + profil laundry
     * (jatah, saldo wallet dari provider, order terbuka, tas).
     */
    public function identify(string $uid): array
    {
        $resolved = $this->rfid->resolve(trim($uid));
        if ($resolved === null) {
            throw ValidationException::withMessages([
                'uid' => 'RFID/QR tidak dikenali (bukan kartu wallet, laundry bag, atau label).',
            ]);
        }

        $studentId = isset($resolved['student_id']) ? (int) $resolved['student_id'] : null;
        $student = $studentId !== null ? $this->students->snapshot($studentId) : null;

        $openOrders = $studentId !== null
            ? LaundryOrder::query()
                ->where('student_id', $studentId)
                ->whereNotIn('status', OrderStatus::TERMINAL)
                ->orderByDesc('id')
                ->limit(10)
                ->get()
                ->map(fn (LaundryOrder $o): array => $this->presentSummary($o))
                ->all()
            : [];

        return [
            'identity' => $resolved,
            'student' => $student,
            'quota' => $studentId !== null ? $this->entitlements->currentState($studentId) : null,
            'wallet' => $studentId !== null ? $this->wallet->accountFor($studentId) : null,
            'open_orders' => $openOrders,
            'bag' => isset($resolved['bag_id']) ? LaundryBag::query()->find($resolved['bag_id']) : null,
        ];
    }

    // =====================================================================
    // PEMBUATAN & PENERIMAAN (§9, §29)
    // =====================================================================

    /**
     * Buat order (DRAFT) — Rule 1: wajib santri; Rule 2: santri aktif.
     *
     * @param  array{student_id:int, laundry_service_id:int, items?:list<array>, notes?:string|null, submitted_by?:string|null, laundry_bag_id?:int|null, pickup_method?:string|null}  $data
     */
    public function create(array $data, ?User $actor = null): LaundryOrder
    {
        $studentId = (int) ($data['student_id'] ?? 0);
        $student = $this->students->snapshot($studentId);

        if ($student === null) {
            throw ValidationException::withMessages(['student_id' => 'Santri tidak ditemukan.']);
        }
        if (! ($student['active'] ?? false)) {
            throw ValidationException::withMessages(['student_id' => 'Santri tidak aktif sehingga tidak dapat order laundry.']);
        }

        $service = LaundryService::query()->where('is_active', true)->find((int) ($data['laundry_service_id'] ?? 0));
        if (! $service) {
            throw ValidationException::withMessages(['laundry_service_id' => 'Layanan laundry tidak ditemukan atau tidak aktif.']);
        }

        $placement = $this->boarding->placementFor($studentId);

        return DB::transaction(function () use ($data, $studentId, $service, $placement, $actor): LaundryOrder {
            $order = LaundryOrder::create([
                'order_number' => 'LDR-TMP',
                'student_id' => $studentId,
                'boarding_dormitory_id' => $placement['dormitory_id'] ?? null,
                'laundry_service_id' => $service->id,
                'laundry_bag_id' => $data['laundry_bag_id'] ?? null,
                'submitted_by' => $data['submitted_by'] ?? null,
                'submitted_by_user_id' => $actor?->id,
                'status' => OrderStatus::DRAFT,
                'order_date' => ($data['order_date'] ?? now()->toDateString()),
                'pickup_method' => $data['pickup_method'] ?? 'PICKUP',
                'notes' => $data['notes'] ?? null,
                'payment_status' => 'PENDING',
                'created_by' => $actor?->id,
            ]);

            $order->update(['order_number' => $this->orderNumber($order)]);

            foreach ((array) ($data['items'] ?? []) as $item) {
                $this->addItem($order, $item);
            }

            $this->audit->record('ORDER_CREATED', $order, $actor, [], [
                'order_number' => $order->order_number,
                'student_id' => $studentId,
                'service' => $service->code,
            ]);

            event(new LaundryOrderCreated((int) $order->id, [
                'order_number' => $order->order_number,
                'student_id' => $studentId,
                'service_id' => (int) $service->id,
            ]));

            return $order;
        });
    }

    public function addItem(LaundryOrder $order, array $item): LaundryOrderItem
    {
        if (OrderStatus::isTerminal((string) $order->status) || ! in_array($order->status, [OrderStatus::DRAFT, OrderStatus::RECEIVED], true)) {
            throw new LaundryDomainException('Item hanya dapat ditambah sebelum penimbangan.');
        }

        $row = LaundryOrderItem::create([
            'laundry_order_id' => $order->id,
            'item_type' => strtoupper((string) ($item['item_type'] ?? 'OTHER')),
            'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
            'color' => $item['color'] ?? null,
            'description' => $item['description'] ?? null,
            'condition' => $item['condition'] ?? null,
            'tag_code' => $item['tag_code'] ?? null,
            'rfid_tag' => $item['rfid_tag'] ?? null,
            'photo_path' => $item['photo_path'] ?? null,
            'notes' => $item['notes'] ?? null,
        ]);

        $order->update(['item_count' => $this->itemCount($order)]);

        return $row;
    }

    /**
     * Terima laundry fisik (§29): verifikasi santri, scan bag, hitung item,
     * generate label. DRAFT → RECEIVED.
     */
    public function receive(LaundryOrder $order, array $data, ?User $actor = null): LaundryOrder
    {
        if (! in_array($order->status, [OrderStatus::DRAFT, OrderStatus::RECEIVED], true)) {
            throw new LaundryDomainException("Order {$order->order_number} sudah lewat tahap penerimaan ({$order->status}).");
        }

        return DB::transaction(function () use ($order, $data, $actor): LaundryOrder {
            $bag = $this->attachBag($order, $data);

            $order->received_by = $actor?->id;
            $order->received_at = now();
            $order->submitted_by = $data['submitted_by'] ?? $order->submitted_by;
            $order->item_count = $this->itemCount($order);
            if ($bag !== null) {
                $order->laundry_bag_id = $bag->id;
            }
            // Due date dari estimasi layanan (§31).
            $hours = (int) ($order->service?->processing_hours ?? 48);
            $order->due_at = now()->addHours(max(1, $hours));
            $order->save();

            $this->state->transitionTo($order, OrderStatus::RECEIVED, $actor, null, $order->order_number);

            $label = $this->ensureLabel($order, $actor);

            $this->audit->record('ORDER_RECEIVED', $order, $actor, [], [
                'bag' => $bag?->bag_code, 'items' => $order->item_count, 'label' => $label->label_number,
            ]);

            $this->notifications->notifyOrder($order, 'ORDER_RECEIVED');

            return $order;
        });
    }

    // =====================================================================
    // PENIMBANGAN (§30)
    // =====================================================================

    /**
     * Timbang/hitung (§30): simpan pengukuran mentah (gross, bag, net).
     * Net = Gross − Bag. RECEIVED → WEIGHED.
     */
    public function weigh(LaundryOrder $order, array $data, ?User $actor = null): LaundryOrder
    {
        if (! in_array($order->status, [OrderStatus::DRAFT, OrderStatus::RECEIVED, OrderStatus::WEIGHED], true)) {
            throw new LaundryDomainException("Order {$order->order_number} tidak dapat ditimbang pada status {$order->status}.");
        }

        $gross = isset($data['gross_weight']) ? LaundryMath::quantity($data['gross_weight']) : null;
        $bagWeight = isset($data['bag_weight'])
            ? LaundryMath::quantity($data['bag_weight'])
            : LaundryMath::quantity($order->bag?->tare_weight ?? 0);

        if ($gross === null && empty($data['item_count']) && $order->item_count === 0) {
            throw ValidationException::withMessages([
                'gross_weight' => 'Berat kotor atau jumlah item wajib diisi.',
            ]);
        }

        $net = $gross !== null ? LaundryMath::quantity(max(0, $gross - $bagWeight)) : null;

        $before = [
            'gross_weight' => $order->gross_weight, 'bag_weight' => $order->bag_weight,
            'net_weight' => $order->net_weight,
        ];

        $order->gross_weight = $gross ?? $order->gross_weight;
        $order->bag_weight = $bagWeight;
        $order->net_weight = $net ?? $order->net_weight;
        $order->weighed_by = $actor?->id;
        $order->weighed_at = now();
        if (isset($data['item_count'])) {
            $order->item_count = max(0, (int) $data['item_count']);
        }
        $order->save();

        $this->state->transitionTo($order, OrderStatus::WEIGHED, $actor, null, $order->order_number);

        $this->audit->record('WEIGHT_RECORDED', $order, $actor, $before, [
            'gross_weight' => $order->gross_weight, 'bag_weight' => $order->bag_weight,
            'net_weight' => $order->net_weight,
        ]);

        return $order;
    }

    // =====================================================================
    // KALKULASI FREE/PAID (§10–§12, §54)
    // =====================================================================

    /**
     * Pratinjau harga sebelum order dikonfirmasi (§54) — tanpa menulis apa pun.
     */
    public function previewQuote(int $studentId, array $input): array
    {
        $state = $this->entitlements->currentState($studentId, $input['date'] ?? null);
        $quote = $this->pricing->quote($input, $state['has_policy'] ? [
            'quota_type' => $state['quota_type'],
            'remaining' => $state['remaining'],
            'max_free_weight_per_order' => $this->maxFreeWeightFor($studentId),
        ] : null);

        $account = $this->wallet->accountFor($studentId);

        return [
            'quote' => $quote,
            'entitlement' => $state,
            'wallet' => $account !== null ? [
                'balance' => LaundryMath::money($account['balance']),
                'after_payment' => LaundryMath::money($account['balance'] - $quote['paid_amount']),
                'sufficient' => $account['balance'] >= $quote['paid_amount'],
            ] : null,
        ];
    }

    /**
     * Hitung & kunci split free/paid order (WEIGHED → PAYMENT_REQUIRED/PAID).
     *
     * Jatah gratis dipakai terlebih dahulu (Rule 4/5), pemotongan lewat ledger
     * entitlement (Rule 21), harga disnapshot (Rule 9).
     */
    public function calculate(LaundryOrder $order, ?User $actor = null, array $extras = []): LaundryOrder
    {
        if (! in_array($order->status, [OrderStatus::WEIGHED, OrderStatus::DRAFT, OrderStatus::RECEIVED], true)) {
            throw new LaundryDomainException("Order {$order->order_number} tidak dapat dikalkulasi pada status {$order->status}.");
        }

        if ((float) ($order->net_weight ?? 0) <= 0 && $order->item_count <= 0) {
            throw ValidationException::withMessages([
                'net_weight' => 'Order belum ditimbang/dihitung — tidak ada dasar kalkulasi.',
            ]);
        }

        return DB::transaction(function () use ($order, $actor, $extras): LaundryOrder {
            $date = $order->order_date?->toDateString() ?? now()->toDateString();

            // Kunci entitlement lebih dulu agar quote & consume melihat sisa yang sama.
            $entitlement = $this->entitlements->ensurePeriod((int) $order->student_id, CarbonImmutable::parse($date));
            $locked = $entitlement !== null
                ? LaundryEntitlement::query()->whereKey($entitlement->id)->lockForUpdate()->first()
                : null;

            $state = $locked !== null && in_array($locked->status, ['ACTIVE', 'EXHAUSTED'], true)
                ? [
                    'quota_type' => (string) $locked->quota_type,
                    'remaining' => $locked->remaining(),
                    'max_free_weight_per_order' => $locked->policyVersion?->max_free_weight_per_order !== null
                        ? LaundryMath::quantity($locked->policyVersion->max_free_weight_per_order)
                        : null,
                ]
                : null;

            $items = $order->items->map(fn (LaundryOrderItem $i): array => [
                'item_type' => (string) $i->item_type, 'quantity' => (int) $i->quantity,
            ])->all();

            $quote = $this->pricing->quote([
                'laundry_service_id' => (int) $order->laundry_service_id,
                'net_weight' => (float) ($order->net_weight ?? 0),
                'items' => $items,
                'extras' => $extras,
                'date' => $date,
            ], $state);

            // Potong jatah gratis lewat ledger (Rule 21).
            $freeUsed = 0.0;
            $useTransactionId = null;
            if ($locked !== null && $quote['free_quantity'] > 0) {
                $freeUsed = $this->entitlements->consume($locked, (float) $quote['free_quantity'], [
                    'reference_type' => LaundryOrder::class,
                    'reference_id' => (int) $order->id,
                    'reason' => "Pemakaian jatah gratis order {$order->order_number}",
                ], $actor);

                if (LaundryMath::quantity($freeUsed) !== LaundryMath::quantity($quote['free_quantity'])) {
                    // Sisa berubah di luar dugaan (race) — hitung ulang agar deterministik.
                    $quote = $this->pricing->quote([
                        'laundry_service_id' => (int) $order->laundry_service_id,
                        'net_weight' => (float) ($order->net_weight ?? 0),
                        'items' => $items,
                        'extras' => $extras,
                        'date' => $date,
                    ], ['quota_type' => $state['quota_type'], 'remaining' => $freeUsed,
                        'max_free_weight_per_order' => $state['max_free_weight_per_order'] ?? null]);
                    $freeUsed = (float) $quote['free_quantity'];
                }

                $useTransactionId = \App\Modules\SchoolOperations\Laundry\Models\LaundryEntitlementTransaction::query()
                    ->where('laundry_entitlement_id', $locked->id)
                    ->where('transaction_type', 'USE')
                    ->where('reference_type', LaundryOrder::class)
                    ->where('reference_id', $order->id)
                    ->orderByDesc('id')
                    ->value('id');
            }

            // Snapshot harga & split ke order (Rule 9/23).
            $order->laundry_entitlement_id = $locked?->id;
            $order->quota_type = $quote['quota_type'];
            $order->free_quantity = LaundryMath::quantity($quote['free_quantity']);
            $order->paid_quantity = LaundryMath::quantity($quote['paid_quantity']);
            $order->total_amount = LaundryMath::money($quote['paid_amount']);
            $order->pricing_snapshot = $quote['snapshot'];
            $order->save();

            // Snapshot harga piece ke item.
            foreach ($order->items as $item) {
                $price = $quote['item_unit_prices'][$item->item_type] ?? null;
                if ($price !== null && (float) $item->unit_price !== (float) $price) {
                    $item->update(['unit_price' => $price]);
                }
            }

            // Baris alokasi free/paid (§12).
            LaundryOrderAllocation::query()->where('laundry_order_id', $order->id)->delete();
            if ($quote['free_quantity'] > 0) {
                LaundryOrderAllocation::create([
                    'laundry_order_id' => $order->id,
                    'allocation_type' => 'FREE',
                    'quota_type' => $quote['quota_type'],
                    'quantity' => LaundryMath::quantity($quote['free_quantity']),
                    'amount' => 0,
                    'entitlement_transaction_id' => $useTransactionId,
                ]);
            }
            LaundryOrderAllocation::create([
                'laundry_order_id' => $order->id,
                'allocation_type' => 'PAID',
                'quota_type' => $quote['quota_type'],
                'quantity' => LaundryMath::quantity($quote['paid_quantity']),
                'amount' => LaundryMath::money($quote['paid_amount']),
            ]);

            $payable = $order->payableAmount();

            if ($payable > 0) {
                $order->payment_status = 'PAYMENT_REQUIRED';
                $order->save();
                $this->state->transitionTo($order, OrderStatus::PAYMENT_REQUIRED, $actor, null, $order->order_number);

                $account = $this->wallet->accountFor((int) $order->student_id);
                $this->notifications->notifyOrder($order, 'PAYMENT_REQUIRED', [
                    'amount' => $payable,
                    'balance_text' => $account !== null
                        ? 'Rp '.number_format((float) $account['balance'], 0, ',', '.')
                        : null,
                ]);
            } else {
                // Sepenuhnya gratis — tidak ada tagihan (payment NOT_REQUIRED).
                $order->payment_status = 'NOT_REQUIRED';
                $order->save();
                $this->state->transitionTo($order, OrderStatus::PAID, $actor, 'Seluruhnya tertutup jatah gratis.', $order->order_number);
            }

            if ($quote['free_quantity'] > 0) {
                event(new LaundryQuotaAllocated((int) $order->id, [
                    'order_number' => $order->order_number,
                    'student_id' => (int) $order->student_id,
                    'quota_type' => $quote['quota_type'],
                    'free_quantity' => (float) $quote['free_quantity'],
                    'entitlement_id' => $locked?->id,
                ]));
            }

            $this->audit->record('ORDER_CALCULATED', $order, $actor, [], [
                'free_quantity' => (float) $order->free_quantity,
                'paid_quantity' => (float) $order->paid_quantity,
                'total_amount' => (float) $order->total_amount,
                'quota_type' => $order->quota_type,
            ], null, $order->order_number);

            return $order->fresh(['items', 'allocations']);
        });
    }

    // =====================================================================
    // ANTREAN & PEMROSESAN (§32, §56)
    // =====================================================================

    /**
     * Masukkan order ke antrean proses. Rule 27: porsi berbayar belum lunas
     * tidak boleh diproses kecuali override beralasan (`allow_unpaid_processing`).
     */
    public function queue(LaundryOrder $order, ?User $actor = null): LaundryOrder
    {
        if (! $order->isProcessable()) {
            throw new LaundryDomainException(
                "Order {$order->order_number} belum dibayar — pemrosesan berbayar ditolak (Rule 27).",
                ['order_id' => (int) $order->id, 'payment_status' => $order->payment_status]
            );
        }

        $this->state->transitionTo($order, OrderStatus::QUEUED, $actor, null, $order->order_number);

        return $order;
    }

    // =====================================================================
    // PEMBATALAN & REFUND (§23)
    // =====================================================================

    /**
     * Batalkan order: kembalikan jatah gratis (ledger REVERSE), refund wallet
     * bila sudah dibayar (Rule 14), lepas tas. Reason wajib.
     */
    public function cancel(LaundryOrder $order, string $reason, ?User $actor = null): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan pembatalan wajib diisi.']);
        }

        if (OrderStatus::isTerminal((string) $order->status)) {
            throw new LaundryDomainException("Order {$order->order_number} sudah berstatus final ({$order->status}).");
        }

        $result = DB::transaction(function () use ($order, $reason, $actor): array {
            $reversed = 0.0;

            // Kembalikan jatah gratis yang sudah dipakai order ini.
            if ((float) $order->free_quantity > 0 && $order->laundry_entitlement_id !== null) {
                $entitlement = LaundryEntitlement::query()->find($order->laundry_entitlement_id);
                if ($entitlement !== null && in_array($entitlement->status, ['ACTIVE', 'EXHAUSTED', 'EXPIRED'], true)) {
                    // Entitlement EXPIRED tetap bisa dikembalikan used-nya (jejak ledger),
                    // tetapi tidak dihidupkan lagi.
                    if ($entitlement->status === 'EXPIRED') {
                        $reversed = 0.0;
                    } else {
                        $reversed = $this->entitlements->reverse($entitlement, (float) $order->free_quantity, [
                            'reference_type' => LaundryOrder::class,
                            'reference_id' => (int) $order->id,
                            'reason' => "Pembatalan order {$order->order_number}: {$reason}",
                        ], $actor);
                    }
                }
            }

            // Refund pembayaran wallet bila ada (§23).
            $refund = null;
            if ($order->payment_status === 'PAID' && $order->payableAmount() > 0) {
                $refund = $this->payments->refund($order, "Pembatalan order: {$reason}", $actor);
            }

            $this->releaseBag($order);

            $this->state->transitionTo($order, OrderStatus::CANCELLED, $actor, $reason, $order->order_number);

            $this->audit->record('ORDER_CANCELLED', $order, $actor, [
                'status' => 'active', 'free_quantity' => (float) $order->free_quantity,
            ], [
                'status' => OrderStatus::CANCELLED,
                'quota_reversed' => $reversed,
                'refund' => $refund['refund']['refund_number'] ?? null,
            ], $reason, $order->order_number);

            return ['quota_reversed' => $reversed, 'refund' => $refund['refund'] ?? null];
        });

        return $result;
    }

    // =====================================================================
    // OVERRIDE (§56, §57, Rule 24)
    // =====================================================================

    /**
     * Override terkontrol: WAIVE_FEE (bebaskan biaya) atau FORCE_PROCESS
     * (proses tanpa pembayaran). Reason wajib, diaudit before/after.
     */
    public function override(LaundryOrder $order, string $type, string $reason, ?User $actor = null): LaundryOrder
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan override wajib diisi (Rule 24).']);
        }

        if (OrderStatus::isTerminal((string) $order->status)) {
            throw new LaundryDomainException("Order {$order->order_number} sudah berstatus final.");
        }

        $before = [
            'payment_status' => $order->payment_status,
            'discount_amount' => (float) $order->discount_amount,
            'allow_unpaid_processing' => (bool) $order->allow_unpaid_processing,
            'status' => $order->status,
        ];

        switch (strtoupper($type)) {
            case 'WAIVE_FEE':
                $order->discount_amount = $order->total_amount;
                $order->payment_status = 'WAIVED';
                $order->override_reason = $reason;
                $order->override_by = $actor?->id;
                $order->save();
                if ($order->status === OrderStatus::PAYMENT_REQUIRED || $order->status === OrderStatus::WEIGHED) {
                    $this->state->transitionTo($order, OrderStatus::PAID, $actor, "Waive fee: {$reason}", $order->order_number);
                }
                break;

            case 'FORCE_PROCESS':
                $order->allow_unpaid_processing = true;
                $order->override_reason = $reason;
                $order->override_by = $actor?->id;
                $order->save();
                break;

            default:
                throw ValidationException::withMessages([
                    'type' => 'Jenis override tidak dikenal. Gunakan WAIVE_FEE atau FORCE_PROCESS.',
                ]);
        }

        $this->audit->record('ORDER_OVERRIDE', $order, $actor, $before, [
            'payment_status' => $order->payment_status,
            'discount_amount' => (float) $order->discount_amount,
            'allow_unpaid_processing' => (bool) $order->allow_unpaid_processing,
        ], $reason, $order->order_number);

        return $order->fresh();
    }

    // =====================================================================
    // LABEL & TIMELINE (§31)
    // =====================================================================

    /** Label digital order: nomor + payload QR (dirender frontend sebagai QR). */
    public function ensureLabel(LaundryOrder $order, ?User $actor = null): LaundryLabel
    {
        $existing = LaundryLabel::query()->where('laundry_order_id', $order->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $payload = json_encode([
            'order_number' => $order->order_number,
            'student_id' => (int) $order->student_id,
            'student_nis' => $this->students->snapshot((int) $order->student_id)['nis'] ?? null,
            'bag_code' => $order->bag?->bag_code,
            'net_weight' => $order->net_weight !== null ? (float) $order->net_weight : null,
            'service' => $order->service?->code,
            'due_at' => $order->due_at?->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES);

        $label = LaundryLabel::create([
            'laundry_order_id' => $order->id,
            'label_number' => 'LBL-TMP',
            'qr_payload' => (string) $payload,
            'laundry_bag_id' => $order->laundry_bag_id,
            'printed_by' => $actor?->id,
        ]);
        $label->update(['label_number' => 'LBL-'.str_pad((string) $label->id, 6, '0', STR_PAD_LEFT)]);

        return $label->fresh();
    }

    /** Timeline lengkap satu order untuk traceability (pakaian masuk → kembali). */
    public function timeline(LaundryOrder $order): array
    {
        $order->loadMissing(['logs.actor:id,name', 'steps', 'payments', 'refunds', 'qualityChecks',
            'incidents', 'allocations', 'items', 'labels', 'pickups', 'deliveries']);

        return [
            'order' => $this->present($order),
            'status_logs' => $order->logs->map(fn ($log): array => [
                'from' => $log->from_status, 'to' => $log->to_status,
                'actor' => $log->actor?->name, 'reason' => $log->reason,
                'reference' => $log->reference, 'at' => $log->created_at?->toIso8601String(),
            ])->all(),
            'processing_steps' => $order->steps->map(fn ($step): array => [
                'step' => $step->step_type, 'status' => $step->status,
                'machine_id' => $step->laundry_machine_id, 'operator_id' => $step->operator_id,
                'started_at' => $step->started_at?->toIso8601String(),
                'ended_at' => $step->ended_at?->toIso8601String(),
                'notes' => $step->notes,
            ])->all(),
            'payments' => $order->payments->map(fn ($p): array => [
                'number' => $p->payment_number, 'amount' => (float) $p->amount,
                'method' => $p->method, 'status' => $p->status,
                'wallet_transaction' => $p->wallet_transaction_number,
                'balance_before' => $p->balance_before !== null ? (float) $p->balance_before : null,
                'balance_after' => $p->balance_after !== null ? (float) $p->balance_after : null,
                'paid_at' => $p->paid_at?->toIso8601String(),
            ])->all(),
            'refunds' => $order->refunds->map(fn ($r): array => [
                'number' => $r->refund_number, 'amount' => (float) $r->amount,
                'status' => $r->status, 'reason' => $r->reason,
                'wallet_transaction' => $r->wallet_transaction_number,
            ])->all(),
            'quality_checks' => $order->qualityChecks->map(fn ($qc): array => [
                'result' => $qc->result, 'checklist' => $qc->checklist,
                'notes' => $qc->notes, 'at' => $qc->checked_at?->toIso8601String(),
            ])->all(),
            'incidents' => $order->incidents->map(fn ($i): array => [
                'number' => $i->incident_number, 'type' => $i->incident_type,
                'status' => $i->status, 'description' => $i->description,
            ])->all(),
            'handover' => [
                'pickups' => $order->pickups->map(fn ($p): array => [
                    'receiver' => $p->receiver_name, 'method' => $p->verification_method,
                    'at' => $p->picked_up_at?->toIso8601String(),
                ])->all(),
                'deliveries' => $order->deliveries->map(fn ($d): array => [
                    'receiver' => $d->receiver_name, 'method' => $d->verification_method,
                    'dormitory_id' => $d->dormitory_id, 'at' => $d->delivered_at?->toIso8601String(),
                ])->all(),
            ],
        ];
    }

    // =====================================================================
    // PRESENTASI
    // =====================================================================

    public function presentSummary(LaundryOrder $order): array
    {
        return [
            'id' => (int) $order->id,
            'order_number' => (string) $order->order_number,
            'student_id' => (int) $order->student_id,
            'service' => $order->service?->name ?? null,
            'status' => (string) $order->status,
            'status_group' => OrderStatus::group((string) $order->status),
            'order_date' => $order->order_date?->toDateString(),
            'net_weight' => $order->net_weight !== null ? LaundryMath::quantity($order->net_weight) : null,
            'item_count' => (int) $order->item_count,
            'free_quantity' => LaundryMath::quantity($order->free_quantity),
            'paid_quantity' => LaundryMath::quantity($order->paid_quantity),
            'total_amount' => LaundryMath::money($order->total_amount),
            'payment_status' => (string) $order->payment_status,
            'due_at' => $order->due_at?->toIso8601String(),
        ];
    }

    public function present(LaundryOrder $order): array
    {
        $order->loadMissing(['student:id,full_name,nis', 'service', 'bag', 'items', 'allocations', 'labels']);

        return array_merge($this->presentSummary($order), [
            'student_name' => $order->student?->full_name,
            'student_nis' => $order->student?->nis,
            'dormitory_id' => $order->boarding_dormitory_id ? (int) $order->boarding_dormitory_id : null,
            'bag_code' => $order->bag?->bag_code,
            'gross_weight' => $order->gross_weight !== null ? LaundryMath::quantity($order->gross_weight) : null,
            'bag_weight' => $order->bag_weight !== null ? LaundryMath::quantity($order->bag_weight) : null,
            'quota_type' => $order->quota_type,
            'discount_amount' => LaundryMath::money($order->discount_amount),
            'wallet_transaction_number' => $order->wallet_transaction_number,
            'pickup_method' => $order->pickup_method,
            'notes' => $order->notes,
            'received_at' => $order->received_at?->toIso8601String(),
            'weighed_at' => $order->weighed_at?->toIso8601String(),
            'completed_at' => $order->completed_at?->toIso8601String(),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $order->cancellation_reason,
            'pricing_snapshot' => $order->pricing_snapshot,
            'items' => $order->items->map(fn (LaundryOrderItem $i): array => [
                'id' => (int) $i->id, 'item_type' => (string) $i->item_type,
                'quantity' => (int) $i->quantity, 'color' => $i->color,
                'condition' => $i->condition, 'unit_price' => $i->unit_price !== null ? (float) $i->unit_price : null,
                'tag_code' => $i->tag_code, 'notes' => $i->notes,
            ])->all(),
            'allocations' => $order->allocations->map(fn (LaundryOrderAllocation $a): array => [
                'type' => (string) $a->allocation_type,
                'quantity' => LaundryMath::quantity($a->quantity),
                'amount' => LaundryMath::money($a->amount),
                'entitlement_transaction_id' => $a->entitlement_transaction_id ? (int) $a->entitlement_transaction_id : null,
            ])->all(),
            'labels' => $order->labels->map(fn (LaundryLabel $l): array => [
                'label_number' => (string) $l->label_number, 'qr_payload' => (string) $l->qr_payload,
            ])->all(),
        ]);
    }

    // =====================================================================
    // INTERNAL
    // =====================================================================

    private function orderNumber(LaundryOrder $order): string
    {
        $prefix = (string) config('laundry.numbering.order_prefix', 'LDR');

        return $prefix.'-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT);
    }

    private function itemCount(LaundryOrder $order): int
    {
        return (int) LaundryOrderItem::query()->where('laundry_order_id', $order->id)->sum('quantity');
    }

    private function attachBag(LaundryOrder $order, array $data): ?LaundryBag
    {
        $bag = null;
        if (! empty($data['laundry_bag_id'])) {
            $bag = LaundryBag::query()->find((int) $data['laundry_bag_id']);
        } elseif (! empty($data['bag_code'])) {
            $bag = LaundryBag::query()
                ->where(fn ($q) => $q->where('bag_code', $data['bag_code'])
                    ->orWhere('rfid_uid', $data['bag_code'])
                    ->orWhere('qr_code', $data['bag_code']))
                ->first();
        }

        if ($bag !== null && in_array($bag->status, ['RETIRED', 'REPAIR'], true)) {
            throw ValidationException::withMessages([
                'bag' => "Tas {$bag->bag_code} berstatus {$bag->status} dan tidak dapat dipakai.",
            ]);
        }

        if ($bag !== null) {
            $bag->update(['status' => 'IN_USE']);
        }

        return $bag;
    }

    private function releaseBag(LaundryOrder $order): void
    {
        if ($order->laundry_bag_id === null) {
            return;
        }

        $bag = LaundryBag::query()->find($order->laundry_bag_id);
        if ($bag !== null && $bag->status === 'IN_USE') {
            // Kembali ke ASSIGNED bila masih dipegang santri, selain itu AVAILABLE.
            $hasActiveAssignment = DB::table('laundry_bag_assignments')
                ->where('laundry_bag_id', $bag->id)
                ->where('status', 'ACTIVE')
                ->exists();
            $bag->update(['status' => $hasActiveAssignment ? 'ASSIGNED' : 'AVAILABLE']);
        }
    }

    private function maxFreeWeightFor(int $studentId): ?float
    {
        $entitlement = $this->entitlements->ensurePeriod($studentId);

        return $entitlement?->policyVersion?->max_free_weight_per_order !== null
            ? LaundryMath::quantity($entitlement->policyVersion->max_free_weight_per_order)
            : null;
    }
}
