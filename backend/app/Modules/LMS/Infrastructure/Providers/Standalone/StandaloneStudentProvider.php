<?php

namespace App\Modules\LMS\Infrastructure\Providers\Standalone;

use App\Modules\LMS\Domain\Contracts\StudentProviderInterface;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsLearner as LearnerDto;
use App\Modules\LMS\Models\LmsGuardian;
use App\Modules\LMS\Models\LmsLearner;
use Illuminate\Database\Eloquent\Builder;

/** Standalone Mode: siswa dari tabel `lms_learners` + `lms_guardians`. */
class StandaloneStudentProvider implements StudentProviderInterface
{
    public function find(int $studentId): ?LearnerDto
    {
        $learner = LmsLearner::query()
            ->where('learner_type', LmsLearner::TYPE_STUDENT)
            ->find($studentId);

        return $learner ? $this->toLearner($learner) : null;
    }

    public function forUser(LmsActor $actor): ?LearnerDto
    {
        $learner = LmsLearner::query()
            ->where('lms_user_id', $actor->id)
            ->where('learner_type', LmsLearner::TYPE_STUDENT)
            ->first();

        return $learner ? $this->toLearner($learner) : null;
    }

    public function inClass(int $classId, ?int $academicYearId = null): array
    {
        // Pada mode mandiri, "kelas" adalah label kelas pada profil peserta.
        $label = LmsLearner::query()->whereKey($classId)->value('class_label');

        return LmsLearner::query()
            ->where('learner_type', LmsLearner::TYPE_STUDENT)
            ->where('is_active', true)
            ->when($label !== null, fn (Builder $q) => $q->where('class_label', $label))
            ->orderBy('name')
            ->get()
            ->map(fn (LmsLearner $learner) => $this->toLearner($learner))
            ->all();
    }

    public function childrenOf(LmsActor $actor): array
    {
        $learners = LmsLearner::query()
            ->whereIn('id', LmsGuardian::query()
                ->where('lms_user_id', $actor->id)
                ->pluck('lms_learner_id'))
            ->orderBy('name')
            ->get();

        return $learners->map(function (LmsLearner $learner) use ($actor): LmsLearner {
            $link = LmsGuardian::query()
                ->where('lms_user_id', $actor->id)
                ->where('lms_learner_id', $learner->id)
                ->first();

            return $this->toLearner($learner, $link);
        })->all();
    }

    public function isGuardianOf(LmsActor $actor, int $studentId): bool
    {
        return LmsGuardian::query()
            ->where('lms_user_id', $actor->id)
            ->where('lms_learner_id', $studentId)
            ->exists();
    }

    public function search(string $term, ?int $classId = null, int $limit = 25): array
    {
        $term = trim($term);

        return LmsLearner::query()
            ->where('learner_type', LmsLearner::TYPE_STUDENT)
            ->where('is_active', true)
            ->when($term !== '', fn (Builder $q) => $q->where(
                fn (Builder $inner) => $inner
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%"),
            ))
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (LmsLearner $learner) => $this->toLearner($learner))
            ->all();
    }

    protected function toLearner(LmsLearner $learner, ?LmsGuardian $link = null): LearnerDto
    {
        return new LearnerDto(
            id: (int) $learner->id,
            userId: $learner->lms_user_id !== null ? (int) $learner->lms_user_id : null,
            name: (string) $learner->name,
            code: $learner->code,
            classId: null,
            className: $learner->class_label,
            programId: null,
            programName: $learner->program_label,
            academicYearId: null,
            photoPath: $learner->photo_path,
            guardianId: $link?->id !== null ? (int) $link->id : null,
            relation: $link?->relation,
            isPrimaryGuardian: (bool) ($link?->is_primary ?? false),
        );
    }
}
