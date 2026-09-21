<?php

namespace App\Services\Library\Circulation;

use App\Models\Library;
use App\Models\LibraryBibliography;
use App\Models\LibraryItem;
use App\Models\LibraryMember;
use App\Models\LibraryReservation;
use App\Services\Library\LibrarySettingsService;
use App\Services\Library\NumberingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reservasi (antrean) judul.
 *
 * Alur §11: `waiting` → `available` → `picked_up` → `completed`; bila tidak
 * diambil sebelum tenggat, `available` → `expired`. Posisi antrean dihitung
 * per judul dan tidak pernah menimpa antrean yang lebih dulu.
 *
 * Service ini **tidak** bergantung pada CirculationService (menghindari
 * dependensi melingkar): pengambilan reservasi ditangani controller yang
 * memanggil keduanya.
 */
class ReservationService
{
    public function __construct(
        protected NumberingService $numbering,
        protected LibrarySettingsService $settings,
    ) {
    }

    public function query(Request $request): Builder
    {
        $query = LibraryReservation::query()->with([
            'member:id,member_number,name,user_id',
            'bibliography:id,title,slug,control_number',
            'item:id,item_code,barcode,location_id',
        ]);

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (in_array($column, ['library_id', 'member_id', 'bibliography_id', 'status'], true)) {
                $query->where($column, $value);
            }
        }

        if ($request->boolean('open')) {
            $query->open();
        }

