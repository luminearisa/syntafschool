<?php

namespace App\Modules\SchoolOperations\Religious\Application\Services;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\{DisciplineGatewayInterface, EmployeeGatewayInterface, StudentGatewayInterface};
use App\Modules\SchoolOperations\Religious\Domain\Support\ReligiousContext;
use App\Modules\SchoolOperations\Religious\Models\{
    ImamAssignment, ImamProfile, KhatibAssignment, KhatibProfile, MuadzinAssignment, MuadzinProfile,
    ReligiousPersonnelAvailability, ReligiousPersonnelProfile
};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Personel keagamaan (§16-§21, §28) — imam, muadzin, khatib.
 *
 * Prinsip penting: TIDAK membuat master pegawai/siswa baru. Personel selalu
 * merujuk ke pegawai (HRD), siswa, atau tamu eksternal yang dicatat sebagai
 * profil personel, sehingga tidak ada dua sumber kebenaran identitas.
 */
class PersonnelService
{
    public function __construct(
        protected ReligiousContext $context,
        protected EmployeeGatewayInterface $employees,
        protected StudentGatewayInterface $students,
    ) {
    }

    // ── Profil personel (§16) ────────────────────────────────────────────

    /**
     * Pastikan profil personel ada untuk seseorang.
     *
     * @param string $personType EMPLOYEE | STUDENT | EXTERNAL | COMMUNITY_LEADER
     */
    public function ensurePersonnel(string $personType, array $payload): ReligiousPersonnelProfile
    {
        $personType = strtoupper($personType);
        $keys = ['person_type' => $personType];

        if (! empty($payload['employee_id'])) {
            $keys['employee_id'] = (int) $payload['employee_id'];
        } elseif (! empty($payload['student_id'])) {
            $keys['student_id'] = (int) $payload['student_id'];
        } elseif (! empty($payload['external_name'])) {
            $keys['external_name'] = $payload['external_name'];
        } else {
            throw ValidationException::withMessages([
                'person_type' => 'Personel wajib merujuk ke pegawai, siswa, atau nama tamu eksternal.',
            ]);
        }

        $name = $payload['full_name'] ?? $this->resolveName($personType, $payload);

        $profile = ReligiousPersonnelProfile::query()->firstOrNew($keys);
        $profile->fill([
            'user_id' => $payload['user_id'] ?? null,
            'code' => $profile->code ?: $this->context->nextCode('REL', 'religious_personnel_profiles'),
            'full_name' => $name,
            'gender' => $payload['gender'] ?? null,
            'external_phone' => $payload['external_phone'] ?? null,
            'external_organization' => $payload['external_organization'] ?? null,
            'specialization' => $payload['specialization'] ?? null,
            'qualification' => $payload['qualification'] ?? null,
            'bio' => $payload['bio'] ?? null,
            'languages' => $payload['languages'] ?? null,
            'status' => strtoupper((string) ($payload['status'] ?? 'ACTIVE')),
            'active' => (bool) ($payload['active'] ?? true),
            'meta' => $payload['meta'] ?? null,
        ]);

        if (! $profile->exists) {
            $profile->save();
            $this->context->audit('religious.personnel.registered', $profile, [
                'person_type' => $personType,
            ]);
        }

        return $profile;
    }

    protected function resolveName(string $personType, array $payload): string
    {
        if ($personType === 'EMPLOYEE' && ! empty($payload['employee_id'])) {
            $snapshot = $this->employees->snapshot((int) $payload['employee_id']);

            return (string) ($snapshot['full_name'] ?? ('Pegawai #'.$payload['employee_id']));
        }

        if ($personType === 'STUDENT' && ! empty($payload['student_id'])) {
            $snapshot = $this->students->snapshot((int) $payload['student_id']);

            return (string) ($snapshot['full_name'] ?? ('Siswa #'.$payload['student_id']));
        }

        return (string) ($payload['external_name'] ?? 'Personel');
    }

