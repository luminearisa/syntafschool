<?php

namespace App\Modules\SchoolOperations\Religious\Application\Services;

use App\Modules\SchoolOperations\Religious\Domain\Support\ReligiousContext;
use App\Modules\SchoolOperations\Religious\Models\{ReligiousDutyAssignment, ReligiousDutyRoster};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Roster tugas keagamaan (§19, §20, §54).
 *
 * Mendukung pola harian, mingguan, bulanan, Ramadan, Jumat, dan event khusus.
 * Aturan: satu orang tidak boleh mendapat dua tugas pada slot yang sama kecuali
 * memakai penimpaan yang berwenang (override beralasan, tetap tercatat).
 */
class DutyRosterService
{
    public function __construct(protected ReligiousContext $context, protected PersonnelService $personnel)
    {
    }

    public function create(array $payload): ReligiousDutyRoster
    {
        $roster = ReligiousDutyRoster::query()->create([
            'code' => $payload['code'] ?? $this->context->nextCode('RST', 'religious_duty_rosters'),
            'name' => $payload['name'],
            'roster_type' => strtoupper((string) ($payload['roster_type'] ?? 'WEEKLY')),
            'mosque_id' => $payload['mosque_id'] ?? null,
            'campus_id' => $payload['campus_id'] ?? null,
            'effective_from' => $payload['effective_from'] ?? now()->toDateString(),
            'effective_until' => $payload['effective_until'] ?? null,
            'status' => 'DRAFT',
            'notes' => $payload['notes'] ?? null,
            'meta' => $payload['meta'] ?? null,
        ]);

        $this->context->audit('religious.roster.created', $roster, [
            'mosque_id' => $roster->mosque_id, 'campus_id' => $roster->campus_id,
        ]);

        return $roster;
    }

    /**
     * Tambahkan satu penugasan ke roster.
     *
     * @param bool $allowOverride mengizinkan double assignment (beralasan)
     */
    public function addAssignment(int $rosterId, array $payload, bool $allowOverride = false): ReligiousDutyAssignment
    {
        $roster = ReligiousDutyRoster::query()->findOrFail($rosterId);
        $date = $payload['duty_date'] ?? now()->toDateString();
        $prayerType = isset($payload['prayer_type']) ? strtoupper((string) $payload['prayer_type']) : null;
        $personnelId = $payload['personnel_id'] ?? null;

        if ($personnelId && $prayerType) {
            $duplicate = ReligiousDutyAssignment::query()
                ->whereDate('duty_date', $date)
                ->where('prayer_type', $prayerType)
                ->where('personnel_id', $personnelId)
                ->where('status', 'ASSIGNED')
                ->exists();

            if ($duplicate && ! $allowOverride) {
                throw ValidationException::withMessages([
                    'personnel_id' => 'Personel sudah mendapat tugas pada slot ini (§20). Gunakan override beralasan bila memang disengaja.',
                ]);
            }

            if ($duplicate && $allowOverride && empty($payload['override_reason'])) {
                throw ValidationException::withMessages([
                    'override_reason' => 'Override penugasan ganda wajib menyertakan alasan.',
                ]);
            }
        }

        $assignment = ReligiousDutyAssignment::query()->create([
            'roster_id' => $roster->getKey(),
            'duty_date' => $date,
            'day_of_week' => $payload['day_of_week'] ?? \Carbon\Carbon::parse($date)->format('l'),
            'prayer_type' => $prayerType,
            'duty_role' => strtoupper((string) ($payload['duty_role'] ?? 'IMAM')),
            'personnel_id' => $personnelId,
            'personnel_name' => $payload['personnel_name'] ?? null,
            'mosque_id' => $payload['mosque_id'] ?? $roster->mosque_id,
            'area_id' => $payload['area_id'] ?? null,
            'starts_at' => $payload['starts_at'] ?? null,
            'ends_at' => $payload['ends_at'] ?? null,
            'status' => 'ASSIGNED',
            'is_substitute' => false,
            'source' => $allowOverride && ! empty($payload['override_reason']) ? 'OVERRIDE' : 'ROSTER',
            'override_reason' => $payload['override_reason'] ?? null,
            'created_by' => $this->context->actorId(),
            'notes' => $payload['notes'] ?? null,
            'meta' => $payload['meta'] ?? null,
        ]);

        $this->context->audit('religious.roster.assignment_added', $assignment, [
            'mosque_id' => $assignment->mosque_id, 'roster_id' => $roster->getKey(),
        ]);
        $this->context->publish('ReligiousDutyAssigned', $assignment, [
            'date' => $date, 'role' => $assignment->duty_role,
        ]);

        return $assignment;
    }

