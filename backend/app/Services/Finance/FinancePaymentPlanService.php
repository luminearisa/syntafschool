<?php

namespace App\Services\Finance;

use App\Models\FinanceInvoice;
use App\Models\FinancePaymentPlan;
use App\Models\FinancePaymentPlanInstallment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payment plan / cicilan (§7).
 *
 * Dua kalimat §7 yang menentukan seluruh desain di sini: "Payment plan tidak
 * membuat revenue baru" dan "Payment tetap dialokasikan ke invoice asli".
 * Karena itu service ini:
 *  - tidak pernah menyentuh invoice.paid_amount, tidak membuat payment,
 *    allocation, maupun jurnal — ia hanya membagi sisa tagihan yang sudah ada;
 *  - menghitung progress angsuran DARI alokasi payment confirmed pada invoice
 *    (dikurangi saldo saat plan dibuat), bukan dari angka yang diketik orang.
 *
 * Konsekuensi desain yang disengaja: tidak ada kaitan ke FinancePaymentService.
 * Mesin pembayaran tidak perlu tahu ada cicilan — uang masuk seperti biasa, dan
 * jadwalnya hanyalah cara membaca pelunasan itu. Menaruh hook di sana akan
 * menaruh satu kebenaran di dua tempat.
 *
 * Fitur ini juga bisa ditutup lewat konfigurasi (§7: "jika diizinkan
 * konfigurasi"): `payment_plan_enabled`, `payment_plan_max_installments`,
 * `payment_plan_min_amount`.
 */
class FinancePaymentPlanService
{
    /** §7 contoh 3 × Rp2.000.000: satu angsuran bukan rencana, jadi minimal dua. */
    public const MIN_INSTALLMENTS = 2;

    public const INTERVAL_DAY = 'day';

    public const INTERVAL_WEEK = 'week';

    public const INTERVAL_MONTH = 'month';

    public const INTERVALS = [
        self::INTERVAL_DAY => 'Harian',
        self::INTERVAL_WEEK => 'Mingguan',
        self::INTERVAL_MONTH => 'Bulanan',
    ];

    public function __construct(
        private readonly FinanceSettingsService $settings,
        private readonly FinanceNumberingService $numbering,
    ) {}

    /**
     * Nilai konfigurasi yang dibutuhkan UI — termasuk apakah fitur cicilan ini
     * boleh ditawarkan sama sekali (§7).
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return [
            'enabled' => $this->enabled(),
            'max_installments' => $this->maxInstallments(),
            'min_amount' => $this->minAmount(),
            'min_installments' => self::MIN_INSTALLMENTS,
            'intervals' => self::INTERVALS,
        ];
    }

    /**
     * Buat rencana cicilan untuk satu invoice.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): FinancePaymentPlan
    {
        $this->assertEnabled();

        $installmentCount = (int) ($data['installment_count'] ?? 0);
        $this->assertInstallmentCount($installmentCount);

        $interval = (string) ($data['interval'] ?? self::INTERVAL_MONTH);

        if (! array_key_exists($interval, self::INTERVALS)) {
            throw ValidationException::withMessages([
                'interval' => 'Interval angsuran harus salah satu dari: '.implode(', ', array_keys(self::INTERVALS)).'.',
            ]);
        }

        $intervalEvery = max(1, min(12, (int) ($data['interval_every'] ?? 1)));
        $invoiceId = (int) ($data['invoice_id'] ?? 0);

        if ($invoiceId <= 0) {
            throw ValidationException::withMessages(['invoice_id' => 'Invoice yang akan dicicil wajib dipilih.']);
        }

        return DB::transaction(function () use ($data, $actor, $invoiceId, $installmentCount, $interval, $intervalEvery): FinancePaymentPlan {
            // Kunci di baris invoice, bukan di plan: yang dilarang §7 adalah dua
            // rencana aktif atas tagihan yang sama, dan dua permintaan bersamaan
            // atas invoice itulah yang harus diurutkan.
            $invoice = FinanceInvoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();

            $this->assertInvoiceTaggable($invoice);
            $this->assertNoActivePlan($invoice);

            $total = round((float) ($data['total_amount'] ?? $invoice->remaining_amount), 2);
            $this->assertAmount($total, $invoice);

            $startDate = $this->startDate($data['start_date'] ?? null);
            $dueDates = $this->schedule($startDate, $installmentCount, $interval, $intervalEvery);
            $amounts = $this->split($total, $installmentCount);

            $plan = FinancePaymentPlan::create([
                'plan_number' => $this->numbering->next(FinanceNumberingService::CODE_PAYMENT_PLAN),
                'invoice_id' => (int) $invoice->id,
                'student_id' => $invoice->student_id,
                'cost_center_id' => $invoice->cost_center_id,
                'total_amount' => $total,
                'installment_count' => $installmentCount,
                'start_date' => $startDate->toDateString(),
                // Pembayaran yang lebih tua dari rencana bukan bagian darinya.
                'baseline_paid_amount' => round((float) $invoice->paid_amount, 2),
                'status' => FinancePaymentPlan::STATUS_PLANNED,
                'notes' => filled($data['notes'] ?? null) ? (string) $data['notes'] : null,
                'created_by' => $actor?->id,
            ]);

            foreach ($dueDates as $index => $dueDate) {
                $plan->installments()->create([
                    'sequence' => $index + 1,
                    'due_date' => $dueDate->toDateString(),
                    'amount' => $amounts[$index],
                    'paid_amount' => 0,
                    'outstanding_amount' => $amounts[$index],
                    'status' => FinancePaymentPlan::STATUS_PLANNED,
                ]);
            }

            // Sinkronkan sekali di tempat: angsuran yang jatuh temponya sudah
            // lewat saat plan dibuat tidak boleh tercatat sebagai "terencana",
            // dan invoice yang sudah ada bayarannya tetap nol progress-nya.
            return $this->sync($plan->fresh(['installments', 'invoice', 'student', 'createdBy']));
        });
    }

    /**
     * Hitung ulang progress sebuah plan dari pelunasan invoice-nya.
     *
     * Dibaca sebagai "berapa rupiah invoice ini dilunasi SEJAK cicilan disepakati",
     * lalu dibagi berurutan: angsuran 1 lebih dulu, dan seterusnya. Uang yang sama
     * tidak dihitung dua kali — sumbernya tetap satu, yaitu `paid_amount` invoice
     * yang dijaga mesin pembayaran (§17 Phase 2).
     *
     * Baris hanya ditulis kalau angkanya benar-benar berubah, jadi tabel plan dan
     * API tidak pernah berbeda cerita sementara jejak audit tidak berisi refresh
     * kosong. Plan yang sudah dibatalkan tidak pernah dihitung ulang: pembatalan
     * membekukan jadwalnya.
     */
    public function sync(FinancePaymentPlan $plan, ?Carbon $asOf = null): FinancePaymentPlan
    {
        if ($plan->isCancelled()) {
            return $plan;
        }

        $day = ($asOf ?? Carbon::now())->copy()->startOfDay();
        $plan->loadMissing(['installments', 'invoice']);

        $invoice = $plan->getRelation('invoice');

        if (! $invoice instanceof FinanceInvoice || $plan->installments->isEmpty()) {
            return $plan;
        }

        $applied = round((float) $invoice->paid_amount - (float) $plan->baseline_paid_amount, 2);
        $toAllocate = max(0.0, min($applied, round((float) $plan->total_amount, 2)));

        foreach ($plan->installments as $installment) {
            $paid = round(min($toAllocate, (float) $installment->amount), 2);
            $toAllocate = round($toAllocate - $paid, 2);
            $outstanding = round((float) $installment->amount - $paid, 2);
            $status = $this->installmentStatus($installment, $paid, $outstanding, $day);

            if (round((float) $installment->paid_amount, 2) !== $paid
                || round((float) $installment->outstanding_amount, 2) !== $outstanding
                || $installment->status !== $status) {
                $installment->forceFill([
                    'paid_amount' => $paid,
                    'outstanding_amount' => $outstanding,
                    'status' => $status,
                ])->save();
            }
        }

        $status = $this->planStatus($plan, $day);

        if ($plan->status !== $status) {
            // Write senyap: ini proyeksi, bukan keputusan seseorang. Kalau tidak,
            // refresh yang dipicu pembacaan — termasuk oleh auditor yang hanya
            // berhak baca — akan tercatat di audit trail seolah dia yang
            // melunasi cicilan. Yang diaudit hanyalah plan dibuat dan dibatalkan.
            $plan->status = $status;
            $plan->saveQuietly();
        }

        return $plan;
    }

    /** @param  array<string, mixed>  $filters */
    public function query(array $filters = []): Builder
    {
        return FinancePaymentPlan::query()
            ->with([
                'invoice:id,invoice_number,customer_type,customer_id,due_date,total,paid_amount,written_off_amount,remaining_amount,status',
                'student:id,full_name,nis',
                'costCenter:id,name',
                'installments',
                'createdBy:id,name',
            ])
            ->when(filled($filters['invoice_id'] ?? null), fn (Builder $q) => $q->where('invoice_id', (int) $filters['invoice_id']))
            ->when(filled($filters['student_id'] ?? null), fn (Builder $q) => $q->where('student_id', (int) $filters['student_id']))
            ->when(filled($filters['cost_center_id'] ?? null), fn (Builder $q) => $q->where('cost_center_id', (int) $filters['cost_center_id']))
            ->when(filled($filters['status'] ?? null), fn (Builder $q) => $q->where('status', (string) $filters['status']))
            ->when($this->isTrue($filters['active_only'] ?? null), fn (Builder $q) => $q->active())
            ->when(filled($filters['search'] ?? null), function (Builder $q) use ($filters): void {
                $term = trim((string) $filters['search']);
                $q->where(fn (Builder $inner) => $inner
                    ->where('plan_number', 'like', "%$term%")
                    ->orWhereHas('invoice', fn (Builder $i) => $i->where('invoice_number', 'like', "%$term%"))
                    ->orWhereHas('student', fn (Builder $s) => $s->where('full_name', 'like', "%$term%")->orWhere('nis', 'like', "%$term%")));
            })
            ->orderBy('status')
            ->orderBy('id');
    }

    /**
     * Daftar plan beserta progress terbaru. Sync dilakukan per baris yang tampil
     * (bukan seluruh tabel) supaya halaman list seharga satu halaman datanya.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, FinancePaymentPlan>
     */
    public function plans(array $filters = []): Collection
    {
        $plans = $this->query($filters)
            ->limit(max(1, min(1000, (int) ($filters['limit'] ?? 200))))
            ->get();

        return $plans->each(fn (FinancePaymentPlan $plan) => $this->sync($plan));
    }

    public function show(FinancePaymentPlan $plan): FinancePaymentPlan
    {
        $plan->loadMissing(['installments', 'invoice', 'student', 'costCenter', 'createdBy', 'cancelledBy']);

        return $this->sync($plan);
    }

    /**
     * Batalkan sebuah rencana (§7 status CANCELLED).
     *
     * Yang batal hanya jadwal sisanya. Pembayaran yang sudah masuk tetap menjadi
     * pelunasan invoice — membaliknya akan berarti plan bisa menghapus uang, dan
     * itu bukan wewenang sebuah jadwal. Karena itu `paid_amount` angsuran yang
     * sudah terisi dibiarkan apa adanya.
     *
     * @param  array<string, mixed>  $data
     */
    public function cancel(FinancePaymentPlan $plan, array $data, ?User $actor = null): FinancePaymentPlan
    {
        $reason = trim((string) ($data['reason'] ?? ''));

        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages([
                'reason' => 'Alasan pembatalan wajib diisi (minimal 5 karakter) — pembatalan jadwal cicilan harus terbaca alasannya di audit trail.',
            ]);
        }

        return DB::transaction(function () use ($plan, $reason, $actor): FinancePaymentPlan {
            $locked = FinancePaymentPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

            if ($locked->isCancelled()) {
                throw ValidationException::withMessages(['status' => 'Rencana pembayaran ini sudah dibatalkan.']);
            }

            if ($locked->status === FinancePaymentPlan::STATUS_PAID) {
                throw ValidationException::withMessages([
                    'status' => 'Cicilan '.$locked->plan_number.' sudah lunas; tidak ada jadwal yang bisa dibatalkan.',
                ]);
            }

            $locked->loadMissing('installments');

            // Sinkron dulu: angsuran yang uangnya sudah masuk harus terlihat lunas
            // saat jadwalnya dibubarkan, bukan ikut terbawa batal.
            $this->sync($locked);

            foreach ($locked->installments as $installment) {
                if ($installment->isSettled()) {
                    continue;
                }

                $installment->forceFill(['status' => FinancePaymentPlan::STATUS_CANCELLED])->save();
            }

            $locked->forceFill([
                'status' => FinancePaymentPlan::STATUS_CANCELLED,
                'cancelled_by' => $actor?->id,
                'cancelled_at' => Carbon::now(),
                'cancellation_reason' => mb_substr($reason, 0, 500),
            ])->save();

            return $locked->fresh(['installments', 'invoice', 'student', 'cancelledBy']);
        });
    }

    /**
     * Pembagian nominal angsuran. Sisa pembulatan jatuh ke angsuran terakhir
     * supaya SUM angsuran selalu sama persis dengan total — kalau sisanya
     * disebar, plan Rp6.000.000 bisa berujung menagih Rp6.000.003.
     *
     * @return list<float>
     */
    public function split(float $total, int $count): array
    {
        $base = floor(round($total / $count, 4) * 100) / 100;
        $amounts = [];

        for ($i = 0; $i < $count - 1; $i++) {
            $amounts[] = $base;
        }

        $amounts[] = round($total - $base * ($count - 1), 2);

        return $amounts;
    }

    // ==================== Internal ====================

    /**
     * @return list<Carbon>
     */
    protected function schedule(Carbon $start, int $count, string $interval, int $every): array
    {
        $dates = [$start->copy()];

        for ($i = 1; $i < $count; $i++) {
            // addMonthsNoOverflow: cicilan "setiap tanggal 31" tidak boleh
            // melompat ke awal bulan berikutnya hanya karena bulannya 30 hari.
            $dates[] = match ($interval) {
                self::INTERVAL_DAY => $dates[$i - 1]->copy()->addDays($every),
                self::INTERVAL_WEEK => $dates[$i - 1]->copy()->addWeeks($every),
                default => $dates[$i - 1]->copy()->addMonthsNoOverflow($every),
            };
        }

        return $dates;
    }

    protected function installmentStatus(FinancePaymentPlanInstallment $installment, float $paid, float $outstanding, Carbon $day): string
    {
        // Tangga prioritas, sengaja berurutan (lihat docblock model):
        // lunas > terlambat > sebagian > hari-H > belum waktunya.
        // Angsuran yang lewat tempo DAN baru dibayar sebagian dihitung TERLAMBAT,
        // karena yang dibaca halaman penagihan (§6) adalah keterlambatannya.
        return match (true) {
            $outstanding <= FinancePaymentPlan::EPSILON => FinancePaymentPlan::STATUS_PAID,
            $installment->due_date->lt($day) => FinancePaymentPlan::STATUS_OVERDUE,
            $paid > FinancePaymentPlan::EPSILON => FinancePaymentPlan::STATUS_PARTIAL,
            $installment->due_date->lte($day) => FinancePaymentPlan::STATUS_DUE,
            default => FinancePaymentPlan::STATUS_PLANNED,
        };
    }

    protected function planStatus(FinancePaymentPlan $plan, Carbon $day): string
    {
        $installments = $plan->installments;

        return match (true) {
            $installments->every(fn (FinancePaymentPlanInstallment $i) => $i->isSettled()) => FinancePaymentPlan::STATUS_PAID,
            $installments->contains('status', FinancePaymentPlan::STATUS_OVERDUE) => FinancePaymentPlan::STATUS_OVERDUE,
            $installments->sum('paid_amount') > FinancePaymentPlan::EPSILON => FinancePaymentPlan::STATUS_PARTIAL,
            $installments->contains(fn (FinancePaymentPlanInstallment $i) => $i->due_date->lte($day)) => FinancePaymentPlan::STATUS_DUE,
            default => FinancePaymentPlan::STATUS_PLANNED,
        };
    }

    protected function assertEnabled(): void
    {
        if ($this->enabled()) {
            return;
        }

        throw ValidationException::withMessages([
            'payment_plan_enabled' => 'Rencana pembayaran sedang tidak diaktifkan. Buka lewat Settings → Keuangan (kunci payment_plan_enabled).',
        ]);
    }

    protected function assertInstallmentCount(int $count): void
    {
        $max = $this->maxInstallments();

        if ($count < self::MIN_INSTALLMENTS || $count > $max) {
            throw ValidationException::withMessages([
                'installment_count' => 'Jumlah angsuran harus antara '.self::MIN_INSTALLMENTS." dan {$max} (konfigurasi payment_plan_max_installments).",
            ]);
        }
    }

    protected function assertInvoiceTaggable(FinanceInvoice $invoice): void
    {
        if ($invoice->status === FinanceInvoice::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'invoice_id' => "Invoice {$invoice->invoice_number} masih draft; terbitkan dulu sebelum menjadwalkan cicilan.",
            ]);
        }

        if ($invoice->status === FinanceInvoice::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'invoice_id' => "Invoice {$invoice->invoice_number} sudah dibatalkan; tidak ada tagihan untuk dicicil.",
            ]);
        }

        if ($invoice->isSettled()) {
            throw ValidationException::withMessages([
                'invoice_id' => "Invoice {$invoice->invoice_number} sudah selesai; cicilan hanya untuk sisa tagihan yang masih ada.",
            ]);
        }
    }

    protected function assertNoActivePlan(FinanceInvoice $invoice): void
    {
        $existing = FinancePaymentPlan::query()
            ->where('invoice_id', (int) $invoice->id)
            ->active()
            ->first();

        if ($existing === null) {
            return;
        }

        throw ValidationException::withMessages([
            'invoice_id' => "Invoice {$invoice->invoice_number} sudah punya rencana pembayaran aktif ({$existing->plan_number}). Batalkan dulu bila jadwalnya mau disusun ulang.",
        ]);
    }

    protected function assertAmount(float $total, FinanceInvoice $invoice): void
    {
        if ($total <= FinancePaymentPlan::EPSILON) {
            throw ValidationException::withMessages(['total_amount' => 'Nilai yang dicicil harus lebih besar dari nol.']);
        }

        if ($total < $this->minAmount() - FinancePaymentPlan::EPSILON) {
            $min = $this->minAmount();

            throw ValidationException::withMessages([
                'total_amount' => 'Tagihan sebesar '.number_format($total, 0, ',', '.').' di bawah batas minimum cicilan '.number_format($min, 0, ',', '.').' (konfigurasi payment_plan_min_amount).',
            ]);
        }

        $remaining = round((float) $invoice->remaining_amount, 2);

        if ($total > $remaining + FinancePaymentPlan::EPSILON) {
            // §7: plan membagi tagihan yang ada, bukan menciptakan tagihan baru.
            throw ValidationException::withMessages([
                'total_amount' => 'Nilai cicilan tidak boleh melebihi sisa tagihan invoice ini ('.number_format($remaining, 0, ',', '.').').',
            ]);
        }
    }

    protected function startDate(mixed $value): Carbon
    {
        if (filled($value)) {
            return Carbon::parse((string) $value)->startOfDay();
        }

        return Carbon::today();
    }

    protected function isTrue(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    protected function enabled(): bool
    {
        return (bool) $this->settings->get('payment_plan_enabled', false);
    }

    protected function maxInstallments(): int
    {
        return max(self::MIN_INSTALLMENTS, (int) $this->settings->get('payment_plan_max_installments', 12));
    }

    protected function minAmount(): float
    {
        return round((float) $this->settings->get('payment_plan_min_amount', 0), 2);
    }
}
