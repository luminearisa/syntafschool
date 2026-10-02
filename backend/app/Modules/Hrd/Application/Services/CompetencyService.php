<?php

namespace App\Modules\Hrd\Application\Services;

use App\Modules\Hrd\Application\Support\HrdSettingsService;

use App\Models\User;
use App\Modules\Hrd\Domain\Contracts\EmployeeCertificationProviderInterface;
use App\Modules\Hrd\Domain\Contracts\EmployeeSkillProviderInterface;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Models\HrCertification;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrEmployeeCertification;
use App\Modules\Hrd\Models\HrEmployeeEducation;
use App\Modules\Hrd\Models\HrEmployeeSkill;
use App\Modules\Hrd\Models\HrJob;
use App\Modules\Hrd\Models\HrPosition;
use App\Modules\Hrd\Models\HrSkill;
use Illuminate\Support\Facades\DB;

/**
 * Keterampilan, sertifikasi, dan pendidikan (§28–§30, §84).
 *
 * Fondasi yang disiapkan untuk HR berbasis keterampilan pada fase berikutnya:
 * `EmployeeSkillProviderInterface` dapat menanyakan **kesenjangan keterampilan**
 * pegawai terhadap syarat jabatannya, dan **siapa yang memegang keterampilan
 * tertentu** — dua pertanyaan yang menjadi dasar pembelajaran, karier, dan
 * perencanaan suksesi tanpa perlu mengubah skema.
 *
 * Sertifikasi dijaga sebagai kepatuhan, bukan dokumentasi: `holds()` dan
 * `missingMandatory()` yang dipakai aturan "guru wajib bersertifikat pendidik".
 */
class CompetencyService implements EmployeeSkillProviderInterface, EmployeeCertificationProviderInterface
{
    public function __construct(
        private readonly HrdTimelineService $timeline,
        private readonly HrdAuditService $audit,
        private readonly HrdSettingsService $settings,
        private readonly HrdNotificationService $notifications,
    ) {}

    /* ── Keterampilan ───────────────────────────────────────────────────── */

    /** @param array<string, mixed> $attributes */
    public function setSkill(HrEmployee $employee, array $attributes, User $actor): HrEmployeeSkill
    {
        $skill = HrSkill::query()->find($attributes['skill_id'] ?? null);

        if ($skill === null) {
            throw HrdValidationException::forField('skill_id', 'Keterampilan tidak ditemukan pada master.', 'hr.skill.missing');
        }

        $level = strtoupper((string) ($attributes['proficiency_level_code'] ?? 'BEGINNER'));

        if (! in_array($level, ['BEGINNER', 'INTERMEDIATE', 'ADVANCED', 'EXPERT'], true)) {
            throw HrdValidationException::forField('proficiency_level_code', "Tingkat \"{$level}\" tidak dikenal.", 'hr.skill.level_invalid');
        }

        $existing = HrEmployeeSkill::query()
            ->where('employee_id', $employee->getKey())
            ->where('skill_id', $skill->getKey())
            ->first();

        $before = $existing?->toArray();

        $record = HrEmployeeSkill::query()->updateOrCreate(
            ['employee_id' => $employee->getKey(), 'skill_id' => $skill->getKey()],
            [
                'proficiency_level_code' => $level,
                'years_experience' => $attributes['years_experience'] ?? $existing?->years_experience,
                'source' => $attributes['source'] ?? $existing?->source ?? 'self',
                'last_assessed_at' => $attributes['last_assessed_at'] ?? $existing?->last_assessed_at,
                'notes' => $attributes['notes'] ?? $existing?->notes,
                // Perubahan tingkat membatalkan verifikasi lama — penilaian ulang
                // harus dilakukan manusia, bukan diwarisi diam-diam.
                'verified' => false,
                'verified_by' => null,
                'verified_at' => null,
            ],
        );

        $this->timeline->record($employee, 'skill.recorded', 'Keterampilan: '.$skill->name.' ('.$level.')', [
            'category' => 'skill',
            'source_type' => HrEmployeeSkill::class,
            'source_id' => $record->getKey(),
            'visibility' => 'employee',
            'actor' => $actor,
        ]);

        $this->audit->log($actor, 'skill.recorded', $record, $before, $record->toArray(), null, null, (int) $employee->getKey());

        return $record->refresh();
    }

