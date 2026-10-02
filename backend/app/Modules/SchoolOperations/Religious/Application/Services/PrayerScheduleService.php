<?php

namespace App\Modules\SchoolOperations\Religious\Application\Services;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\PrayerTimeProviderInterface;
use App\Modules\SchoolOperations\Religious\Domain\Support\ReligiousContext;
use App\Modules\SchoolOperations\Religious\Models\{
    PrayerSchedule, PrayerScheduleOverride, PrayerScheduleVersion, PrayerTimeSource, PrayerType
};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Jadwal shalat (§10-§14).
 *
 * - Jenis shalat configurable (bukan hanya lima waktu wajib) — §10.
 * - Sumber waktu lewat PrayerTimeProviderInterface — §12.
 * - Setiap perubahan jadwal tercatat sebagai versi — §13.
 * - Adhan, iqamah, mulai, dan selesai dipisah — §14.
 * - Aturan 1: jadwal wajib terikat masjid/kampus.
 */
class PrayerScheduleService
{
    public function __construct(
        protected ReligiousContext $context,
        protected PrayerTimeProviderInterface $provider,
    ) {
    }

    // ── Jenis shalat (§10) ───────────────────────────────────────────────

    public function seedPrayerTypes(): int
    {
        $count = 0;
        foreach ((array) config('religious.prayer_time.types', []) as $code => $definition) {
            PrayerType::query()->updateOrCreate(
                ['code' => strtoupper((string) $code)],
                [
                    'name' => $definition['name'] ?? $code,
                    'arabic_name' => $definition['arabic'] ?? null,
                    'category' => $definition['category'] ?? 'OBLIGATORY',
                    'default_rakaat' => $definition['rakaat'] ?? null,
                    'congregation_required' => (bool) ($definition['congregation'] ?? false),
                    'ramadan_only' => (bool) ($definition['ramadan_only'] ?? false),
                    'sort_order' => (int) ($definition['order'] ?? 0),
                    'active' => true,
                    'config' => $definition['config'] ?? null,
                ]
            );
            $count++;
        }

        return $count;
    }

    public function prayerTypes(bool $activeOnly = true): array
    {
        return PrayerType::query()
            ->when($activeOnly, fn ($q) => $q->where('active', true))
            ->orderBy('sort_order')->get()
            ->map(fn (PrayerType $t): array => [
                'code' => $t->code,
                'name' => $t->name,
                'arabic_name' => $t->arabic_name,
                'category' => $t->category,
                'default_rakaat' => $t->default_rakaat,
                'congregation_required' => (bool) $t->congregation_required,
                'ramadan_only' => (bool) $t->ramadan_only,
            ])->all();
    }

    // ── Sumber waktu (§12) ───────────────────────────────────────────────

    public function sources(): array
    {
        return PrayerTimeSource::query()->orderBy('priority')->get()
            ->map(fn (PrayerTimeSource $s): array => [
                'id' => $s->getKey(),
                'code' => $s->code,
                'name' => $s->name,
                'provider' => $s->provider,
                'priority' => $s->priority,
                'active' => (bool) $s->active,
            ])->all();
    }

    public function providerCapabilities(): array
    {
        return array_merge($this->provider->capabilities(), [
            'active_provider' => $this->provider->providerCode(),
            'available' => $this->provider->available(),
            'reason' => $this->provider->unavailableReason(),
            'configured_priority' => (array) config('religious.prayer_time.priority', []),
        ]);
    }

    // ── Pembuatan jadwal (§11, §13) ─────────────────────────────────

