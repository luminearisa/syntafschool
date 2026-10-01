<?php

namespace App\Modules\Activity\Infrastructure\Providers;

use App\Models\BoardingActivitySchedule;
use App\Models\BoardingPlacement;
use App\Modules\Activity\Domain\Contracts\BoardingProviderInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Jadwal asrama untuk deteksi bentrok (§59, §98).
 *
 * Modul Activity tidak menyimpan jadwal asrama dan tidak mengubahnya; ia hanya
 * menanyakannya. Bila jadwal kegiatan asrama beririsan dengan jadwal aktivitas,
 * pengajuan keanggotaan ditandai perlu izin — bukan ditolak diam-diam.
 */
class EloquentBoardingProvider implements BoardingProviderInterface
{
    public function obligationsFor(int $studentId, string $date): array
    {
        $placements = $this->placementsFor($studentId);

        if ($placements === []) {
            return [];
        }

        $dayOfWeek = (int) date('N', strtotime($date));

        $rows = BoardingActivitySchedule::query()
            ->when($this->hasColumn('is_active'), fn (Builder $query) => $query->where('is_active', true))
            ->when($this->hasColumn('day_of_week'), fn (Builder $query) => $query->where('day_of_week', $dayOfWeek))
            ->with('activity:id,name')
            ->get();

        return $rows->map(fn ($row) => [
            'activity' => $row->activity?->name,
            'start_time' => $this->time($row->start_time),
            'end_time' => $this->time($row->end_time),
            'dormitory_id' => $row->dormitory_id ?? null,
        ])->all();
    }

    public function placementsFor(int $studentId): array
    {
        return BoardingPlacement::query()
            ->where('student_id', $studentId)
            ->with(['dormitory:id,name', 'room:id,name'])
            ->get()
            ->map(fn (BoardingPlacement $placement) => [
                'id' => (int) $placement->id,
                'dormitory' => $placement->dormitory?->name,
                'room' => $placement->room?->name,
                'status' => $placement->status,
            ])
            ->all();
    }

    public function weeklyObligations(): array
    {
        return BoardingActivitySchedule::query()
            ->when($this->hasColumn('is_active'), fn (Builder $query) => $query->where('is_active', true))
            ->with('activity:id,name')
            ->get()
            ->map(fn ($row) => [
                'activity' => $row->activity?->name,
                'day_of_week' => (int) $row->day_of_week,
                'start_time' => $this->time($row->start_time),
                'end_time' => $this->time($row->end_time),
            ])
            ->all();
    }

    /**
     * Normalisasi jam ke `H:i:s`.
     *
     * Kolom `time` dapat dikembalikan sebagai string atau Carbon (bergantung
     * cast model tetangga). Tanpa normalisasi, perbandingan bentrok akan
     * membandingkan "2026-10-01T15:00:00Z" dengan "15:30" — dan bentrok
     * yang nyata tidak terdeteksi.
     */
    protected function time(mixed $value): string
    {
        if ($value === null) {
            return '00:00:00';
        }

        $string = $value instanceof \DateTimeInterface ? $value->format('H:i:s') : (string) $value;

        if (str_contains($string, 'T')) {
            return substr($string, 11, 8) ?: '00:00:00';
        }

        return strlen($string) === 5 ? $string.':00' : $string;
    }

    protected function hasColumn(string $column): bool
    {
        static $columns = null;

        if ($columns === null) {
            $columns = \Illuminate\Support\Facades\Schema::getColumnListing('boarding_activity_schedules');
        }

        return in_array($column, $columns, true);
    }
}