    /** Tandai profil personel terverifikasi (mis. setelah validasi kualifikasi). */
    public function verify(ReligiousPersonnelProfile $profile): ReligiousPersonnelProfile
    {
        $profile->forceFill([
            'verified_at' => now(),
            'verified_by' => $this->context->actorId(),
            'status' => 'VERIFIED',
        ])->save();

        $this->context->audit('religious.personnel.verified', $profile);

        return $profile;
    }

    // ── Imam (§16, §17) ─────────────────────────────────────────────

    public function registerImam(array $payload): ImamProfile
    {
        $personnel = $this->ensurePersonnel($payload['person_type'] ?? 'EMPLOYEE', $payload);

        $imam = ImamProfile::query()->updateOrCreate(
            ['personnel_id' => $personnel->getKey()],
            [
                'imam_code' => $payload['imam_code'] ?? $this->context->nextCode('IMM', 'imam_profiles'),
                'qualification' => $payload['qualification'] ?? $personnel->qualification,
                'specialization' => $payload['specialization'] ?? $personnel->specialization,
                'scope_type' => strtoupper((string) ($payload['scope_type'] ?? 'CAMPUS')),
                'campus_id' => $payload['campus_id'] ?? null,
                'mosque_id' => $payload['mosque_id'] ?? null,
                'availability' => $payload['availability'] ?? null,
                'status' => 'ACTIVE',
                'notes' => $payload['notes'] ?? null,
                'meta' => $payload['meta'] ?? null,
            ]
        );

        $this->context->audit('religious.imam.registered', $imam, [
            'mosque_id' => $imam->mosque_id, 'campus_id' => $imam->campus_id,
        ]);

        return $imam;
    }

    public function imams(array $filters = []): array
    {
        return ImamProfile::query()
            ->when(! empty($filters['mosque_id']), fn ($q) => $q->where('mosque_id', $filters['mosque_id']))
            ->when(! empty($filters['campus_id']), fn ($q) => $q->where('campus_id', $filters['campus_id']))
            ->where('status', 'ACTIVE')
            ->limit((int) ($filters['limit'] ?? 200))->get()
            ->map(fn (ImamProfile $i): array => [
                'id' => $i->getKey(),
                'imam_code' => $i->imam_code,
                'personnel_id' => $i->personnel_id,
                'name' => $i->personnel?->full_name,
                'qualification' => $i->qualification,
                'mosque_id' => $i->mosque_id,
                'campus_id' => $i->campus_id,
                'rating' => $i->rating,
            ])->all();
    }

    // ── Muadzin (§18) ───────────────────────────────────────────────────

    public function registerMuadzin(array $payload): MuadzinProfile
    {
        $personnel = $this->ensurePersonnel($payload['person_type'] ?? 'EMPLOYEE', $payload);

        $muadzin = MuadzinProfile::query()->updateOrCreate(
            ['personnel_id' => $personnel->getKey()],
            [
                'muadzin_code' => $payload['muadzin_code'] ?? $this->context->nextCode('MUZ', 'muadzin_profiles'),
                'qualification' => $payload['qualification'] ?? $personnel->qualification,
                'scope_type' => strtoupper((string) ($payload['scope_type'] ?? 'CAMPUS')),
                'campus_id' => $payload['campus_id'] ?? null,
                'mosque_id' => $payload['mosque_id'] ?? null,
                'availability' => $payload['availability'] ?? null,
                'status' => 'ACTIVE',
                'notes' => $payload['notes'] ?? null,
                'meta' => $payload['meta'] ?? null,
            ]
        );

        $this->context->audit('religious.muadzin.registered', $muadzin, [
            'mosque_id' => $muadzin->mosque_id, 'campus_id' => $muadzin->campus_id,
        ]);

        return $muadzin;
    }

    public function muadzins(array $filters = []): array
    {
        return MuadzinProfile::query()
            ->when(! empty($filters['mosque_id']), fn ($q) => $q->where('mosque_id', $filters['mosque_id']))
            ->where('status', 'ACTIVE')
            ->limit((int) ($filters['limit'] ?? 200))->get()
            ->map(fn (MuadzinProfile $m): array => [
                'id' => $m->getKey(),
                'muadzin_code' => $m->muadzin_code,
                'personnel_id' => $m->personnel_id,
                'name' => $m->personnel?->full_name,
                'mosque_id' => $m->mosque_id,
            ])->all();
    }

