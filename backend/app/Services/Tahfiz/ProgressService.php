<?php

namespace App\Services\Tahfiz;

use App\Models\QuranSurah;
use App\Models\TahfizEnrollment;
use App\Models\TahfizIndividualTarget;
use App\Models\TahfizMemorizationRecord;
use App\Models\TahfizParticipantProgress;
use App\Models\TahfizRevisionRecord;
use App\Models\TahfizTarget;
use Illuminate\Support\Collection;

/**
 * Progress engine modul Tahfiz.
 *
 * `tahfiz_participant_progress` adalah ringkasan per (peserta, surah) yang
 * dihitung ulang dari catatan mentah — bukan ditulis langsung oleh pengguna.
 * Karena itu service ini adalah satu-satunya tempat yang menulis tabel ini.
 *
 * Inti desain: "hafal" tidak sama dengan "mutqin".
 * - `accepted` saja → mutqin (hafalan stabil).
 * - `accepted_with_revision` → hafal, tapi belum mutqin (perlu diulang).
 * Karena itu ada dua counter terpisah: `ayahs_memorized` vs `ayahs_mutqin`.
 *
 * Rentang ayat digabung (merge) sebelum dihitung, sehingga setoran yang
 * tumpang tindih tidak dihitung ganda.
 */
class ProgressService
{
    /** Skor rata-rata minimum agar surah dianggap mutqin. */
    private const MUTQIN_MIN_SCORE = 90.0;

    /**
     * Hitung ulang progress satu peserta untuk satu surah.
     */
    public function recomputeForSurah(int $participantId, int $surahId): ?TahfizParticipantProgress
    {
        $surah = QuranSurah::query()->whereKey($surahId)->first();

        if ($surah === null) {
            return null;
        }

        $records = TahfizMemorizationRecord::query()
            ->where('participant_id', $participantId)
            ->where('surah_id', $surahId)
            ->get();

        $revisions = TahfizRevisionRecord::query()
            ->where('participant_id', $participantId)
            ->where('surah_id', $surahId)
            ->get();

        // Surah tanpa aktivitas apa pun tidak perlu baris progress.
        if ($records->isEmpty() && $revisions->isEmpty()) {
            TahfizParticipantProgress::query()
                ->where('participant_id', $participantId)
                ->where('surah_id', $surahId)
                ->delete();

            return null;
        }

        $accepted = $records->filter(
            fn (TahfizMemorizationRecord $r) => $r->status === TahfizMemorizationRecord::STATUS_ACCEPTED
        );
        $counted = $records->filter(fn (TahfizMemorizationRecord $r) => $r->isAccepted());

        $ayahsMemorized = $this->mergedLength($counted->map(fn ($r) => [(int) $r->ayah_start, (int) $r->ayah_end]));
        $ayahsMutqin = $this->mergedLength($accepted->map(fn ($r) => [(int) $r->ayah_start, (int) $r->ayah_end]));

        $pageMemorized = $this->mergedLength($this->pageRanges($counted));
        $pageMutqin = $this->mergedLength($this->pageRanges($accepted));

        $scored = $counted->filter(fn ($r) => $r->score !== null);
        $avgScore = $scored->isNotEmpty()
            ? (float) round((float) $scored->avg(fn ($r) => (float) $r->score), 2)
            : null;

        return TahfizParticipantProgress::updateOrCreate(
            [
                'participant_id' => $participantId,
                'surah_id' => $surahId,
            ],
            [
                'juz' => $surah->juz_start,
                'ayahs_memorized' => $ayahsMemorized,
                'pages_memorized' => $pageMemorized,
                'ayahs_mutqin' => $ayahsMutqin,
                'pages_mutqin' => $pageMutqin,
                'status' => $this->resolveStatus($ayahsMemorized, $ayahsMutqin, (int) $surah->ayah_count, $avgScore),
                'avg_score' => $avgScore,
                'setoran_count' => $records->count(),
                'murojaah_count' => $revisions->count(),
                'last_setor_date' => $records->max('date'),
                'last_murojaah_date' => $revisions->max('date'),
            ]
        );
    }

    /**
     * Hitung ulang seluruh surah yang menyentuh peserta ini.
     */
    public function recomputeForParticipant(int $participantId): void
    {
        $surahIds = $this->touchedSurahIds($participantId);

        foreach ($surahIds as $surahId) {
            $this->recomputeForSurah($participantId, (int) $surahId);
        }

        // Surah yang dulu punya progress tapi kini tanpa aktivitas.
        TahfizParticipantProgress::query()
            ->where('participant_id', $participantId)
            ->whereNotIn('surah_id', $surahIds)
            ->delete();
    }

