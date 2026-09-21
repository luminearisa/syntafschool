<?php

namespace App\Services\Library\Reports;

use App\Models\LibraryBibliography;
use App\Models\LibraryFine;
use App\Models\LibraryItem;
use App\Models\LibraryLoan;
use App\Models\LibraryMember;
use App\Models\LibraryReservation;
use App\Services\Library\Circulation\AcquisitionService;
use App\Services\Library\Circulation\CirculationService;
use App\Services\Library\Circulation\FineService;
use App\Services\Library\Circulation\InventoryService;
use App\Services\Library\Circulation\ReservationService;
use App\Services\Library\Membership\MembershipService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Laporan modul Library (§3).
 *
 * Seluruh laporan dihitung **dari data mentah saat diminta** dengan query
 * agregat (group by / count), bukan menyimpan ringkasan yang bisa menjadi
 * usang. Setiap laporan menerima filter yang sama: rentang tanggal,
 * perpustakaan, lokasi, kategori, tipe anggota, dan status.
 */
class LibraryReportService
{
    public function __construct(
        protected CirculationService $circulation,
        protected FineService $fines,
        protected ReservationService $reservations,
        protected MembershipService $members,
        protected AcquisitionService $acquisitions,
        protected InventoryService $inventory,
    ) {
    }

