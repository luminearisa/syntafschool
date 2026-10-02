<?php

namespace App\Modules\SchoolOperations\Religious\Application\Services;

use App\Modules\SchoolOperations\Religious\Domain\Support\ReligiousContext;
use App\Modules\SchoolOperations\Religious\Models\{FridayPrayer, FridayPrayerAssignment, KhatibProfile, Mosque};
use Illuminate\Validation\ValidationException;

/**
 * Shalat Jumat (§27, §28, §92): jadwal, penugasan khatib/imam/muadzin,
 * khutbah, dan pencatatan kehadiran jamaah.
 *
 * Aturan 4: khatib Jumat harus tersedia pada waktu yang ditentukan.
 */
class FridayPrayerService
{
    public function __construct(
        protected ReligiousContext $context,
        protected PersonnelService $personnel,
        protected KhutbahService $khutbahs,
    ) {
    }

    public function schedule(array $payload): FridayPrayer
    {
        $mosqueId = $payload['mosque_id'] ?? null;
        if (! $mosqueId) {
            throw ValidationException::withMessages(['mosque_id' => 'Shalat Jumat wajib terikat masjid.']);
        }

        $date = $payload['prayer_date'] ?? now()->toDateString();

        if (! \Carbon\Carbon::parse($date)->isFriday() && ! ($payload['allow_non_friday'] ?? false)) {
            throw ValidationException::withMessages([
                'prayer_date' => 'Tanggal yang dipilih bukan hari Jumat.',
            ]);
        }

        $prayer = FridayPrayer::query()->updateOrCreate(
            ['mosque_id' => (int) $mosqueId, 'prayer_date' => $date],
            [
                'campus_id' => $payload['campus_id'] ?? Mosque::query()->whereKey($mosqueId)->value('campus_id'),
                'khatib_id' => $payload['khatib_id'] ?? null,
                'imam_id' => $payload['imam_id'] ?? null,
                'muadzin_id' => $payload['muadzin_id'] ?? null,
                'topic' => $payload['topic'] ?? null,
                'theme' => $payload['theme'] ?? null,
                'language' => $payload['language'] ?? config('religious.khutbah.default_language', 'id'),
                'start_time' => $payload['start_time'] ?? config('religious.friday.default_start_time', '12:00'),
                'end_time' => $payload['end_time'] ?? config('religious.friday.default_end_time', '13:00'),
                'estimated_attendance' => $payload['estimated_attendance'] ?? null,
                'khutbah_id' => $payload['khutbah_id'] ?? null,
                'status' => 'SCHEDULED',
                'created_by' => $this->context->actorId(),
                'notes' => $payload['notes'] ?? null,
                'meta' => $payload['meta'] ?? null,
            ]
        );

        // Khutbah yang sudah disetujui dijadwalkan pada Jumat ini.
        if ($prayer->khutbah_id) {
            $khutbah = \App\Modules\SchoolOperations\Religious\Models\Khutbah::query()->find($prayer->khutbah_id);
            if ($khutbah && in_array($khutbah->status, ['APPROVED', 'DRAFT', 'REVIEW'], true)
                && in_array('SCHEDULED', (array) config('religious.khutbah.transitions.'.strtoupper((string) $khutbah->status), []), true)) {
                $this->context->safe(fn () => $this->khutbahs->transition($khutbah, 'SCHEDULED'));
            }
        }

        $this->syncAssignments($prayer);
        $this->context->audit('religious.friday.scheduled', $prayer, ['mosque_id' => $prayer->mosque_id]);
        $this->context->publish('FridayPrayerScheduled', $prayer, [
            'date' => $date, 'mosque_id' => (int) $mosqueId,
        ]);

        return $prayer;
    }

    /** Tugaskan petugas Jumat dengan validasi ketersediaan (Aturan 4). */
    public function assign(int $fridayPrayerId, string $role, int $profileId): FridayPrayer
    {
        $prayer = FridayPrayer::query()->findOrFail($fridayPrayerId);
        $role = strtoupper($role);

        $personnelId = match ($role) {
            'KHATIB' => KhatibProfile::query()->whereKey($profileId)->value('personnel_id'),
            'IMAM' => \App\Modules\SchoolOperations\Religious\Models\ImamProfile::query()->whereKey($profileId)->value('personnel_id'),
            default => \App\Modules\SchoolOperations\Religious\Models\MuadzinProfile::query()->whereKey($profileId)->value('personnel_id'),
        };

        if ($personnelId) {
            $availability = $this->personnel->availability((int) $personnelId, (string) $prayer->prayer_date);
            if (! ($availability['available'] ?? true)) {
                throw ValidationException::withMessages([
                    'role' => 'Petugas tidak tersedia pada tanggal tersebut (Aturan 4).',
                ]);
            }
        }

        $column = match ($role) {
            'KHATIB' => 'khatib_id',
            'IMAM' => 'imam_id',
            default => 'muadzin_id',
        };

        $prayer->forceFill([$column => $profileId])->save();
        $this->syncAssignments($prayer);

        $this->context->audit('religious.friday.assigned', $prayer, ['role' => $role, 'profile_id' => $profileId]);
        $this->context->publish('KhatibAssigned', $prayer, ['role' => $role]);

        return $prayer;
    }

