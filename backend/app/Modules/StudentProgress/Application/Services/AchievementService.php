<?php

namespace App\Modules\StudentProgress\Application\Services;

use App\Models\AchievementType;
use App\Models\Student;
use App\Modules\StudentProgress\Models\StudentAchievement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Prestasi siswa: CRUD dan penelusuran per siswa.
 *
 * `level` divalidasi terhadap daftar konstanta model supaya laporan tidak
 * pernah menyimpan jenjang di luar urutan yang dikenali UI.
 */
class AchievementService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function query(array $filters = []): Builder
    {
        return StudentAchievement::query()
            ->with(['student:id,nis,full_name', 'achievementType:id,name,level'])
            ->forStudent(filled($filters['student_id'] ?? null) ? (int) $filters['student_id'] : null)
            ->when(
                filled($filters['achievement_type_id'] ?? null),
                fn (Builder $q) => $q->where('achievement_type_id', (int) $filters['achievement_type_id']),
            )
            ->when(
                filled($filters['level'] ?? null),
                fn (Builder $q) => $q->where('level', $filters['level']),
            )
            ->between($filters['from'] ?? null, $filters['to'] ?? null)
            ->when(
                filled($filters['keyword'] ?? null),
                fn (Builder $q) => $q->where(
                    fn (Builder $w) => $w->where('title', 'like', '%'.$filters['keyword'].'%')
                        ->orWhere('organizer', 'like', '%'.$filters['keyword'].'%'),
                ),
            )
            ->orderByDesc('date')
            ->orderByDesc('id');
    }

    /**
     * Daftar prestasi satu siswa (dipakai portal & halaman detail siswa).
     *
     * @param  array<string, mixed>  $filters
     */
    public function listForStudent(int $studentId, array $filters = []): Builder
    {
        return $this->query(['student_id' => $studentId] + $filters);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): StudentAchievement
    {
        $student = $this->assertStudent($data['student_id'] ?? null);
        $type = $this->assertType($data['achievement_type_id'] ?? null);
        $this->assertLevel($data['level'] ?? null);

        $achievement = StudentAchievement::create([
            'student_id' => $student->id,
            'achievement_type_id' => $type->id,
            'title' => trim((string) ($data['title'] ?? '')),
            'date' => $data['date'] ?? null,
            'level' => $this->text($data['level'] ?? null),
            'rank' => $this->text($data['rank'] ?? null),
            'organizer' => $this->text($data['organizer'] ?? null),
            'description' => $this->text($data['description'] ?? null),
            'certificate_path' => $this->text($data['certificate_path'] ?? null),
        ]);

        return $achievement->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(StudentAchievement $achievement, array $data): StudentAchievement
    {
        if (array_key_exists('student_id', $data) && (int) $data['student_id'] !== (int) $achievement->student_id) {
            $achievement->student_id = $this->assertStudent($data['student_id'])->id;
        }

        if (array_key_exists('achievement_type_id', $data)) {
            $achievement->achievement_type_id = $this->assertType($data['achievement_type_id'])->id;
        }

        if (array_key_exists('level', $data)) {
            $this->assertLevel($data['level']);
            $achievement->level = $this->text($data['level']);
        }

        if (array_key_exists('title', $data)) {
            $achievement->title = trim((string) $data['title']);
        }

        foreach (['rank', 'organizer', 'description', 'certificate_path'] as $key) {
            if (array_key_exists($key, $data)) {
                $achievement->{$key} = $this->text($data[$key]);
            }
        }

        if (array_key_exists('date', $data)) {
            $achievement->date = $data['date'];
        }

        if ($achievement->isDirty()) {
            $achievement->save();
        }

        return $achievement->refresh();
    }

    public function delete(StudentAchievement $achievement): void
    {
        $achievement->delete();
    }

    public function assertStudent(mixed $studentId): Student
    {
        $student = is_numeric($studentId)
            ? Student::query()->find((int) $studentId)
            : null;

        if ($student === null) {
            throw ValidationException::withMessages(['student_id' => 'Siswa tidak ditemukan.']);
        }

        return $student;
    }

    public function assertType(mixed $typeId): AchievementType
    {
        $type = is_numeric($typeId)
            ? AchievementType::query()->find((int) $typeId)
            : null;

        if ($type === null) {
            throw ValidationException::withMessages([
                'achievement_type_id' => 'Jenis prestasi tidak ditemukan.',
            ]);
        }

        return $type;
    }

    protected function assertLevel(mixed $level): void
    {
        if ($level === null || $level === '') {
            return;
        }

        if (! in_array((string) $level, StudentAchievement::LEVELS, true)) {
            throw ValidationException::withMessages([
                'level' => 'Tingkat prestasi harus salah satu dari: '.implode(', ', StudentAchievement::LEVELS).'.',
            ]);
        }
    }

    /**
     * Normalisasi teks opsional: string kosong disimpan sebagai null.
     */
    protected function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
