<?php

namespace App\Modules\Activity\Application\Services;

use App\Models\User;
use App\Modules\Activity\Domain\Enums\AuditAction;
use App\Modules\Activity\Domain\Enums\MembershipStatus;
use App\Modules\Activity\Domain\Enums\ProgramStatus;
use App\Modules\Activity\Domain\Exceptions\ActivityConflictException;
use App\Modules\Activity\Domain\Exceptions\ActivityValidationException;
use App\Modules\Activity\Models\ActivityMembership;
use App\Modules\Activity\Models\ActivityProgram;
use App\Modules\Activity\Models\ActivityRegistration;
use App\Modules\Activity\Models\ActivityRegistrationRequirement;
use App\Modules\Activity\Models\ActivityWaitlistEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Program aktivitas — katalog, penyuntingan, dan penerbitan (§16, §91).
 *
 * Penerbitan adalah gerbang yang disengaja: program yang belum terbit tidak
 * muncul di katalog siswa, tidak bisa didaftari, dan tidak bisa dijadwalkan.
 * Dengan begitu kesalahan konfigurasi (kapasitas kosong, pembina belum ada,
 * jadwal bentrok) tertangkap sebelum siswa melihatnya.
 */
class ActivityProgramService
{
    public function __construct(
        private readonly ActivityAuditService $audit,
        private readonly ActivityPeriodService $periods,
        private readonly ActivityIntegrationService $integrations,
    ) {}

    // ------------------------------------------------------------- katalog

