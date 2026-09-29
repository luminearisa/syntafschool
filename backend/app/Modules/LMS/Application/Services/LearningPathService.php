<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Domain\Enums\DripReleaseType;
use App\Modules\LMS\Domain\Enums\LearningPathMode;
use App\Modules\LMS\Domain\Enums\LearningPathStatus;
use App\Modules\LMS\Domain\Support\AccessDecision;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsCourseSection;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsLearningPath;
use App\Modules\LMS\Models\LmsLearningPathItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Learning path (§1–§2) — Course → Learning Path → Module → Section → Activity.
 *
 * Modul hanyalah pembungkus `lms_course_sections`; tidak ada tabel konten baru
 * dan tidak ada mesin progress kedua. Persentase modul diambil dari ringkasan
 * `CourseProgressService`, dan penguncian berurutan memakai mesin completion
 * Phase 1 yang sama.
 */
class LearningPathService
{
    public function __construct(
        private readonly CourseProgressService $progress,
        private readonly CourseCompletionService $completions,
        private readonly DripContentService $drip,
        private readonly LearningEventService $events,
    ) {}

    /** Path aktif (atau path pertama) milik sebuah course. */
    public function primaryPath(LmsCourse $course): ?LmsLearningPath
    {
        return LmsLearningPath::query()
            ->where('course_id', $course->id)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->first();
    }

    /**
     * Modul path yang membungkus sebuah section (bila ada).
     *
     * Dipakai jalur availability supaya urutan modul dapat mengunci materi
     * tanpa jalur akses kedua.
     *
     * @return array{path: LmsLearningPath, module: LmsLearningPathItem}|null
     */
    public function moduleForSection(LmsCourse $course, ?LmsCourseSection $section): ?array
    {
        if ($section === null) {
            return null;
        }

        $path = $this->primaryPath($course);

        if ($path === null) {
            return null;
        }

        $module = $path->items()->where('section_id', $section->id)->first();

        return $module !== null ? ['path' => $path, 'module' => $module] : null;
    }

    /**
     * Struktur path untuk UI peserta/pengajar.
     *
     * @return array<string, mixed>|null
     */
    public function structure(LmsCourse $course, ?LmsEnrollment $enrollment, bool $forStaff = false): ?array
    {
        $path = $this->primaryPath($course);

        if ($path === null) {
            return null;
        }

        return [
            'id' => (int) $path->id,
            'title' => (string) $path->title,
            'description' => $path->description,
            'mode' => $path->mode?->value,
            'mode_label' => $path->mode?->label(),
            'status' => $path->status?->value,
            'is_sequential' => $path->isSequential(),
            'drip_enabled' => (bool) $path->drip_enabled,
            'modules' => $this->resolveModules($path, $course, $enrollment, $forStaff),
        ];
    }

    /** Satu modul lengkap dengan status kunci & progresnya. */
    public function moduleState(
        LmsLearningPath $path,
        LmsLearningPathItem $module,
        LmsCourse $course,
        ?LmsEnrollment $enrollment,
        bool $forStaff = false,
        ?array $sectionProgress = null,
    ): array {
        $section = $module->section;
        $progress = $sectionProgress ?? $this->sectionProgress($course, $enrollment, $section);

        $decision = $forStaff || $enrollment === null
            ? AccessDecision::allow()
            : $this->gate($path, $module, $course, $enrollment);

        return [
            'id' => (int) $module->id,
            'position' => (int) $module->position,
            'title' => $module->moduleTitle(),
            'description' => $module->description ?? $section?->description,
            'section_id' => $module->section_id !== null ? (int) $module->section_id : null,
            'items_count' => (int) ($progress['required_items'] ?? 0),
            'completed_items' => (int) ($progress['completed_items'] ?? 0),
            'progress_percent' => (float) ($progress['progress_percent'] ?? 0),
            'is_complete' => $this->isModuleComplete($progress),
            'is_required' => (bool) $module->is_required,
            'locked' => ! $decision->allowed,
            'lock_reason' => $decision->allowed ? null : $decision->message,
            'lock_code' => $decision->allowed ? null : $decision->reason,
            'unlock_at' => $this->unlockAt($course, $module, $enrollment)?->toIso8601String(),
            'unlock_rule' => $module->unlock_rule?->value,
        ];
    }

    /**
     * Kunci modul berurutan (§2) + drip (§4).
     *
     * SEQUENTIAL: modul ke-n terbuka hanya bila seluruh modul wajib sebelumnya
     * tuntas. FLEXIBLE & SELF_PACED: urutan tidak mengunci apa pun.
     */
    public function gate(
        LmsLearningPath $path,
        LmsLearningPathItem $module,
        LmsCourse $course,
        LmsEnrollment $enrollment,
    ): AccessDecision {
        $drip = $this->drip->evaluate($course, $enrollment, $module->section, null);

        if (! $drip->allowed) {
            return $drip;
        }

        if (! $path->isSequential()) {
            return AccessDecision::allow();
        }

        $previousRequired = $path->items()
            ->where('sort_order', '<', $module->sort_order)
            ->where('is_required', true)
            ->get();

        $sectionProgress = $this->progressCache($course, $enrollment);

        foreach ($previousRequired as $previous) {
            $progress = $sectionProgress[(int) $previous->section_id] ?? null;

            if ($progress === null) {
                continue;
            }

            if (! $this->isModuleComplete($progress)) {
                return AccessDecision::deny(
                    'sequential_module_locked',
                    'Selesaikan modul "'.$previous->moduleTitle().'" terlebih dahulu ('.$progress['completed_items'].'/'.$progress['required_items'].').',
                );
            }
        }

        return AccessDecision::allow();
    }

