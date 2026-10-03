<?php

namespace App\Modules\SchoolOperations\Laundry\Application\Services;

use App\Models\User;
use App\Modules\SchoolOperations\Laundry\Domain\Events\LaundryQuotaAdjusted;
use App\Modules\SchoolOperations\Laundry\Domain\Support\LaundryMath;
use App\Modules\SchoolOperations\Laundry\Domain\Support\LaundryPeriod;
use App\Modules\SchoolOperations\Laundry\Models\LaundryEntitlement;
use App\Modules\SchoolOperations\Laundry\Models\LaundryEntitlementTransaction;
use App\Modules\SchoolOperations\Laundry\Models\LaundryPolicyVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Jatah gratis santri (§6–§8, §13, §14).
 *
 * Prinsip:
 * - Periode baru → entitlement baru; entitlement historis tidak pernah diubah
 *   (Rule 22).
 * - Setiap pergerakan jatah (ALLOCATE/CARRY_IN/USE/ADJUST/EXPIRE/REVERSE)
 *   tercatat di ledger dengan rantai `balance_after` (Rule 21).
 * - Sisa tidak pernah negatif (Rule 6); pemakaian mengunci baris entitlement
 *   sehingga dua order bersamaan tidak bisa memakai jatah yang sama.
 * - Carry-over mengikuti policy: NO/FULL/LIMITED (§8).
 */
class LaundryEntitlementService
{
    public function __construct(
        private readonly LaundryPolicyService $policies,
        private readonly LaundryAuditService $audit,
    ) {
    }

    // =====================================================================
    // PEMBACAAN
    // =====================================================================

    /**
     * Status jatah santri pada tanggal tertentu (siap tampil di portal):
     * allocated/used/remaining + periode. Tanpa policy → kuota nol.
     */
    public function currentState(int $studentId, ?string $onDate = null): array
    {
        $date = CarbonImmutable::parse($onDate ?: now()->toDateString());
        $entitlement = $this->ensurePeriod($studentId, $date);

        if ($entitlement === null) {
            return [
                'entitlement_id' => null,
                'has_policy' => false,
                'quota_type' => null,
                'period' => null,
                'allocated' => 0.0,
                'carry_in' => 0.0,
                'adjusted' => 0.0,
                'used' => 0.0,
                'remaining' => 0.0,
            ];
        }

        return $this->present($entitlement);
    }

    public function present(LaundryEntitlement $entitlement): array
    {
        return [
            'entitlement_id' => (int) $entitlement->id,
            'has_policy' => true,
            'quota_type' => (string) $entitlement->quota_type,
            'period' => [
                'type' => (string) $entitlement->period_type,
                'key' => (string) $entitlement->period_key,
                'start' => $entitlement->period_start?->toDateString(),
                'end' => $entitlement->period_end?->toDateString(),
            ],
            'allocated' => LaundryMath::quantity($entitlement->allocated_quota),
            'carry_in' => LaundryMath::quantity($entitlement->carry_in_quota),
            'adjusted' => LaundryMath::quantity($entitlement->adjusted_quota),
            'used' => LaundryMath::quantity($entitlement->used_quota),
            'remaining' => $entitlement->remaining(),
            'status' => (string) $entitlement->status,
        ];
    }

    /** Riwayat entitlement santri (semua periode). */
    public function historyFor(int $studentId): array
    {
        return LaundryEntitlement::query()
            ->where('student_id', $studentId)
            ->orderByDesc('period_start')
            ->get()
            ->map(fn (LaundryEntitlement $e): array => $this->present($e))
            ->all();
    }

    /** Ledger entitlement (§13) — semua baris untuk satu santri/periode. */
    public function transactionsFor(int $studentId, ?string $periodKey = null): array
    {
        return LaundryEntitlementTransaction::query()
            ->where('student_id', $studentId)
            ->when($periodKey, function ($q) use ($periodKey): void {
                $q->whereHas('entitlement', fn ($e) => $e->where('period_key', $periodKey));
            })
            ->orderBy('id')
            ->get()
            ->map(fn (LaundryEntitlementTransaction $t): array => [
                'id' => (int) $t->id,
                'type' => (string) $t->transaction_type,
                'amount' => LaundryMath::quantity($t->amount),
                'balance_after' => LaundryMath::quantity($t->balance_after),
                'period_key' => $t->entitlement?->period_key,
                'reference_type' => $t->reference_type,
                'reference_id' => $t->reference_id ? (int) $t->reference_id : null,
                'reason' => $t->reason,
                'occurred_at' => $t->occurred_at?->toIso8601String(),
            ])
            ->all();
    }

