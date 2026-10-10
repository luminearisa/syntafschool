<?php

namespace App\Services\Academic;

use App\Models\Assessment;
use App\Models\ClassStudent;
use App\Models\PassingGradeRule;
use App\Models\ReportCard;
use App\Models\ReportCardItem;
use App\Models\SchoolClass;
use App\Models\Semester;
use App\Models\Student;
use App\Services\Assessment\GradingService;
use App\Services\Attendance\AttendanceRecapService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Generates immutable-ish semester snapshots from the live grading and attendance engines. */
class ReportCardService
{
    public function __construct(
        private readonly GradingService $grading,
        private readonly AttendanceRecapService $attendance,
    ) {}

    /** @return Collection<int, ReportCard> */
    public function generate(SchoolClass $class, Semester $semester, string $type): Collection
    {
        if ((int) $class->academic_year_id !== (int) $semester->academic_year_id) {
            throw ValidationException::withMessages([
                'semester_id' => 'Semester harus berada pada tahun ajaran kelas yang dipilih.',
            ]);
        }

        $subjects = $class->classSubjects()
            ->with('subject')
            ->whereHas('subject')
            ->get()
            ->map(fn ($row) => $row->subject)
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values();

        if ($subjects->isEmpty()) {
            throw ValidationException::withMessages([
                'class_id' => 'Kelas ini belum memiliki mata pelajaran yang diampu.',
            ]);
        }

        $unfinished = Assessment::query()
            ->where('school_class_id', $class->id)
            ->where('semester_id', $semester->id)
            ->whereIn('subject_id', $subjects->pluck('id'))
            ->where('status', '!=', Assessment::STATUS_FINALIZED)
            ->exists();

        if ($unfinished) {
            throw ValidationException::withMessages([
                'assessment' => 'Finalisasi seluruh penilaian pada kelas dan semester ini sebelum membuat rapor.',
            ]);
        }

        $students = Student::query()
            ->whereIn('id', ClassStudent::query()
                ->where('school_class_id', $class->id)
                ->where('status', 'aktif')
                ->pluck('student_id'))
            ->orderBy('full_name')
            ->get();

        if ($students->isEmpty()) {
            throw ValidationException::withMessages([
                'class_id' => 'Kelas ini belum memiliki siswa aktif.',
            ]);
        }

        $gradebooks = $subjects->mapWithKeys(fn ($subject) => [
            $subject->id => $this->grading->build($class, $subject, $semester),
        ]);

        $existingLocked = ReportCard::withTrashed()
            ->where('school_class_id', $class->id)
            ->where('semester_id', $semester->id)
            ->where('type', $type)
            ->whereIn('status', [
                ReportCard::STATUS_READY,
                ReportCard::STATUS_APPROVED,
                ReportCard::STATUS_PUBLISHED,
            ])
            ->exists();

        if ($existingLocked) {
            throw ValidationException::withMessages([
                'type' => 'Rapor yang sudah diajukan/disetujui/diterbitkan tidak dapat dibuat ulang.',
            ]);
        }

        $cards = DB::transaction(function () use ($class, $semester, $type, $students, $subjects, $gradebooks): Collection {
            $cards = collect();

            foreach ($students as $student) {
                $existing = ReportCard::withTrashed()
                    ->where('student_id', $student->id)
                    ->where('semester_id', $semester->id)
                    ->where('type', $type)
                    ->first();

                if (in_array($existing?->status, [
                    ReportCard::STATUS_READY,
                    ReportCard::STATUS_APPROVED,
                    ReportCard::STATUS_PUBLISHED,
                ], true)) {
                    throw ValidationException::withMessages([
                        'type' => 'Rapor yang sudah diajukan/disetujui/diterbitkan tidak dapat dibuat ulang.',
                    ]);
                }

                $items = $subjects->map(function ($subject, int $index) use ($gradebooks, $student): array {
                    $gradebook = $gradebooks->get($subject->id);
                    $scoreRow = collect($gradebook['students'] ?? [])->firstWhere('student_id', $student->id);

                    return [
                        'subject_id' => $subject->id,
                        'score' => $scoreRow['final_score'] ?? null,
                        'grade' => $scoreRow['grade'] ?? null,
                        'predicate' => $scoreRow['predicate'] ?? null,
                        'description' => null,
                        'teacher_notes' => null,
                        'position' => $index + 1,
                    ];
                })->values();

                $scores = $items->pluck('score')->filter(fn ($score) => $score !== null)->map(fn ($score) => (float) $score);
                $average = $scores->isEmpty() ? null : round((float) $scores->avg(), 2);
                $attendance = $this->attendance->forStudent(
                    (int) $student->id,
                    (int) $semester->id,
                    $class->education_level_id === null ? null : (int) $class->education_level_id,
                );

                $card = $existing ?? new ReportCard();
                if ($card->trashed()) {
                    $card->restore();
                }

                $card->fill([
                    'student_id' => $student->id,
                    'school_class_id' => $class->id,
                    'semester_id' => $semester->id,
                    'academic_year_id' => $semester->academic_year_id,
                    'type' => $type,
                    'status' => ReportCard::STATUS_DRAFT,
                    'average_score' => $average,
                    'rank' => null,
                    'attendance_summary' => $attendance,
                ])->save();

                $card->items()->delete();
                foreach ($items as $item) {
                    ReportCardItem::create(['report_card_id' => $card->id] + $item);
                }

                $cards->push($card);
            }

            $this->assignRanks($cards);

            return $cards->map(fn (ReportCard $card) => $card->fresh(['student', 'schoolClass', 'semester', 'academicYear'])->loadCount('items'));
        });

        return $cards;
    }