    /**
     * Bangkitkan jadwal harian dari penyedia waktu shalat.
     * Aturan 1: masjid/kampus wajib ada.
     */
    public function generate(string $date, array $options = []): array
    {
        $mosqueId = $options['mosque_id'] ?? null;
        $campusId = $options['campus_id'] ?? null;

        if (! $mosqueId && ! $campusId) {
            throw ValidationException::withMessages([
                'mosque_id' => 'Jadwal shalat wajib terikat masjid atau kampus (§78 Aturan 1).',
            ]);
        }

        $resolved = $this->provider->scheduleFor($date, $options);
        if ($resolved === []) {
            return [
                'created' => 0,
                'date' => $date,
                'source' => $this->provider->providerCode(),
                'provider_available' => $this->provider->available(),
                'reason' => $this->provider->unavailableReason()
                    ?? 'Tidak ada sumber jadwal yang menghasilkan waktu untuk tanggal ini.',
            ];
        }

        $created = [];
        foreach ($resolved as $entry) {
            $created[] = $this->upsertSchedule([
                'schedule_date' => $date,
                'mosque_id' => $mosqueId,
                'campus_id' => $campusId,
                'prayer_type' => $entry['prayer_type'],
                'adhan_time' => $entry['adhan_time'] ?? null,
                'iqamah_time' => $entry['iqamah_time'] ?? null,
                'start_time' => $entry['start_time'] ?? null,
                'end_time' => $entry['end_time'] ?? null,
                'source' => $entry['resolved_by'] ?? ($entry['source'] ?? $this->provider->providerCode()),
            ], 'generate')->getKey();
        }

        return [
            'created' => count($created),
            'date' => $date,
            'source' => $this->provider->providerCode(),
            'schedule_ids' => $created,
        ];
    }

    /** Bangkitkan jadwal untuk rentang tanggal (Ramadan/bulanan). */
    public function generateRange(string $startDate, string $endDate, array $options = []): array
    {
        $out = [];
        $cursor = \Carbon\Carbon::parse($startDate);
        $end = \Carbon\Carbon::parse($endDate);
        $guard = 0;

        while ($cursor->lte($end) && $guard < 400) {
            $out[$cursor->toDateString()] = $this->generate($cursor->toDateString(), $options);
            $cursor->addDay();
            $guard++;
        }

        return $out;
    }

    /**
     * Simpan/perbarui satu baris jadwal. Bila ada perubahan waktu, versi baru
     * dibuat sehingga riwayat perubahan dapat diaudit (§13).
     */
    public function upsertSchedule(array $payload, string $reason = 'manual'): PrayerSchedule
    {
        $date = $payload['schedule_date'];
        $prayerType = strtoupper((string) $payload['prayer_type']);

        $existing = PrayerSchedule::query()
            ->whereDate('schedule_date', $date)
            ->where('prayer_type', $prayerType)
            ->when(! empty($payload['mosque_id']), fn ($q) => $q->where('mosque_id', $payload['mosque_id']))
            ->when(! empty($payload['campus_id']), fn ($q) => $q->where('campus_id', $payload['campus_id']))
            ->first();

        $times = [
            'adhan_time' => $payload['adhan_time'] ?? null,
            'iqamah_time' => $payload['iqamah_time'] ?? null,
            'start_time' => $payload['start_time'] ?? null,
            'end_time' => $payload['end_time'] ?? null,
        ];

        if ($existing) {
            $changed = false;
            foreach ($times as $field => $value) {
                if ($value !== null && $this->short($existing->{$field}) !== $this->short($value)) {
                    $changed = true;
                }
            }

            if ($changed) {
                $this->snapshotVersion($existing, $reason);
            }

            $existing->fill(array_filter($times, fn ($v): bool => $v !== null) + [
                'status' => $payload['status'] ?? $existing->status,
                'source' => $payload['source'] ?? $existing->source,
                'is_ramadan' => $payload['is_ramadan'] ?? $existing->is_ramadan,
                'version_number' => $changed ? ((int) $existing->version_number + 1) : $existing->version_number,
            ])->save();

            if ($changed) {
                $this->context->audit('religious.prayer_schedule.changed', $existing, [
                    'mosque_id' => $existing->mosque_id, 'campus_id' => $existing->campus_id,
                    'reason' => $reason,
                ]);
                $this->context->publish('PrayerScheduleChanged', $existing, [
                    'date' => $date, 'prayer_type' => $prayerType,
                ]);
            }

            return $existing;
        }

        $schedule = PrayerSchedule::query()->create(array_merge($times, [
            'schedule_date' => $date,
            'mosque_id' => $payload['mosque_id'] ?? null,
            'campus_id' => $payload['campus_id'] ?? null,
            'prayer_type' => $prayerType,
            'status' => strtoupper((string) ($payload['status'] ?? 'SCHEDULED')),
            'source' => strtoupper((string) ($payload['source'] ?? 'MANUAL')),
            'source_id' => $payload['source_id'] ?? null,
            'is_ramadan' => (bool) ($payload['is_ramadan'] ?? false),
            'version_number' => 1,
            'timezone' => $payload['timezone'] ?? config('religious.prayer_time.timezone'),
            'notes' => $payload['notes'] ?? null,
            'meta' => $payload['meta'] ?? null,
        ]));

        $this->context->audit('religious.prayer_schedule.created', $schedule, [
            'mosque_id' => $schedule->mosque_id, 'campus_id' => $schedule->campus_id,
        ]);
        $this->context->publish('PrayerScheduleCreated', $schedule, [
            'date' => $date, 'prayer_type' => $prayerType, 'source' => $schedule->source,
        ]);

        return $schedule;
    }

