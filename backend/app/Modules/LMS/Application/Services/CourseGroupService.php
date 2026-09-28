<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Models\LmsCourse;
use App\Modules\LMS\Models\LmsEnrollment;
use App\Modules\LMS\Models\LmsGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Kelompok peserta (§19) — dasar kerja kelompok pada phase berikutnya. */
class CourseGroupService
{
    public function create(LmsCourse $course, array $data): LmsGroup
    {
        return $course->groups()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'capacity' => $data['capacity'] ?? null,
            'sort_order' => $data['sort_order'] ?? ((int) $course->groups()->max('sort_order') + 1),
        ]);
    }

    public function update(LmsGroup $group, array $data): LmsGroup
    {
        $group->fill(array_intersect_key($data, array_flip(['name', 'description', 'capacity', 'sort_order'])))->save();

        return $group->fresh();
    }

    public function delete(LmsGroup $group): void
    {
        $group->delete();
    }

    /**
     * Tambahkan peserta ke kelompok. Anggota wajib sudah terdaftar di course
     * yang sama — kelompok tidak boleh menjadi jalur pendaftaran diam-diam.
     *
     * @param  list<int>  $enrollmentIds
     */
    public function addMembers(LmsGroup $group, array $enrollmentIds): LmsGroup
    {
        $enrollments = LmsEnrollment::query()
            ->where('course_id', $group->course_id)
            ->whereIn('id', $enrollmentIds)
            ->get();

        if ($group->capacity !== null && ($group->members()->count() + $enrollments->count()) > (int) $group->capacity) {
            throw ValidationException::withMessages([
                'members' => 'Jumlah anggota melebihi kapasitas kelompok ('.$group->capacity.').',
            ]);
        }

        DB::transaction(function () use ($group, $enrollments): void {
            foreach ($enrollments as $enrollment) {
                $group->members()->updateOrCreate(
                    ['enrollment_id' => $enrollment->id],
                    ['user_id' => $enrollment->user_id],
                );
            }
        });

        return $group->fresh(['members']);
    }

    /** @param list<int> $enrollmentIds */
    public function removeMembers(LmsGroup $group, array $enrollmentIds): LmsGroup
    {
        $group->members()->whereIn('enrollment_id', $enrollmentIds)->delete();

        return $group->fresh(['members']);
    }

    public function forUser(LmsCourse $course, int $userId): array
    {
        return $course->groups()
            ->whereHas('members', fn ($q) => $q->where('user_id', $userId))
            ->get()
            ->map(fn (LmsGroup $group) => ['id' => (int) $group->id, 'name' => (string) $group->name])
            ->all();
    }
}
