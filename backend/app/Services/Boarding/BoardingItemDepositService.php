<?php

namespace App\Services\Boarding;

use App\Models\BoardingItemDeposit;
use App\Models\BoardingItemDepositItem;
use App\Models\BoardingItemDepositItemLoan;
use App\Models\Student;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Titipan barang santri di pengasuhan (laptop/HP/kamera → gudang titipan).
 *
 * Aturan inti (reliable):
 * - peminjaman mengambil kunci baris barang (`lockForUpdate`) sehingga dua
 *   petugas yang menekan "Pinjamkan" bersamaan tidak membuka dua pinjaman;
 * - satu barang hanya punya satu pinjaman TERBUKA — riwayat penuh di tabel
 *   loans, status terkini tetap cepat dibaca dari kolom `status`;
 * - titipan hanya bisa ditutup bila tidak ada barang yang sedang dipinjam;
 *   barang di gudang otomatis menjadi `returned_permanently`;
 * - semua mutasi (batal/pengeditan) hanya boleh saat titipan masih aktif.
 */
class BoardingItemDepositService
{
    public function __construct(private readonly BoardingScopeService $scope) {}

    /**
     * Daftar titipan dengan filter pencarian & status.
     *
     * @param  array<string, mixed>  $params
     */
    public function list(array $params, User $actor): LengthAwarePaginator
    {
        $allowed = $this->scope->studentIdsFor($actor);

        $query = BoardingItemDeposit::query()
            ->with([
                'student:id,full_name,nis',
                'receivedBy:id,name',
                'items' => fn ($q) => $q->orderBy('id'),
            ])
            ->when($allowed !== null, fn (Builder $q) => $q->whereIn('student_id', $allowed ?: [-1]))
            ->when(filled($params['status'] ?? null), function (Builder $q) use ($params): void {
                $status = (string) $params['status'];
                // Filter status barang (borrowed/in_storage) dipetakan ke level titipan:
                // tampilkan titipan yang MEMILIKI barang berstatus itu.
                if (in_array($status, [BoardingItemDepositItem::STATUS_BORROWED, BoardingItemDepositItem::STATUS_IN_STORAGE], true)) {
                    $q->active()->whereHas('items', fn (Builder $i) => $i->where('status', $status));
                } else {
                    $q->where('status', $status);
                }
            })
            ->when(filled($params['search'] ?? null), function (Builder $q) use ($params): void {
                $term = trim((string) $params['search']);
                $q->whereHas('student', fn (Builder $s) => $s
                    ->where('full_name', 'like', "%$term%")
                    ->orWhere('nis', 'like', "%$term%"));
            })
            ->when(filled($params['item_search'] ?? null), function (Builder $q) use ($params): void {
                $term = trim((string) $params['item_search']);
                $q->whereHas('items', fn (Builder $i) => $i
                    ->where('item_name', 'like', "%$term%")
                    ->orWhere('item_type', $term));
            })
            ->orderByDesc('deposited_at')
            ->orderByDesc('id');

        $perPage = max(1, min(50, (int) ($params['per_page'] ?? 15)));

        return $query->paginate($perPage)->appends($params);
    }

    public function show(BoardingItemDeposit $deposit, User $actor): BoardingItemDeposit
    {
        $this->scope->assertCanAccessStudent($actor, (int) $deposit->student_id);

        return $deposit->load([
            'student:id,full_name,nis',
            'receivedBy:id,name',
            'closedBy:id,name',
            'items.currentLoan',
            'items.loans.processedBy:id,name',
        ]);
    }