    // ── Khatib (§28) ────────────────────────────────────────────────────

    public function registerKhatib(array $payload): KhatibProfile
    {
        $personnel = $this->ensurePersonnel($payload['person_type'] ?? 'EMPLOYEE', $payload);

        $khatib = KhatibProfile::query()->updateOrCreate(
            ['personnel_id' => $personnel->getKey()],
            [
                'khatib_code' => $payload['khatib_code'] ?? $this->context->nextCode('KHT', 'khatib_profiles'),
                'qualification' => $payload['qualification'] ?? $personnel->qualification,
                'specialization' => $payload['specialization'] ?? $personnel->specialization,
                'languages' => $payload['languages'] ?? $personnel->languages,
                'bio' => $payload['bio'] ?? $personnel->bio,
                'scope_type' => strtoupper((string) ($payload['scope_type'] ?? 'CAMPUS')),
                'campus_id' => $payload['campus_id'] ?? null,
                'status' => 'ACTIVE',
                'notes' => $payload['notes'] ?? null,
                'meta' => $payload['meta'] ?? null,
            ]
        );

        $this->context->audit('religious.khatib.registered', $khatib, ['campus_id' => $khatib->campus_id]);

        return $khatib;
    }

    public function khatibs(array $filters = []): array
    {
        return KhatibProfile::query()
            ->when(! empty($filters['campus_id']), fn ($q) => $q->where('campus_id', $filters['campus_id']))
            ->where('status', 'ACTIVE')
            ->limit((int) ($filters['limit'] ?? 200))->get()
            ->map(fn (KhatibProfile $k): array => [
                'id' => $k->getKey(),
                'khatib_code' => $k->khatib_code,
                'personnel_id' => $k->personnel_id,
                'name' => $k->personnel?->full_name,
                'specialization' => $k->specialization,
                'languages' => $k->languages,
            ])->all();
    }

    // ── Penugasan (§17) + validasi bentrok (§60) ────────────────────

    /**
     * Tugaskan imam. Aturan 2: imam tidak boleh punya penugasan bertumpang.
     */
    public function assignImam(int $imamId, array $payload): ImamAssignment
    {
        $imam = ImamProfile::query()->findOrFail($imamId);
        $personnelId = (int) $imam->personnel_id;

        if ($conflict = $this->conflictFor($personnelId, $payload)) {
            throw ValidationException::withMessages([
                'imam_id' => 'Imam sudah bertugas pada waktu tersebut ('.$conflict.') — §78 Aturan 2.',
            ]);
        }

        $assignment = ImamAssignment::query()->create([
            'imam_id' => $imam->getKey(),
            'mosque_id' => $payload['mosque_id'] ?? $imam->mosque_id,
            'prayer_type' => isset($payload['prayer_type']) ? strtoupper((string) $payload['prayer_type']) : null,
            'assignment_type' => strtoupper((string) ($payload['assignment_type'] ?? 'RECURRING')),
            'roster_id' => $payload['roster_id'] ?? null,
            'prayer_schedule_id' => $payload['prayer_schedule_id'] ?? null,
            'effective_from' => $payload['effective_from'] ?? now()->toDateString(),
            'effective_until' => $payload['effective_until'] ?? null,
            'day_of_week' => $payload['day_of_week'] ?? null,
            'status' => 'ACTIVE',
            'assigned_by' => $this->context->actorId(),
            'notes' => $payload['notes'] ?? null,
        ]);

        $this->context->audit('religious.imam.assigned', $assignment, [
            'mosque_id' => $assignment->mosque_id,
        ]);
        $this->context->publish('ImamAssigned', $assignment, ['imam_id' => $imamId]);

        return $assignment;
    }