    /* ---------------------------------------------------------------------
     | Penulisan struktur (§2) — guru & admin
     * ------------------------------------------------------------------- */

    public function create(LmsCourse $course, array $data, ?LmsActor $actor = null): LmsLearningPath
    {
        return DB::transaction(function () use ($course, $data, $actor) {
            $path = LmsLearningPath::query()->create([
                'course_id' => $course->id,
                'title' => $data['title'] ?? $course->name,
                'slug' => $data['slug'] ?? null,
                'description' => $data['description'] ?? null,
                'mode' => $data['mode'] ?? LearningPathMode::FLEXIBLE->value,
                'status' => $data['status'] ?? LearningPathStatus::DRAFT->value,
                'is_default' => $data['is_default'] ?? true,
                'sort_order' => $data['sort_order'] ?? 0,
                'drip_enabled' => $data['drip_enabled'] ?? false,
                'settings' => $data['settings'] ?? null,
                'created_by' => $actor?->id,
            ]);

            if (($data['sync_sections'] ?? true) === true) {
                $this->syncModules($path, $actor);
            }

            $course->forceFill(['learning_path_enabled' => true])->save();

            $this->events->audit($course, 'learning_path_created', ['learning_path_id' => (int) $path->id], $actor);

            return $path->fresh();
        });
    }

    public function update(LmsLearningPath $path, array $data, ?LmsActor $actor = null): LmsLearningPath
    {
        $path->fill(array_filter([
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'mode' => $data['mode'] ?? null,
            'status' => $data['status'] ?? null,
            'is_default' => $data['is_default'] ?? null,
            'drip_enabled' => $data['drip_enabled'] ?? null,
            'settings' => $data['settings'] ?? null,
        ], fn ($value) => $value !== null));

        if (isset($data['mode']) && $data['mode'] === LearningPathMode::SEQUENTIAL->value && ! $path->drip_enabled) {
            // Mode berurutan tidak wajib drip, tetapi penanda ini dipakai UI
            // untuk menjelaskan mengapa modul berikutnya terkunci.
            $path->drip_enabled = $path->drip_enabled;
        }

        $path->save();

        $this->events->audit($path->course, 'learning_path_updated', ['learning_path_id' => (int) $path->id], $actor);

        return $path->fresh();
    }

    public function delete(LmsLearningPath $path, ?LmsActor $actor = null): void
    {
        $course = $path->course;
        $path->delete();

        if ($course !== null) {
            $this->events->audit($course, 'learning_path_deleted', ['learning_path_id' => (int) $path->id], $actor);
        }
    }