    public function verifySkill(HrEmployeeSkill $skill, User $actor): HrEmployeeSkill
    {
        $before = $skill->toArray();

        $skill->forceFill([
            'verified' => true,
            'verified_by' => $actor->getKey(),
            'verified_at' => now(),
            'last_assessed_at' => now()->toDateString(),
        ])->save();

        $this->audit->log($actor, 'skill.verified', $skill, $before, $skill->toArray(), null, null, (int) $skill->employee_id);

        return $skill->refresh();
    }

    public function skillsFor(int $employeeId): array
    {
        return HrEmployeeSkill::query()
            ->with('skill:id,code,name,category')
            ->where('employee_id', $employeeId)
            ->get()
            ->map(fn (HrEmployeeSkill $record) => [
                'skill_id' => (int) $record->skill_id,
                'code' => $record->skill?->code,
                'name' => $record->skill?->name,
                'category' => $record->skill?->category,
                'level' => $record->proficiency_level_code,
                'years_experience' => $record->years_experience,
                'verified' => (bool) $record->verified,
                'last_assessed_at' => $record->last_assessed_at?->toDateString(),
            ])
            ->all();
    }

    public function skillsAtLevel(int $employeeId, string $minimumLevel): array
    {
        $rank = ['BEGINNER' => 1, 'INTERMEDIATE' => 2, 'ADVANCED' => 3, 'EXPERT' => 4];
        $minimum = $rank[strtoupper($minimumLevel)] ?? 1;

        return array_values(array_filter(
            $this->skillsFor($employeeId),
            fn (array $skill) => ($rank[$skill['level']] ?? 0) >= $minimum,
        ));
    }

    public function skillsInOrganization(int $organizationId, ?string $skillCode = null): array
    {
        return HrEmployeeSkill::query()
            ->with(['skill:id,code,name,category', 'employee.person:id,full_name'])
            ->whereHas('employee', fn ($q) => $q->where('organization_id', $organizationId)->active())
            ->when($skillCode !== null, fn ($q) => $q->whereHas('skill', fn ($s) => $s->where('code', $skillCode)))
            ->get()
            ->map(fn (HrEmployeeSkill $record) => [
                'employee_id' => (int) $record->employee_id,
                'employee_name' => $record->employee?->displayName(),
                'skill_code' => $record->skill?->code,
                'skill_name' => $record->skill?->name,
                'level' => $record->proficiency_level_code,
            ])
            ->all();
    }

    /** Kesenjangan keterampilan terhadap syarat jabatan (§84). */
    public function skillGap(int $employeeId, ?int $jobId = null): array
    {
        $employee = HrEmployee::query()->find($employeeId);

        $job = $jobId === null
            ? ($employee?->position_id === null ? null : HrPosition::query()->find($employee->position_id)?->job)
            : HrJob::query()->find($jobId);

        $required = array_values(array_filter((array) ($job?->required_skills ?? [])));
        $held = collect($this->skillsFor($employeeId))->pluck('code')->filter()->all();

        $missing = array_values(array_diff($required, $held));

        return [
            'job_id' => $job?->getKey(),
            'job_title' => $job?->title,
            'required' => $required,
            'held' => array_values($held),
            'missing' => $missing,
            'gap_count' => count($missing),
        ];
    }

    public function holdersOfSkill(string $skillCode, string $minimumLevel = 'BEGINNER'): array
    {
        $rank = ['BEGINNER' => 1, 'INTERMEDIATE' => 2, 'ADVANCED' => 3, 'EXPERT' => 4];
        $minimum = $rank[strtoupper($minimumLevel)] ?? 1;

        return HrEmployeeSkill::query()
            ->with(['skill:id,code,name', 'employee.person:id,full_name', 'employee.organization:id,name'])
            ->whereHas('skill', fn ($q) => $q->where('code', $skillCode))
            ->get()
            ->filter(fn (HrEmployeeSkill $record) => ($rank[$record->proficiency_level_code] ?? 0) >= $minimum)
            ->map(fn (HrEmployeeSkill $record) => [
                'employee_id' => (int) $record->employee_id,
                'employee_number' => $record->employee?->employee_number,
                'name' => $record->employee?->displayName(),
                'organization' => $record->employee?->organization?->name,
                'level' => $record->proficiency_level_code,
            ])
            ->values()
            ->all();
    }

    /* ── Sertifikasi ────────────────────────────────────────────────────── */