    protected function snapshotVersion(PrayerSchedule $schedule, string $reason): PrayerScheduleVersion
    {
        return PrayerScheduleVersion::query()->create([
            'schedule_id' => $schedule->getKey(),
            'version_number' => (int) $schedule->version_number,
            'snapshot' => $schedule->only(['adhan_time', 'iqamah_time', 'start_time', 'end_time', 'status', 'source']),
            'change_reason' => $reason,
            'changed_by' => $this->context->actorId(),
            'effective_from' => $schedule->schedule_date,
            'created_by' => $this->context->actorId(),
        ]);
    }

    /** Riwayat versi jadwal (§13). */
    public function versions(int $scheduleId): array
    {
        return PrayerScheduleVersion::query()->where('schedule_id', $scheduleId)
            ->orderByDesc('version_number')->get()
            ->map(fn (PrayerScheduleVersion $v): array => [
                'version_number' => $v->version_number,
                'snapshot' => $v->snapshot,
                'change_reason' => $v->change_reason,
                'changed_by' => $v->changed_by,
                'created_at' => $v->created_at?->toIso8601String(),
            ])->all();
    }

    // ── Penimpaan (§13) ──────────────────────────────────────────────────

    /** Timpa jadwal untuk tanggal tertentu (mis. perubahan musim/kebijakan). */
    public function override(string $date, string $prayerType, array $times, string $reason, array $context = []): PrayerScheduleOverride
    {
        $schedule = PrayerSchedule::query()
            ->whereDate('schedule_date', $date)
            ->where('prayer_type', strtoupper($prayerType))
            ->when(! empty($context['mosque_id']), fn ($q) => $q->where('mosque_id', $context['mosque_id']))
            ->first();

        $override = PrayerScheduleOverride::query()->create([
            'prayer_schedule_id' => $schedule?->getKey(),
            'campus_id' => $context['campus_id'] ?? $schedule?->campus_id,
            'mosque_id' => $context['mosque_id'] ?? $schedule?->mosque_id,
            'override_date' => $date,
            'prayer_type' => strtoupper($prayerType),
            'adhan_time' => $times['adhan_time'] ?? null,
            'iqamah_time' => $times['iqamah_time'] ?? null,
            'reason' => $reason,
            'approved_by' => $this->context->actorId(),
            'status' => 'ACTIVE',
            'notes' => $times['notes'] ?? null,
        ]);

        if ($schedule) {
            $this->snapshotVersion($schedule, 'override: '.$reason);
            $schedule->forceFill([
                'status' => 'OVERRIDDEN',
                'adhan_time' => $times['adhan_time'] ?? $schedule->adhan_time,
                'iqamah_time' => $times['iqamah_time'] ?? $schedule->iqamah_time,
                'end_time' => $times['end_time'] ?? $schedule->end_time,
                'version_number' => (int) $schedule->version_number + 1,
            ])->save();
        }

        $this->context->audit('religious.prayer_schedule.overridden', $override, [
            'mosque_id' => $override->mosque_id, 'campus_id' => $override->campus_id,
        ], $reason);

        return $override;
    }

