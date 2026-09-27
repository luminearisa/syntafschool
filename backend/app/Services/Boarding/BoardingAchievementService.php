<?php

namespace App\Services\Boarding;

use App\Models\BoardingAchievement;
use App\Models\Student;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Prestasi santri di asrama (§41): hafalan, lomba, kepemimpinan kamar, santri
 * teladan.
 *
 * ## Kenapa kabar orang tua juga dikirim di sini
 *
 * Modul kedisiplinan mengirim `violation_recorded`; kalau hanya kabar buruk yang
 * sampai ke portal, orang tua membaca asrama lewat satu sisi saja. Karena itu
 * `create()` mengirim `achievement_recorded` dengan rantai penerima yang sama
 * (`student_guardians` → `ppdb_parent_accounts` → akun login) — tanpa permission
 * tambahan di sisi wali (§56).
 *
 * ## Snapshot, bukan perhitungan ulang
 *
 * Prestasi tidak punya bobot poin di skema (§41), jadi tidak ada yang perlu
 * disalin. Yang dijaga di sini justru hal sebaliknya: `recorded_by` disimpan
 * supaya laporan bisa menyatakan siapa yang menyatakan seorang santri berprestasi.
 */
class BoardingAchievementService
{
    public function __construct(
        protected readonly BoardingScopeService $scope,
        protected readonly BoardingNotificationService $notifications,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function query(array $filters = [], ?User $actor = null): Builder
    {
        $allowed = $this->scope->studentIdsFor($actor);

        return BoardingAchievement::query()
            ->with(['student:id,full_name,nis', 'recordedBy:id,name'])
            ->forStudent(is_numeric($filters['student_id'] ?? null) ? (int) $filters['student_id'] : null)
            ->ofCategory(filled($filters['category'] ?? null) ? (string) $filters['category'] : null)
            ->between($filters['from'] ?? null, $filters['to'] ?? null)
            ->when(
                filled($filters['level'] ?? null),
                fn (Builder $q) => $q->where('level', $filters['level']),
            )
            ->when(
                filled($filters['keyword'] ?? null),
                fn (Builder $q) => $q->where('title', 'like', '%'.$filters['keyword'].'%'),
            )
            ->when($allowed !== null, fn (Builder $q) => $q->whereIn('student_id', $allowed ?: [0]))
            ->orderByDesc('date')
            ->orderByDesc('id');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = [], ?User $actor = null, int $perPage = 25): LengthAwarePaginator
    {
        return $this->query($filters, $actor)->paginate(max(1, min($perPage, 200)));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): BoardingAchievement
    {
        $student = $this->assertStudent($data['student_id'] ?? null, $actor);
        $this->assertCategory((string) ($data['category'] ?? ''));
        $this->assertLevel($data['level'] ?? null);

        $achievement = BoardingAchievement::create($this->payload($data) + [
            'student_id' => $student->id,
            'recorded_by' => $actor?->id,
        ]);

        $this->notify($achievement);

        return $achievement->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(BoardingAchievement $achievement, array $data, ?User $actor = null): BoardingAchievement
    {
        $this->assertStudent($achievement->student_id, $actor);

        if (array_key_exists('category', $data)) {
            $this->assertCategory((string) $data['category']);
        }

        if (array_key_exists('level', $data)) {
            $this->assertLevel($data['level']);
        }

        $payload = $this->payload($data);

        if ($payload === []) {
            return $achievement;
        }

        $achievement->update($payload);

        return $achievement->refresh();
    }

    public function delete(BoardingAchievement $achievement, ?User $actor = null): void
    {
        $this->assertStudent($achievement->student_id, $actor);

        $achievement->delete();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    public function summary(array $filters = [], ?User $actor = null): array
    {
        $base = $this->query($filters, $actor);

        $byCategory = (clone $base)
            ->selectRaw('category, COUNT(*) as aggregate')
            ->groupBy('category')
            ->pluck('aggregate', 'category');

        $byLevel = (clone $base)
            ->selectRaw('COALESCE(level, \'-\') as level, COUNT(*) as aggregate')
            ->groupBy('level')
            ->pluck('aggregate', 'level');

        return [
            'total' => (clone $base)->count(),
            'by_category' => $byCategory->mapWithKeys(fn (mixed $n, mixed $k): array => [(string) $k => (int) $n])->all(),
            'by_level' => $byLevel->mapWithKeys(fn (mixed $n, mixed $k): array => [(string) $k => (int) $n])->all(),
        ];
    }

    /**
     * Dropdown kategori/jenjang untuk form petugas — satu sumber dengan validasi.
     *
     * @return array<string, array<string, string>>
     */
    public static function options(): array
    {
        return [
            'categories' => BoardingAchievement::CATEGORY_LABELS,
            'levels' => BoardingAchievement::LEVEL_LABELS,
        ];
    }

    /**
     * @return array<string, mixed>  hanya kolom yang boleh ditulis
     */
    protected function payload(array $data): array
    {
        $payload = [];

        foreach (['title', 'description', 'evidence_path', 'level'] as $key) {
            if (array_key_exists($key, $data)) {
                $value = is_string($data[$key]) ? trim($data[$key]) : $data[$key];
                $payload[$key] = ($value === null || $value === '') ? null : (string) $value;
            }
        }

        foreach (['category', 'date'] as $key) {
            if (array_key_exists($key, $data)) {
                $payload[$key] = $data[$key];
            }
        }

        return $payload;
    }

    /**
     * Kabar orang tua: prestasi juga kabar baik yang layak sampai ke portal.
     */
    protected function notify(BoardingAchievement $achievement): int
    {
        if (! $achievement->relationLoaded('student')) {
            $achievement->load('student:id,full_name,nis');
        }

        $title = sprintf('Prestasi asrama: %s', (string) $achievement->title);
        $body = sprintf(
            'Ananda %s mencatat prestasi "%s" (%s%s) pada %s. %s',
            $achievement->student?->full_name ?? 'santri',
            (string) $achievement->title,
            $achievement->categoryLabel(),
            $achievement->level ? ', '.$achievement->levelLabel() : '',
            $achievement->date?->format('d/m/Y') ?? '-',
            filled($achievement->description) ? (string) $achievement->description : 'Pengasuh mendoakan agar prestasinya meningkat.',
        );

        return $this->notifications->toParentsOf(
            (int) $achievement->student_id,
            'achievement_recorded',
            $title,
            $body,
            [
                'reference_type' => BoardingAchievement::class,
                'reference_id' => (int) $achievement->id,
                'status' => $achievement->category,
                'route' => '/boarding/portal/children/'.$achievement->student_id.'/achievements',
            ],
        );
    }

    /**
     * @return Student
     */
    public function assertStudent(mixed $studentId, ?User $actor): Student
    {
        $id = is_numeric($studentId) ? (int) $studentId : 0;
        $student = $id > 0 ? Student::query()->find($id) : null;

        if ($student === null) {
            throw ValidationException::withMessages(['student_id' => 'Santri tidak ditemukan.']);
        }

        $this->scope->assertCanAccessStudent($actor, (int) $student->id);

        return $student;
    }

    protected function assertCategory(string $category): void
    {
        if (! in_array($category, BoardingAchievement::CATEGORIES, true)) {
            throw ValidationException::withMessages([
                'category' => 'Kategori prestasi harus salah satu dari: '.implode(', ', BoardingAchievement::CATEGORIES).'.',
            ]);
        }
    }

    /**
     * Jenjang adalah kolom string bebas di skema (§41) — konstanta model hanya
     * daftar dropdown. Nilai asing ditolak agar laporan tidak punya "level" yang
     * tidak bisa diurutkan.
     */
    protected function assertLevel(mixed $level): void
    {
        if ($level === null || $level === '') {
            return;
        }

        if (! in_array((string) $level, BoardingAchievement::LEVELS, true)) {
            throw ValidationException::withMessages([
                'level' => 'Jenjang prestasi harus salah satu dari: '.implode(', ', BoardingAchievement::LEVELS).'.',
            ]);
        }
    }
}