    /**
     * Selaraskan modul dengan daftar section course (§1).
     *
     * Section yang belum menjadi modul ditambahkan pada urutan berikutnya;
     * modul yang section-nya sudah tidak ada dibiarkan (tidak dihapus diam-diam)
     * agar konfigurasi guru tidak hilang.
     */
    public function syncModules(LmsLearningPath $path, ?LmsActor $actor = null): LmsLearningPath
    {
        $sections = LmsCourseSection::query()
            ->where('course_id', $path->course_id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $existing = $path->items()->pluck('section_id')->filter()->all();
        $position = (int) $path->items()->max('position');

        foreach ($sections as $index => $section) {
            if (in_array((int) $section->id, array_map('intval', $existing), true)) {
                continue;
            }

            $position++;

            $path->items()->create([
                'section_id' => $section->id,
                'title' => $section->title,
                'position' => $position,
                'sort_order' => ($index + 1) * 10,
                'unlock_rule' => DripReleaseType::IMMEDIATE->value,
                'is_required' => true,
            ]);
        }

        $this->events->audit($path->course, 'learning_path_synced', [
            'learning_path_id' => (int) $path->id,
            'modules' => (int) $path->items()->count(),
        ], $actor);

        return $path->fresh();
    }

    public function addModule(LmsLearningPath $path, array $data): LmsLearningPathItem
    {
        $sort = (int) $path->items()->max('sort_order');

        return $path->items()->create([
            'section_id' => $data['section_id'] ?? null,
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'position' => $data['position'] ?? ((int) $path->items()->max('position') + 1),
            'sort_order' => $data['sort_order'] ?? $sort + 10,
            'unlock_rule' => $data['unlock_rule'] ?? DripReleaseType::IMMEDIATE->value,
            'unlock_at' => $data['unlock_at'] ?? null,
            'unlock_after_days' => $data['unlock_after_days'] ?? null,
            'is_required' => $data['is_required'] ?? true,
            'settings' => $data['settings'] ?? null,
        ]);
    }

    public function updateModule(LmsLearningPathItem $module, array $data): LmsLearningPathItem
    {
        $module->fill(array_filter([
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'unlock_rule' => $data['unlock_rule'] ?? null,
            'unlock_at' => $data['unlock_at'] ?? null,
            'unlock_after_days' => $data['unlock_after_days'] ?? null,
            'is_required' => $data['is_required'] ?? null,
        ], fn ($value) => $value !== null));

        $module->save();

        return $module->fresh();
    }

    /** @param list<int> $orderedIds */
    public function reorderModules(LmsLearningPath $path, array $orderedIds): LmsLearningPath
    {
        DB::transaction(function () use ($path, $orderedIds) {
            foreach (array_values($orderedIds) as $index => $id) {
                $path->items()->whereKey($id)->update([
                    'sort_order' => ($index + 1) * 10,
                    'position' => $index + 1,
                ]);
            }
        });

        return $path->fresh();
    }

    public function deleteModule(LmsLearningPathItem $module): void
    {
        $module->delete();
    }

    /**
     * Mengubah aturan buka modul menjadi baris drip (§4) agar konsisten dengan
     * jalur akses lain — satu sumber aturan, bukan dua.
     */
    public function applyModuleUnlockRule(LmsLearningPathItem $module, ?LmsActor $actor = null): void
    {
        $path = $module->learningPath;
        $course = $path?->course;

        if ($path === null || $course === null || $module->section_id === null) {
            return;
        }

        $existing = \App\Modules\LMS\Models\LmsDripRule::query()
            ->where('course_id', $course->id)
            ->where('section_id', $module->section_id)
            ->whereNull('item_id')
            ->whereNull('activity_id')
            ->first();

        if ($module->unlock_rule === null || $module->unlock_rule === DripReleaseType::IMMEDIATE) {
            $existing?->delete();

            return;
        }

        $this->drip->upsert($course, [
            'id' => $existing?->id,
            'section_id' => $module->section_id,
            'release_type' => $module->unlock_rule->value,
            'release_at' => $module->unlock_at,
            'days_after_enrollment' => $module->unlock_after_days,
        ], $actor);
    }

    /* ---------------------------------------------------------------------
     | Pembantu
     * ------------------------------------------------------------------- */

    /** @return list<array<string, mixed>> */
    protected function resolveModules(
        LmsLearningPath $path,
        LmsCourse $course,
        ?LmsEnrollment $enrollment,
        bool $forStaff,
    ): array {
        $cache = $enrollment !== null ? $this->progressCache($course, $enrollment) : [];

        return $path->items()->with('section')->get()->map(function (LmsLearningPathItem $module) use ($path, $course, $enrollment, $forStaff, $cache) {
            $progress = $module->section_id !== null ? ($cache[(int) $module->section_id] ?? null) : null;

            return $this->moduleState($path, $module, $course, $enrollment, $forStaff, $progress);
        })->all();
    }

    /**
     * Progres per section — memakai satu-satunya mesin progress yang ada
     * (§CourseProgressService), bukan perhitungan tandingan.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function progressCache(LmsCourse $course, LmsEnrollment $enrollment): array
    {
        $actor = $this->actorFromEnrollment($enrollment);

        if ($actor === null) {
            return [];
        }

        $summary = $this->progress->summary($course, $actor);
        $cache = [];

        foreach ((array) ($summary['sections'] ?? []) as $section) {
            $cache[(int) $section['section_id']] = $section;
        }

        return $cache;
    }

    /** @return array<string, mixed>|null */
    protected function sectionProgress(LmsCourse $course, ?LmsEnrollment $enrollment, ?LmsCourseSection $section): ?array
    {
        if ($enrollment === null || $section === null) {
            return null;
        }

        return $this->progressCache($course, $enrollment)[(int) $section->id] ?? null;
    }

    protected function isModuleComplete(?array $progress): bool
    {
        if ($progress === null) {
            return false;
        }

        $required = (int) ($progress['required_items'] ?? 0);

        return $required > 0 && (int) ($progress['completed_items'] ?? 0) >= $required;
    }

    protected function unlockAt(LmsCourse $course, LmsLearningPathItem $module, ?LmsEnrollment $enrollment): ?\Illuminate\Support\Carbon
    {
        if ($module->unlock_at !== null) {
            return $module->unlock_at;
        }

        if ($module->unlock_rule === DripReleaseType::DAYS_AFTER_ENROLLMENT && $module->unlock_after_days !== null && $enrollment !== null) {
            $anchor = $enrollment->enrolled_at ?? $enrollment->created_at;

            return $anchor?->copy()->addDays($module->unlock_after_days);
        }

        return $this->drip->unlockDate($course, $enrollment, $module->section, null);
    }

    /** Aktor dari sebuah enrollment — path hanya butuh identitas. */
    protected function actorFromEnrollment(LmsEnrollment $enrollment): ?LmsActor
    {
        $user = $enrollment->user;

        if ($user === null) {
            return null;
        }

        return app(\App\Modules\LMS\Domain\Contracts\UserProviderInterface::class)->find((int) $user->getKey());
    }
}
