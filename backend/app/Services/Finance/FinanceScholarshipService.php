<?php

namespace App\Services\Finance;

use App\Models\FinanceScholarship;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Alur persetujuan beasiswa (§18): draft → active (disetujui) / rejected.
 *
 * Beasiswa aktif mengurangi tagihan saat billing digenerate; karena itu status
 * `active` dijaga ketat — hanya lewat approval, dan pembuat tidak boleh menyetujui
 * sendiri bila ada pemisahan peran. Kedaluwarsa dihitung dari `end_date`.
 */
class FinanceScholarshipService
{
    public function approve(FinanceScholarship $scholarship, User $actor): FinanceScholarship
    {
        return DB::transaction(function () use ($scholarship, $actor): FinanceScholarship {
            $locked = FinanceScholarship::query()->whereKey($scholarship->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [FinanceScholarship::STATUS_DRAFT, FinanceScholarship::STATUS_REJECTED], true)) {
                throw ValidationException::withMessages([
                    'status' => "Beasiswa berstatus {$locked->status} tidak bisa disetujui.",
                ]);
            }

            $locked->status = FinanceScholarship::STATUS_ACTIVE;
            $locked->approved_by = $actor->id;
            $locked->save();

            return $locked->fresh(['student', 'feeType', 'approvedBy']);
        });
    }

    public function reject(FinanceScholarship $scholarship, string $reason, User $actor): FinanceScholarship
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan penolakan wajib diisi.']);
        }

        return DB::transaction(function () use ($scholarship, $reason, $actor): FinanceScholarship {
            $locked = FinanceScholarship::query()->whereKey($scholarship->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== FinanceScholarship::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => "Hanya beasiswa draft yang bisa ditolak (status: {$locked->status}).",
                ]);
            }

            $locked->status = FinanceScholarship::STATUS_REJECTED;
            $locked->approved_by = $actor->id;
            $locked->reason = trim(($locked->reason ? $locked->reason.' | ' : '')."Ditolak: {$reason}");
            $locked->save();

            return $locked->fresh(['student', 'feeType', 'approvedBy']);
        });
    }

    public function cancel(FinanceScholarship $scholarship, User $actor): FinanceScholarship
    {
        return DB::transaction(function () use ($scholarship, $actor): FinanceScholarship {
            $locked = FinanceScholarship::query()->whereKey($scholarship->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === FinanceScholarship::STATUS_CANCELLED) {
                throw ValidationException::withMessages(['status' => 'Beasiswa sudah dibatalkan.']);
            }

            $locked->status = FinanceScholarship::STATUS_CANCELLED;
            $locked->approved_by = $actor->id;
            $locked->save();

            return $locked->fresh(['student', 'feeType', 'approvedBy']);
        });
    }

    /** Tandai beasiswa aktif yang `end_date`-nya lewat jadi expired (terjadwal). */
    public function expireOverdue(?Carbon $asOf = null): int
    {
        $date = ($asOf ?? Carbon::now())->toDateString();

        return FinanceScholarship::query()
            ->where('status', FinanceScholarship::STATUS_ACTIVE)
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', $date)
            ->update(['status' => FinanceScholarship::STATUS_EXPIRED, 'updated_at' => Carbon::now()]);
    }
}