    /** Catat kehadiran jamaah dan selesaikan (§27). */
    public function recordAttendance(int $fridayPrayerId, int $actual, array $payload = []): FridayPrayer
    {
        $prayer = FridayPrayer::query()->findOrFail($fridayPrayerId);
        $prayer->forceFill([
            'actual_attendance' => $actual,
            'status' => strtoupper((string) ($payload['status'] ?? 'COMPLETED')),
        ])->save();

        // Khutbah ditandai tersampaikan bila memang sudah dijadwalkan.
        if ($prayer->khutbah_id) {
            $khutbah = \App\Modules\SchoolOperations\Religious\Models\Khutbah::query()->find($prayer->khutbah_id);
            $allowed = (array) config('religious.khutbah.transitions.'.strtoupper((string) $khutbah?->status), []);
            if ($khutbah && $khutbah->status !== 'DELIVERED' && in_array('DELIVERED', $allowed, true)) {
                $this->context->safe(fn () => $this->khutbahs->transition($khutbah, 'DELIVERED'));
            }
        }

        $this->context->audit('religious.friday.attendance_recorded', $prayer, ['actual' => $actual]);

        return $prayer;
    }

    /** Riwayat Jumat + khatib (§74). */
    public function history(array $filters = []): array
    {
        $query = FridayPrayer::query()
            ->when(! empty($filters['mosque_id']), fn ($q) => $q->where('mosque_id', $filters['mosque_id']))
            ->when(! empty($filters['from']), fn ($q) => $q->whereDate('prayer_date', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->whereDate('prayer_date', '<=', $filters['to']));

        $khatibs = KhatibProfile::query()->with('personnel')->get()->keyBy('id');

        return $query->orderByDesc('prayer_date')->limit((int) ($filters['limit'] ?? 100))->get()
            ->map(fn (FridayPrayer $p): array => [
                'id' => $p->getKey(),
                'date' => $p->prayer_date?->toDateString(),
                'mosque_id' => $p->mosque_id,
                'khatib' => $khatibs->get($p->khatib_id)?->personnel?->full_name ?? $p->topic,
                'topic' => $p->topic,
                'theme' => $p->theme,
                'language' => $p->language,
                'start_time' => $p->start_time,
                'estimated_attendance' => $p->estimated_attendance,
                'actual_attendance' => $p->actual_attendance,
                'status' => $p->status,
            ])->all();
    }

    /** Jadwal Jumat mendatang (kartu dashboard & mobile §66). */
    public function upcoming(int $limit = 5, ?int $mosqueId = null): array
    {
        return FridayPrayer::query()
            ->when($mosqueId, fn ($q) => $q->where('mosque_id', $mosqueId))
            ->whereDate('prayer_date', '>=', now()->toDateString())
            ->orderBy('prayer_date')->limit($limit)->get()
            ->map(fn (FridayPrayer $p): array => [
                'id' => $p->getKey(),
                'date' => $p->prayer_date?->toDateString(),
                'start_time' => $p->start_time,
                'khatib_id' => $p->khatib_id,
                'topic' => $p->topic,
                'status' => $p->status,
            ])->all();
    }

    /** Jaga tabel penugasan tetap sinkron dengan kolom pada tabel Jumat. */
    protected function syncAssignments(FridayPrayer $prayer): void
    {
        foreach (['KHATIB' => $prayer->khatib_id, 'IMAM' => $prayer->imam_id, 'MUADZIN' => $prayer->muadzin_id] as $role => $profileId) {
            if (! $profileId) {
                continue;
            }

            FridayPrayerAssignment::query()->updateOrCreate(
                ['friday_prayer_id' => $prayer->getKey(), 'duty_role' => $role],
                [
                    'personnel_id' => $this->personnelIdFor($role, (int) $profileId),
                    'personnel_name' => null,
                    'status' => 'ASSIGNED',
                ]
            );
        }
    }

    protected function personnelIdFor(string $role, int $profileId): ?int
    {
        return match ($role) {
            'KHATIB' => KhatibProfile::query()->whereKey($profileId)->value('personnel_id'),
            'IMAM' => \App\Modules\SchoolOperations\Religious\Models\ImamProfile::query()->whereKey($profileId)->value('personnel_id'),
            default => \App\Modules\SchoolOperations\Religious\Models\MuadzinProfile::query()->whereKey($profileId)->value('personnel_id'),
        };
    }
}