    /** Tugaskan muadzin. Aturan 3: muadzin tidak boleh bertumpang. */
    public function assignMuadzin(int $muadzinId, array $payload): MuadzinAssignment
    {
        $muadzin = MuadzinProfile::query()->findOrFail($muadzinId);
        $personnelId = (int) $muadzin->personnel_id;

        if ($conflict = $this->conflictFor($personnelId, $payload)) {
            throw ValidationException::withMessages([
                'muadzin_id' => 'Muadzin sudah bertugas pada waktu tersebut ('.$conflict.') — §78 Aturan 3.',
            ]);
        }

        $assignment = MuadzinAssignment::query()->create([
            'muadzin_id' => $muadzin->getKey(),
            'mosque_id' => $payload['mosque_id'] ?? $muadzin->mosque_id,
            'prayer_type' => isset($payload['prayer_type']) ? strtoupper((string) $payload['prayer_type']) : null,
            'assignment_type' => strtoupper((string) ($payload['assignment_type'] ?? 'RECURRING')),
            'roster_id' => $payload['roster_id'] ?? null,
            'effective_from' => $payload['effective_from'] ?? now()->toDateString(),
            'effective_until' => $payload['effective_until'] ?? null,
            'day_of_week' => $payload['day_of_week'] ?? null,
            'status' => 'ACTIVE',
            'assigned_by' => $this->context->actorId(),
            'notes' => $payload['notes'] ?? null,
        ]);

        $this->context->audit('religious.muadzin.assigned', $assignment, [
            'mosque_id' => $assignment->mosque_id,
        ]);
        $this->context->publish('MuadzinAssigned', $assignment, ['muadzin_id' => $muadzinId]);

        return $assignment;
    }

    /** Tugaskan khatib Jumat. Aturan 4: khatib harus tersedia pada waktunya. */
    public function assignKhatib(int $khatibId, array $payload): KhatibAssignment
    {
        $khatib = KhatibProfile::query()->findOrFail($khatibId);
        $personnelId = (int) $khatib->personnel_id;

        if ($conflict = $this->conflictFor($personnelId, $payload)) {
            throw ValidationException::withMessages([
                'khatib_id' => 'Khatib sudah bertugas pada waktu tersebut ('.$conflict.') — §78 Aturan 4.',
            ]);
        }

        $assignment = KhatibAssignment::query()->create([
            'khatib_id' => $khatib->getKey(),
            'mosque_id' => $payload['mosque_id'] ?? null,
            'friday_prayer_id' => $payload['friday_prayer_id'] ?? null,
            'assignment_date' => $payload['assignment_date'] ?? now()->toDateString(),
            'topic' => $payload['topic'] ?? null,
            'language' => $payload['language'] ?? null,
            'status' => 'ASSIGNED',
            'assigned_by' => $this->context->actorId(),
            'notes' => $payload['notes'] ?? null,
        ]);

        $this->context->audit('religious.khatib.assigned', $assignment, [
            'mosque_id' => $assignment->mosque_id,
        ]);
        $this->context->publish('KhatibAssigned', $assignment, ['khatib_id' => $khatibId]);

        return $assignment;
    }

    /**
     * Deteksi bentrok penugasan personel (§60).
     * Memeriksa roster tugas dan shalat berjamaah pada tanggal yang sama.
     *
     * @return string|null deskripsi bentrok, atau null bila aman
     */
    public function conflictFor(int $personnelId, array $payload): ?string
    {
        $date = $payload['effective_from'] ?? $payload['assignment_date'] ?? now()->toDateString();
        $prayerType = isset($payload['prayer_type']) ? strtoupper((string) $payload['prayer_type']) : null;

        if ($prayerType === null) {
            return null; // penugasan tanpa slot waktu tidak dapat bertumpang
        }

        // 1) Roster tugas harian.
        $roster = $this->context->safe(fn () => DB::table('religious_duty_assignments')
            ->where('personnel_id', $personnelId)
            ->whereDate('duty_date', $date)
            ->where('prayer_type', $prayerType)
            ->where('status', 'ASSIGNED')
            ->where('is_substitute', false)
            ->first());

        if ($roster) {
            return 'roster '.($roster->duty_role ?? 'tugas').' pada '.$date.' ('.$prayerType.')';
        }

        // 2) Penugasan tetap imam/muadzin pada shalat & tanggal yang sama.
        foreach ([['imam_assignments', 'imam', 'imam_id'], ['muadzin_assignments', 'muadzin', 'muadzin_id']] as [$table, $label]) {
            $row = $this->context->safe(fn () => DB::table($table)
                ->where('prayer_type', $prayerType)
                ->where('status', 'ACTIVE')
                ->whereDate('effective_from', '<=', $date)
                ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date))
                ->whereIn($label === 'imam' ? 'imam_id' : 'muadzin_id', function ($sub) use ($personnelId, $label): void {
                    $sub->select('id')->from($label === 'imam' ? 'imam_profiles' : 'muadzin_profiles')
                        ->where('personnel_id', $personnelId);
                })
                ->first());