    /**
     * Buat titipan baru beserta barang-barangnya (minimal satu barang).
     *
     * @param  array<string, mixed>  $data
     */
    public function store(array $data, User $actor): BoardingItemDeposit
    {
        $student = Student::query()->findOrFail($data['student_id'] ?? 0);

        if (! (bool) $student->is_active) {
            throw ValidationException::withMessages([
                'student_id' => 'Santri tidak aktif, titipan tidak dapat dibuat.',
            ]);
        }

        $this->scope->assertCanAccessStudent($actor, (int) $student->id);

        $items = array_values(array_filter((array) ($data['items'] ?? []), fn (mixed $i): bool => is_array($i) && trim((string) ($i['item_name'] ?? '')) !== ''));

        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => 'Minimal satu barang harus ditambahkan.',
            ]);
        }

        $deposit = DB::transaction(function () use ($data, $items, $student, $actor): BoardingItemDeposit {
            /** @var BoardingItemDeposit $deposit */
            $deposit = BoardingItemDeposit::query()->create([
                'student_id' => $student->id,
                'received_by' => $actor->id,
                'deposited_at' => $this->timestampOrNow($data['deposited_at'] ?? null),
                'status' => BoardingItemDeposit::STATUS_ACTIVE,
                'notes' => $this->nullableText($data['notes'] ?? null),
            ]);

            foreach ($items as $item) {
                $deposit->items()->create([
                    'item_type' => $this->itemType($item['item_type'] ?? null),
                    'item_name' => trim((string) $item['item_name']),
                    'status' => BoardingItemDepositItem::STATUS_IN_STORAGE,
                    'notes' => $this->nullableText($item['notes'] ?? null),
                ]);
            }

            return $deposit;
        });

        $this->audit($deposit, $actor, 'BOARDING_ITEM_DEPOSIT_CREATED', sprintf(
            'Titipan %d barang dari %s dicatat oleh %s.',
            $deposit->items()->count(),
            (string) $student->full_name,
            (string) $actor->name,
        ));

        return $this->show($deposit, $actor);
    }

    /** Tambah barang ke titipan aktif. */
    public function addItem(BoardingItemDeposit $deposit, array $data, User $actor): BoardingItemDepositItem
    {
        $this->assertActive($deposit);
        $this->scope->assertCanAccessStudent($actor, (int) $deposit->student_id);

        $item = $deposit->items()->create([
            'item_type' => $this->itemType($data['item_type'] ?? null),
            'item_name' => trim((string) ($data['item_name'] ?? '')),
            'status' => BoardingItemDepositItem::STATUS_IN_STORAGE,
            'notes' => $this->nullableText($data['notes'] ?? null),
        ]);

        $this->audit($deposit, $actor, 'BOARDING_ITEM_DEPOSIT_ITEM_ADDED', sprintf(
            'Barang "%s" ditambahkan ke titipan %s oleh %s.',
            (string) $item->item_name,
            (string) ($deposit->student?->full_name ?? '#'.$deposit->student_id),
            (string) $actor->name,
        ));

        return $item;
    }

    /** Ubah barang — hanya bila titipan aktif dan barang sedang di gudang. */
    public function updateItem(BoardingItemDeposit $deposit, BoardingItemDepositItem $item, array $data, User $actor): BoardingItemDepositItem
    {
        $this->assertActive($deposit);
        $this->assertItemOf($deposit, $item);
        $this->scope->assertCanAccessStudent($actor, (int) $deposit->student_id);

        if ($item->status !== BoardingItemDepositItem::STATUS_IN_STORAGE) {
            throw ValidationException::withMessages([
                'item' => 'Barang yang sedang dipinjam atau sudah diambil tidak dapat diubah. Kembalikan dulu bila sedang dipinjam.',
            ]);
        }

        $item->update([
            'item_type' => $this->itemType($data['item_type'] ?? $item->item_type),
            'item_name' => trim((string) ($data['item_name'] ?? $item->item_name)),
            'notes' => $this->nullableText($data['notes'] ?? $item->notes),
        ]);

        return $item->refresh();
    }

    public function removeItem(BoardingItemDeposit $deposit, BoardingItemDepositItem $item, User $actor): void
    {
        $this->assertActive($deposit);
        $this->assertItemOf($deposit, $item);
        $this->scope->assertCanAccessStudent($actor, (int) $deposit->student_id);

        if ($item->status === BoardingItemDepositItem::STATUS_BORROWED) {
            throw ValidationException::withMessages([
                'item' => 'Barang sedang dipinjam. Terima kembali dulu sebelum menghapus.',
            ]);
        }

        $item->delete();

        $this->audit($deposit, $actor, 'BOARDING_ITEM_DEPOSIT_ITEM_REMOVED', sprintf(
            'Barang "%s" dihapus dari titipan #%d oleh %s.',
            (string) $item->item_name,
            (int) $deposit->id,
            (string) $actor->name,
        ));
    }

    /** Pinjamkan barang kepada santri (membuka satu pinjaman). */
    public function borrow(BoardingItemDeposit $deposit, BoardingItemDepositItem $item, ?string $notes, User $actor): BoardingItemDepositItem
    {
        $this->assertActive($deposit);
        $this->assertItemOf($deposit, $item);
        $this->scope->assertCanAccessStudent($actor, (int) $deposit->student_id);

        return DB::transaction(function () use ($deposit, $item, $notes, $actor): BoardingItemDepositItem {
            /** @var BoardingItemDepositItem $locked */
            $locked = BoardingItemDepositItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== BoardingItemDepositItem::STATUS_IN_STORAGE) {
                throw ValidationException::withMessages([
                    'item' => 'Barang "'.$locked->item_name.'" tidak sedang berada di gudang (status saat ini: '.$locked->statusLabel().').',
                ]);
            }

            BoardingItemDepositItemLoan::query()->create([
                'item_id' => $locked->id,
                'borrowed_at' => Carbon::now(),
                'notes' => $this->nullableText($notes),
                'processed_by' => $actor->id,
            ]);

            $locked->forceFill(['status' => BoardingItemDepositItem::STATUS_BORROWED])->save();

            $this->audit($deposit, $actor, 'BOARDING_ITEM_DEPOSIT_ITEM_BORROWED', sprintf(
                'Barang "%s" (%s) dipinjamkan kepada %s oleh %s.',
                (string) $locked->item_name,
                $locked->itemTypeLabel(),
                (string) ($deposit->student?->full_name ?? '#'.$deposit->student_id),
                (string) $actor->name,
            ));

            return $locked->refresh();
        });
    }

    /** Terima kembali barang yang dipinjam (menutup pinjaman terbuka). */
    public function returnItem(BoardingItemDeposit $deposit, BoardingItemDepositItem $item, ?string $notes, User $actor): BoardingItemDepositItem
    {
        $this->assertActive($deposit);
        $this->assertItemOf($deposit, $item);
        $this->scope->assertCanAccessStudent($actor, (int) $deposit->student_id);

        return DB::transaction(function () use ($deposit, $item, $notes, $actor): BoardingItemDepositItem {
            /** @var BoardingItemDepositItem $locked */
            $locked = BoardingItemDepositItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            $loan = $locked->loans()->whereNull('returned_at')->first();

            if ($locked->status !== BoardingItemDepositItem::STATUS_BORROWED || $loan === null) {
                throw ValidationException::withMessages([
                    'item' => 'Barang "'.$locked->item_name.'" tidak sedang dipinjam.',
                ]);
            }

            $loan->forceFill(['returned_at' => Carbon::now(), 'processed_by' => $actor->id])->save();
            if (trim((string) $notes) !== '') {
                $loan->forceFill(['notes' => trim((string) ($loan->notes ?? '').' | Kembali: '.$notes)])->save();
            }

            $locked->forceFill(['status' => BoardingItemDepositItem::STATUS_IN_STORAGE])->save();

            $this->audit($deposit, $actor, 'BOARDING_ITEM_DEPOSIT_ITEM_RETURNED', sprintf(
                'Barang "%s" kembali ke gudang titipan oleh %s.',
                (string) $locked->item_name,
                (string) $actor->name,
            ));

            return $locked->refresh();
        });
    }

    /**
     * Tutup titipan: seluruh barang di gudang diserahkan permanen ke santri.
     * Ditolak bila masih ada barang yang dipinjam.
     */
    public function finish(BoardingItemDeposit $deposit, User $actor): BoardingItemDeposit
    {
        $this->assertCanFinish($deposit, $actor);

        DB::transaction(function () use ($deposit, $actor): void {
            /** @var BoardingItemDeposit $locked */
            $locked = BoardingItemDeposit::query()->whereKey($deposit->id)->lockForUpdate()->firstOrFail();

            $borrowed = $locked->items()->where('status', BoardingItemDepositItem::STATUS_BORROWED)->count();
            if ($borrowed > 0) {
                throw ValidationException::withMessages([
                    'deposit' => 'Masih ada '.$borrowed.' barang yang dipinjam. Terima kembali dulu sebelum menutup titipan.',
                ]);
            }

            $locked->items()
                ->where('status', BoardingItemDepositItem::STATUS_IN_STORAGE)
                ->update(['status' => BoardingItemDepositItem::STATUS_RETURNED_PERMANENTLY]);

            $locked->forceFill([
                'status' => BoardingItemDeposit::STATUS_RETURNED,
                'returned_at' => Carbon::now(),
                'closed_by' => $actor->id,
            ])->save();
        });

        $this->audit($deposit, $actor, 'BOARDING_ITEM_DEPOSIT_FINISHED', sprintf(
            'Titipan %s ditutup; seluruh barang diserahkan permanen.',
            (string) ($deposit->student?->full_name ?? '#'.$deposit->student_id),
        ));

        return $deposit->refresh();
    }

    public function destroy(BoardingItemDeposit $deposit, User $actor): void
    {
        $this->scope->assertCanAccessStudent($actor, (int) $deposit->student_id);

        if ($deposit->items()->where('status', BoardingItemDepositItem::STATUS_BORROWED)->exists()) {
            throw ValidationException::withMessages([
                'deposit' => 'Masih ada barang yang dipinjam. Terima kembali dulu sebelum menghapus titipan.',
            ]);
        }

        $deposit->delete();

        $this->audit($deposit, $actor, 'BOARDING_ITEM_DEPOSIT_DELETED', sprintf(
            'Titipan #%d milik %s dihapus oleh %s.',
            (int) $deposit->id,
            (string) ($deposit->student?->full_name ?? '#'.$deposit->student_id),
            (string) $actor->name,
        ));
    }

    // ------------------------------------------------------------------

    private function assertActive(BoardingItemDeposit $deposit): void
    {
        if (! $deposit->isActive()) {
            throw ValidationException::withMessages([
                'deposit' => 'Titipan ini sudah ditutup, tidak dapat diubah lagi.',
            ]);
        }
    }

    private function assertCanFinish(BoardingItemDeposit $deposit, User $actor): void
    {
        $this->assertActive($deposit);
        $this->scope->assertCanAccessStudent($actor, (int) $deposit->student_id);
    }

    private function assertItemOf(BoardingItemDeposit $deposit, BoardingItemDepositItem $item): void
    {
        if ((int) $item->deposit_id !== (int) $deposit->id) {
            throw ValidationException::withMessages([
                'item' => 'Barang ini bukan milik titipan tersebut.',
            ]);
        }
    }

    private function itemType(mixed $value): string
    {
        $type = strtolower(trim((string) ($value ?? BoardingItemDepositItem::TYPE_CUSTOM)));

        return in_array($type, BoardingItemDepositItem::TYPES, true) ? $type : BoardingItemDepositItem::TYPE_CUSTOM;
    }

    private function timestampOrNow(mixed $value): Carbon
    {
        if (is_string($value) && trim($value) !== '') {
            return Carbon::parse($value);
        }

        return Carbon::now();
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    /** Laporan mutasi ke log audit kanal boarding. */
    private function audit(BoardingItemDeposit $deposit, User $actor, string $event, string $message): void
    {
        activity('boarding')
            ->event($event)
            ->performedOn($deposit)
            ->causedBy($actor)
            ->withProperties([
                'deposit_id' => (int) $deposit->id,
                'student_id' => (int) $deposit->student_id,
            ])
            ->log($message);
    }
}