    /** @param array<string, mixed> $attributes */
    public function addCertification(HrEmployee $employee, array $attributes, User $actor): HrEmployeeCertification
    {
        $certification = isset($attributes['certification_id'])
            ? HrCertification::query()->find($attributes['certification_id'])
            : null;

        $name = $attributes['certificate_name'] ?? $certification?->name;

        if ($name === null || trim($name) === '') {
            throw HrdValidationException::forField('certificate_name', 'Nama sertifikat wajib diisi.', 'hr.certification.name_required');
        }

        if (($certification?->requires_expiry ?? true) && empty($attributes['expiry_date'])) {
            throw HrdValidationException::forField('expiry_date', 'Sertifikat ini wajib memiliki tanggal berakhir.', 'hr.certification.expiry_required');
        }

        $status = $this->certificationStatus($attributes['expiry_date'] ?? null);

        $record = HrEmployeeCertification::query()->create([
            'employee_id' => $employee->getKey(),
            'certification_id' => $certification?->getKey(),
            'certificate_name' => $name,
            'issuer' => $attributes['issuer'] ?? $certification?->issuer,
            'certificate_number' => $attributes['certificate_number'] ?? null,
            'issued_date' => $attributes['issued_date'] ?? null,
            'expiry_date' => $attributes['expiry_date'] ?? null,
            'status' => $status,
            'document_id' => $attributes['document_id'] ?? null,
            'verified' => false,
            'notes' => $attributes['notes'] ?? null,
        ]);

        $this->timeline->record($employee, 'certification.added', 'Sertifikasi ditambahkan: '.$name, [
            'category' => 'certification',
            'source_type' => HrEmployeeCertification::class,
            'source_id' => $record->getKey(),
            'visibility' => 'employee',
            'actor' => $actor,
            'meta' => ['expiry_date' => $record->expiry_date?->toDateString()],
        ]);

        $this->audit->log($actor, 'certification.added', $record, null, $record->toArray(), null, null, (int) $employee->getKey());

        return $record->refresh();
    }

    public function verifyCertification(HrEmployeeCertification $certification, User $actor, bool $valid = true, ?string $notes = null): HrEmployeeCertification
    {
        $before = $certification->toArray();

        $certification->forceFill([
            'verified' => $valid,
            'verified_by' => $valid ? $actor->getKey() : null,
            'verified_at' => $valid ? now() : null,
            'status' => $valid ? $this->certificationStatus($certification->expiry_date?->toDateString()) : 'pending_verification',
            'notes' => $notes ?? $certification->notes,
        ])->save();

        $this->audit->log($actor, 'certification.verified', $certification, $before, $certification->toArray(), $notes, null, (int) $certification->employee_id);

        return $certification->refresh();
    }

    public function certificationsFor(int $employeeId): array
    {
        return HrEmployeeCertification::query()
            ->with('certification:id,code,name,category')
            ->where('employee_id', $employeeId)
            ->orderByDesc('issued_date')
            ->get()
            ->toArray();
    }

