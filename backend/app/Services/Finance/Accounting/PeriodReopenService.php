<?php

namespace App\Services\Finance\Accounting;

use App\Models\FinancePeriod;
use App\Models\FinancePeriodReopenRequest;
use App\Models\User;
use App\Services\Finance\FinancePeriodService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Membuka kembali periode yang sudah ditutup — lewat permohonan, bukan satu klik.
 *
 * Tutup buku mengikat: selama periode tertutup, seluruh jurnal ke periode itu
 * ditolak tanpa toleransi hari. Konsekuensinya, membukanya kembali harus
 * keputusan sadar: seorang admin keuangan mengajukan beserta alasannya, orang
 * lain memutuskan, dan keduanya tercatat. Tanpa pemisahan itu, laporan yang
 * sudah dicetak dan disampaikan ke yayasan dapat berubah angkanya oleh satu
 * orang tanpa jejak.
 */
class PeriodReopenService
{
    public function __construct(
        private readonly FinancePeriodService $periods,
    ) {
    }

    public function request(FinancePeriod $period, string $reason, User $actor): FinancePeriodReopenRequest
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan pembukaan periode wajib diisi.']);
        }

        if ($period->isOpen()) {
            throw ValidationException::withMessages(['period' => "Periode {$period->code} masih terbuka; tidak ada yang perlu dibuka."]);
        }

        $pending = FinancePeriodReopenRequest::query()->where('period_id', $period->id)->pending()->exists();

        if ($pending) {
            throw ValidationException::withMessages(['period' => "Sudah ada permohonan pembukaan yang menunggu keputusan untuk periode {$period->code}."]);
        }

        return FinancePeriodReopenRequest::query()->create([
            'period_id' => $period->id,
            'reason' => $reason,
            'status' => 'pending',
            'requested_by' => $actor->id,
        ]);
    }

    public function decide(FinancePeriodReopenRequest $request, bool $approved, ?User $actor = null, ?string $note = null): FinancePeriodReopenRequest
    {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'Permohonan ini sudah diputuskan.']);
        }

        // Pemisahan tugas: pengaju tidak memutuskan permohonannya sendiri.
        if ($actor !== null && (int) $request->requested_by === $actor->id) {
            throw ValidationException::withMessages([
                'decided_by' => 'Permohonan pembukaan periode harus diputuskan orang lain, bukan pengaju permohonan itu sendiri.',
            ]);
        }

        return DB::transaction(function () use ($request, $approved, $actor, $note): FinancePeriodReopenRequest {
            if ($approved) {
                $period = FinancePeriod::query()->findOrFail($request->period_id);
                $this->periods->reopen($period, $actor);
            }

            $request->forceFill([
                'status' => $approved ? 'approved' : 'rejected',
                'decided_by' => $actor?->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();

            return $request->refresh()->load(['period:id,code,name', 'requestedBy:id,name', 'decidedBy:id,name']);
        });
    }

    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator */
    public function paginate(int $perPage = 25)
    {
        return FinancePeriodReopenRequest::query()
            ->with(['period:id,code,name,status', 'requestedBy:id,name', 'decidedBy:id,name'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /** Riwayat permohonan untuk satu periode — dipakai layar tutup buku. */
    public function history(FinancePeriod $period): array
    {
        return FinancePeriodReopenRequest::query()
            ->where('period_id', $period->id)
            ->with(['requestedBy:id,name', 'decidedBy:id,name'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (FinancePeriodReopenRequest $r): array => [
                'id' => $r->id,
                'status' => $r->status,
                'reason' => $r->reason,
                'requested_by' => $r->requestedBy?->name,
                'decided_by' => $r->decidedBy?->name,
                'decided_at' => $r->decided_at?->toIso8601String(),
                'decision_note' => $r->decision_note,
            ])
            ->all();
    }
}