    public function onMemorizationRecordSaved(TahfizMemorizationRecord $record): void
    {
        $this->recomputeForSurah((int) $record->participant_id, (int) $record->surah_id);
    }

    public function onMemorizationRecordDeleted(TahfizMemorizationRecord $record): void
    {
        $this->recomputeForSurah((int) $record->participant_id, (int) $record->surah_id);
    }

    public function onRevisionSaved(TahfizRevisionRecord $record): void
    {
        if ($record->surah_id === null) {
            return;
        }

        $this->recomputeForSurah((int) $record->participant_id, (int) $record->surah_id);
    }

    public function onRevisionDeleted(TahfizRevisionRecord $record): void
    {
        $this->onRevisionSaved($record);
    }

    /**
     * Peta hafalan peserta: per surah + rekap per juz + total.
     *
     * Inilah yang ditampilkan sebagai "peta hafalan" hingga level ayat.
     */
    public function memorizationMap(int $participantId): array
    {
        $rows = TahfizParticipantProgress::query()
            ->with('surah')
            ->where('participant_id', $participantId)
            ->orderBy('juz')
            ->orderBy('surah_id')
            ->get();

        $totalAyahs = (int) QuranSurah::query()->sum('ayah_count');
        $totalPages = (int) QuranSurah::query()->max('page_end') ?: 604;

        $ayahsMemorized = (int) $rows->sum('ayahs_memorized');
        $ayahsMutqin = (int) $rows->sum('ayahs_mutqin');
        $pagesMemorized = (int) $rows->sum('pages_memorized');

        $surahs = $rows->map(fn (TahfizParticipantProgress $p) => [
            'surah_id' => $p->surah_id,
            'number' => $p->surah?->number,
            'name' => $p->surah?->name,
            'arabic_name' => $p->surah?->arabic_name,
            'juz' => $p->juz,
            'ayah_count' => $p->surah?->ayah_count,
            'ayahs_memorized' => $p->ayahs_memorized,
            'ayahs_mutqin' => $p->ayahs_mutqin,
            'memorized_percent' => $p->memorizedPercent(),
            'mutqin_percent' => $p->mutqinPercent(),
            'status' => $p->status,
            'status_label' => TahfizParticipantProgress::STATUS_LABELS[$p->status] ?? $p->status,
            'avg_score' => $p->avg_score !== null ? (float) $p->avg_score : null,
            'setoran_count' => $p->setoran_count,
            'murojaah_count' => $p->murojaah_count,
            'last_setor_date' => $p->last_setor_date?->toDateString(),
            'last_murojaah_date' => $p->last_murojaah_date?->toDateString(),
        ])->values();

        return [
            'participant_id' => $participantId,
            'total_ayahs' => $totalAyahs,
            'total_pages' => $totalPages,
            'ayahs_memorized' => $ayahsMemorized,
            'ayahs_mutqin' => $ayahsMutqin,
            'pages_memorized' => $pagesMemorized,
            'memorized_percent' => $this->safePercent($ayahsMemorized, $totalAyahs),
            'mutqin_percent' => $this->safePercent($ayahsMutqin, $totalAyahs),
            'surahs_memorized' => $rows->filter(
                fn (TahfizParticipantProgress $p) => $p->surah !== null
                    && $p->ayahs_memorized >= (int) $p->surah->ayah_count
            )->count(),
            'juz_memorized' => $this->juzCompleted($rows)->count(),
            'juz' => $this->juzRollup($rows),
            'surahs' => $surahs,
        ];
    }

    /**
     * Target yang berlaku untuk peserta pada periode tertentu.
     *
     * Urutan prioritas: individual → halaqah → program.
     */
    public function resolveTarget(int $participantId, ?int $enrollmentId, string $period, ?string $date = null): ?object
    {
        $date ??= now()->toDateString();

        if ($enrollmentId !== null) {
            $individual = TahfizIndividualTarget::query()
                ->where('participant_id', $participantId)
                ->where('enrollment_id', $enrollmentId)
                ->active()
                ->where('period', $period)
                ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $date))
                ->latest('id')
                ->first();

