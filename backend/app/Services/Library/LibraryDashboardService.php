<?php

namespace App\Services\Library;

use App\Models\Library;
use App\Models\LibraryBibliography;
use App\Models\LibraryClosure;
use App\Models\LibraryItem;
use App\Models\LibraryLocation;
use App\Models\LibraryOperatingHour;
use App\Services\Library\Circulation\CirculationService;
use App\Services\Library\Circulation\FineService;
use App\Services\Library\Circulation\ReservationService;
use App\Services\Library\Membership\MembershipService;
use Illuminate\Support\Carbon;

/**
 * Ringkasan dashboard sebuah perpustakaan.
 *
 * Hanya data yang benar-benar tersedia yang ditampilkan. Statistik yang
 * sumber datanya baru akan ada pada Step berikutnya (buku, anggota,
 * peminjaman) dikembalikan sebagai `null` — antarmuka menampilkannya
 * sebagai "—", bukan angka 0 palsu.
 */
class LibraryDashboardService
{
    public function __construct(
        protected LibrarySetupService $setup,
        protected MembershipService $members,
        protected CirculationService $circulation,
        protected FineService $fines,
        protected ReservationService $reservations,
    ) {
    }

    public function forLibrary(Library $library): array
    {
        $timezone = filled($library->timezone) ? $library->timezone : config('app.timezone', 'UTC');
        $now = Carbon::now($timezone);
        $today = $now->toDateString();
        $dayOfWeek = (int) $now->dayOfWeek;

        $hours = LibraryOperatingHour::query()
            ->where('library_id', $library->id)
            ->where('day_of_week', $dayOfWeek)
            ->orderBy('open_time')
            ->get();

        $closures = LibraryClosure::query()
            ->where('library_id', $library->id)
            ->whereDate('date', $today)
            ->where('status', LibraryClosure::STATUS_ACTIVE)
            ->get();

        $setup = $this->setup->summary($library);

        $members = $this->members->summary($library->id);
        [$bibliographyCount, $activeBibliographyCount, $itemCount, $availableItemCount, $recent] = $this->catalogTotals($library);

        $circulation = $this->circulation->summary($library->id);
        $fines = $this->fines->summary($library->id);
        $reservations = $this->reservations->summary($library->id);

        return [
            'library' => [
                'id' => $library->id,
                'code' => $library->code,
                'name' => $library->name,
                'status' => $library->status,
                'timezone' => $library->timezone,
            ],
            'operating' => [
                'status' => $this->operatingStatus($hours, $closures, $now),
                'today_label' => LibraryOperatingHour::DAY_LABELS[$dayOfWeek] ?? null,
                'today_hours' => $this->todayHours($hours),
                'closures_today' => $closures->map(fn (LibraryClosure $c) => [
                    'reason' => $c->reason,
                    'start_time' => $c->start_time?->format('H:i'),
                    'end_time' => $c->end_time?->format('H:i'),
                ])->values()->all(),
            ],
            'location' => [
                'count' => LibraryLocation::query()
                    ->where('library_id', $library->id)
                    ->where('status', LibraryLocation::STATUS_ACTIVE)
                    ->count(),
            ],
            'configuration' => [
                'percent' => $setup['percent'],
                'status' => $setup['status'],
            ],
            'setup' => $setup['checks'],
            'system' => [
                'is_active' => $library->isActive(),
                'last_update' => $library->updated_at?->toIso8601String(),
            ],
            // Seluruh angka kini punya sumber data (Fase 3 & 4).
            'statistics' => [
                'books' => $bibliographyCount,
                'items' => $itemCount,
                'members' => $members['active'],
                'active_loans' => $circulation['active_loans'],
                'overdue_loans' => $circulation['overdue_loans'],
            ],
            'catalog' => [
                'bibliographies' => $bibliographyCount,
                'bibliographies_active' => $activeBibliographyCount,
                'items' => $itemCount,
                'items_available' => $availableItemCount,
                'recent' => $recent,
            ],
            'members' => $members,
            // Fase 4 — sirkulasi, denda, reservasi (§2).
            'circulation' => $circulation,
            'fines' => $fines,
            'reservations' => $reservations,
            'trend' => $this->circulation->monthlyTrend($library->id, 6),
            'popular' => $this->circulation->popularBooks($library->id, 5),
        ];
    }

