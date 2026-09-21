<?php

namespace App\Services\Library\Circulation;

use App\Models\Library;
use App\Models\LibraryFine;
use App\Models\LibraryFinePayment;
use App\Models\LibraryLoan;
use App\Models\LibraryMember;
use App\Services\Library\LibrarySettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Denda & pembayaran.
 *
 * Modul Library **tidak** membuat sistem akuntansi baru: nominal, pembayaran
 * (memakai master `payment_methods`), dan pembebasan dicatat di sini; riwayat
 * perubahannya ada pada jejak audit.
 *
 * Penilaian denda keterlambatan bersifat idempoten (unik per pinjaman + tipe)
 * sehingga scheduler boleh berjalan berulang tanpa menggandakan denda.
 */
class FineService
{
    public function __construct(protected LibrarySettingsService $settings)
    {
    }

    public function query(Request $request): Builder
    {
        $query = LibraryFine::query()->with([
            'member:id,member_number,name,student_id,employee_id,guardian_id,user_id',
            'member.student:id,full_name',
            'member.employee:id,full_name',
            'loan:id,loan_code,due_at,returned_on',
            'item:id,item_code,barcode',
            'payments.paymentMethod:id,name',
        ]);

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (in_array($column, ['library_id', 'member_id', 'status', 'type'], true)) {
                $query->where($column, $value);
            }
        }

        if ($request->boolean('outstanding')) {
            $query->outstanding();
        }

        if ($from = $request->query('from')) {
            $query->whereDate('assessed_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('assessed_at', '<=', $to);
        }

        return $query->orderByDesc('assessed_at')->orderByDesc('id');
    }

    /** Tarif denda per hari menurut konfigurasi perpustakaan. */
    public function dailyRate(Library $library): float
    {
        return (float) $this->settings->get($library, 'fine.per_day', 0);
    }

    public function finesEnabled(Library $library): bool
    {
        return (bool) $this->settings->get($library, 'fine.enabled', false);
    }

    /**
     * Nilai denda keterlambatan sebuah pinjaman (idempoten).
     *
     * Bila denda dimatikan atau belum terlambat, tidak ada yang dibuat.
     */
    public function assessOverdue(LibraryLoan $loan, ?Carbon $date = null): ?LibraryFine
    {
        $date ??= Carbon::now();

        if ($loan->returned_on !== null) {
            return null;
        }

        $days = $loan->overdueDays($date);

        if ($days <= 0) {
            return null;
        }

        $library = $loan->library ?? Library::query()->findOrFail($loan->library_id);

        if (! $this->finesEnabled($library)) {
            return null;
        }

        $amount = round($this->dailyRate($library) * $days, 2);

        if ($amount <= 0) {
            return null;
        }

        return DB::transaction(function () use ($loan, $days, $amount, $date): LibraryFine {
            // Kunci unik (loan_id, type) menjaga idempotensi.
            $fine = LibraryFine::query()->firstOrNew([
                'loan_id' => $loan->id,
                'type' => LibraryFine::TYPE_OVERDUE,
            ]);

            $fine->fill([
                'library_id' => $loan->library_id,
                'member_id' => $loan->member_id,
                'item_id' => $loan->item_id,
                'amount' => $amount,
                'assessed_at' => $date->toDateString(),
                'overdue_days' => $days,
            ]);

            if (! $fine->exists) {
                $fine->status = LibraryFine::STATUS_UNPAID;
            } else {
                // Jumlah hari (dan nominal) bertambah selama belum dibayar.
                $fine->status = $fine->resolveStatus();
            }

            $fine->save();

            return $fine->refresh();
        });
    }

