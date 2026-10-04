<?php

namespace App\Modules\StudentProgress\Application\Services;

use App\Models\Employee;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Models\ViolationType;
use App\Modules\StudentProgress\Models\AcademicAlert;
use App\Modules\StudentProgress\Models\StudentViolation;
use App\Modules\StudentProgress\Support\StudentViolationPoint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pelanggaran siswa: pencatatan, poin, dan ambang peringatan.
 *
 * ## Poin disalin, bukan dihitung ulang
 *
 * `student_violations.point` adalah SALINAN `violation_types.point` pada saat
 * kejadian. Bila bobot master diubah kemudian, total poin historis siswa tidak
 * ikut berubah — karena itu seluruh perhitungan membaca kolom `point` itu
 * sendiri, bukan JOIN ke master.
 *
 * ## Ambang & alert
 *
 * Bila total poin seorang siswa mencapai ambang (default 100, dapat di-override)
 * modul ini membuat baris `academic_alerts` bertipe `pelanggaran`. Alert tidak
 * pernah dihapus; penyelesaiannya lewat `resolved_at`.
 */
class ViolationService
{
    /** Ambang default (poin) sebelum peringatan pelanggaran dibuat. */
    public const DEFAULT_THRESHOLD = StudentViolationPoint::DEFAULT_THRESHOLD;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function query(array $filters = []): Builder
    {
        return StudentViolation::query()
            ->with(['student:id,nis,full_name', 'violationType:id,name,category,point', 'reporter:id,full_name'])
            ->forStudent(filled($filters['student_id'] ?? null) ? (int) $filters['student_id'] : null)
            ->ofType(filled($filters['violation_type_id'] ?? null) ? (int) $filters['violation_type_id'] : null)
            ->withStatus($filters['status'] ?? null)
            ->between($filters['from'] ?? null, $filters['to'] ?? null)
            ->when(
                filled($filters['category'] ?? null),
                fn (Builder $q) => $q->whereHas('violationType', fn (Builder $t) => $t->where('category', $filters['category'])),
            )
            ->when(
                filled($filters['keyword'] ?? null),
                fn (Builder $q) => $q->where('description', 'like', '%'.$filters['keyword'].'%'),
            )
            ->orderByDesc('date')
            ->orderByDesc('id');
    }

    /**
     * Daftar pelanggaran satu siswa.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listForStudent(int $studentId, array $filters = []): Builder
    {
        return $this->query(['student_id' => $studentId] + $filters);
    }

    /**
     * Catat pelanggaran baru + evaluasi ambang poin.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: StudentViolation, 1: AcademicAlert|null}
     */
    public function record(array $data, ?User $actor = null): array
    {
        $student = $this->assertStudent($data['student_id'] ?? null);
        $type = $this->assertType($data['violation_type_id'] ?? null);

        // Poin dikirim eksplisit? Pakai itu; kalau tidak, salin dari master.
        $point = array_key_exists('point', $data) && $data['point'] !== null
            ? (int) $data['point']
            : (int) $type->point;

        $threshold = $this->threshold($data['threshold'] ?? null);

        return DB::transaction(function () use ($data, $student, $type, $point, $threshold, $actor): array {
            $violation = StudentViolation::create([
                'student_id' => $student->id,
                'violation_type_id' => $type->id,
                'date' => $data['date'] ?? now()->toDateString(),
                'description' => $this->text($data['description'] ?? null),
                'point' => $point,
                'reported_by' => $this->reportedBy($data, $actor),
                'action_taken' => $this->text($data['action_taken'] ?? null),
                'status' => StudentViolation::STATUS_RECORDED,
            ]);

            $alert = $this->evaluateThreshold((int) $student->id, $this->activeSemesterId(), $threshold);

            return [$violation->refresh(), $alert];
        });
    }