    /**
     * Bangkitkan roster mingguan dari pola §19:
     * [['day' => 'Monday', 'prayer_type' => 'FAJR', 'duty_role' => 'IMAM', 'personnel_id' => 3], ...]
     */
    public function generateWeekly(int $rosterId, array $pattern, int $weeks = 1): array
    {
        $roster = ReligiousDutyRoster::query()->findOrFail($rosterId);
        $created = [];
        $cursor = \Carbon\Carbon::parse($roster->effective_from);

        for ($w = 0; $w < $weeks; $w++) {
            foreach ($pattern as $entry) {
                $day = strtoupper((string) ($entry['day'] ?? 'MONDAY'));
                $date = null;

                for ($offset = 0; $offset < 7; $offset++) {
                    $candidate = $cursor->copy()->addDays($offset);
                    if (strtoupper($candidate->format('l')) === $day) {
                        $date = $candidate->toDateString();
                        break;
                    }
                }

                if ($date === null) {
                    continue;
                }

                $created[] = $this->addAssignment($rosterId, array_merge($entry, [
                    'duty_date' => $date,
                ]), (bool) ($entry['allow_override'] ?? false))->getKey();
            }

            $cursor->addWeek();
        }

        return [
            'roster_id' => $rosterId,
            'created' => count($created),
            'assignment_ids' => $created,
        ];
    }

    /** Publikasikan roster agar terlihat personel (§20). */
    public function publish(int $rosterId): ReligiousDutyRoster
    {
        $roster = ReligiousDutyRoster::query()->findOrFail($rosterId);
        $roster->forceFill([
            'status' => 'PUBLISHED',
            'published_at' => now(),
            'published_by' => $this->context->actorId(),
        ])->save();

        $this->context->audit('religious.roster.published', $roster, [
            'mosque_id' => $roster->mosque_id,
        ]);

        return $roster;
    }

    /** Roster satu hari (dipakai layar masjid & mobile §65, §66). */
    public function forDate(string $date, ?int $mosqueId = null): array
    {
        $rows = ReligiousDutyAssignment::query()->whereDate('duty_date', $date)
            ->when($mosqueId, fn ($q) => $q->where('mosque_id', $mosqueId))
            ->orderByRaw("COALESCE(starts_at, '00:00:00')")->get();

        return $rows->map(fn (ReligiousDutyAssignment $a): array => [
            'id' => $a->getKey(),
            'duty_role' => $a->duty_role,
            'prayer_type' => $a->prayer_type,
            'personnel_id' => $a->personnel_id,
            'personnel_name' => $a->personnel_name ?? $this->context->safe(fn () => DB::table('religious_personnel_profiles')
                ->where('id', $a->personnel_id)->value('full_name')),
            'starts_at' => $a->starts_at,
            'ends_at' => $a->ends_at,
            'is_substitute' => (bool) $a->is_substitute,
            'status' => $a->status,
        ])->all();
    }

    /** Daftar penugasan satu roster. */
    public function assignments(int $rosterId): array
    {
        return ReligiousDutyAssignment::query()->where('roster_id', $rosterId)
            ->orderBy('duty_date')->limit(1000)->get()
            ->map(fn (ReligiousDutyAssignment $a): array => $a->only([
                'id', 'duty_date', 'day_of_week', 'prayer_type', 'duty_role',
                'personnel_id', 'personnel_name', 'starts_at', 'ends_at', 'status', 'is_substitute',
            ]))->all();
    }

    /**
     * Tugas keagamaan siswa (§54): imam, muadzin, MC, qari, kebersihan,
     * panitia, dan dokumentasi. Partisipasi tetap dapat diteruskan ke Student
     * Activity lewat ActivityParticipationGateway.
     */
    public function assignStudentDuty(int $rosterId, array $payload): ReligiousDutyAssignment
    {
        $payload['duty_role'] = strtoupper((string) ($payload['duty_role'] ?? 'STUDENT_DUTY'));

        return $this->addAssignment($rosterId, $payload, (bool) ($payload['allow_override'] ?? false));
    }
}