            if ($individual !== null) {
                return $individual;
            }
        }

        $enrollment = $enrollmentId !== null
            ? TahfizEnrollment::query()->whereKey($enrollmentId)->first()
            : null;

        if ($enrollment !== null && $enrollment->halaqah_id !== null) {
            $halaqah = TahfizTarget::query()
                ->where('halaqah_id', $enrollment->halaqah_id)
                ->active()
                ->where('period', $period)
                ->latest('id')
                ->first();

            if ($halaqah !== null) {
                return $halaqah;
            }
        }

        if ($enrollment !== null) {
            return TahfizTarget::query()
                ->where('program_id', $enrollment->program_id)
                ->whereNull('halaqah_id')
                ->active()
                ->where('period', $period)
                ->latest('id')
                ->first();
        }

        return null;
    }

    /**
     * Pencapaian target periode berjalan untuk satu peserta.
     */
    public function targetProgress(int $participantId, ?int $enrollmentId, string $period = TahfizTarget::PERIOD_WEEKLY, ?string $date = null): array
    {
        $date ??= now()->toDateString();
        $target = $this->resolveTarget($participantId, $enrollmentId, $period, $date);

        if ($target === null) {
            return [
                'period' => $period,
                'has_target' => false,
                'target_type' => null,
                'target_value' => null,
                'achieved' => null,
                'percent' => null,
            ];
        }

        [$from, $to] = $this->periodBounds($period, $date);

        $achieved = $this->achievementForPeriod(
            $participantId,
            $target->target_type,
            $from,
            $to
        );

        $value = (float) $target->value;

        return [
            'period' => $period,
            'has_target' => true,
            'target_type' => $target->target_type,
            'target_value' => $value,
            'achieved' => $achieved,
            'percent' => $value > 0 ? round($achieved * 100 / $value, 1) : 0.0,
            'from' => $from,
            'to' => $to,
        ];
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    /**
     * Status penguasaan pada tangga: belum < hafal < belum_mutqin < lancar < mutqin.
     */
    protected function resolveStatus(int $memorized, int $mutqin, int $totalAyahs, ?float $avgScore): string
    {
        if ($memorized === 0) {
            return TahfizParticipantProgress::STATUS_BELUM;
        }

        if ($mutqin === 0) {
            // Pernah setor, tapi belum ada ayat yang mutqin.
            return TahfizParticipantProgress::STATUS_HAFAL;
        }

        if ($mutqin < $totalAyahs) {
            // Sebagian sudah mutqin, tapi surah belum selesai.
            return TahfizParticipantProgress::STATUS_BELUM_MUTQIN;
        }

        if ($avgScore !== null && $avgScore >= self::MUTQIN_MIN_SCORE) {
            return TahfizParticipantProgress::STATUS_MUTQIN;
        }

        return TahfizParticipantProgress::STATUS_LANCAR;
    }

    /**
     * Gabungkan rentang yang tumpang tindih, lalu jumlahkan panjangnya.
     *
     * @param  Collection<int, array{0:int,1:int}>  $ranges
     */
    protected function mergedLength(Collection $ranges): int
    {
        return (int) $this->mergedRanges($ranges)->sum(fn (array $r) => $r[1] - $r[0] + 1);
    }

    /**
     * Gabungkan rentang yang tumpang tindih/bersambung.
     *
     * Dipakai baik untuk menghitung jumlah ayat unik, maupun untuk
     * mengetahui rentang ayat mana yang sudah dihafal (mis. butir ujian acak).
     *
     * @param  Collection<int, array{0:int,1:int}>  $ranges
     * @return Collection<int, array{0:int,1:int}>
     */
    protected function mergedRanges(Collection $ranges): Collection
    {
        $items = $ranges
            ->filter(fn ($r) => is_array($r) && count($r) === 2 && $r[0] !== null && $r[1] !== null && $r[1] >= $r[0])
            ->sortBy(fn ($r) => $r[0])
            ->values();

        if ($items->isEmpty()) {
            return collect();
        }

        $merged = collect();
        $current = $items[0];

        foreach ($items as $range) {
            if ($range[0] <= $current[1] + 1) {
                // Menyambung atau tumpang tindih → lebaskan batas atas.
                $current[1] = max($current[1], $range[1]);
            } else {
                $merged->push($current);
                $current = $range;
            }
        }

        $merged->push($current);

        return $merged;
    }

    /**
     * Rentang ayat yang sudah dihafal peserta pada satu surah.
     *
     * @return list<array{0:int,1:int}>
     */
    public function memorizedRanges(int $participantId, int $surahId): array
    {
        $records = TahfizMemorizationRecord::query()
            ->where('participant_id', $participantId)
            ->where('surah_id', $surahId)
            ->accepted()
            ->get();

        return $this->mergedRanges($records->map(fn ($r) => [(int) $r->ayah_start, (int) $r->ayah_end]))
            ->map(fn (array $r) => [(int) $r[0], (int) $r[1]])
            ->all();
    }

    /**
     * @param  Collection<int, TahfizMemorizationRecord>  $records
     * @return Collection<int, array{0:int,1:int}>
     */
    protected function pageRanges(Collection $records): Collection
    {
        return $records
            ->filter(fn ($r) => $r->page_start !== null && $r->page_end !== null)
            ->map(fn ($r) => [(int) $r->page_start, (int) $r->page_end])
            ->values();
    }

    /**
     * Seluruh surah yang punya catatan setoran/murojaah/progress milik peserta.
     */
    protected function touchedSurahIds(int $participantId): Collection
    {
        return TahfizMemorizationRecord::query()->where('participant_id', $participantId)->pluck('surah_id')
            ->merge(TahfizRevisionRecord::query()->where('participant_id', $participantId)->whereNotNull('surah_id')->pluck('surah_id'))
            ->merge(TahfizParticipantProgress::query()->where('participant_id', $participantId)->pluck('surah_id'))
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * Juz yang seluruh surah awalnya sudah dihafal lengkap.
     *
     * Juz dihitung dari SELURUH surah yang dimulai di juz tersebut, bukan hanya
     * surah yang pernah disentuh peserta — jika tidak, seseorang yang
     * menghafal Al-Fatihah saja akan terlihat seolah juz 1 selesai.
     */
    protected function juzCompleted(Collection $rows): Collection
    {
        $byJuz = $rows->groupBy('juz');
        $completed = collect();

        foreach ($byJuz as $juz => $group) {
            $surahIds = QuranSurah::query()->where('juz_start', $juz)->pluck('id');

            // Tidak ada surah di juz ini (tidak mungkin terjadi) → lewati.
            if ($surahIds->isEmpty()) {
                continue;
            }

            $memorizedIds = $group->filter(
                fn (TahfizParticipantProgress $p) => $p->surah !== null
                    && $p->ayahs_memorized >= (int) $p->surah->ayah_count
            )->pluck('surah_id');

            if ($surahIds->diff($memorizedIds)->isEmpty()) {
                $completed->put($juz, $group);
            }
        }

        return $completed;
    }

    /**
     * Rekap per juz berdasarkan juz awal surah.
     */
    protected function juzRollup(Collection $rows): array
    {
        return $rows->groupBy('juz')->map(function (Collection $group, int $juz): array {
            // Seluruh surah yang dimulai pada juz ini, agar persentase
            // tidak hanya menghitung surah yang pernah disentuh peserta.
            $ayahs = (int) QuranSurah::query()->where('juz_start', $juz)->sum('ayah_count');

            return [
                'juz' => $juz,
                'surah_count' => QuranSurah::query()->where('juz_start', $juz)->count(),
                'ayahs' => $ayahs,
                'ayahs_memorized' => (int) $group->sum('ayahs_memorized'),
                'ayahs_mutqin' => (int) $group->sum('ayahs_mutqin'),
                'memorized_percent' => $this->safePercent((int) $group->sum('ayahs_memorized'), $ayahs),
            ];
        })->values()->all();
    }

    protected function safePercent(int $part, int $whole): float
    {
        return $whole > 0 ? round($part * 100 / $whole, 1) : 0.0;
    }

    /**
     * Batas awal & akhir sebuah periode.
     *
     * @return array{0:string,1:string}
     */
    protected function periodBounds(string $period, string $date): array
    {
        $day = date('Y-m-d', strtotime($date));

        return match ($period) {
            TahfizTarget::PERIOD_DAILY => [$day, $day],
            TahfizTarget::PERIOD_WEEKLY => [
                date('Y-m-d', strtotime('monday this week', strtotime($day))),
                date('Y-m-d', strtotime('sunday this week', strtotime($day))),
            ],
            TahfizTarget::PERIOD_MONTHLY => [
                date('Y-m-01', strtotime($day)),
                date('Y-m-t', strtotime($day)),
            ],
            TahfizTarget::PERIOD_SEMESTER, TahfizTarget::PERIOD_PROGRAM => [
                date('Y-01-01', strtotime($day)),
                date('Y-12-31', strtotime($day)),
            ],
            default => [$day, $day],
        };
    }

    /**
     * Jumlah satuan target yang tercapai pada rentang tanggal.
     */
    protected function achievementForPeriod(int $participantId, string $targetType, string $from, string $to): float
    {
        $records = TahfizMemorizationRecord::query()
            ->accepted()
            ->where('participant_id', $participantId)
            ->betweenDates($from, $to)
            ->get();

        return match ($targetType) {
            TahfizTarget::TARGET_AYAT => (float) $records->sum(fn ($r) => $r->ayahCount()),
            TahfizTarget::TARGET_PAGE => (float) $records->reduce(fn ($carry, $r) => $carry + ($r->pageCount() ?? 0), 0),
            TahfizTarget::TARGET_JUZ => (float) $records->pluck('juz')->filter()->unique()->count(),
            TahfizTarget::TARGET_SURAH => (float) $records->pluck('surah_id')->unique()->count(),
            default => 0.0,
        };
    }
}