    /**
     * Sunting catatan pelanggaran. Status TIDAK diubah di sini — gunakan
     * `verify()` / `resolve()` agar transisinya terkontrol.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(StudentViolation $violation, array $data): StudentViolation
    {
        $payload = [];

        $typeChanged = array_key_exists('violation_type_id', $data)
            && (int) $data['violation_type_id'] !== (int) $violation->violation_type_id;

        if ($typeChanged) {
            $type = $this->assertType($data['violation_type_id']);
            $payload['violation_type_id'] = $type->id;

            // Ganti jenis = salin ulang bobot master, kecuali klien mengirim poin eksplisit.
            if (! array_key_exists('point', $data) || $data['point'] === null) {
                $payload['point'] = (int) $type->point;
            }
        }

        if (array_key_exists('point', $data) && $data['point'] !== null) {
            $payload['point'] = (int) $data['point'];
        }

        foreach (['description', 'action_taken'] as $key) {
            if (array_key_exists($key, $data)) {
                $payload[$key] = $this->text($data[$key]);
            }
        }

        if (array_key_exists('reported_by', $data)) {
            $payload['reported_by'] = $data['reported_by'] === null ? null : (int) $data['reported_by'];
        }

        if (array_key_exists('date', $data)) {
            $payload['date'] = $data['date'];
        }

        if ($payload === []) {
            return $violation;
        }

        DB::transaction(function () use ($violation, $payload): void {
            $violation->update($payload);

            // Poin/jenis berubah bisa memindahkan siswa melewati ambang.
            if (array_key_exists('point', $payload) || array_key_exists('violation_type_id', $payload)) {
                $this->evaluateThreshold((int) $violation->student_id, $this->activeSemesterId());
            }
        });

        return $violation->refresh();
    }

    /**
     * `recorded` → `verified`.
     */
    public function verify(StudentViolation $violation): StudentViolation
    {
        if (! $violation->isRecorded()) {
            throw ValidationException::withMessages([
                'status' => 'Hanya pelanggaran berstatus "Tercatat" yang dapat diverifikasi.',
            ]);
        }

        $violation->update(['status' => StudentViolation::STATUS_VERIFIED]);

        return $violation->refresh();
    }

    /**
     * `verified` → `resolved`.
     */
    public function resolve(StudentViolation $violation): StudentViolation
    {
        if (! $violation->isVerified()) {
            throw ValidationException::withMessages([
                'status' => 'Pelanggaran harus diverifikasi terlebih dahulu sebelum diselesaikan.',
            ]);
        }

        $violation->update(['status' => StudentViolation::STATUS_RESOLVED]);

        return $violation->refresh();
    }

    public function delete(StudentViolation $violation): void
    {
        $violation->delete();
    }

    /**
     * Total poin siswa. Bila `semesterId` diisi, hanya pelanggaran pada rentang
     * tanggal semester itu yang dihitung; bila null, seluruh riwayat.
     */
    public function totalPointsForStudent(int $studentId, ?int $semesterId = null): int
    {
        $query = StudentViolation::query()->where('student_id', $studentId);

        if ($semesterId !== null && ($semester = Semester::query()->find($semesterId)) !== null) {
            $query->whereDate('date', '>=', $semester->start_date)
                ->whereDate('date', '<=', $semester->end_date);
        }

        return (int) $query->sum('point');
    }

    /**
     * Buat alert bila total poin mencapai ambang. Idempoten per level: satu
     * level aktif per siswa per semester tidak dibuat ganda.
     *
     * @return AcademicAlert|null alert yang BARU dibuat, atau null
     */
    public function evaluateThreshold(
        int $studentId,
        ?int $semesterId = null,
        ?int $threshold = null,
    ): ?AcademicAlert {
        $threshold ??= self::DEFAULT_THRESHOLD;
        $total = $this->totalPointsForStudent($studentId, $semesterId);

        if (! StudentViolationPoint::exceeded($total, $threshold)) {
            return null;
        }

        // Alert wajib menempel pada semester; kalau tidak ada, lewati.
        $semesterId ??= $this->activeSemesterId();

        if ($semesterId === null) {
            return null;
        }

        $level = StudentViolationPoint::levelFor($total, $threshold);

        $exists = AcademicAlert::query()
            ->where('student_id', $studentId)
            ->where('semester_id', $semesterId)
            ->ofType(AcademicAlert::TYPE_VIOLATION)
            ->where('level', $level)
            ->unresolved()
            ->exists();

        if ($exists) {
            return null;
        }

        return AcademicAlert::create([
            'student_id' => $studentId,
            'semester_id' => $semesterId,
            'type' => AcademicAlert::TYPE_VIOLATION,
            'level' => $level,
            'message' => $this->thresholdMessage($total, $threshold, $level),
            'data' => [
                'total_points' => $total,
                'threshold' => $threshold,
                'level' => $level,
            ],
            'triggered_at' => now(),
        ]);
    }