    /**
     * Laporan koleksi (§3): jumlah judul & eksemplar per kategori, jenis
     * koleksi, lokasi, dan tahun terbit.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function collection(array $filters = []): array
    {
        $libraryId = $this->intOrNull($filters['library_id'] ?? null);

        $bibliographies = fn () => LibraryBibliography::query()
            ->when($libraryId !== null, fn (Builder $q) => $q->where('library_id', $libraryId))
            ->when($filters['category_id'] ?? null, fn (Builder $q, $v) => $q->where('category_id', $v))
            ->when($filters['collection_type_id'] ?? null, fn (Builder $q, $v) => $q->where('collection_type_id', $v))
            ->when($filters['from'] ?? null, fn (Builder $q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn (Builder $q, $v) => $q->whereDate('created_at', '<=', $v));

        $items = fn () => LibraryItem::query()
            ->when($libraryId !== null, fn (Builder $q) => $q->where('library_id', $libraryId))
            ->when($filters['location_id'] ?? null, fn (Builder $q, $v) => $q->where('location_id', $v));

        return [
            'totals' => [
                'titles' => (clone $bibliographies())->count(),
                'titles_active' => (clone $bibliographies())->where('status', LibraryBibliography::STATUS_ACTIVE)->count(),
                'copies' => $items()->count(),
                'copies_available' => $items()->available()->count(),
            ],
            'by_category' => $this->grouped($bibliographies(), 'category_id', 'book_categories'),
            'by_collection_type' => $this->grouped($bibliographies(), 'collection_type_id', 'library_collection_types'),
            'by_location' => (clone $items())
                ->select('location_id', DB::raw('count(*) as total'))
                ->groupBy('location_id')
                ->with('location:id,name')
                ->get()
                ->map(fn ($row) => [
                    'label' => $row->location?->name ?? 'Tanpa lokasi',
                    'total' => (int) $row->total,
                ])->values()->all(),
            'by_year' => (clone $bibliographies())
                ->whereNotNull('publication_year')
                ->select('publication_year', DB::raw('count(*) as total'))
                ->groupBy('publication_year')
                ->orderByDesc('publication_year')
                ->get()
                ->map(fn ($row) => ['year' => (int) $row->publication_year, 'total' => (int) $row->total])
                ->values()->all(),
            'by_status' => (clone $items())
                ->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status')
                ->map(fn ($total) => (int) $total)->all(),
        ];
    }

    /**
     * Laporan sirkulasi (§3): peminjaman, pengembalian, perpanjangan,
     * keterlambatan, koleksi terpopuler, dan anggota teraktif.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function circulation(array $filters = []): array
    {
        $libraryId = $this->intOrNull($filters['library_id'] ?? null);
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        $loans = fn () => LibraryLoan::query()
            ->when($libraryId !== null, fn (Builder $q) => $q->where('library_id', $libraryId))
            ->when($from !== null, fn (Builder $q, $v) => $q->whereDate('borrowed_at', '>=', $v))
            ->when($to !== null, fn (Builder $q, $v) => $q->whereDate('borrowed_at', '<=', $v))
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v));

        $popular = (clone $loans())
            ->whereNotNull('bibliography_id')
            ->select('bibliography_id', DB::raw('count(*) as total'))
            ->groupBy('bibliography_id')
            ->orderByDesc('total')
            ->limit(10)
            ->with('bibliography:id,title')
            ->get()
            ->map(fn ($row) => [
                'title' => $row->bibliography?->title,
                'loans' => (int) $row->total,
            ])->values()->all();

        $activeMembers = (clone $loans())
            ->select('member_id', DB::raw('count(*) as total'))
            ->groupBy('member_id')
            ->orderByDesc('total')
            ->limit(10)
            ->with('member:id,member_number,name')
            ->get()
            ->map(fn ($row) => [
                'member_number' => $row->member?->member_number,
                'name' => $row->member?->display_name,
                'loans' => (int) $row->total,
            ])->values()->all();

        return [
            'borrowed' => (clone $loans())->count(),
            'returned' => (clone $loans())->whereNotNull('returned_on')->count(),
            'active' => (clone $loans())->open()->count(),
            'overdue' => (clone $loans())->open()->whereDate('due_at', '<', now()->toDateString())->count(),
            'renewals' => (int) DB::table('library_loan_renewals')
                ->whereIn('loan_id', (clone $loans())->select('id'))
                ->count(),
            'by_month' => $this->circulation->monthlyTrend($libraryId, 12),
            'popular' => $popular,
            'active_members' => $activeMembers,
        ];
    }

    /**
     * Laporan anggota (§3).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function member(array $filters = []): array
    {
        $libraryId = $this->intOrNull($filters['library_id'] ?? null);

        $members = fn () => LibraryMember::query()
            ->when($libraryId !== null, fn (Builder $q) => $q->where('library_id', $libraryId))
            ->when($filters['membership_type_id'] ?? null, fn (Builder $q, $v) => $q->where('membership_type_id', $v))
            ->when($filters['linked_type'] ?? null, fn (Builder $q, $v) => match ($v) {
                'student' => $q->whereNotNull('student_id'),
                'employee' => $q->whereNotNull('employee_id'),
                'guardian' => $q->whereNotNull('guardian_id'),
                'public' => $q->whereNull('student_id')->whereNull('employee_id')->whereNull('guardian_id'),
                default => $q,
            })
            ->when($filters['from'] ?? null, fn (Builder $q, $v) => $q->whereDate('registered_at', '>=', $v))
            ->when($filters['to'] ?? null, fn (Builder $q, $v) => $q->whereDate('registered_at', '<=', $v));

        return [
            'total' => (clone $members())->count(),
            'active' => (clone $members())->where('status', LibraryMember::STATUS_ACTIVE)->count(),
            'expired' => (clone $members())->where('status', LibraryMember::STATUS_EXPIRED)->count(),
            'pending' => (clone $members())->where('status', LibraryMember::STATUS_PENDING)->count(),
            'suspended' => (clone $members())->where('status', LibraryMember::STATUS_SUSPENDED)->count(),
            'by_type' => (clone $members())
                ->select('membership_type_id', DB::raw('count(*) as total'))
                ->groupBy('membership_type_id')
                ->with('membershipType:id,name')
                ->get()
                ->map(fn ($row) => [
                    'label' => $row->membershipType?->name ?? 'Tanpa jenis',
                    'total' => (int) $row->total,
                ])->values()->all(),
            'by_linked_type' => [
                'student' => (clone $members())->whereNotNull('student_id')->count(),
                'employee' => (clone $members())->whereNotNull('employee_id')->count(),
                'guardian' => (clone $members())->whereNotNull('guardian_id')->count(),
                'public' => (clone $members())->whereNull('student_id')->whereNull('employee_id')->whereNull('guardian_id')->count(),
            ],
            'summary' => $this->members->summary($libraryId),
        ];
    }

    /**
     * Laporan denda (§3): dibuat, dibayar, belum dibayar, dibebaskan.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function fine(array $filters = []): array
    {
        $libraryId = $this->intOrNull($filters['library_id'] ?? null);

        $fines = fn () => LibraryFine::query()
            ->when($libraryId !== null, fn (Builder $q) => $q->where('library_id', $libraryId))
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($filters['type'] ?? null, fn (Builder $q, $v) => $q->where('type', $v))
            ->when($filters['from'] ?? null, fn (Builder $q, $v) => $q->whereDate('assessed_at', '>=', $v))
            ->when($filters['to'] ?? null, fn (Builder $q, $v) => $q->whereDate('assessed_at', '<=', $v));

        return [
            'summary' => $this->fines->summary($libraryId),
            'assessed' => (clone $fines())->count(),
            'assessed_amount' => round((float) (clone $fines())->sum('amount'), 2),
            'paid_amount' => round((float) (clone $fines())->sum('paid_amount'), 2),
            'waived_amount' => round((float) (clone $fines())->sum('waived_amount'), 2),
            'outstanding_amount' => round((float) (clone $fines())->outstanding()
                ->sum(DB::raw('amount - paid_amount - waived_amount')), 2),
            'by_type' => (clone $fines())
                ->select('type', DB::raw('count(*) as total'), DB::raw('sum(amount) as amount'))
                ->groupBy('type')
                ->get()
                ->map(fn ($row) => ['type' => $row->type, 'total' => (int) $row->total, 'amount' => (float) $row->amount])
                ->values()->all(),
        ];
    }

    /**
     * Laporan pengadaan (§3): jumlah permintaan, nilai, dan koleksi diperoleh.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function acquisition(array $filters = []): array
    {
        $libraryId = $this->intOrNull($filters['library_id'] ?? null);

        $acquisitions = $this->acquisitions->summary($libraryId, $filters['from'] ?? null, $filters['to'] ?? null);

        $bySupplier = DB::table('library_acquisition_requests as r')
            ->leftJoin('publishers as p', 'p.id', '=', 'r.supplier_id')
            ->when($libraryId !== null, fn ($q) => $q->where('r.library_id', $libraryId))
            ->selectRaw('coalesce(p.name, r.supplier_name, \'Tanpa pemasok\') as label, count(*) as total, sum(r.total_amount) as amount')
            ->groupBy('label')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($row) => ['label' => $row->label, 'total' => (int) $row->total, 'amount' => (float) $row->amount])
            ->all();

        return $acquisitions + ['by_supplier' => $bySupplier];
    }

    /**
     * Laporan inventaris/stock opname (§3 & §13).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function inventory(array $filters = []): array
    {
        $libraryId = $this->intOrNull($filters['library_id'] ?? null);

        $items = fn () => LibraryItem::query()
            ->when($libraryId !== null, fn (Builder $q) => $q->where('library_id', $libraryId))
            ->when($filters['location_id'] ?? null, fn (Builder $q, $v) => $q->where('location_id', $v));

        return [
            'sessions' => $this->inventory->report($libraryId, $filters['from'] ?? null, $filters['to'] ?? null),
            'current' => [
                'lost' => (clone $items())->where('status', 'lost')->count(),
                'damaged' => (clone $items())->where('status', 'damaged')->count(),
                'withdrawn' => (clone $items())->where('status', 'withdrawn')->count(),
                'available' => (clone $items())->available()->count(),
                'on_loan' => (clone $items())->where('status', 'on_loan')->count(),
            ],
        ];
    }

    /**
     * Ringkasan reservasi (melengkapi laporan sirkulasi).
     *
     * @return array<string, mixed>
     */
    public function reservation(?int $libraryId = null): array
    {
        return [
            'summary' => $this->reservations->summary($libraryId),
            'queue' => LibraryReservation::query()
                ->open()
                ->when($libraryId !== null, fn (Builder $q) => $q->where('library_id', $libraryId))
                ->with(['bibliography:id,title', 'member:id,member_number,name'])
                ->orderBy('queue_position')
                ->limit(50)
                ->get()
                ->map(fn (LibraryReservation $reservation) => [
                    'reservation_code' => $reservation->reservation_code,
                    'title' => $reservation->bibliography?->title,
                    'member' => $reservation->member?->display_name,
                    'status' => $reservation->status,
                    'queue_position' => $reservation->queue_position,
                    'expires_at' => $reservation->expires_at?->toDateString(),
                ])->values()->all(),
        ];
    }