        return $query->orderByRaw('queue_position is null')
            ->orderBy('queue_position')
            ->orderBy('id');
    }

    /**
     * Ajukan reservasi.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(LibraryMember $member, LibraryBibliography $bibliography, array $data = []): LibraryReservation
    {
        /** @var Library $library */
        $library = Library::query()->findOrFail($member->library_id);

        if (! (bool) $this->settings->get($library, 'reservation.enabled', true)) {
            throw ValidationException::withMessages([
                'reservation' => 'Reservasi tidak diaktifkan untuk perpustakaan ini.',
            ]);
        }

        $this->assertMemberEligible($member);

        // Bila eksemplar tersedia, anggota seharusnya meminjam langsung.
        $available = LibraryItem::query()
            ->where('bibliography_id', $bibliography->id)
            ->available()
            ->exists();

        if ($available) {
            throw ValidationException::withMessages([
                'reservation' => 'Eksemplar tersedia — silakan pinjam langsung tanpa reservasi.',
            ]);
        }

        $duplicate = LibraryReservation::query()
            ->where('member_id', $member->id)
            ->where('bibliography_id', $bibliography->id)
            ->open()
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'reservation' => 'Anda sudah memiliki reservasi aktif untuk judul ini.',
            ]);
        }

        return DB::transaction(function () use ($member, $bibliography, $library, $data): LibraryReservation {
            $reservation = LibraryReservation::create([
                'library_id' => $library->id,
                'member_id' => $member->id,
                'bibliography_id' => $bibliography->id,
                'reservation_code' => $this->numbering->next($library, 'reservation'),
                'status' => LibraryReservation::STATUS_WAITING,
                'queue_position' => $this->nextQueuePosition($bibliography->id),
                'reserved_at' => Carbon::now()->toDateString(),
                'notes' => $data['notes'] ?? null,
            ]);

            activity('library')
                ->event('RESERVATION_CREATED')
                ->performedOn($reservation)
                ->withProperties(['queue_position' => $reservation->queue_position] + $this->context())
                ->log('Reservasi dibuat');

            return $reservation->refresh();
        });
    }

    /**
     * Tandai eksemplar tersedia untuk reservasi terdepan pada judul ini.
     *
     * Dipanggil saat pengembalian: eksemplar tidak langsung "available",
     * melainkan ditahan untuk pemesan berikutnya.
     */
    public function markAvailableFor(LibraryBibliography $bibliography, LibraryItem $item): ?LibraryReservation
    {
        $reservation = LibraryReservation::query()
            ->where('bibliography_id', $bibliography->id)
            ->where('status', LibraryReservation::STATUS_WAITING)
            ->orderBy('queue_position')
            ->orderBy('id')
            ->first();

        if ($reservation === null) {
            return null;
        }

        $library = Library::query()->find($reservation->library_id);
        $holdDays = $library !== null
            ? max(1, (int) $this->settings->get($library, 'reservation.hold_days', 2))
            : 2;

        $reservation->update([
            'item_id' => $item->id,
            'status' => LibraryReservation::STATUS_AVAILABLE,
            'available_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addDays($holdDays)->toDateString(),
        ]);

        // Eksemplar ditahan (reserved) sampai diambil atau kedaluwarsa.
        $item->update(['status' => 'reserved']);

        activity('library')
            ->event('RESERVATION_AVAILABLE')
            ->performedOn($reservation)
            ->withProperties([
                'item_id' => $item->id,
                'expires_at' => $reservation->expires_at?->toDateString(),
            ] + $this->context())
            ->log('Reservasi tersedia untuk diambil');

        return $reservation->refresh();
    }

    /**
     * Tandai reservasi sedang diambil (dipanggil sebelum checkout).
     *
     * @param  array<string, mixed>  $data
     */
    public function markPickedUp(LibraryReservation $reservation, array $data = []): LibraryReservation
    {
        if ($reservation->status !== LibraryReservation::STATUS_AVAILABLE) {
            throw ValidationException::withMessages([
                'reservation' => 'Hanya reservasi berstatus tersedia yang dapat diambil.',
            ]);
        }

        if ($reservation->item_id === null) {
            throw ValidationException::withMessages([
                'reservation' => 'Belum ada eksemplar yang disiapkan untuk reservasi ini.',
            ]);
        }

        $reservation->update([
            'status' => LibraryReservation::STATUS_PICKED_UP,
            'picked_up_at' => Carbon::now()->toDateString(),
            'handled_by' => $data['handled_by'] ?? null,
        ]);

        return $reservation->refresh();
    }

    public function complete(LibraryReservation $reservation): LibraryReservation
    {
        $reservation->update(['status' => LibraryReservation::STATUS_COMPLETED]);

        return $reservation->refresh();
    }

    public function cancel(LibraryReservation $reservation, string $reason): LibraryReservation
    {
        if (! $reservation->isOpen()) {
            throw ValidationException::withMessages([
                'reservation' => 'Reservasi ini sudah selesai dan tidak dapat dibatalkan.',
            ]);
        }

        return DB::transaction(function () use ($reservation, $reason): LibraryReservation {
            // Eksemplar yang ditahan dikembalikan ke tersedia (atau ke pemesan berikutnya).
            $item = $reservation->item_id !== null
                ? LibraryItem::query()->find($reservation->item_id)
                : null;

            $reservation->update([
                'status' => LibraryReservation::STATUS_CANCELLED,
                'cancelled_at' => Carbon::now()->toDateString(),
                'notes' => trim(($reservation->notes ?? '')."\n".$reason),
            ]);

            if ($item !== null && $item->status === 'reserved') {
                $next = $this->markAvailableFor($reservation->bibliography, $item);

                if ($next === null) {
                    $item->update(['status' => 'available']);
                }
            }

            activity('library')
                ->event('RESERVATION_CANCELLED')
                ->performedOn($reservation)
                ->withProperties(['reason' => $reason] + $this->context())
                ->log('Reservasi dibatalkan');

            return $reservation->refresh();
        });
    }

    /**
     * Tandai reservasi yang melewati tenggat pengambilan sebagai expired.
     *
     * @return int jumlah yang ditandai
     */
    public function expireLapsed(?int $libraryId = null): int
    {
        $query = LibraryReservation::query()
            ->where('status', LibraryReservation::STATUS_AVAILABLE)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<', Carbon::now()->toDateString())
            ->when($libraryId !== null, fn (Builder $q) => $q->where('library_id', $libraryId));

        $reservations = $query->get();

        foreach ($reservations as $reservation) {
            $item = $reservation->item_id !== null
                ? LibraryItem::query()->find($reservation->item_id)
                : null;

            $reservation->update(['status' => LibraryReservation::STATUS_EXPIRED]);

            activity('library')
                ->event('RESERVATION_EXPIRED')
                ->performedOn($reservation)
                ->log('Reservasi kedaluwarsa karena tidak diambil');

            if ($item !== null && $item->status === 'reserved') {
                $next = $this->markAvailableFor($reservation->bibliography, $item);

                if ($next === null) {
                    $item->update(['status' => 'available']);
                }
            }
        }

        return $reservations->count();
    }

    /**
     * @return array<string, int>
     */
    public function summary(?int $libraryId = null): array
    {
        $scope = fn () => LibraryReservation::query()->when($libraryId !== null, fn (Builder $q) => $q->where('library_id', $libraryId));

        return [
            'waiting' => $scope()->where('status', LibraryReservation::STATUS_WAITING)->count(),
            'available' => $scope()->where('status', LibraryReservation::STATUS_AVAILABLE)->count(),
            'open' => $scope()->open()->count(),
            'expired' => $scope()->where('status', LibraryReservation::STATUS_EXPIRED)->count(),
        ];
    }

    protected function nextQueuePosition(int $bibliographyId): int
    {
        return (int) LibraryReservation::query()
            ->where('bibliography_id', $bibliographyId)
            ->open()
            ->max('queue_position') + 1;
    }

    protected function assertMemberEligible(LibraryMember $member): void
    {
        if (! in_array($member->status, LibraryMember::ACTIVE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'member' => 'Keanggotaan tidak aktif — tidak dapat melakukan reservasi.',
            ]);
        }

        if (! $member->isWithinValidity()) {
            throw ValidationException::withMessages([
                'member' => 'Masa berlaku keanggotaan sudah berakhir.',
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