    // =====================================================================
    // ALOKASI PERIODE (§7) & CARRY OVER (§8)
    // =====================================================================

    /**
     * Pastikan entitlement periode berjalan ada untuk santri; buat bila belum
     * (termasuk carry-over dari periode sebelumnya) dan kedaluwarsakan periode
     * lama. Idempoten — aman dipanggil berulang.
     */
    public function ensurePeriod(int $studentId, ?CarbonImmutable $date = null): ?LaundryEntitlement
    {
        $date = $date ?? CarbonImmutable::now();

        $version = $this->policies->resolveForStudent($studentId, $date->toDateString());

        return DB::transaction(function () use ($studentId, $date, $version): ?LaundryEntitlement {
            if ($version === null) {
                // Tanpa policy: tutup entitlement lama yang masih terbuka.
                $this->expireStale($studentId, $date);

                return null;
            }

            $period = LaundryPeriod::resolve(
                (string) $version->period_type,
                $date,
                [
                    'start' => $version->custom_period_start?->toDateString(),
                    'end' => $version->custom_period_end?->toDateString(),
                ]
            );

            $existing = LaundryEntitlement::query()
                ->where('student_id', $studentId)
                ->where('period_key', $period['key'])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $this->expireStale($studentId, $date, (int) $existing->id);

                return $existing;
            }

            try {
                $entitlement = $this->allocate($studentId, $version, $period, $date);
            } catch (\Illuminate\Database\QueryException) {
                // Balapan dua request bersamaan: unique (student, period_key)
                // menjamin satu baris — baca ulang hasilnya.
                $entitlement = LaundryEntitlement::query()
                    ->where('student_id', $studentId)
                    ->where('period_key', $period['key'])
                    ->first();
            }

            $this->expireStale($studentId, $date, $entitlement !== null ? (int) $entitlement->id : null);

            return $entitlement;
        });
    }

    /** Alokasi entitlement baru + carry over dari periode sebelumnya. */
    private function allocate(int $studentId, LaundryPolicyVersion $version, array $period, CarbonImmutable $date): LaundryEntitlement
    {
        [$carryIn, $previous] = $this->carryOverFrom($studentId, $version, $period, $date);

        $entitlement = LaundryEntitlement::create([
            'student_id' => $studentId,
            'laundry_policy_id' => $version->laundry_policy_id,
            'laundry_policy_version_id' => $version->id,
            'period_type' => $period['type'],
            'period_key' => $period['key'],
            'period_start' => $period['start'],
            'period_end' => $period['end'],
            'quota_type' => $version->quota_type,
            'allocated_quota' => LaundryMath::quantity($version->quota_amount),
            'carry_in_quota' => $carryIn,
            'adjusted_quota' => 0,
            'used_quota' => 0,
            'status' => 'ACTIVE',
        ]);

        $this->ledger($entitlement, 'ALLOCATE', LaundryMath::quantity($version->quota_amount), [
            'reason' => "Alokasi periode {$period['key']} (policy v{$version->version})",
        ]);

        if ($carryIn > 0 && $previous !== null) {
            $this->ledger($entitlement, 'CARRY_IN', $carryIn, [
                'reason' => "Sisa periode {$previous->period_key} dibawa ke {$period['key']}",
                'reference_type' => LaundryEntitlement::class,
                'reference_id' => (int) $previous->id,
            ]);
        }

        // Periode sebelumnya ditutup: yang tidak terbawa dicatat EXPIRE.
        if ($previous !== null) {
            $this->closePrevious($previous, $carryIn);
        }

        return $entitlement;
    }

    /** Tutup entitlement periode sebelumnya; EXPIRE hanya untuk sisa tak terbawa. */
    private function closePrevious(LaundryEntitlement $previous, float $carried): void
    {
        if ($previous->status !== 'ACTIVE') {
            return;
        }

        $expired = LaundryMath::quantity(max(0.0, $previous->remaining() - $carried));

        $previous->status = 'EXPIRED';
        $previous->expired_at = now();
        $previous->save();

        if ($expired > 0) {
            $this->ledger($previous, 'EXPIRE', -$expired, [
                'reason' => "Sisa jatah periode {$previous->period_key} kedaluwarsa (tidak dibawa)",
                'balance_after' => 0.0,
            ]);
        }
    }

    /**
     * Hitung carry-over (§8) dari entitlement periode sebelumnya yang cocok.
     *
     * @return array{0: float, 1: LaundryEntitlement|null}
     */
    private function carryOverFrom(int $studentId, LaundryPolicyVersion $version, array $period, CarbonImmutable $date): array
    {
        if ($version->carry_over_mode === 'NO_CARRY_OVER') {
            return [0.0, null];
        }

        $previousPeriod = LaundryPeriod::previous(
            (string) $version->period_type,
            $date,
            ['start' => $version->custom_period_start?->toDateString(), 'end' => $version->custom_period_end?->toDateString()]
        );

        $previous = LaundryEntitlement::query()
            ->where('student_id', $studentId)
            ->where('period_key', $previousPeriod['key'])
            ->lockForUpdate()
            ->first();

        // Periode lama yang sudah ditutup (EXPIRED) berarti sisanya sudah
        // diproses sebelumnya — tidak boleh dibawa dua kali.
        if ($previous === null || $previous->status !== 'ACTIVE') {
            return [0.0, null];
        }

        $remaining = $previous->remaining();
        $carry = match ((string) $version->carry_over_mode) {
            'FULL_CARRY_OVER' => $remaining,
            'LIMITED_CARRY_OVER' => min($remaining, LaundryMath::quantity($version->carry_over_limit ?? 0)),
            default => 0.0,
        };

        return [LaundryMath::quantity($carry), $previous];
    }

    /** Tandai entitlement ACTIVE yang periodenya sudah lewat sebagai EXPIRED + ledger. */
    private function expireStale(int $studentId, CarbonImmutable $date, ?int $exceptId = null): void
    {
        $stale = LaundryEntitlement::query()
            ->where('student_id', $studentId)
            ->where('status', 'ACTIVE')
            ->where('period_end', '<', $date->toDateString())
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->lockForUpdate()
            ->get();

        foreach ($stale as $entitlement) {
            $this->expire($entitlement);
        }
    }

    /**
     * Kedaluwarsakan satu entitlement: sisa yang tidak terpakai dicatat EXPIRE
     * (negatif, balance_after 0) dan status ditutup. Angka historis lain tetap.
     */
    public function expire(LaundryEntitlement $entitlement, ?User $actor = null): LaundryEntitlement
    {
        if ($entitlement->status !== 'ACTIVE') {
            return $entitlement;
        }

        $remaining = $entitlement->remaining();
        $entitlement->status = 'EXPIRED';
        $entitlement->expired_at = now();
        $entitlement->save();

        if ($remaining > 0) {
            $this->ledger($entitlement, 'EXPIRE', -$remaining, [
                'reason' => "Sisa jatah periode {$entitlement->period_key} kedaluwarsa",
                'actor_id' => $actor?->id,
                'balance_after' => 0.0,
            ]);
        }

        return $entitlement;
    }

    // =====================================================================
    // PEMAKAIAN & PENGEMBALIAN (dipanggil LaundryOrderService)
    // =====================================================================

    /**
     * Pakai jatah untuk satu order: kunci baris, potong maksimal sebesar sisa,
     * catat ledger USE. Mengembalikan jumlah yang benar-benar terpakai.
     */
    public function consume(LaundryEntitlement $entitlement, float $quantity, array $reference = [], ?User $actor = null): float
    {
        $quantity = LaundryMath::quantity($quantity);
        if ($quantity <= 0) {
            return 0.0;
        }

        return DB::transaction(function () use ($entitlement, $quantity, $reference, $actor): float {
            /** @var LaundryEntitlement $locked */
            $locked = LaundryEntitlement::query()->whereKey($entitlement->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, ['ACTIVE', 'EXHAUSTED'], true)) {
                throw ValidationException::withMessages([
                    'entitlement' => "Jatah periode {$locked->period_key} tidak aktif ({$locked->status}).",
                ]);
            }

            $use = LaundryMath::quantity(min($locked->remaining(), $quantity));
            if ($use <= 0) {
                return 0.0;
            }

            $locked->used_quota = LaundryMath::quantity((float) $locked->used_quota + $use);
            if ($locked->remaining() <= 0) {
                $locked->status = 'EXHAUSTED';
            }
            $locked->save();

            $this->ledger($locked, 'USE', -$use, array_merge($reference, ['actor_id' => $actor?->id]));

            // Segarkan model pemanggil.
            $entitlement->setRawAttributes($locked->getAttributes(), true);

            return $use;
        });
    }

    /**
     * Kembalikan jatah order yang dibatalkan (Rule: reverse lewat ledger,
     * `used_quota` berkurang, status EXHAUSTED dipulihkan ke ACTIVE).
     */
    public function reverse(LaundryEntitlement $entitlement, float $quantity, array $reference = [], ?User $actor = null): float
    {
        $quantity = LaundryMath::quantity($quantity);
        if ($quantity <= 0) {
            return 0.0;
        }

        return DB::transaction(function () use ($entitlement, $quantity, $reference, $actor): float {
            /** @var LaundryEntitlement $locked */
            $locked = LaundryEntitlement::query()->whereKey($entitlement->id)->lockForUpdate()->firstOrFail();

            $used = LaundryMath::quantity($locked->used_quota);
            $back = LaundryMath::quantity(min($used, $quantity));
            if ($back <= 0) {
                return 0.0;
            }

            $locked->used_quota = $used - $back;
            if ($locked->status === 'EXHAUSTED' && $locked->remaining() > 0) {
                $locked->status = 'ACTIVE';
            }
            $locked->save();

            $this->ledger($locked, 'REVERSE', $back, array_merge($reference, [
                'actor_id' => $actor?->id,
                'reason' => $reference['reason'] ?? 'Pembatalan order laundry',
            ]));

            $entitlement->setRawAttributes($locked->getAttributes(), true);

            return $back;
        });
    }

    // =====================================================================
    // ADJUSTMENT (§14, Rule 15/24)
    // =====================================================================

    /**
     * Penyesuaian jatah oleh admin: reason wajib, before/after diaudit,
     * tercatat sebagai baris ledger ADJUST (+/-).
     */
    public function adjust(int $studentId, float $amount, string $reason, ?User $actor = null, ?string $onDate = null): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan penyesuaian jatah wajib diisi.']);
        }

        $amount = LaundryMath::quantity($amount);
        if ($amount === 0.0) {
            throw ValidationException::withMessages(['amount' => 'Jumlah penyesuaian tidak boleh nol.']);
        }

        $entitlement = $this->ensurePeriod($studentId, CarbonImmutable::parse($onDate ?: now()->toDateString()));
        if ($entitlement === null) {
            throw ValidationException::withMessages([
                'entitlement' => 'Santri tidak memiliki policy jatah aktif pada periode ini.',
            ]);
        }

        return DB::transaction(function () use ($entitlement, $amount, $reason, $actor, $studentId): array {
            /** @var LaundryEntitlement $locked */
            $locked = LaundryEntitlement::query()->whereKey($entitlement->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, ['ACTIVE', 'EXHAUSTED'], true)) {
                throw ValidationException::withMessages([
                    'entitlement' => "Jatah periode {$locked->period_key} tidak aktif ({$locked->status}).",
                ]);
            }

            $before = [
                'adjusted_quota' => LaundryMath::quantity($locked->adjusted_quota),
                'remaining' => $locked->remaining(),
            ];

            $newAdjusted = LaundryMath::quantity((float) $locked->adjusted_quota + $amount);
            // Rule 6: total jatah tidak boleh negatif.
            $totalQuota = LaundryMath::quantity((float) $locked->allocated_quota + (float) $locked->carry_in_quota + $newAdjusted);
            if ($totalQuota < 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Penyesuaian membuat total jatah negatif.',
                ]);
            }

            $locked->adjusted_quota = $newAdjusted;
            if ($locked->status === 'EXHAUSTED' && $locked->remaining() > 0) {
                $locked->status = 'ACTIVE';
            }
            $locked->save();

            $transaction = $this->ledger($locked, 'ADJUST', $amount, [
                'reason' => $reason,
                'actor_id' => $actor?->id,
            ]);

            $after = [
                'adjusted_quota' => $newAdjusted,
                'remaining' => $locked->remaining(),
            ];

            $this->audit->record('QUOTA_ADJUSTED', $locked, $actor, $before, $after, $reason, $transaction ? 'ledger#'.$transaction->id : null);

            event(new LaundryQuotaAdjusted((int) $locked->id, [
                'student_id' => $studentId,
                'amount' => $amount,
                'reason' => $reason,
                'remaining' => $locked->remaining(),
            ]));

            return $this->present($locked);
        });
    }

    // =====================================================================
    // LEDGER (§13)
    // =====================================================================

    private function ledger(LaundryEntitlement $entitlement, string $type, float $amount, array $context = []): LaundryEntitlementTransaction
    {
        return LaundryEntitlementTransaction::create([
            'laundry_entitlement_id' => $entitlement->id,
            'student_id' => $entitlement->student_id,
            'transaction_type' => $type,
            'amount' => LaundryMath::quantity($amount),
            'balance_after' => array_key_exists('balance_after', $context)
                ? LaundryMath::quantity($context['balance_after'])
                : $entitlement->remaining(),
            'reference_type' => $context['reference_type'] ?? null,
            'reference_id' => $context['reference_id'] ?? null,
            'reason' => $context['reason'] ?? null,
            'actor_id' => $context['actor_id'] ?? null,
            'occurred_at' => now(),
        ]);
    }
}