    /**
     * Ringkasan poin satu siswa untuk endpoint `summary`.
     *
     * @return array<string, mixed>
     */
    public function summary(int $studentId, ?int $semesterId = null, ?int $threshold = null): array
    {
        $threshold = $this->threshold($threshold);
        $total = $this->totalPointsForStudent($studentId, $semesterId);

        return [
            'student_id' => $studentId,
            'total_points' => $total,
            'threshold' => $threshold,
            'level' => StudentViolationPoint::levelFor($total, $threshold),
            'exceeded' => StudentViolationPoint::exceeded($total, $threshold),
            'breakdown' => $this->breakdown($studentId, $semesterId),
        ];
    }

    /**
     * Rincian poin per jenis pelanggaran (satu query agregat).
     *
     * @return list<array<string, mixed>>
     */
    public function breakdown(int $studentId, ?int $semesterId = null): array
    {
        $query = StudentViolation::query()
            ->join('violation_types', 'violation_types.id', '=', 'student_violations.violation_type_id')
            ->where('student_violations.student_id', $studentId)
            ->selectRaw('violation_types.id as violation_type_id, violation_types.name as name, violation_types.category as category, COUNT(*) as violation_count, COALESCE(SUM(student_violations.point), 0) as points')
            ->groupBy('violation_types.id', 'violation_types.name', 'violation_types.category')
            ->orderByDesc('points');

        if ($semesterId !== null && ($semester = Semester::query()->find($semesterId)) !== null) {
            $query->whereDate('student_violations.date', '>=', $semester->start_date)
                ->whereDate('student_violations.date', '<=', $semester->end_date);
        }

        return $query->get()->map(fn ($row): array => [
            'violation_type_id' => (int) $row->violation_type_id,
            'name' => $row->name,
            'category' => $row->category,
            'violation_count' => (int) $row->violation_count,
            'points' => (int) $row->points,
        ])->all();
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

    public function assertType(mixed $typeId): ViolationType
    {
        $type = is_numeric($typeId)
            ? ViolationType::query()->find((int) $typeId)
            : null;

        if ($type === null) {
            throw ValidationException::withMessages([
                'violation_type_id' => 'Jenis pelanggaran tidak ditemukan.',
            ]);
        }

        return $type;
    }

    protected function threshold(mixed $value): int
    {
        $threshold = is_numeric($value) ? (int) $value : self::DEFAULT_THRESHOLD;

        return $threshold > 0 ? $threshold : self::DEFAULT_THRESHOLD;
    }

    protected function activeSemesterId(): ?int
    {
        $id = Semester::query()
            ->where('is_active', true)
            ->orderByDesc('start_date')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Pegawai pelapor: pakai `reported_by` eksplisit bila ada, kalau tidak
     * ambil dari akun pengguna yang terhubung ke data pegawai.
     *
     * @param  array<string, mixed>  $data
     */
    protected function reportedBy(array $data, ?User $actor): ?int
    {
        if (array_key_exists('reported_by', $data) && $data['reported_by'] !== null) {
            return (int) $data['reported_by'];
        }

        if ($actor !== null && $actor->userable_type === Employee::class && $actor->userable_id !== null) {
            return (int) $actor->userable_id;
        }

        return null;
    }

    protected function thresholdMessage(int $total, int $threshold, string $level): string
    {
        $label = $level === AcademicAlert::LEVEL_CRITICAL ? 'kritis' : 'peringatan';

        return sprintf(
            'Total poin pelanggaran siswa mencapai %d (ambang %d) — tingkat %s.',
            $total,
            $threshold,
            $label,
        );
    }

    protected function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