    /**
     * Susun angka katalog & keanggotaan.
     *
     * Semua agregat dihitung dengan query count/group, bukan memuat seluruh
     * baris — daftar "recent" dibatasi 5 baris.
     *
     * @return array{0:int,1:int,2:int,3:int,4:list<array<string,mixed>>}
     */
    protected function catalogTotals(Library $library): array
    {
        $scope = fn () => LibraryBibliography::query()->where('library_id', $library->id);

        $bibliographyCount = $scope()->count();
        $activeBibliographyCount = $scope()->where('status', LibraryBibliography::STATUS_ACTIVE)->count();

        $itemScope = fn () => LibraryItem::query()->where('library_id', $library->id);

        $itemCount = $itemScope()->count();
        $availableItemCount = $itemScope()->available()->count();

        $recent = LibraryBibliography::query()
            ->where('library_id', $library->id)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'title', 'slug', 'status', 'created_at'])
            ->map(fn (LibraryBibliography $record) => [
                'id' => $record->id,
                'title' => $record->title,
                'slug' => $record->slug,
                'status' => $record->status,
                'created_at' => $record->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return [$bibliographyCount, $activeBibliographyCount, $itemCount, $availableItemCount, $recent];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, LibraryOperatingHour>  $hours
     * @param  \Illuminate\Support\Collection<int, LibraryClosure>  $closures
     */
    protected function operatingStatus($hours, $closures, Carbon $now): string
    {
        if ($hours->isEmpty()) {
            return 'unset';
        }

        // Penutupan sehari penuh (tanpa jam) menang atas jadwal rutin.
        $fullDay = $closures->first(fn (LibraryClosure $c) => $c->start_time === null && $c->end_time === null);
        if ($fullDay !== null) {
            return 'closed';
        }

        if ($hours->every(fn (LibraryOperatingHour $h) => ! $h->is_open)) {
            return 'closed';
        }

        foreach ($hours as $hour) {
            if (! $hour->is_open || $hour->open_time === null || $hour->close_time === null) {
                continue;
            }

            $open = $now->copy()->setTimeFromTimeString($hour->open_time->format('H:i'));
            $close = $now->copy()->setTimeFromTimeString($hour->close_time->format('H:i'));

            if ($now->between($open, $close) && ! $this->coveredByClosure($closures, $now)) {
                return 'open';
            }
        }

        return 'closed';
    }

    /**
     * @param  \Illuminate\Support\Collection<int, LibraryClosure>  $closures
     */
    protected function coveredByClosure($closures, Carbon $now): bool
    {
        foreach ($closures as $closure) {
            if ($closure->start_time === null || $closure->end_time === null) {
                continue;
            }

            $start = $now->copy()->setTimeFromTimeString($closure->start_time->format('H:i'));
            $end = $now->copy()->setTimeFromTimeString($closure->end_time->format('H:i'));

            if ($now->between($start, $end)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, LibraryOperatingHour>  $hours
     */
    protected function todayHours($hours): ?string
    {
        if ($hours->isEmpty()) {
            return null;
        }

        $intervals = $hours
            ->filter(fn (LibraryOperatingHour $h) => $h->is_open && $h->open_time !== null && $h->close_time !== null)
            ->map(fn (LibraryOperatingHour $h) => $h->open_time->format('H:i').' – '.$h->close_time->format('H:i'))
            ->values();

        return $intervals->isEmpty() ? 'Tutup' : $intervals->implode(', ');
    }
}