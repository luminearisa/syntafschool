<?php

namespace App\Modules\LMS\Infrastructure\Providers\Standalone;

use App\Modules\LMS\Domain\Contracts\TeacherProviderInterface;
use App\Modules\LMS\Domain\Support\LmsActor;
use App\Modules\LMS\Domain\Support\LmsTeacher;
use App\Modules\LMS\Models\LmsLearner;
use Illuminate\Database\Eloquent\Builder;

/** Standalone Mode: guru dari `lms_learners` bertipe TEACHER. */
class StandaloneTeacherProvider implements TeacherProviderInterface
{
    public function find(int $teacherId): ?LmsTeacher
    {
        $learner = LmsLearner::query()->where('learner_type', LmsLearner::TYPE_TEACHER)->find($teacherId);

        return $learner ? $this->toTeacher($learner) : null;
    }

    public function forUser(LmsActor $actor): ?LmsTeacher
    {
        $learner = LmsLearner::query()
            ->where('lms_user_id', $actor->id)
            ->where('learner_type', LmsLearner::TYPE_TEACHER)
            ->first();

        return $learner ? $this->toTeacher($learner) : null;
    }

    public function forClassSubject(int $classId, int $academicCourseId, ?int $academicYearId = null): ?LmsTeacher
    {
        // Mode mandiri tidak punya tabel pengampu mapel per kelas; penentuan
        // pengajar dilakukan manual oleh admin LMS.
        return null;
    }

    public function search(string $term, int $limit = 25): array
    {
        $term = trim($term);

        return LmsLearner::query()
            ->where('learner_type', LmsLearner::TYPE_TEACHER)
            ->where('is_active', true)
            ->when($term !== '', fn (Builder $q) => $q->where('name', 'like', "%{$term}%"))
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (LmsLearner $learner) => $this->toTeacher($learner))
            ->all();
    }

    protected function toTeacher(LmsLearner $learner): LmsTeacher
    {
        return new LmsTeacher(
            id: (int) $learner->id,
            userId: $learner->lms_user_id !== null ? (int) $learner->lms_user_id : null,
            name: (string) $learner->name,
            email: $learner->email,
            nip: $learner->code,
            photoPath: $learner->photo_path,
        );
    }
}