    public function markReady(ReportCard $card): ReportCard
    {
        $this->assertTransition($card, [ReportCard::STATUS_DRAFT, ReportCard::STATUS_IN_PROGRESS], 'Hanya rapor draft yang dapat diajukan untuk review.');

        $items = $card->items()->get();
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Rapor belum memiliki mata pelajaran.']);
        }

        $missing = $items->whereNull('score')->count();
        if ($missing > 0) {
            throw ValidationException::withMessages([
                'items' => "Masih ada {$missing} mata pelajaran tanpa nilai akhir. Lengkapi penilaian sebelum mengajukan rapor.",
            ]);
        }

        $card->update(['status' => ReportCard::STATUS_READY]);

        return $card->fresh(['student', 'schoolClass', 'semester', 'academicYear', 'items.subject']);
    }

    public function approve(ReportCard $card): ReportCard
    {
        $this->assertTransition($card, [ReportCard::STATUS_READY], 'Rapor hanya dapat disetujui setelah diajukan untuk review.');
        $card->update(['status' => ReportCard::STATUS_APPROVED]);

        return $card->fresh(['student', 'schoolClass', 'semester', 'academicYear', 'items.subject']);
    }

    public function publish(ReportCard $card, ?int $userId): ReportCard
    {
        $this->assertTransition($card, [ReportCard::STATUS_APPROVED], 'Rapor hanya dapat diterbitkan setelah disetujui.');
        $card->update([
            'status' => ReportCard::STATUS_PUBLISHED,
            'published_at' => now(),
            'published_by' => $userId,
        ]);

        $fresh = $card->fresh(['student', 'schoolClass', 'semester', 'academicYear', 'items.subject']);

        $this->notifyGuardiansAfterPublish($fresh);

        return $fresh;
    }

    /**
     * Beri tahu wali murid bahwa rapor sudah diterbitkan. Mata pelajaran dengan
     * nilai akhir di bawah KKTP dicantumkan agar wali tahu di mana perlu
     * pendampingan. Notifikasi dikirim setelah status tersimpan agar tidak ikut
     * ter-rollback bila transaksi pemanggil gagal di tengah.
     */
    private function notifyGuardiansAfterPublish(ReportCard $card): void
    {
        $educationLevelId = $card->schoolClass?->education_level_id;
        $educationLevelId = $educationLevelId === null ? null : (int) $educationLevelId;

        $belowKktp = [];

        foreach ($card->items as $item) {
            if ($item->score === null || $item->subject_id === null) {
                continue;
            }

            $rule = PassingGradeRule::resolveFor(
                (int) $item->subject_id,
                $educationLevelId,
                (int) $card->academic_year_id,
            );

            $minScore = $rule?->min_score;

            if ($minScore !== null && (float) $item->score < (float) $minScore) {
                $belowKktp[] = (string) ($item->subject?->name ?? 'Mata pelajaran');
            }
        }

        app(GuardianNotifier::class)->notifyReportPublished($card, $belowKktp);
    }

    /**
     * Kembalikan rapor ke draft agar penilaian/catatan dapat diperbaiki.
     *
     * Rapor `ready_for_review`/`approved` dapat dikembalikan; rapor yang sudah
     * `published` sengaja dikunci agar catatan yang telah dibagikan ke orang tua
     * tidak berubah diam-diam.
     */
    public function reopen(ReportCard $card): ReportCard
    {
        $this->assertTransition(
            $card,
            [ReportCard::STATUS_READY, ReportCard::STATUS_APPROVED],
            'Hanya rapor yang menunggu review atau sudah disetujui yang dapat dikembalikan ke draft.',
        );

        $card->update(['status' => ReportCard::STATUS_DRAFT]);

        return $card->fresh(['student', 'schoolClass', 'semester', 'academicYear', 'items.subject']);
    }

    public function updateNotes(ReportCard $card, array $data): ReportCard
    {
        if (in_array($card->status, [ReportCard::STATUS_APPROVED, ReportCard::STATUS_PUBLISHED], true)) {
            throw ValidationException::withMessages(['status' => 'Catatan rapor tidak dapat diubah setelah rapor disetujui.']);
        }

        $card->update($data);

        return $card->fresh(['student', 'schoolClass', 'semester', 'academicYear', 'items.subject']);
    }

    /** @param list<string> $allowed */
    private function assertTransition(ReportCard $card, array $allowed, string $message): void
    {
        if (! in_array($card->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    /** @param Collection<int, ReportCard> $cards */
    private function assignRanks(Collection $cards): void
    {
        $ranked = $cards->filter(fn (ReportCard $card) => $card->average_score !== null)
            ->sortByDesc(fn (ReportCard $card) => (float) $card->average_score)
            ->values();

        $lastScore = null;
        $lastRank = 0;
        foreach ($ranked as $index => $card) {
            $score = round((float) $card->average_score, 2);
            $rank = $lastScore !== null && abs($score - $lastScore) < 0.001 ? $lastRank : $index + 1;
            $card->update(['rank' => $rank]);
            $lastScore = $score;
            $lastRank = $rank;
        }
    }
}