    /**
     * Data untuk ekspor CSV (§4).
     *
     * @param  array<string, mixed>  $filters
     * @return array{headers:list<string>,rows:iterable<string, mixed>}
     */
    public function exportDataset(string $type, array $filters = []): array
    {
        $libraryId = $this->intOrNull($filters['library_id'] ?? null);

        return match ($type) {
            'catalog' => [
                'headers' => ['control_number', 'title', 'subtitle', 'publisher', 'year', 'language', 'collection_type', 'category', 'status', 'copies', 'available'],
                'rows' => LibraryBibliography::query()
                    ->when($libraryId !== null, fn (Builder $q) => $q->where('library_id', $libraryId))
                    ->with(['publisher:id,name', 'language:id,name', 'collectionType:id,name', 'category:id,name'])
                    ->withCount('items')
                    ->withCount(['items as available_items_count' => fn (Builder $q) => $q->available()])
                    ->orderBy('title')
                    ->lazy(),
                'map' => fn (LibraryBibliography $row) => [
                    $row->control_number, $row->title, $row->subtitle, $row->publisher?->name,
                    $row->publication_year, $row->language?->name, $row->collectionType?->name,
                    $row->category?->name, $row->status, $row->items_count, $row->available_items_count,
                ],
            ],
            'items' => [
                'headers' => ['item_code', 'accession_number', 'barcode', 'title', 'location', 'condition', 'status'],
                'rows' => LibraryItem::query()
                    ->when($libraryId !== null, fn (Builder $q) => $q->where('library_id', $libraryId))
                    ->with(['bibliography:id,title', 'location:id,name', 'condition:id,name'])
                    ->orderBy('item_code')
                    ->lazy(),
                'map' => fn (LibraryItem $row) => [
                    $row->item_code, $row->accession_number, $row->barcode,
                    $row->bibliography?->title, $row->location?->name, $row->condition?->name, $row->status,
                ],
            ],
            'members' => [
                'headers' => ['member_number', 'name', 'type', 'status', 'valid_from', 'valid_until', 'phone', 'email'],
                'rows' => LibraryMember::query()
                    ->when($libraryId !== null, fn (Builder $q) => $q->where('library_id', $libraryId))
                    ->with(['membershipType:id,name', 'student:id,full_name', 'employee:id,full_name'])
                    ->orderBy('member_number')
                    ->lazy(),
                'map' => fn (LibraryMember $row) => [
                    $row->member_number, $row->display_name, $row->membershipType?->name, $row->status,
                    $row->valid_from?->toDateString(), $row->valid_until?->toDateString(), $row->phone, $row->email,
                ],
            ],
            'loans' => [
                'headers' => ['loan_code', 'member_number', 'member', 'item_code', 'title', 'borrowed_at', 'due_at', 'returned_on', 'status', 'overdue_days'],
                'rows' => LibraryLoan::query()
                    ->when($libraryId !== null, fn (Builder $q) => $q->where('library_id', $libraryId))
                    ->when($filters['from'] ?? null, fn (Builder $q, $v) => $q->whereDate('borrowed_at', '>=', $v))
                    ->when($filters['to'] ?? null, fn (Builder $q, $v) => $q->whereDate('borrowed_at', '<=', $v))
                    ->with(['member:id,member_number,name', 'item:id,item_code', 'bibliography:id,title'])
                    ->orderByDesc('borrowed_at')
                    ->lazy(),
                'map' => fn (LibraryLoan $row) => [
                    $row->loan_code, $row->member?->member_number, $row->member?->display_name,
                    $row->item?->item_code, $row->bibliography?->title,
                    $row->borrowed_at?->toDateString(), $row->due_at?->toDateString(),
                    $row->returned_on?->toDateString(), $row->status, $row->overdueDays(),
                ],
            ],
            'overdue' => [
                'headers' => ['loan_code', 'member_number', 'member', 'item_code', 'title', 'due_at', 'overdue_days', 'phone'],
                'rows' => LibraryLoan::query()
                    ->open()
                    ->whereDate('due_at', '<', now()->toDateString())
                    ->when($libraryId !== null, fn (Builder $q) => $q->where('library_id', $libraryId))
                    ->with(['member:id,member_number,name,phone', 'item:id,item_code', 'bibliography:id,title'])
                    ->orderBy('due_at')
                    ->lazy(),
                'map' => fn (LibraryLoan $row) => [
                    $row->loan_code, $row->member?->member_number, $row->member?->display_name,
                    $row->item?->item_code, $row->bibliography?->title,
                    $row->due_at?->toDateString(), $row->overdueDays(), $row->member?->phone,
                ],
            ],
            'fines' => [
                'headers' => ['member_number', 'member', 'type', 'amount', 'paid_amount', 'waived_amount', 'outstanding', 'status', 'assessed_at'],
                'rows' => LibraryFine::query()
                    ->when($libraryId !== null, fn (Builder $q) => $q->where('library_id', $libraryId))
                    ->with('member:id,member_number,name')
                    ->orderByDesc('assessed_at')
                    ->lazy(),
                'map' => fn (LibraryFine $row) => [
                    $row->member?->member_number, $row->member?->display_name, $row->type,
                    (float) $row->amount, (float) $row->paid_amount, (float) $row->waived_amount,
                    $row->outstandingAmount(), $row->status, $row->assessed_at?->toDateString(),
                ],
            ],
            'reservations' => [
                'headers' => ['reservation_code', 'title', 'member_number', 'member', 'status', 'queue_position', 'reserved_at', 'expires_at'],
                'rows' => LibraryReservation::query()
                    ->when($libraryId !== null, fn (Builder $q) => $q->where('library_id', $libraryId))
                    ->with(['bibliography:id,title', 'member:id,member_number,name'])
                    ->orderByDesc('reserved_at')
                    ->lazy(),
                'map' => fn (LibraryReservation $row) => [
                    $row->reservation_code, $row->bibliography?->title, $row->member?->member_number,
                    $row->member?->display_name, $row->status, $row->queue_position,
                    $row->reserved_at?->toDateString(), $row->expires_at?->toDateString(),
                ],
            ],
            'acquisitions' => [
                'headers' => ['code', 'supplier', 'status', 'requested_at', 'received_at', 'total_amount'],
                'rows' => \App\Models\LibraryAcquisitionRequest::query()
                    ->when($libraryId !== null, fn (Builder $q) => $q->where('library_id', $libraryId))
                    ->with(['supplier:id,name'])
                    ->orderByDesc('requested_at')
                    ->lazy(),
                'map' => fn (\App\Models\LibraryAcquisitionRequest $row) => [
                    $row->code, $row->supplier?->name ?? $row->supplier_name, $row->status,
                    $row->requested_at?->toDateString(), $row->received_at?->toIso8601String(),
                    (float) $row->total_amount,
                ],
            ],
            default => throw new \InvalidArgumentException("Jenis ekspor \"{$type}\" tidak dikenal."),
        };
    }

    /**
     * Kelompokkan berdasarkan kolom id + tabel referensi (label).
     *
     * @return list<array{label:string,total:int}>
     */
    protected function grouped(Builder $query, string $column, string $table): array
    {
        $rows = (clone $query)
            ->whereNotNull($column)
            ->select($column, DB::raw('count(*) as total'))
            ->groupBy($column)
            ->orderByDesc('total')
            ->limit(50)
            ->get();

        $labels = DB::table($table)->whereIn('id', $rows->pluck($column))->pluck('name', 'id');

        return $rows->map(fn ($row) => [
            'label' => (string) ($labels[$row->{$column}] ?? 'Tidak diketahui'),
            'total' => (int) $row->total,
        ])->values()->all();
    }

    protected function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}