    /**
     * Denda manual: kerusakan, kehilangan, atau lain-lain.
     *
     * @param  array<string, mixed>  $data
     */
    public function assess(LibraryMember $member, array $data): LibraryFine
    {
        return LibraryFine::create([
            'library_id' => $member->library_id,
            'member_id' => $member->id,
            'loan_id' => $data['loan_id'] ?? null,
            'item_id' => $data['item_id'] ?? null,
            'type' => $data['type'] ?? LibraryFine::TYPE_OTHER,
            'amount' => $data['amount'],
            'assessed_at' => $data['assessed_at'] ?? Carbon::now()->toDateString(),
            'assessed_by' => $data['assessed_by'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => LibraryFine::STATUS_UNPAID,
        ])->refresh();
    }

    /**
     * Catat pembayaran; status denda dihitung ulang dari nominal.
     *
     * @param  array<string, mixed>  $data
     */
    public function pay(LibraryFine $fine, array $data): LibraryFine
    {
        $amount = round((float) $data['amount'], 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Nominal pembayaran harus lebih dari nol.']);
        }

        if ($fine->status === LibraryFine::STATUS_WAIVED && $fine->outstandingAmount() <= 0) {
            throw ValidationException::withMessages(['fine' => 'Denda ini sudah dibebaskan.']);
        }

        if ($amount > $fine->outstandingAmount() + 0.001) {
            throw ValidationException::withMessages([
                'amount' => 'Nominal pembayaran melebihi sisa denda ('.number_format($fine->outstandingAmount(), 2).').',
            ]);
        }

        return DB::transaction(function () use ($fine, $amount, $data): LibraryFine {
            LibraryFinePayment::create([
                'fine_id' => $fine->id,
                'amount' => $amount,
                'paid_at' => $data['paid_at'] ?? Carbon::now()->toDateString(),
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'reference' => $data['reference'] ?? null,
                'received_by' => $data['received_by'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $fine->paid_amount = round((float) $fine->paid_amount + $amount, 2);
            $fine->status = $fine->resolveStatus();
            $fine->save();

            activity('library')
                ->event('FINE_PAID')
                ->performedOn($fine)
                ->withProperties([
                    'amount' => $amount,
                    'paid_amount' => (float) $fine->paid_amount,
                    'outstanding' => $fine->outstandingAmount(),
                ] + $this->context())
                ->log('Pembayaran denda dicatat');

            return $fine->refresh();
        });
    }

    /**
     * Bebaskan (sebagian/seluruh) denda beserta alasannya.
     */
    public function waive(LibraryFine $fine, string $reason, ?float $amount = null): LibraryFine
    {
        $outstanding = $fine->outstandingAmount();

        if ($outstanding <= 0) {
            throw ValidationException::withMessages(['fine' => 'Tidak ada sisa denda yang dapat dibebaskan.']);
        }

        $waived = $amount !== null ? round($amount, 2) : $outstanding;

        if ($waived <= 0 || $waived > $outstanding + 0.001) {
            throw ValidationException::withMessages([
                'amount' => 'Nominal pembebasan tidak valid (sisa '.number_format($outstanding, 2).').',
            ]);
        }

        return DB::transaction(function () use ($fine, $waived, $reason): LibraryFine {
            $fine->waived_amount = round((float) $fine->waived_amount + $waived, 2);
            $fine->waived_reason = $reason;
            $fine->waived_at = Carbon::now();
            $fine->status = $fine->resolveStatus();
            $fine->save();

            activity('library')
                ->event('FINE_WAIVED')
                ->performedOn($fine)
                ->withProperties([
                    'waived' => $waived,
                    'reason' => $reason,
                    'outstanding' => $fine->outstandingAmount(),
                ] + $this->context())
                ->log('Denda dibebaskan');

            return $fine->refresh();
        });
    }

    public function outstandingFor(LibraryMember $member): float
    {
        return $member->outstandingFineAmount();
    }

    /**
     * Ringkasan denda untuk dashboard & laporan (§2, §3).
     *
     * @return array<string, mixed>
     */
    public function summary(?int $libraryId = null): array
    {
        $scope = fn () => LibraryFine::query()->when($libraryId !== null, fn (Builder $q) => $q->where('library_id', $libraryId));

        $outstanding = (float) $scope()->outstanding()
            ->sum(DB::raw('amount - paid_amount - waived_amount'));

        return [
            'total' => $scope()->count(),
            'unpaid' => $scope()->where('status', LibraryFine::STATUS_UNPAID)->count(),
            'partial' => $scope()->where('status', LibraryFine::STATUS_PARTIAL)->count(),
            'paid' => $scope()->where('status', LibraryFine::STATUS_PAID)->count(),
            'waived' => $scope()->where('status', LibraryFine::STATUS_WAIVED)->count(),
            'outstanding_amount' => round($outstanding, 2),
            'collected_amount' => round((float) $scope()->sum('paid_amount'), 2),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function context(): array
    {
        if (app()->runningInConsole()) {
            return [];
        }

        return [
            'ip' => request()->ip(),
            'user_agent' => (string) request()->userAgent(),
        ];
    }
}