<?php

namespace App\Services\Assessment;

use App\Models\Assessment;
use App\Models\ClassStudent;
use App\Models\GradeScale;
use App\Models\Score;
use App\Models\ScoreRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya pintu masuk penulisan skor.
 *
 * Semua jalur (input satuan, input massal, remedial) menulis lewat sini supaya
 * tiga hal selalu terjadi bersamaan: nilai terkunci dijaga, huruf/predikat ikut
 * terisi, dan setiap perubahan meninggalkan jejak di `score_revisions`.
 */
class ScoreRecorder
{
    /** @var array<int, GradeScale|null> */
    protected array $scaleCache = [];

    /**
     * Catat atau ubah skor satu siswa pada satu penilaian.
     *
     * @param  null|float|int|string  $score  kosongkan untuk menghapus nilai
     */
    public function put(
        Assessment $assessment,
        int $studentId,
        null|float|int|string $score,
        ?string $notes = null,
        ?int $userId = null,
        ?string $reason = null,
    ): Score {
        $this->assertWritable($assessment);
        $this->assertMember($assessment, $studentId);

        $value = $score === null || $score === '' ? null : round((float) $score, 2);

        $this->assertInRange($assessment, $value);

        if ($assessment->status !== Assessment::STATUS_DRAFT) {
            $this->assertReason($reason);
        }

        $converted = $this->convert($assessment, $value);

        return DB::transaction(function () use ($assessment, $studentId, $value, $notes, $userId, $reason, $converted) {
            // `withTrashed()`: baris yang pernah dihapus masih menempati indeks
            // unik (assessment_id, student_id), jadi nilai baru memulihkannya,
            // bukan membuat baris kedua yang akan ditolak database.
            $existing = Score::withTrashed()
                ->where('assessment_id', $assessment->id)
                ->where('student_id', $studentId)
                ->first();

            $payload = [
                'score' => $value,
                'grade' => $converted['grade'] ?? null,
                'predicate' => $converted['predicate'] ?? null,
                'notes' => $notes,
            ];

            if ($existing === null) {
                $existing = Score::create($payload + [
                    'assessment_id' => $assessment->id,
                    'student_id' => $studentId,
                ]);

                $this->revise($existing, null, $value, $userId, $reason ?? 'Input nilai awal');

                return $existing;
            }

            $before = $existing->score === null ? null : (float) $existing->score;

            if ($existing->trashed()) {
                $existing->restore();
                $existing->update($payload);

                $this->revise($existing, null, $value, $userId, $reason ?? 'Nilai dimasukkan kembali setelah dihapus');

                return $existing;
            }

            if ($before !== $value) {
                $this->revise(
                    $existing,
                    $before,
                    $value,
                    $userId,
                    $reason ?? 'Perubahan nilai oleh guru',
                );
            }

            $existing->update($payload);

            return $existing;
        });
    }

    /**
     * Hapus catatan skor. Perubahan ini juga dicatat sebagai revisi.
     */
    public function remove(Score $score, ?int $userId = null, ?string $reason = null): void
    {
        $assessment = $score->assessment()->first();

        if ($assessment !== null) {
            $this->assertWritable($assessment);

            if ($assessment->status !== Assessment::STATUS_DRAFT) {
                $this->assertReason($reason);
            }
        }

        DB::transaction(function () use ($score, $userId, $reason) {
            $this->revise(
                $score,
                $score->score === null ? null : (float) $score->score,
                null,
                $userId,
                $reason ?? 'Penghapusan catatan nilai',
            );

            $score->delete();
        });
    }

    /**
     * Huruf & predikat dari skor mentah, dinilai pada skala yang berlaku.
     *
     * @return array{grade: string, predicate: string|null}|null
     */
    protected function convert(Assessment $assessment, ?float $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $scale = $this->scaleFor($assessment);

        if ($scale === null) {
            return null;
        }

        return $scale->convert(($value * 100) / $assessment->maxScoreValue());
    }

    protected function scaleFor(Assessment $assessment): ?GradeScale
    {
        $key = $assessment->schoolClass?->education_level_id ?? 0;

        if (! array_key_exists($key, $this->scaleCache)) {
            $this->scaleCache[$key] = GradeScale::resolveFor($key ?: null);
        }

        return $this->scaleCache[$key];
    }

    /**
     * Nilai yang sudah difinalisasi hanya bisa diubah setelah dibuka kembali.
     */
    protected function assertWritable(Assessment $assessment): void
    {
        if ($assessment->isLocked()) {
            throw ValidationException::withMessages([
                'score' => 'Nilai penilaian ini sudah difinalisasi dan terkunci. Ajukan revisi nilai untuk mengubahnya.',
            ]);
        }
    }

    protected function assertMember(Assessment $assessment, int $studentId): void
    {
        $isMember = ClassStudent::query()
            ->where('school_class_id', $assessment->school_class_id)
            ->where('student_id', $studentId)
            ->where('status', 'aktif')
            ->exists();

        if (! $isMember) {
            throw ValidationException::withMessages([
                'student_id' => 'Siswa ini bukan anggota aktif kelas pada penilaian tersebut.',
            ]);
        }
    }

    protected function assertInRange(Assessment $assessment, ?float $value): void
    {
        if ($value === null) {
            return;
        }

        $max = $assessment->maxScoreValue();

        if ($value < 0 || $value > $max) {
            throw ValidationException::withMessages([
                'score' => "Nilai harus berada di antara 0 dan {$max}.",
            ]);
        }
    }

    /**
     * Setelah nilai diserahkan, mengubahnya wajib disertai alasan.
     */
    protected function assertReason(?string $reason): void
    {
        if ($reason === null || trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'Alasan wajib diisi karena penilaian ini sudah diserahkan (status bukan draft).',
            ]);
        }
    }

    protected function revise(
        Score $score,
        ?float $before,
        ?float $after,
        ?int $userId,
        string $reason,
    ): ScoreRevision {
        return ScoreRevision::create([
            'score_id' => $score->id,
            'old_score' => $before,
            'new_score' => $after,
            'reason' => $reason,
            'revised_by' => $userId,
        ]);
    }
}