    // ── Pembacaan ────────────────────────────────────────────────────────────

    /** Jadwal satu hari lengkap dengan iqamah dan waktu berikutnya (§11, §65). */
    public function forDate(string $date, ?int $mosqueId = null, ?int $campusId = null): array
    {
        $query = PrayerSchedule::query()->whereDate('schedule_date', $date)
            ->when($mosqueId, fn ($q) => $q->where('mosque_id', $mosqueId))
            ->when($campusId, fn ($q) => $q->where('campus_id', $campusId));

        $rows = $query->get()->keyBy(fn (PrayerSchedule $s): string => strtoupper((string) $s->prayer_type));

        $out = [];
        foreach ($this->prayerOrder() as $code) {
            $row = $rows->get($code);
            if (! $row) {
                continue;
            }
            $out[] = [
                'prayer_type' => $code,
                'name' => $this->prayerLabel($code),
                'adhan_time' => $this->short($row->adhan_time),
                'iqamah_time' => $this->short($row->iqamah_time),
                'start_time' => $this->short($row->start_time ?? $row->adhan_time),
                'end_time' => $this->short($row->end_time),
                'status' => $row->status,
                'source' => $row->source,
                'version' => (int) $row->version_number,
                'schedule_id' => $row->getKey(),
            ];
        }

        return [
            'date' => $date,
            'mosque_id' => $mosqueId,
            'campus_id' => $campusId,
            'prayers' => $out,
            'next' => $this->nextPrayer($out, now()->format('H:i')),
            'source' => $this->provider->providerCode(),
        ];
    }

    /** Shalat berikutnya dari daftar jadwal (§65, §66). */
    public function nextPrayer(array $prayers, string $at): ?array
    {
        $nowMinutes = $this->context->timeToMinutes($at);

        foreach ($prayers as $prayer) {
            $adhan = $this->context->timeToMinutes($prayer['adhan_time'] ?? null);
            if ($adhan !== null && $nowMinutes !== null && $adhan > $nowMinutes) {
                return $prayer + ['starts_in_minutes' => $adhan - $nowMinutes];
            }
        }

        return null;
    }

    /** Label shalat dari konfigurasi institusi (terminologi dapat diubah §23). */
    public function prayerLabel(string $code): string
    {
        return (string) config('religious.prayer_time.types.'.strtoupper($code).'.name', ucfirst(strtolower($code)));
    }

    /** Urutan shalat untuk tampilan. */
    public function prayerOrder(): array
    {
        $configured = array_keys((array) config('religious.prayer_time.types', []));
        if ($configured !== []) {
            return array_map('strtoupper', $configured);
        }

        return ['FAJR', 'DHUHR', 'ASR', 'MAGHRIB', 'ISHA'];
    }

    /** Jadwal mingguan (untuk rekap laporan §74). */
    public function weekly(?int $mosqueId = null, ?string $from = null): array
    {
        $start = $from ? \Carbon\Carbon::parse($from) : now()->startOfWeek();
        $out = [];

        for ($i = 0; $i < 7; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();
            $out[$date] = $this->forDate($date, $mosqueId)['prayers'];
        }

        return $out;
    }

    /** Statistik ringkas pemakaian sumber jadwal (audit §13). */
    public function sourceBreakdown(string $from, string $to): array
    {
        return DB::table('prayer_schedules')
            ->whereBetween('schedule_date', [$from, $to])
            ->selectRaw('source, count(*) as total')
            ->groupBy('source')->orderByDesc('total')->get()
            ->map(fn ($row): array => ['source' => $row->source, 'total' => (int) $row->total])->all();
    }

    protected function short(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr(is_string($value) ? $value : (string) $value, 0, 5);
    }
}
