<?php

namespace App\Services\Library\Circulation;

use App\Models\Library;
use App\Models\LibraryBibliography;
use App\Models\LibraryItem;
use App\Models\LibraryLoan;
use App\Models\LibraryLoanRenewal;
use App\Models\LibraryMember;
use App\Services\Library\LibrarySettingsService;
use App\Services\Library\NumberingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sirkulasi: peminjaman, pengembalian, perpanjangan, dan keterlambatan.
 *
 * Policy diambil dari **jenis keanggotaan** (durasi, jumlah maksimum, batas
 * perpanjangan) dengan fallback ke konfigurasi perpustakaan — bukan hardcode.
 * Status eksemplar (`on_loan`, `reserved`) dikendalikan di sini, sehingga
 * modul ini pemilik tunggal status transaksi tersebut.
 */
class CirculationService
{
    public function __construct(
        protected NumberingService $numbering,
        protected LibrarySettingsService $settings,
        protected FineService $fines,
        protected ReservationService $reservations,
    ) {
    }

    public function query(Request $request): Builder
    {
        $query = LibraryLoan::query()->with([
            'member:id,member_number,name,user_id,student_id,employee_id,guardian_id',
            'member.student:id,full_name',
            'member.employee:id,full_name',
            'item:id,item_code,barcode,accession_number,library_id',
            'bibliography:id,title,slug,control_number',
        ]);

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (in_array($column, ['library_id', 'member_id', 'item_id', 'status', 'bibliography_id'], true)) {
                $query->where($column, $value);
            }
        }

        if ($request->boolean('open')) {
            $query->open();
        }

        // Daftar keterlambatan: pinjaman berjalan yang sudah melewati tenggat.
        if ($request->boolean('overdue')) {
            $query->open()->whereDate('due_at', '<', Carbon::now()->toDateString());
        }

        if ($from = $request->query('from')) {
            $query->whereDate('borrowed_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('borrowed_at', '<=', $to);
        }

        if ($search = $request->query('search')) {
            $query->where(fn (Builder $q) => $q
                ->where('loan_code', 'like', "{$search}%")
                ->orWhereHas('member', fn (Builder $m) => $m
                    ->where('member_number', 'like', "{$search}%")
                    ->orWhere('name', 'like', "%{$search}%"))
                ->orWhereHas('item', fn (Builder $i) => $i
                    ->where('item_code', 'like', "{$search}%")
                    ->orWhere('barcode', 'like', "{$search}%")));
        }

        $sortDir = $request->query('sort_dir') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy('borrowed_at', $sortDir)->orderBy('id', $sortDir);
    }

