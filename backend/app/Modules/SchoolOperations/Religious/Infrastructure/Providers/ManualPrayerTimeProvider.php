<?php

namespace App\Modules\SchoolOperations\Religious\Infrastructure\Providers;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\PrayerTimeProviderInterface;
use App\Modules\SchoolOperations\Religious\Models\PrayerSchedule;
use Illuminate\Support\Facades\DB;

/**
 * Penyedia jadwal MANUAL (§12): membaca jadwal yang sudah dimasukkan
 * operator institusi ke tabel `prayer_schedules`.
 *
 * Jadwal manual tetap yang paling diutamakan karena institusi sering
 * menyesuaikan iqamah mengikuti kebiasaan setempat.
 */
class ManualPrayerTimeProvider implements PrayerTimeProviderInterface
{
    public function __construct(protected \App\Modules\SchoolOperations\Religious\Domain\Support\ReligiousContext $context)
    {
    }

    public function providerCode(): string
    {
        return 'MANUAL';
    }

    public function scheduleFor(string $date, array $options = []): array
    {
        $query = PrayerSchedule::query()->whereDate('schedule_date', $date);

        if (! empty($options['mosque_id'])) {
            $query->where('mosque_id', (int) $options['mosque_id']);
        }
        if (! empty($options['campus_id'])) {
            $query->where('campus_id', (int) $options['campus_id']);
        }

        $rows = $query->orderBy('adhan_time')->get();
        if ($rows->isEmpty()) {
            return [];
        }

        return $rows->map(fn (PrayerSchedule $row): array => [
            'prayer_type' => strtoupper((string) $row->prayer_type),
            'adhan_time' => $this->short($row->adhan_time),
            'iqamah_time' => $this->short($row->iqamah_time),
            'start_time' => $this->short($row->start_time),
            'end_time' => $this->short($row->end_time),
            'status' => $row->status,
            'source' => 'MANUAL',
            'schedule_id' => $row->getKey(),
        ])->all();
    }

    public function rangeFor(string $startDate, string $endDate, array $options = []): array
    {
        $query = PrayerSchedule::query()
            ->whereBetween('schedule_date', [$startDate, $endDate]);

        if (! empty($options['mosque_id'])) {
            $query->where('mosque_id', (int) $options['mosque_id']);
        }

        $grouped = [];
        foreach ($query->orderBy('schedule_date')->get() as $row) {
            $key = $row->schedule_date instanceof \DateTimeInterface
                ? $row->schedule_date->format('Y-m-d')
                : (string) $row->schedule_date;
            $grouped[$key][] = [
                'prayer_type' => strtoupper((string) $row->prayer_type),
                'adhan_time' => $this->short($row->adhan_time),
                'iqamah_time' => $this->short($row->iqamah_time),
                'start_time' => $this->short($row->start_time),
                'end_time' => $this->short($row->end_time),
                'source' => 'MANUAL',
            ];
        }

        return $grouped;
    }

    public function capabilities(): array
    {
        return [
            'source' => 'MANUAL',
            'label' => 'Jadwal manual operator',
            'supports_ramadan' => true,
            'supports_iqamah' => true,
            'requires_network' => false,
        ];
    }

    public function available(): bool
    {
        return $this->context->hasTable('prayer_schedules');
    }

    public function unavailableReason(): ?string
    {
        return $this->available() ? null : 'Tabel jadwal shalat belum tersedia.';
    }

    protected function short(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $text = is_string($value) ? $value : (string) $value;

        return substr($text, 0, 5);
    }
}
