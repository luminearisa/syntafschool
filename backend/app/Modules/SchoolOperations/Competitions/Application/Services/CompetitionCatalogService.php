<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Application\Services;

use App\Models\User;
use App\Modules\SchoolOperations\Competitions\Domain\Support\CompetitionStatus;
use App\Modules\SchoolOperations\Competitions\Models\Competition;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionCategory;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionDivision;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionDomain;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionEdition;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionFormat;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionLevel;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionMatch;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionRegistration;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionRound;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionRubric;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionRubricCriterion;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionScorecard;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Competition master, edition, category, rubric and administrative overview. */
class CompetitionCatalogService
{
    public function __construct(
        private readonly CompetitionAuditService $audit,
        private readonly CompetitionScopeService $scope,
    ) {}

    public function meta(): array
    {
        return [
            'domains' => CompetitionDomain::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'code', 'name', 'color', 'icon']),
            'types' => CompetitionType::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'code', 'name', 'competition_domain_id', 'default_format', 'default_scoring_model', 'participant_mode', 'requires_team', 'requires_judge', 'supports_bracket']),
            'formats' => CompetitionFormat::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'code', 'name', 'family', 'supports_advancement', 'requires_bracket']),
            'levels' => CompetitionLevel::query()->where('is_active', true)->orderBy('sort_order')->orderBy('rank')->get(['id', 'code', 'name', 'scope', 'rank']),
            'divisions' => CompetitionDivision::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'code', 'name', 'division_type', 'gender', 'min_age', 'max_age', 'min_grade_level', 'max_grade_level']),
            'edition_statuses' => CompetitionStatus::EDITION,
            'registration_statuses' => CompetitionStatus::REGISTRATION,
            'scoring_models' => ['NUMERIC', 'WEIGHTED', 'POINTS', 'TIME', 'DISTANCE', 'PLACEMENT', 'BEST_OF_N'],
            'aggregation_methods' => ['AVERAGE', 'WEIGHTED_AVERAGE', 'MEDIAN', 'DROP_HIGHEST', 'DROP_LOWEST', 'HEAD_JUDGE_OVERRIDE', 'CONSENSUS'],
        ];
    }

    public function paginate(array $filters, User $actor)
    {
        $query = $this->scope->visibleCompetitions(Competition::query(), $actor)
            ->with(['competitionType:id,code,name', 'competitionDomain:id,code,name'])
            ->withCount('competitionEditions')
            ->when(! empty($filters['status']), fn (Builder $q) => $q->where('status', strtoupper((string) $filters['status'])))
            ->when(! empty($filters['domain']), fn (Builder $q) => $q->where('competition_domain_id', $filters['domain']))
            ->when(! empty($filters['type']), fn (Builder $q) => $q->where('competition_type_id', $filters['type']))
            ->when(! empty($filters['search']), function (Builder $q) use ($filters): void {
                $term = '%'.trim((string) $filters['search']).'%';
                $q->where(fn (Builder $search) => $search->where('name', 'like', $term)->orWhere('code', 'like', $term)->orWhere('organizer_name', 'like', $term));
            })
            ->orderByDesc('created_at');

        return $query->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 20))));
    }

    public function detail(Competition $competition, User $actor): array
    {
        $this->scope->assertCompetition($actor, $competition);
        $editions = CompetitionEdition::query()->where('competition_id', $competition->id)
            ->orderByDesc('year')->get()->map(fn (CompetitionEdition $edition) => $this->editionData($edition))->all();

        return [
            'competition' => $competition->toArray(),
            'type' => $competition->competitionType?->only(['id', 'code', 'name', 'default_format', 'default_scoring_model']),
            'domain' => CompetitionDomain::query()->find($competition->competition_domain_id)?->only(['id', 'code', 'name']),
            'editions' => $editions,
        ];
    }

    public function createCompetition(array $data, User $actor): Competition
    {
        $this->assertScopeAssignment($data, $actor);

        return DB::transaction(function () use ($data, $actor): Competition {
            $type = CompetitionType::query()->where('is_active', true)->findOrFail($data['competition_type_id']);
            $domainId = $data['competition_domain_id'] ?? $type->competition_domain_id;
            $competition = Competition::query()->create([
                'organization_id' => $data['organization_id'] ?? $this->actorId($actor, 'organization_id'),
                'campus_id' => $data['campus_id'] ?? $this->actorId($actor, 'campus_id'),
                'competition_type_id' => $type->id,
                'competition_domain_id' => $domainId,
                'code' => $data['code'] ?? $this->uniqueCode(),
                'name' => trim($data['name']),
                'slug' => $data['slug'] ?? $this->uniqueSlug($data['name']),
                'short_name' => $data['short_name'] ?? null,
                'description' => $data['description'] ?? null,
                'organizer_name' => $data['organizer_name'] ?? null,
                'organizer_contact_email' => $data['organizer_contact_email'] ?? null,
                'organizer_contact_phone' => $data['organizer_contact_phone'] ?? null,
                'organizer_organization_id' => $data['organizer_organization_id'] ?? null,
                'is_internal' => (bool) ($data['is_internal'] ?? true),
                'is_recurring' => (bool) ($data['is_recurring'] ?? false),
                'recurrence_pattern' => $data['recurrence_pattern'] ?? null,
                'default_visibility' => strtoupper((string) ($data['default_visibility'] ?? 'INTERNAL')),
                'default_participant_mode' => strtoupper((string) ($data['default_participant_mode'] ?? $type->participant_mode)),
                'status' => 'DRAFT',
                'created_by' => $actor->id,
                'settings' => $data['settings'] ?? null,
            ]);
            $this->audit->record('CompetitionCreated', $actor, $competition, after: $competition->only(['id', 'code', 'name', 'status']));

            return $competition->refresh()->load(['competitionType:id,code,name', 'competitionDomain:id,code,name']);
        });
    }

    public function updateCompetition(Competition $competition, array $data, User $actor): Competition
    {
        $this->scope->assertCompetition($actor, $competition);
        if (! in_array($competition->status, ['DRAFT', 'PLANNING'], true)) {
            throw ValidationException::withMessages(['competition' => 'Master kompetisi hanya dapat diubah saat DRAFT atau PLANNING.']);
        }
        $this->assertScopeAssignment($data, $actor);
        $before = $competition->only(['name', 'description', 'competition_type_id', 'competition_domain_id', 'campus_id', 'organization_id', 'status']);
        $editable = array_intersect_key($data, array_flip([
            'name', 'short_name', 'description', 'organizer_name', 'organizer_contact_email',
            'organizer_contact_phone', 'organizer_organization_id', 'competition_type_id',
            'competition_domain_id', 'campus_id', 'organization_id', 'is_internal',
            'is_recurring', 'recurrence_pattern', 'default_visibility', 'default_participant_mode', 'settings',
        ]));
        $competition->fill($editable)->save();
        $this->audit->record('CompetitionUpdated', $actor, $competition, before: $before, after: $competition->only(array_keys($before)));

        return $competition->refresh()->load(['competitionType:id,code,name', 'competitionDomain:id,code,name']);
    }

    public function deleteCompetition(Competition $competition, User $actor): void
    {
        $this->scope->assertCompetition($actor, $competition);
        if ($competition->status !== 'DRAFT' || CompetitionEdition::query()->where('competition_id', $competition->id)->exists()) {
            throw ValidationException::withMessages(['competition' => 'Kompetisi hanya dapat dihapus saat DRAFT dan belum memiliki edition.']);
        }
        $before = $competition->only(['code', 'name', 'status']);
        $competition->delete();
        $this->audit->record('CompetitionDeleted', $actor, $competition, before: $before, reason: 'Soft delete oleh pengelola.');
    }

    public function createEdition(Competition $competition, array $data, User $actor): CompetitionEdition
    {
        $this->scope->assertCompetition($actor, $competition);
        if (! in_array($competition->status, ['DRAFT', 'PLANNING'], true)) {
            throw ValidationException::withMessages(['competition' => 'Edition baru hanya dapat dibuat saat kompetisi DRAFT atau PLANNING.']);
        }
        $this->assertScopeAssignment($data, $actor);
        if (isset($data['starts_on'], $data['ends_on']) && $data['starts_on'] > $data['ends_on']) {
            throw ValidationException::withMessages(['ends_on' => 'Tanggal selesai harus sama atau sesudah tanggal mulai.']);
        }
        if (isset($data['registration_opens'], $data['registration_closes']) && $data['registration_opens'] >= $data['registration_closes']) {
            throw ValidationException::withMessages(['registration_closes' => 'Pendaftaran harus ditutup setelah waktu pembukaan.']);
        }
        if (CompetitionEdition::query()->where('competition_id', $competition->id)->where('year', $data['year'])->exists()) {
            throw ValidationException::withMessages(['year' => 'Edition untuk tahun tersebut sudah ada.']);
        }

        return DB::transaction(function () use ($competition, $data, $actor): CompetitionEdition {
            $name = trim((string) ($data['name'] ?? $competition->name.' '.$data['year']));
            $edition = CompetitionEdition::query()->create([
                'competition_id' => $competition->id,
                'level_id' => $data['level_id'] ?? null,
                'campus_id' => $data['campus_id'] ?? $competition->campus_id,
                'organization_id' => $data['organization_id'] ?? $competition->organization_id,
                'year' => (int) $data['year'],
                'code' => $data['code'] ?? $this->uniqueEditionCode($competition, (int) $data['year']),
                'name' => $name,
                'slug' => $data['slug'] ?? $this->uniqueSlug($name),
                'theme' => $data['theme'] ?? null,
                'organizer_name' => $data['organizer_name'] ?? $competition->organizer_name,
                'host_name' => $data['host_name'] ?? null,
                'event_id' => $data['event_id'] ?? null,
                'description' => $data['description'] ?? null,
                'terms' => $data['terms'] ?? null,
                'starts_on' => $data['starts_on'] ?? null,
                'ends_on' => $data['ends_on'] ?? null,
                'registration_opens' => $data['registration_opens'] ?? null,
                'registration_closes' => $data['registration_closes'] ?? null,
                'status' => 'DRAFT',
                'participant_type' => strtoupper((string) ($data['participant_type'] ?? 'STUDENT')),
                'registration_mode' => strtoupper((string) ($data['registration_mode'] ?? 'OPEN')),
                'max_participants' => $data['max_participants'] ?? null,
                'max_teams' => $data['max_teams'] ?? null,
                'allows_external' => (bool) ($data['allows_external'] ?? false),
                'allows_internal' => (bool) ($data['allows_internal'] ?? true),
                'requires_parent_consent' => (bool) ($data['requires_parent_consent'] ?? false),
                'requires_payment' => (bool) ($data['requires_payment'] ?? false),
                'requires_eligibility' => (bool) ($data['requires_eligibility'] ?? true),
                'requires_selection' => (bool) ($data['requires_selection'] ?? false),
                'requires_documents' => (bool) ($data['requires_documents'] ?? false),
                'timezone' => $data['timezone'] ?? 'Asia/Jakarta',
                'public_visibility' => strtoupper((string) ($data['public_visibility'] ?? 'INTERNAL')),
                'created_by' => $actor->id,
                'settings' => $data['settings'] ?? null,
            ]);
            $this->audit->record('CompetitionEditionCreated', $actor, $edition, after: $edition->only(['id', 'code', 'name', 'year', 'status']));

            return $edition;
        });
    }

    public function editionData(CompetitionEdition $edition): array
    {
        return [
            ...$edition->toArray(),
            'categories' => CompetitionCategory::query()->where('edition_id', $edition->id)->where('is_active', true)
                ->orderBy('sort_order')->orderBy('name')->get()->toArray(),
            'competition' => Competition::query()->find($edition->competition_id)?->only(['id', 'code', 'name', 'slug']),
        ];
    }

    public function updateEdition(CompetitionEdition $edition, array $data, User $actor): CompetitionEdition
    {
        $this->scope->assertEdition($actor, $edition);
        if (! in_array($edition->status, ['DRAFT', 'PLANNING'], true)) {
            throw ValidationException::withMessages(['edition' => 'Edition hanya dapat diubah saat DRAFT atau PLANNING.']);
        }
        $before = $edition->only(['name', 'theme', 'starts_on', 'ends_on', 'registration_opens', 'registration_closes', 'status']);
        $edition->fill(array_intersect_key($data, array_flip([
            'name', 'theme', 'organizer_name', 'host_name', 'description', 'terms', 'starts_on', 'ends_on',
            'registration_opens', 'registration_closes', 'participant_type', 'registration_mode', 'max_participants',
            'max_teams', 'allows_external', 'allows_internal', 'requires_parent_consent', 'requires_payment',
            'requires_eligibility', 'requires_selection', 'requires_documents', 'timezone', 'public_visibility', 'settings',
        ])))->save();
        $this->audit->record('CompetitionEditionUpdated', $actor, $edition, before: $before, after: $edition->only(array_keys($before)));

        return $edition->refresh();
    }

    public function publishEdition(CompetitionEdition $edition, User $actor): CompetitionEdition
    {
        $this->scope->assertEdition($actor, $edition);
        if ($edition->status !== 'DRAFT' && $edition->status !== 'PLANNING') {
            throw ValidationException::withMessages(['edition' => 'Hanya edition DRAFT/PLANNING yang dapat dipublikasikan.']);
        }
        if (! CompetitionCategory::query()->where('edition_id', $edition->id)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['categories' => 'Tambahkan minimal satu kategori sebelum memublikasikan edition.']);
        }
        $edition->forceFill(['status' => 'PLANNING', 'published_at' => now()])->save();
        $this->audit->record('CompetitionPublished', $actor, $edition, after: ['published_at' => now()->toIso8601String()]);

        return $edition->refresh();
    }

    public function openRegistration(CompetitionEdition $edition, User $actor): CompetitionEdition
    {
        $this->scope->assertEdition($actor, $edition);
        if (! in_array($edition->status, ['PLANNING', 'DRAFT'], true)) {
            throw ValidationException::withMessages(['edition' => 'Pendaftaran hanya dapat dibuka dari status DRAFT atau PLANNING.']);
        }
        if (! CompetitionCategory::query()->where('edition_id', $edition->id)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['categories' => 'Tambahkan minimal satu kategori sebelum membuka pendaftaran.']);
        }
        if ($edition->registration_closes !== null && $edition->registration_closes->isPast()) {
            throw ValidationException::withMessages(['registration_closes' => 'Waktu penutupan pendaftaran sudah lewat.']);
        }
        $edition->forceFill(['status' => 'REGISTRATION_OPEN', 'published_at' => $edition->published_at ?? now()])->save();
        $this->audit->record('CompetitionRegistrationOpened', $actor, $edition, after: ['status' => $edition->status]);

        return $edition->refresh();
    }

    public function createCategory(CompetitionEdition $edition, array $data, User $actor): CompetitionCategory
    {
        $this->scope->assertEdition($actor, $edition);
        if (! in_array($edition->status, ['DRAFT', 'PLANNING'], true)) {
            throw ValidationException::withMessages(['category' => 'Kategori tidak dapat ditambah setelah edition dibuka.']);
        }
        if (CompetitionCategory::query()->where('edition_id', $edition->id)->where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => 'Kode kategori sudah digunakan pada edition ini.']);
        }
        if (($data['participant_mode'] ?? 'INDIVIDUAL') === 'TEAM'
            && isset($data['min_team_size'], $data['max_team_size'])
            && (int) $data['min_team_size'] > (int) $data['max_team_size']) {
            throw ValidationException::withMessages(['min_team_size' => 'Ukuran minimum tim tidak boleh melebihi ukuran maksimum.']);
        }

        return DB::transaction(function () use ($edition, $data, $actor): CompetitionCategory {
            $category = CompetitionCategory::query()->create([
                'edition_id' => $edition->id,
                'competition_division_id' => $data['competition_division_id'] ?? null,
                'code' => strtoupper(trim($data['code'])),
                'name' => trim($data['name']),
                'slug' => $data['slug'] ?? $this->uniqueSlug($data['name']),
                'description' => $data['description'] ?? null,
                'gender_restriction' => strtoupper((string) ($data['gender_restriction'] ?? 'ANY')),
                'min_grade_level' => $data['min_grade_level'] ?? null,
                'max_grade_level' => $data['max_grade_level'] ?? null,
                'program_restriction' => $data['program_restriction'] ?? null,
                'min_age' => $data['min_age'] ?? null,
                'max_age' => $data['max_age'] ?? null,
                'participant_mode' => strtoupper((string) ($data['participant_mode'] ?? 'INDIVIDUAL')),
                'min_team_size' => $data['min_team_size'] ?? null,
                'max_team_size' => $data['max_team_size'] ?? null,
                'capacity' => $data['capacity'] ?? null,
                'min_participants' => $data['min_participants'] ?? null,
                'registration_fee' => $data['registration_fee'] ?? null,
                'currency' => strtoupper((string) ($data['currency'] ?? 'IDR')),
                'fee_mode' => strtoupper((string) ($data['fee_mode'] ?? 'PER_PARTICIPANT')),
                'qualification_requirement' => $data['qualification_requirement'] ?? null,
                'scoring_model' => strtoupper((string) ($data['scoring_model'] ?? 'WEIGHTED')),
                'ranking_model' => strtoupper((string) ($data['ranking_model'] ?? 'HIGHER_BETTER')),
                'aggregation_method' => strtoupper((string) ($data['aggregation_method'] ?? 'AVERAGE')),
                'tie_break_strategy' => $data['tie_break_strategy'] ?? [],
                'award_configuration' => $data['award_configuration'] ?? [],
                'round_format' => strtoupper((string) ($data['round_format'] ?? 'SINGLE')),
                'selection_method' => $data['selection_method'] ?? null,
                'requires_submission' => (bool) ($data['requires_submission'] ?? false),
                'requires_judge' => (bool) ($data['requires_judge'] ?? true),
                'requires_documents' => (bool) ($data['requires_documents'] ?? false),
                'requires_parent_consent' => (bool) ($data['requires_parent_consent'] ?? false),
                'is_active' => true,
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'settings' => $data['settings'] ?? null,
            ]);
            $this->audit->record('CompetitionCategoryCreated', $actor, $category, $edition, after: $category->only(['id', 'code', 'name', 'participant_mode']));

            return $category;
        });
    }

    public function createRubric(CompetitionEdition $edition, array $data, User $actor): CompetitionRubric
    {
        $this->scope->assertEdition($actor, $edition);
        $criteria = (array) $data['criteria'];
        $totalWeight = round(array_sum(array_map(fn (array $criterion) => (float) ($criterion['weight'] ?? 0), $criteria)), 2);
        $scoringType = strtoupper((string) ($data['scoring_type'] ?? 'WEIGHTED'));
        if ($scoringType === 'WEIGHTED' && abs($totalWeight - 100.0) > 0.01) {
            throw ValidationException::withMessages(['criteria' => 'Bobot rubric berbobot harus berjumlah tepat 100%.']);
        }
        $categoryId = $data['category_id'] ?? null;
        if ($categoryId && ! CompetitionCategory::query()->where('edition_id', $edition->id)->whereKey($categoryId)->exists()) {
            throw ValidationException::withMessages(['category_id' => 'Kategori tidak termasuk edition ini.']);
        }

        return DB::transaction(function () use ($edition, $data, $actor, $criteria, $totalWeight, $scoringType, $categoryId): CompetitionRubric {
            $rubric = CompetitionRubric::query()->create([
                'edition_id' => $edition->id,
                'category_id' => $categoryId,
                'code' => strtoupper((string) ($data['code'] ?? 'RUB-'.Str::upper(Str::random(6)))),
                'name' => trim($data['name']),
                'description' => $data['description'] ?? null,
                'version' => 1,
                'total_weight' => $totalWeight,
                'scoring_type' => $scoringType,
                'application_scope' => strtoupper((string) ($data['application_scope'] ?? 'CATEGORY')),
                'is_default' => (bool) ($data['is_default'] ?? true),
                'is_active' => true,
                'status' => 'PUBLISHED',
                'published_at' => now(),
                'created_by' => $actor->id,
                'settings' => $data['settings'] ?? null,
            ]);
            foreach ($criteria as $index => $criterion) {
                if ((float) ($criterion['max_score'] ?? 100) <= (float) ($criterion['min_score'] ?? 0)) {
                    throw ValidationException::withMessages(["criteria.{$index}.max_score" => 'Skor maksimum harus lebih besar daripada minimum.']);
                }
                CompetitionRubricCriterion::query()->create([
                    'rubric_id' => $rubric->id,
                    'code' => strtoupper((string) ($criterion['code'] ?? 'CRIT-'.($index + 1))),
                    'name' => trim($criterion['name']),
                    'description' => $criterion['description'] ?? null,
                    'weight' => $criterion['weight'] ?? 0,
                    'min_score' => $criterion['min_score'] ?? 0,
                    'max_score' => $criterion['max_score'] ?? 100,
                    'step' => $criterion['step'] ?? null,
                    'scoring_type' => strtoupper((string) ($criterion['scoring_type'] ?? 'NUMERIC')),
                    'is_required' => (bool) ($criterion['is_required'] ?? true),
                    'required_comment' => (bool) ($criterion['required_comment'] ?? false),
                    'penalty_enabled' => (bool) ($criterion['penalty_enabled'] ?? false),
                    'max_penalty' => $criterion['max_penalty'] ?? null,
                    'sort_order' => (int) ($criterion['sort_order'] ?? $index),
                    'help_text' => $criterion['help_text'] ?? null,
                ]);
            }
            $this->audit->record('CompetitionRubricCreated', $actor, $rubric, $edition, after: ['id' => $rubric->id, 'name' => $rubric->name, 'criteria_count' => count($criteria)]);

            return $rubric->load('competitionRubricCriterions');
        });
    }

    public function dashboard(User $actor): array
    {
        $competitionIds = $this->scope->visibleCompetitions(Competition::query(), $actor)->pluck('competitions.id')->map(fn ($id) => (int) $id)->all();
        $editionIds = $competitionIds === [] ? [] : CompetitionEdition::query()->whereIn('competition_id', $competitionIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $roundIds = $editionIds === [] ? [] : CompetitionRound::query()->whereIn('edition_id', $editionIds)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return [
            'competitions' => [
                'total' => count($competitionIds),
                'active' => CompetitionEdition::query()->whereIn('id', $editionIds)->whereNotIn('status', ['COMPLETED', 'CANCELLED', 'ARCHIVED'])->count(),
                'completed' => CompetitionEdition::query()->whereIn('id', $editionIds)->where('status', 'COMPLETED')->count(),
            ],
            'registrations' => [
                'total' => CompetitionRegistration::query()->whereIn('edition_id', $editionIds)->count(),
                'pending' => CompetitionRegistration::query()->whereIn('edition_id', $editionIds)->whereIn('status', ['SUBMITTED', 'UNDER_REVIEW', 'NEEDS_REVISION'])->count(),
                'approved' => CompetitionRegistration::query()->whereIn('edition_id', $editionIds)->where('status', 'APPROVED')->count(),
                'waitlisted' => CompetitionRegistration::query()->whereIn('edition_id', $editionIds)->where('status', 'WAITLISTED')->count(),
            ],
            'operations' => [
                'upcoming_matches' => CompetitionMatch::query()->whereIn('round_id', $roundIds)->where('status', 'SCHEDULED')->count(),
                'running_matches' => CompetitionMatch::query()->whereIn('round_id', $roundIds)->where('status', 'RUNNING')->count(),
                'pending_scores' => CompetitionScorecard::query()->whereIn('edition_id', $editionIds)->whereIn('status', ['DRAFT', 'SUBMITTED'])->count(),
            ],
            'recent' => Competition::query()->whereIn('id', $competitionIds)->with(['competitionType:id,name'])->orderByDesc('created_at')->limit(6)->get(),
        ];
    }

    private function assertScopeAssignment(array $data, User $actor): void
    {
        if (! $this->scope->isGlobal($actor)) {
            $actorCampus = $this->actorId($actor, 'campus_id');
            if (isset($data['campus_id']) && (int) $data['campus_id'] !== $actorCampus) {
                abort(403, 'Tidak dapat membuat kompetisi di kampus lain.');
            }
            $actorOrg = $this->actorId($actor, 'organization_id');
            if (isset($data['organization_id']) && $actorOrg !== null && (int) $data['organization_id'] !== $actorOrg) {
                abort(403, 'Tidak dapat membuat kompetisi di organisasi lain.');
            }
        }
    }

    private function actorId(User $actor, string $attribute): ?int
    {
        $value = $actor->getAttribute($attribute) ?? $actor->userable?->getAttribute($attribute);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function uniqueCode(): string
    {
        do {
            $code = 'CMP-'.now()->format('ym').'-'.Str::upper(Str::random(5));
        } while (Competition::query()->where('code', $code)->exists());

        return $code;
    }

    private function uniqueEditionCode(Competition $competition, int $year): string
    {
        return strtoupper($competition->code.'-'.$year.'-'.Str::random(3));
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'competition';
        $slug = $base;
        $suffix = 2;
        while (Competition::query()->where('slug', $slug)->exists() || CompetitionEdition::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
