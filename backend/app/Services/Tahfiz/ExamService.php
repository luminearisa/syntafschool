<?php

namespace App\Services\Tahfiz;

use App\Models\QuranSurah;
use App\Models\TahfizExam;
use App\Models\TahfizExamItem;
use App\Models\TahfizExamParticipant;
use App\Models\TahfizParticipantProgress;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Layanan ujian Tahfiz.
 *
 * Butir ujian dibangkitkan dari hafalan nyata peserta: ayat awal diambil acak
 * dari rentang yang sudah dihafal (bukan dari seluruh Al-Quran), sehingga
 * ujian selalu sepadan dengan kemampuan peserta.
 */
class ExamService
{
    /** Jumlah butir default untuk ujian acak/sambung ayat. */
    private const DEFAULT_ITEM_COUNT = 10;

    public function __construct(protected ProgressService $progress)
    {
    }

    /**
     * Daftarkan peserta ke ujian (idempoten).
     *
     * @param  list<int>  $participantIds
     * @return \Illuminate\Database\Eloquent\Collection<int, TahfizExamParticipant>
     */
    public function registerParticipants(TahfizExam $exam, array $participantIds)
    {
        $ids = [];

        DB::transaction(function () use ($exam, $participantIds, &$ids) {
            foreach (array_unique(array_map('intval', $participantIds)) as $participantId) {
                if ($participantId <= 0) {
                    continue;
                }

                $created = TahfizExamParticipant::firstOrCreate(
                    ['exam_id' => $exam->id, 'participant_id' => $participantId],
                    ['status' => TahfizExamParticipant::STATUS_TERDAFTAR]
                );

                $ids[$created->participant_id] = $created->id;
            }
        });

        return TahfizExamParticipant::query()
            ->whereIn('id', array_values($ids))
            ->with('participant')
            ->orderBy('id')
            ->get();
    }

    /**
     * Bangkitkan butir ujian untuk satu peserta.
     *
     * @param  list<string>  $itemTypes  sambung_ayat | acak_ayat | hafalan | tahsin
     */
    public function generateItems(TahfizExamParticipant $participant, array $itemTypes, int $count = self::DEFAULT_ITEM_COUNT): array
    {
        $count = max(1, min(60, $count));

        $types = array_values(array_intersect($itemTypes, TahfizExamItem::ITEM_TYPES));
        if ($types === []) {
            throw ValidationException::withMessages([
                'item_types' => 'Jenis butir ujian tidak valid.',
            ]);
        }

        // Surah yang sudah dihafal peserta, berbobot agar surah dengan hafalan
        // lebih banyak lebih mungkin dipakai.
        $candidates = TahfizParticipantProgress::query()
            ->with('surah')
            ->where('participant_id', $participant->participant_id)
            ->where('ayahs_memorized', '>', 0)
            ->get();

        if ($candidates->isEmpty()) {
            throw ValidationException::withMessages([
                'participant_id' => 'Peserta belum memiliki hafalan untuk diujikan.',
            ]);
        }

        return DB::transaction(function () use ($participant, $types, $count, $candidates) {
            $items = [];

            for ($i = 0; $i < $count; $i++) {
                $type = $types[$i % count($types)];
                $progress = $this->weightedPick($candidates);
                $surah = $progress->surah;

                $ranges = $this->progress->memorizedRanges($participant->participant_id, $progress->surah_id);
                if ($ranges === []) {
                    continue;
                }

                $range = $ranges[array_rand($ranges)];
                $ayahStart = random_int($range[0], $range[1]);

                $items[] = TahfizExamItem::create([
                    'exam_participant_id' => $participant->id,
                    'item_type' => $type,
                    'surah_id' => $progress->surah_id,
                    'ayah_start' => $ayahStart,
                    // Kelanjutan yang diharapkan: peserta harus melanjutkan
                    // sekian ayat dari titik awal.
                    'expected_ayah_end' => $this->expectedEnd($surah, $ayahStart, $range[1], $type),
                ])->id;
            }

            return TahfizExamItem::query()->whereIn('id', $items)->get()->all();
        });
    }

    /**
     * Catat hasil satu peserta: skor, grade, dan hasil kelulusan.
     *
     * Skor dihitung ulang dari butir — bukan dipercaya dari input — sehingga
     * selalu konsisten dengan nilai butir yang tersimpan.
     */
    public function recordResult(TahfizExamParticipant $participant, ?string $feedback = null): TahfizExamParticipant
    {
        return DB::transaction(function () use ($participant, $feedback) {
            $items = $participant->items;
            $exam = $participant->exam;

            $maxScore = (float) ($exam->max_score ?: 100);
            // Bagian porsi skor maksimum yang dialokasikan untuk tiap butir.
            $itemMax = $items->count() > 0 ? $maxScore / $items->count() : 0;

            // Skor = rata-rata proporsi tiap butir dikalikan skor maksimum
            // ujian. Butir yang belum dinilai dianggap 0.
            $total = null;
            if ($items->count() > 0) {
                $proportions = $items->map(fn ($i) => $itemMax > 0 && $i->score !== null ? (float) $i->score / $itemMax : 0.0);
                $total = (float) round(($proportions->sum() / $items->count()) * $maxScore, 2);
            }

            $passing = (float) ($exam->passing_score ?: 75);
            $result = $total === null ? null : ($total >= $passing ? 'lulus' : ($total >= $passing * 0.7 ? 'remedial' : 'tidak_lulus'));

            $participant->update([
                'score' => $total,
                'grade' => $total === null ? null : $this->gradeOf($total, $maxScore),
                'result' => $result,
                'status' => $total === null ? 'menunggu_nilai' : 'dinilai',
                'feedback' => $feedback,
                'finished_at' => now(),
            ]);

            return $participant->fresh();
        });
    }

    /**
     * Pilih surah berbobot oleh jumlah ayat yang dihafal.
     */
    protected function weightedPick(Collection $candidates): TahfizParticipantProgress
    {
        $weights = $candidates->map(fn ($p) => max(1, (int) $p->ayahs_memorized));
        $total = (int) $weights->sum();

        $pick = random_int(1, max(1, $total));
        $cumulative = 0;

        foreach ($candidates as $i => $candidate) {
            $cumulative += (int) $weights[$i];
            if ($pick <= $cumulative) {
                return $candidate;
            }
        }

        return $candidates->last();
    }

    /**
     * Kelanjutan ayat yang diharapkan sesuai jenis butir.
     */
    protected function expectedEnd(QuranSurah $surah, int $ayahStart, int $memorizedEnd, string $type): ?int
    {
        if ($type === 'tahsin') {
            return null;
        }

        $span = $type === 'acak_ayat' ? 1 : 3;

        return (int) min($memorizedEnd, $surah->ayah_count, $ayahStart + $span);
    }

    protected function gradeOf(float $score, float $maxScore): string
    {
        $percent = $maxScore > 0 ? ($score / $maxScore) * 100 : 0;

        return match (true) {
            $percent >= 90 => 'A',
            $percent >= 80 => 'B',
            $percent >= 70 => 'C',
            $percent >= 60 => 'D',
            default => 'E',
        };
    }
}