            if ($row) {
                return $label.' sudah bertugas pada '.$date.' ('.$prayerType.')';
            }
        }

        return null;
    }

    /** Ketersediaan personel (§16). */
    public function availability(int $personnelId, string $date): array
    {
        $rows = ReligiousPersonnelAvailability::query()
            ->where('personnel_id', $personnelId)
            ->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->whereDate('availability_date', $date)
                ->orWhere(fn ($inner) => $inner->whereNull('availability_date')
                    ->where('day_of_week', \Carbon\Carbon::parse($date)->format('l'))))
            ->get();

        $unavailable = $rows->where('availability_type', 'UNAVAILABLE')->isNotEmpty();
        $available = $rows->where('availability_type', 'AVAILABLE')->isNotEmpty();

        return [
            'personnel_id' => $personnelId,
            'date' => $date,
            'available' => ! $unavailable,
            'blocks' => $rows->map(fn (ReligiousPersonnelAvailability $r): array => [
                'type' => $r->availability_type,
                'from' => $r->time_from,
                'to' => $r->time_to,
                'reason' => $r->reason,
            ])->all(),
            'has_explicit_availability' => $available,
        ];
    }

    public function setAvailability(int $personnelId, array $payload): ReligiousPersonnelAvailability
    {
        $row = ReligiousPersonnelAvailability::query()->create([
            'personnel_id' => $personnelId,
            'availability_date' => $payload['date'] ?? null,
            'day_of_week' => $payload['day_of_week'] ?? null,
            'time_from' => $payload['time_from'] ?? null,
            'time_to' => $payload['time_to'] ?? null,
            'availability_type' => strtoupper((string) ($payload['availability_type'] ?? 'AVAILABLE')),
            'reason' => $payload['reason'] ?? null,
            'recurring' => (bool) ($payload['recurring'] ?? false),
            'status' => 'ACTIVE',
            'notes' => $payload['notes'] ?? null,
        ]);

        $this->context->audit('religious.personnel.availability_set', $row, [], $payload['reason'] ?? null);

        return $row;
    }

    // ── Pengganti (§21) ─────────────────────────────────────────────────

    /**
     * Ajukan personel pengganti: Original → Unavailable → Substitute
     * → Approval → Notification. Riwayat selalu disimpan.
     */
    public function requestSubstitute(int $assignmentId, int $substitutePersonnelId, array $payload = []): \App\Modules\SchoolOperations\Religious\Models\ReligiousSubstitution
    {
        $assignment = $this->context->safe(fn () => DB::table('religious_duty_assignments')
            ->where('id', $assignmentId)->first());

        if (! $assignment) {
            throw ValidationException::withMessages([
                'assignment_id' => 'Penugasan tidak ditemukan.',
            ]);
        }

        $substitution = \App\Modules\SchoolOperations\Religious\Models\ReligiousSubstitution::query()->create([
            'assignment_id' => $assignmentId,
            'original_personnel_id' => $assignment->personnel_id ?? null,
            'substitute_personnel_id' => $substitutePersonnelId,
            'reason' => strtoupper((string) ($payload['reason'] ?? 'UNAVAILABLE')),
            'reason_detail' => $payload['reason_detail'] ?? null,
            'requested_by' => $this->context->actorId(),
            'status' => 'PENDING',
            'notes' => $payload['notes'] ?? null,
        ]);

        $this->context->audit('religious.substitution.requested', $substitution, [
            'mosque_id' => $assignment->mosque_id ?? null,
        ], $payload['reason_detail'] ?? null);

        return $substitution;
    }

    /** Setujui penggantian dan pindahkan slot tugasnya. */
    public function approveSubstitute(int $substitutionId, array $payload = []): array
    {
        $substitution = \App\Modules\SchoolOperations\Religious\Models\ReligiousSubstitution::query()->findOrFail($substitutionId);

        $substitution->forceFill([
            'status' => 'APPROVED',
            'approved_by' => $this->context->actorId(),
            'approved_at' => now(),
            'notified_at' => now(),
        ])->save();

        $updated = $this->context->safe(function () use ($substitution, $payload) {
            $table = DB::table('religious_duty_assignments')->where('id', $substitution->assignment_id);
            $original = (clone $table)->first();

            $table->update([
                'personnel_id' => $substitution->substitute_personnel_id,
                'substitute_for_id' => $original->personnel_id ?? null,
                'is_substitute' => true,
                'override_reason' => $payload['reason'] ?? $substitution->reason_detail,
                'updated_at' => now(),
            ]);

            return true;
        }, false);

        $this->context->audit('religious.substitution.approved', $substitution, [
            'assignment_updated' => (bool) $updated,
        ]);
        $this->context->publish('ReligiousDutySubstituted', $substitution, [
            'assignment_id' => $substitution->assignment_id,
        ]);

        return [
            'substitution_id' => $substitution->getKey(),
            'status' => 'APPROVED',
            'assignment_updated' => (bool) $updated,
        ];
    }

    // ── Guru agama (§36) ────────────────────────────────────────────────

    public function registerReligiousTeacher(array $payload): \App\Modules\SchoolOperations\Religious\Models\ReligiousTeacherProfile
    {
        $personnel = $this->ensurePersonnel($payload['person_type'] ?? 'EMPLOYEE', $payload);

        $teacher = \App\Modules\SchoolOperations\Religious\Models\ReligiousTeacherProfile::query()->updateOrCreate(
            ['personnel_id' => $personnel->getKey()],
            [
                'employee_id' => $payload['employee_id'] ?? null,
                'teacher_id' => $payload['teacher_id'] ?? null,
                'code' => $payload['code'] ?? $this->context->nextCode('RTP', 'religious_teacher_profiles'),
                'religious_specialization' => $payload['religious_specialization'] ?? null,
                'subjects' => $payload['subjects'] ?? null,
                'qualification' => $payload['qualification'] ?? null,
                'certification_body' => $payload['certification_body'] ?? null,
                'teaching_levels' => $payload['teaching_levels'] ?? null,
                'status' => 'ACTIVE',
                'notes' => $payload['notes'] ?? null,
            ]
        );

        $this->context->audit('religious.teacher.registered', $teacher);

        return $teacher;
    }

    /** Dashboard personel (§74: riwayat khatib, pemanfaatan imam). */
    public function dashboard(array $filters = []): array
    {
        $from = $filters['from'] ?? now()->startOfMonth()->toDateString();
        $to = $filters['to'] ?? now()->toDateString();

        return [
            'imam_count' => ImamProfile::query()->where('status', 'ACTIVE')->count(),
            'muadzin_count' => MuadzinProfile::query()->where('status', 'ACTIVE')->count(),
            'khatib_count' => KhatibProfile::query()->where('status', 'ACTIVE')->count(),
            'assignments_in_period' => (int) $this->context->safe(fn () => DB::table('religious_duty_assignments')
                ->whereBetween('duty_date', [$from, $to])->count(), 0),
            'substitutions_pending' => (int) $this->context->safe(fn () => DB::table('religious_substitutions')
                ->where('status', 'PENDING')->count(), 0),
            'period' => ['from' => $from, 'to' => $to],
        ];
    }
}