    /** @return LengthAwarePaginator katalog untuk siswa/orang tua/admin */
    public function paginate(array $filters = [], bool $onlyPublic = false): LengthAwarePaginator
    {
        return ActivityProgram::query()
            ->with(['category:id,name,code', 'period:id,name', 'coach:id,full_name', 'advisor:id,full_name'])
            ->when($onlyPublic, fn ($query) => $query->whereIn('status', [ProgramStatus::PUBLISHED->value, ProgramStatus::ACTIVE->value]))
            ->when(isset($filters['category_id']), fn ($query) => $query->where('category_id', $filters['category_id']))
            ->when(isset($filters['type']), fn ($query) => $query->where('type', $filters['type']))
            ->when(isset($filters['level']), fn ($query) => $query->where('level', $filters['level']))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['period_id']), fn ($query) => $query->where('period_id', $filters['period_id']))
            ->when(isset($filters['search']), function ($query) use ($filters) {
                $like = '%'.$filters['search'].'%';
                $query->where(fn ($inner) => $inner->where('name', 'like', $like)->orWhere('code', 'like', $like));
            })
            ->when(($filters['open_registration'] ?? false) === true, fn ($query) => $this->applyOpenRegistrationFilter($query))
            ->orderBy('name')
            ->paginate((int) ($filters['per_page'] ?? 20));
    }

    protected function applyOpenRegistrationFilter($query): void
    {
        $today = now()->toDateString();

        $query->whereIn('status', [ProgramStatus::PUBLISHED->value, ProgramStatus::ACTIVE->value])
            ->where(fn ($inner) => $inner->whereNull('registration_opens')->orWhere('registration_opens', '<=', $today))
            ->where(fn ($inner) => $inner->whereNull('registration_closes')->orWhere('registration_closes', '>=', $today));
    }

    public function detail(ActivityProgram $program): ActivityProgram
    {
        return $program->load([
            'category:id,name,code', 'period:id,name', 'room:id,name', 'lmsCourse:id,name,code',
            'advisor:id,full_name', 'coach:id,full_name', 'coordinator:id,name',
            'skills:id,name,code', 'objectives:id,name,code', 'requirements',
            'coaches.employee:id,full_name',
        ]);
    }

    // -------------------------------------------------------- tulis program

    public function create(array $attributes, User $actor): ActivityProgram
    {
        return DB::transaction(function () use ($attributes, $actor) {
            $program = ActivityProgram::query()->create([
                'code' => $attributes['code'],
                'name' => $attributes['name'],
                'slug' => $attributes['slug'] ?? \Illuminate\Support\Str::slug($attributes['name']),
                'category_id' => $attributes['category_id'],
                'description' => $attributes['description'] ?? null,
                'objectives_summary' => $attributes['objectives_summary'] ?? null,
                'type' => $attributes['type'] ?? 'EXTRACURRICULAR',
                'delivery_type' => $attributes['delivery_type'] ?? 'RECURRING',
                'level' => $attributes['level'] ?? null,
                'gender' => $attributes['gender'] ?? 'ANY',
                'status' => ProgramStatus::DRAFT->value,
                'period_id' => $attributes['period_id'] ?? $this->periods->current()?->id,
                'coordinator_user_id' => $attributes['coordinator_user_id'] ?? null,
                'advisor_employee_id' => $attributes['advisor_employee_id'] ?? null,
                'coach_employee_id' => $attributes['coach_employee_id'] ?? null,
                'room_id' => $attributes['room_id'] ?? null,
                'minimum_capacity' => $attributes['minimum_capacity'] ?? null,
                'maximum_capacity' => $attributes['maximum_capacity'] ?? null,
                'minimum_grade_level' => $attributes['minimum_grade_level'] ?? null,
                'maximum_grade_level' => $attributes['maximum_grade_level'] ?? null,
                'selection_method' => $attributes['selection_method'] ?? 'OPEN',
                'registration_opens' => $attributes['registration_opens'] ?? null,
                'registration_closes' => $attributes['registration_closes'] ?? null,
                'starts_on' => $attributes['starts_on'] ?? null,
                'ends_on' => $attributes['ends_on'] ?? null,
                'requires_parent_consent' => (bool) ($attributes['requires_parent_consent'] ?? false),
                'requires_payment' => (bool) ($attributes['requires_payment'] ?? false),
                'fee_amount' => $attributes['fee_amount'] ?? 0,
                'fee_currency' => $attributes['fee_currency'] ?? 'IDR',
                'minimum_attendance_percentage' => $attributes['minimum_attendance_percentage'] ?? null,
                'completion_rules' => $attributes['completion_rules'] ?? null,
                'certificate_rules' => $attributes['certificate_rules'] ?? null,
                'lms_course_id' => $attributes['lms_course_id'] ?? null,
                'metadata' => $attributes['metadata'] ?? null,
                'created_by' => $actor->id,
            ]);

            $this->syncRelations($program, $attributes, true);

            $this->audit->record(AuditAction::PROGRAM_CREATED, [
                'subject' => $program,
                'after' => $program,
                'program_id' => $program->id,
                'actor' => $actor->id,
            ]);

            return $program->refresh();
        });
    }

    public function update(ActivityProgram $program, array $attributes, User $actor): ActivityProgram
    {
        return DB::transaction(function () use ($program, $attributes, $actor) {
            $before = $program->attributesToArray();

            $program->fill(collect($attributes)->only([
                'name', 'slug', 'description', 'objectives_summary', 'type', 'delivery_type', 'level', 'gender',
                'coordinator_user_id', 'advisor_employee_id', 'coach_employee_id', 'room_id',
                'minimum_capacity', 'maximum_capacity', 'minimum_grade_level', 'maximum_grade_level',
                'selection_method', 'registration_opens', 'registration_closes', 'starts_on', 'ends_on',
                'requires_parent_consent', 'requires_payment', 'fee_amount', 'fee_currency',
                'minimum_attendance_percentage', 'completion_rules', 'certificate_rules', 'lms_course_id', 'metadata',
                'ticket_price_currency',
            ])->all());

            $program->save();
            $this->syncRelations($program, $attributes, false);
            $this->syncRequirements($program, $attributes['requirements'] ?? null);

            $this->audit->record(AuditAction::PROGRAM_UPDATED, [
                'subject' => $program,
                'before' => $before,
                'after' => $program,
                'program_id' => $program->id,
                'actor' => $actor->id,
            ]);

            return $program->refresh();
        });
    }

    /**
     * Terbitkan program (§91).
     *
     * Validasi di sini bukan formalitas: program tanpa kapasitas maksimum tidak
     * punya cara menolak pendaftar, dan program tanpa jadwal tidak bisa
     * menghasilkan sesi.
     */
    public function publish(ActivityProgram $program, User $actor): ActivityProgram
    {
        $blockers = [];

        if ($program->maximum_capacity === null) {
            $blockers[] = 'Kapasitas maksimum belum ditentukan.';
        }

        if ($program->category_id === null) {
            $blockers[] = 'Kategori belum dipilih.';
        }

        if ($program->period_id === null) {
            $blockers[] = 'Periode belum dipilih.';
        }

        if ($program->schedules()->where('status', 'ACTIVE')->count() === 0) {
            $blockers[] = 'Belum ada jadwal aktif — sesi tidak dapat dibuat.';
        }

        if ($program->coach_employee_id === null && $program->coaches()->where('is_active', true)->count() === 0) {
            $blockers[] = 'Belum ada pembina/pelatih yang ditugaskan.';
        }

        if ($blockers !== []) {
            throw ActivityValidationException::withErrors($blockers, 'Program belum siap diterbitkan.');
        }

        $program->forceFill([
            'status' => ProgramStatus::PUBLISHED->value,
            'published_at' => now(),
        ])->save();

        $this->audit->record(AuditAction::PROGRAM_PUBLISHED, [
            'subject' => $program,
            'program_id' => $program->id,
            'actor' => $actor->id,
        ]);

        return $program->refresh();
    }

    public function archive(ActivityProgram $program, User $actor): ActivityProgram
    {
        $program->forceFill(['status' => ProgramStatus::ARCHIVED->value, 'archived_at' => now()])->save();

        $this->audit->record(AuditAction::PROGRAM_ARCHIVED, [
            'subject' => $program,
            'program_id' => $program->id,
            'actor' => $actor->id,
        ]);

        return $program->refresh();
    }

    /** @return array{registered: int, active: int, waitlisted: int, maximum: int|null, minimum: int|null, is_full: bool, seats_left: int|null} */
    public function capacity(ActivityProgram $program): array
    {
        $active = ActivityMembership::query()
            ->where('program_id', $program->id)
            ->whereIn('status', [MembershipStatus::ACTIVE->value, MembershipStatus::SUSPENDED->value])
            ->count();

        $waitlisted = ActivityWaitlistEntry::query()
            ->where('program_id', $program->id)
            ->where('status', 'WAITING')
            ->count();

        $maximum = $program->maximum_capacity === null ? null : (int) $program->maximum_capacity;

        return [
            'registered' => ActivityRegistration::query()->where('program_id', $program->id)->count(),
            'active' => $active,
            'waitlisted' => $waitlisted,
            'maximum' => $maximum,
            'minimum' => $program->minimum_capacity === null ? null : (int) $program->minimum_capacity,
            'is_full' => $maximum !== null && $active >= $maximum,
            'seats_left' => $maximum === null ? null : max(0, $maximum - $active),
        ];
    }

    /** @param array<string, mixed> $attributes */
    protected function syncRelations(ActivityProgram $program, array $attributes, bool $creating): void
    {
        if (array_key_exists('skills', $attributes)) {
            $program->skills()->sync(collect($attributes['skills'])->mapWithKeys(fn ($skill) => [
                is_array($skill) ? $skill['id'] : $skill => [
                    'is_required' => is_array($skill) ? (bool) ($skill['is_required'] ?? false) : false,
                    'weight' => is_array($skill) ? ($skill['weight'] ?? 1) : 1,
                ],
            ])->all());
        }

        if (array_key_exists('objectives', $attributes)) {
            $program->objectives()->sync(collect($attributes['objectives'])->mapWithKeys(fn ($objective) => [
                is_array($objective) ? $objective['id'] : $objective => [
                    'target_level' => is_array($objective) ? ($objective['target_level'] ?? null) : null,
                ],
            ])->all());
        }

        if (array_key_exists('coaches', $attributes)) {
            $program->coaches()->delete();

            foreach ($attributes['coaches'] as $coach) {
                $program->coaches()->create([
                    'employee_id' => $coach['employee_id'] ?? null,
                    'user_id' => $coach['user_id'] ?? null,
                    'role' => $coach['role'] ?? 'COACH',
                    'starts_on' => $coach['starts_on'] ?? null,
                    'ends_on' => $coach['ends_on'] ?? null,
                    'is_active' => true,
                    'notes' => $coach['notes'] ?? null,
                ]);
            }
        }

        if ($creating && $program->lms_course_id !== null) {
            $this->integrations->call('LMS', 'course.linked', [
                'subject_type' => ActivityProgram::class,
                'subject_id' => $program->id,
                'course_id' => $program->lms_course_id,
            ], fn () => $this->integrations->lms()->course((int) $program->lms_course_id) ?? ['status' => 'SKIPPED']);
        }
    }

    /** @param list<array<string, mixed>>|null $requirements */
    protected function syncRequirements(ActivityProgram $program, ?array $requirements): void
    {
        if ($requirements === null) {
            return;
        }

        $program->requirements()->delete();

        foreach ($requirements as $index => $requirement) {
            ActivityRegistrationRequirement::query()->create([
                'program_id' => $program->id,
                'type' => $requirement['type'],
                'operator' => $requirement['operator'] ?? 'GTE',
                'value' => $requirement['value'] ?? null,
                'is_mandatory' => (bool) ($requirement['is_mandatory'] ?? true),
                'message' => $requirement['message'] ?? null,
                'sort_order' => $requirement['sort_order'] ?? $index,
            ]);
        }
    }
}