    /**
     * Peminjaman (checkout).
     *
     * @param  array<string, mixed>  $data
     */
    public function checkout(LibraryMember $member, LibraryItem $item, array $data = []): LibraryLoan
    {
        /** @var Library $library */
        $library = Library::query()->findOrFail($member->library_id);

        $this->assertMemberEligible($member, $library);
        $this->assertItemBorrowable($item);

        if ($item->library_id !== null && (int) $item->library_id !== (int) $library->id) {
            throw ValidationException::withMessages([
                'item' => 'Eksemplar ini milik perpustakaan lain.',
            ]);
        }

        $openLoans = $member->openLoansCount();
        $maxLoans = $this->maxLoans($member, $library);

        if ($openLoans >= $maxLoans) {
            throw ValidationException::withMessages([
                'member' => "Batas peminjaman tercapai ({$openLoans} dari {$maxLoans}). Kembalikan koleksi terlebih dahulu.",
            ]);
        }

        $duration = $this->loanDuration($member, $library);

        return DB::transaction(function () use ($member, $item, $library, $duration, $data): LibraryLoan {
            $borrowedAt = Carbon::now();

            $loan = LibraryLoan::create([
                'library_id' => $library->id,
                'member_id' => $member->id,
                'item_id' => $item->id,
                'bibliography_id' => $item->bibliography_id,
                'loan_code' => $this->numbering->next($library, 'loan'),
                'borrowed_at' => $borrowedAt->toDateString(),
                'due_at' => $borrowedAt->copy()->addDays($duration)->toDateString(),
                'status' => LibraryLoan::STATUS_ACTIVE,
                'borrowed_by' => $data['borrowed_by'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $item->update(['status' => 'on_loan']);

            activity('library')
                ->event('LOAN_CHECKOUT')
                ->performedOn($loan)
                ->withProperties([
                    'member_id' => $member->id,
                    'item_id' => $item->id,
                    'due_at' => $loan->due_at?->toDateString(),
                ] + $this->context())
                ->log('Peminjaman dicatat');

            return $loan->refresh();
        });
    }

    /**
     * Pengembalian (return) beserta perhitungan denda keterlambatan.
     *
     * @param  array<string, mixed>  $data
     */
    public function returnItem(LibraryLoan $loan, array $data = []): LibraryLoan
    {
        if (! $loan->isOpen()) {
            throw ValidationException::withMessages([
                'loan' => 'Pinjaman ini sudah dikembalikan.',
            ]);
        }

        return DB::transaction(function () use ($loan, $data): LibraryLoan {
            $returnedAt = Carbon::now();
            $overdueDays = $loan->overdueDays($returnedAt);

            // Denda keterlambatan (idempoten — unik per pinjaman & tipe).
            $fine = $overdueDays > 0 && $loan->status !== LibraryLoan::STATUS_LOST
                ? $this->fines->assessOverdue($loan, $returnedAt)
                : null;

            $loan->update([
                'returned_on' => $returnedAt->toDateString(),
                'returned_at' => $returnedAt,
                'status' => LibraryLoan::STATUS_RETURNED,
                'returned_by' => $data['returned_by'] ?? null,
            ]);

            /** @var LibraryItem|null $item */
            $item = LibraryItem::query()->find($loan->item_id);

            if ($item !== null) {
                // Kondisi dapat diperbarui saat pengembalian (mis. rusak).
                if (! empty($data['condition_id'])) {
                    $item->update(['condition_id' => $data['condition_id']]);
                }

                // Status eksemplar setelah kembali: sesuai masukan petugas,
                // atau tersedia.
                $nextStatus = $data['status'] ?? 'available';
                $item->update(['status' => $nextStatus]);

                // Antrean reservasi diutamakan: eksemplar ditahan untuk
                // pemesan terdepan bila ada.
                if ($nextStatus === 'available' && $item->bibliography !== null) {
                    $this->reservations->markAvailableFor($item->bibliography, $item);
                }
            }

            activity('library')
                ->event('LOAN_RETURNED')
                ->performedOn($loan)
                ->withProperties([
                    'overdue_days' => $overdueDays,
                    'fine_id' => $fine?->id,
                    'fine_amount' => $fine !== null ? (float) $fine->amount : 0,
                ] + $this->context())
                ->log('Pengembalian dicatat');

            return $loan->refresh();
        });
    }

    /**
     * Perpanjangan pinjaman.
     *
     * @param  array<string, mixed>  $data
     */
    public function renew(LibraryLoan $loan, array $data = []): LibraryLoan
    {
        if (! $loan->isOpen()) {
            throw ValidationException::withMessages(['loan' => 'Pinjaman ini sudah selesai.']);
        }

        /** @var Library $library */
        $library = Library::query()->findOrFail($loan->library_id);

        $member = $loan->member ?? LibraryMember::query()->findOrFail($loan->member_id);

        $limit = $this->renewalLimit($member, $library);

        if ($loan->renewed_count >= $limit) {
            throw ValidationException::withMessages([
                'loan' => "Batas perpanjangan tercapai ({$limit}×).",
            ]);
        }

        // Keterlambatan harus diselesaikan dengan pengembalian, bukan perpanjangan.
        if ($loan->overdueDays() > 0) {
            throw ValidationException::withMessages([
                'loan' => 'Pinjaman sudah terlambat — kembalikan koleksi sebelum memperpanjang.',
            ]);
        }

        // Antrean reservasi tidak boleh dilangkahi.
        $waiting = \App\Models\LibraryReservation::query()
            ->where('bibliography_id', $loan->bibliography_id)
            ->where('status', \App\Models\LibraryReservation::STATUS_WAITING)
            ->exists();

        if ($waiting) {
            throw ValidationException::withMessages([
                'loan' => 'Ada anggota lain dalam antrean judul ini — perpanjangan tidak diizinkan.',
            ]);
        }

        $duration = $this->loanDuration($member, $library);

        return DB::transaction(function () use ($loan, $duration, $data): LibraryLoan {
            $previousDue = $loan->due_at?->copy() ?? Carbon::now();
            $newDue = $previousDue->copy()->addDays($duration);

            LibraryLoanRenewal::create([
                'loan_id' => $loan->id,
                'previous_due_at' => $previousDue->toDateString(),
                'new_due_at' => $newDue->toDateString(),
                'renewed_by' => $data['renewed_by'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $loan->update([
                'due_at' => $newDue->toDateString(),
                'renewed_count' => $loan->renewed_count + 1,
                'status' => LibraryLoan::STATUS_ACTIVE,
            ]);

            activity('library')
                ->event('LOAN_RENEWED')
                ->performedOn($loan)
                ->withProperties([
                    'previous_due_at' => $previousDue->toDateString(),
                    'new_due_at' => $newDue->toDateString(),
                    'renewed_count' => $loan->renewed_count,
                ] + $this->context())
                ->log('Pinjaman diperpanjang');

            return $loan->refresh();
        });
    }

    /**
     * Tandai pinjaman berjalan yang melewati tenggat sebagai terlambat dan
     * nilai dendanya (idempoten — dijalankan scheduler harian).
     *
     * @return int jumlah pinjaman yang ditandai
     */
    public function markOverdue(?int $libraryId = null): int
    {
        $loanIds = LibraryLoan::query()
            ->open()
            ->whereDate('due_at', '<', Carbon::now()->toDateString())
            ->where('status', LibraryLoan::STATUS_ACTIVE)
            ->when($libraryId !== null, fn (Builder $q) => $q->where('library_id', $libraryId))
            ->pluck('id');

        $count = 0;

        foreach (LibraryLoan::query()->whereIn('id', $loanIds)->get() as $loan) {
            $loan->update(['status' => LibraryLoan::STATUS_OVERDUE]);
            $this->fines->assessOverdue($loan);

            activity('library')
                ->event('LOAN_OVERDUE')
                ->performedOn($loan)
                ->withProperties(['overdue_days' => $loan->overdueDays()])
                ->log('Pinjaman melewati tenggat');

            $count++;
        }

        return $count;
    }

    /**
     * Cari cepat berdasarkan hasil pindai (§7 & §8).
     *
     * Satu kode dapat berupa barcode/kode eksemplar, nomor inventaris, nomor
     * anggota, token QR kartu, atau barcode kartu.
     *
     * @return array<string, mixed>
     */
    public function lookup(string $code): array
    {
        $code = trim($code);

        if ($code === '') {
            return ['type' => null, 'message' => 'Kode kosong.'];
        }

        $item = LibraryItem::query()
            ->with(['bibliography:id,title,slug', 'location:id,name'])
            ->where(fn (Builder $q) => $q
                ->where('barcode', $code)
                ->orWhere('item_code', $code)
                ->orWhere('accession_number', $code))
            ->first();

        if ($item !== null) {
            return [
                'type' => 'item',
                'item' => [
                    'id' => $item->id,
                    'item_code' => $item->item_code,
                    'accession_number' => $item->accession_number,
                    'barcode' => $item->barcode,
                    'status' => $item->status,
                    'is_available' => $item->isAvailable(),
                    'title' => $item->bibliography?->title,
                    'bibliography_id' => $item->bibliography_id,
                    'location' => $item->location?->name,
                    'open_loan' => $item->loans()->open()->first()?->loan_code,
                ],
            ];
        }

        $member = LibraryMember::query()
            ->with(['membershipType:id,name', 'student:id,full_name', 'employee:id,full_name'])
            ->where(fn (Builder $q) => $q
                ->where('member_number', $code)
                ->orWhere('identity_number', $code)
                ->orWhereHas('cards', fn (Builder $c) => $c->where('qr_token', $code)->orWhere('barcode', $code)))
            ->first();

        if ($member !== null) {
            return ['type' => 'member', 'member' => $this->memberSummary($member)];
        }

        return ['type' => null, 'message' => 'Kode tidak dikenali.'];
    }

    /**
     * Ringkasan cepat seorang anggota untuk layar checkout (§8).
     *
     * @return array<string, mixed>
     */
    public function memberSummary(LibraryMember $member): array
    {
        $openLoans = $member->loans()->open()
            ->with(['item:id,item_code,barcode', 'bibliography:id,title'])
            ->orderBy('due_at')
            ->get();

        return [
            'id' => $member->id,
            'member_number' => $member->member_number,
            'display_name' => $member->display_name,
            'membership_type' => $member->membershipType?->name,
            'status' => $member->status,
            'valid_until' => $member->valid_until?->toDateString(),
            'is_within_validity' => $member->isWithinValidity(),
            'open_loans' => $openLoans->count(),
            'max_loans' => $member->membershipType?->max_loan,
            'overdue_loans' => $openLoans->filter(fn (LibraryLoan $loan) => $loan->overdueDays() > 0)->count(),
            'outstanding_fine' => $member->outstandingFineAmount(),
            'active_reservations' => $member->reservations()->open()->count(),
            'loans' => $openLoans->map(fn (LibraryLoan $loan) => [
                'id' => $loan->id,
                'loan_code' => $loan->loan_code,
                'item_code' => $loan->item?->item_code,
                'title' => $loan->bibliography?->title,
                'due_at' => $loan->due_at?->toDateString(),
                'overdue_days' => $loan->overdueDays(),
            ])->values()->all(),
        ];
    }

    /**
     * Ringkasan sirkulasi untuk dashboard (§2).
     *
     * @return array<string, mixed>
     */
    public function summary(?int $libraryId = null): array
    {
        $today = Carbon::now()->toDateString();
        $monthStart = Carbon::now()->startOfMonth()->toDateString();

        $loans = fn () => LibraryLoan::query()->when($libraryId !== null, fn (Builder $q) => $q->where('library_id', $libraryId));
        $items = fn () => LibraryItem::query()->when($libraryId !== null, fn (Builder $q) => $q->where('library_id', $libraryId));

        return [
            'active_loans' => $loans()->open()->count(),
            'overdue_loans' => $loans()->open()->whereDate('due_at', '<', $today)->count(),
            'due_today' => $loans()->open()->whereDate('due_at', $today)->count(),
            'returned_this_month' => $loans()->whereNotNull('returned_on')->whereDate('returned_on', '>=', $monthStart)->count(),
            'borrowed_this_month' => $loans()->whereDate('borrowed_at', '>=', $monthStart)->count(),
            'on_loan_items' => $items()->where('status', 'on_loan')->count(),
            'reserved_items' => $items()->where('status', 'reserved')->count(),
            'new_items_this_month' => $items()->whereDate('created_at', '>=', $monthStart)->count(),
        ];
    }

    /**
     * Tren peminjaman & pengembalian per bulan (§2 grafik).
     *
     * @return list<array{month:string,borrowed:int,returned:int}>
     */
    public function monthlyTrend(?int $libraryId = null, int $months = 6): array
    {
        $start = Carbon::now()->startOfMonth()->subMonths($months - 1);

        $borrowed = LibraryLoan::query()
            ->when($libraryId !== null, fn (Builder $q) => $q->where('library_id', $libraryId))
            ->whereDate('borrowed_at', '>=', $start->toDateString())
            ->selectRaw("strftime('%Y-%m', borrowed_at) as month, count(*) as total")
            ->groupBy('month')
            ->pluck('total', 'month');

        $returned = LibraryLoan::query()
            ->when($libraryId !== null, fn (Builder $q) => $q->where('library_id', $libraryId))
            ->whereNotNull('returned_on')
            ->whereDate('returned_on', '>=', $start->toDateString())
            ->selectRaw("strftime('%Y-%m', returned_on) as month, count(*) as total")
            ->groupBy('month')
            ->pluck('total', 'month');

        $rows = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $start->copy()->addMonths($i)->format('Y-m');

            $rows[] = [
                'month' => $month,
                'borrowed' => (int) ($borrowed[$month] ?? 0),
                'returned' => (int) ($returned[$month] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Koleksi paling banyak dipinjam (§2 & §3).
     *
     * @return list<array<string, mixed>>
     */
    public function popularBooks(?int $libraryId = null, int $limit = 5): array
    {
        return LibraryLoan::query()
            ->when($libraryId !== null, fn (Builder $q) => $q->where('library_id', $libraryId))
            ->whereNotNull('bibliography_id')
            ->selectRaw('bibliography_id, count(*) as total')
            ->groupBy('bibliography_id')
            ->orderByDesc('total')
            ->limit($limit)
            ->with('bibliography:id,title,slug')
            ->get()
            ->map(fn (LibraryLoan $row) => [
                'bibliography_id' => $row->bibliography_id,
                'title' => $row->bibliography?->title,
                'slug' => $row->bibliography?->slug,
                'loans' => (int) $row->total,
            ])
            ->values()
            ->all();
    }

    protected function loanDuration(LibraryMember $member, Library $library): int
    {
        $fromType = $member->membershipType?->loan_duration_days;

        if ($fromType !== null && (int) $fromType > 0) {
            return (int) $fromType;
        }

        return max(1, (int) $this->settings->get($library, 'loan.default_duration', 7));
    }

    protected function maxLoans(LibraryMember $member, Library $library): int
    {
        $fromType = $member->membershipType?->max_loan;

        if ($fromType !== null && (int) $fromType > 0) {
            return (int) $fromType;
        }

        return max(1, (int) $this->settings->get($library, 'loan.max_items', 3));
    }

    protected function renewalLimit(LibraryMember $member, Library $library): int
    {
        $fromType = $member->membershipType?->renewal_limit;

        if ($fromType !== null) {
            return max(0, (int) $fromType);
        }

        return max(0, (int) $this->settings->get($library, 'loan.renewal_limit', 1));
    }

    protected function assertMemberEligible(LibraryMember $member, Library $library): void
    {
        if (! in_array($member->status, LibraryMember::ACTIVE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'member' => 'Keanggotaan tidak aktif — peminjaman ditolak.',
            ]);
        }

        if (! $member->isWithinValidity()) {
            throw ValidationException::withMessages([
                'member' => 'Masa berlaku keanggotaan sudah berakhir.',
            ]);
        }

        if ((bool) $this->settings->get($library, 'loan.block_on_outstanding_fine', false)) {
            $outstanding = $member->outstandingFineAmount();

            if ($outstanding > 0) {
                throw ValidationException::withMessages([
                    'member' => 'Anggota masih memiliki denda belum dibayar ('.number_format($outstanding, 2).').',
                ]);
            }
        }
    }

    protected function assertItemBorrowable(LibraryItem $item): void
    {
        if (! $item->isAvailable()) {
            $reason = match ($item->status) {
                'on_loan' => 'sedang dipinjam',
                'reserved' => 'ditahan untuk pemesan lain',
                'reference_only' => 'hanya boleh dibaca di tempat',
                'lost' => 'hilang',
                'damaged' => 'rusak',
                'withdrawn' => 'telah ditarik dari koleksi',
                default => 'tidak tersedia',
            };

            throw ValidationException::withMessages([
                'item' => "Eksemplar {$item->item_code} {$reason} — tidak dapat dipinjam.",
            ]);
        }

        if ($item->loans()->open()->exists()) {
            throw ValidationException::withMessages([
                'item' => 'Eksemplar ini masih terhubung ke pinjaman berjalan.',
            ]);
        }
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