    public function validFor(int $employeeId, ?string $onDate = null): array
    {
        $date = $onDate ?? now()->toDateString();

        return HrEmployeeCertification::query()
            ->where('employee_id', $employeeId)
            ->where(fn ($q) => $q->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', $date))
            ->get()
            ->toArray();
    }

    public function expiring(int $days, ?int $organizationId = null): array
    {
        return $this->expiringCertifications($days, $organizationId);
    }

    /** @return list<array<string, mixed>> */
    public function expiringCertifications(int $days, ?int $organizationId = null): array
    {
        return HrEmployeeCertification::query()
            ->with(['employee.person:id,full_name', 'employee:id,employee_number,person_id,organization_id'])
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays($days)->toDateString()])
            ->when($organizationId !== null, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('organization_id', $organizationId)))
            ->orderBy('expiry_date')
            ->get()
            ->map(fn (HrEmployeeCertification $record) => [
                'id' => (int) $record->getKey(),
                'employee_id' => (int) $record->employee_id,
                'employee_name' => $record->employee?->displayName(),
                'employee_number' => $record->employee?->employee_number,
                'certificate_name' => $record->certificate_name,
                'certificate_number' => $record->certificate_number,
                'expiry_date' => $record->expiry_date?->toDateString(),
                'days_left' => (int) now()->startOfDay()->diffInDays($record->expiry_date, false),
                'verified' => (bool) $record->verified,
            ])
            ->all();
    }

    public function holds(string $certificationCode, int $employeeId, ?string $onDate = null): bool
    {
        $date = $onDate ?? now()->toDateString();

        return HrEmployeeCertification::query()
            ->where('employee_id', $employeeId)
            ->whereHas('certification', fn ($q) => $q->where('code', $certificationCode))
            ->where(fn ($q) => $q->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', $date))
            ->exists();
    }

    public function missingMandatory(string $certificationCode): array
    {
        $certification = HrCertification::query()->where('code', $certificationCode)->first();

        if ($certification === null) {
            return [];
        }

        return HrEmployee::query()
            ->active()
            ->whereHas('position', fn ($q) => $q->whereHas('job', fn ($job) => $job->where('is_teacher_role', true)))
            ->whereDoesntHave('certifications', fn ($q) => $q->where('certification_id', $certification->getKey())
                ->where(fn ($inner) => $inner->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', now()->toDateString())))
            ->get(['id', 'employee_number', 'person_id'])
            ->map(fn (HrEmployee $employee) => [
                'employee_id' => (int) $employee->getKey(),
                'employee_number' => $employee->employee_number,
                'name' => $employee->displayName(),
            ])
            ->all();
    }

    /* ── Pendidikan ─────────────────────────────────────────────────────── */

    /** @param array<string, mixed> $attributes */
    public function addEducation(HrEmployee $employee, array $attributes, User $actor): HrEmployeeEducation
    {
        if (empty($attributes['institution'])) {
            throw HrdValidationException::forField('institution', 'Nama institusi pendidikan wajib diisi.', 'hr.education.institution_required');
        }

        $this->settings->get('reminders.contract_days');

        return DB::transaction(function () use ($employee, $attributes, $actor): HrEmployeeEducation {
            $record = HrEmployeeEducation::query()->create([
                ...$attributes,
                'employee_id' => $employee->getKey(),
            ]);

            if (($attributes['is_highest'] ?? false) === true) {
                HrEmployeeEducation::query()
                    ->where('employee_id', $employee->getKey())
                    ->whereKeyNot($record->getKey())
                    ->update(['is_highest' => false]);
            }

            $this->timeline->record($employee, 'education.added', 'Pendidikan ditambahkan: '.$record->institution, [
                'category' => 'education',
                'source_type' => HrEmployeeEducation::class,
                'source_id' => $record->getKey(),
                'visibility' => 'employee',
                'actor' => $actor,
            ]);

            $this->audit->log($actor, 'education.added', $record, null, $record->toArray(), null, null, (int) $employee->getKey());

            return $record;
        });
    }

    public function verifyEducation(HrEmployeeEducation $education, User $actor): HrEmployeeEducation
    {
        $before = $education->toArray();

        $education->forceFill([
            'verified' => true,
            'verified_by' => $actor->getKey(),
            'verified_at' => now(),
        ])->save();

        $this->audit->log($actor, 'education.verified', $education, $before, $education->toArray(), null, null, (int) $education->employee_id);

        return $education->refresh();
    }

    /** Perbarui status kedaluwarsa seluruh sertifikasi (§70). */
    public function refreshCertificationStatuses(int $days = 60): array
    {
        $expired = HrEmployeeCertification::query()
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', now()->toDateString())
            ->whereNotIn('status', ['expired', 'revoked'])
            ->update(['status' => 'expired']);

        $expiring = HrEmployeeCertification::query()
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays($days)->toDateString()])
            ->where('status', 'valid')
            ->update(['status' => 'expiring']);

        return ['expired' => $expired, 'expiring' => $expiring];
    }

    /** Kirim pengingat sertifikasi yang akan berakhir (§55, §70). */
    public function sendExpiryReminders(int $days = 60): int
    {
        $sent = 0;

        foreach ($this->expiringCertifications($days) as $row) {
            $record = HrEmployeeCertification::query()->find($row['id']);

            if ($record === null || $record->reminder_sent_at !== null) {
                continue;
            }

            $employee = HrEmployee::query()->find($record->employee_id);

            $this->notifications->notify(
                'certification.expiring',
                'Sertifikasi akan berakhir',
                $record->certificate_name.' berakhir pada '.$record->expiry_date?->format('d/m/Y').'.',
                array_values(array_filter([(int) ($employee?->user_id ?? 0)])),
                (array) config('hrd.integration.notify_roles', ['HR_ADMIN']),
                $employee,
                ['certification_id' => (int) $record->getKey()],
            );

            $record->forceFill(['reminder_sent_at' => now()])->save();
            $sent++;
        }

        return $sent;
    }

    private function certificationStatus(?string $expiryDate): string
    {
        if ($expiryDate === null) {
            return 'valid';
        }

        $windowDays = (int) $this->settings->integer('expiry_warning_days', 60);

        return match (true) {
            strtotime($expiryDate) < strtotime(now()->toDateString()) => 'expired',
            strtotime($expiryDate) <= strtotime(now()->addDays($windowDays)->toDateString()) => 'expiring',
            default => 'valid',
        };
    }
}
