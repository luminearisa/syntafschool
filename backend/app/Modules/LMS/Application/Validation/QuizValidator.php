<?php

namespace App\Modules\LMS\Application\Validation;

use App\Modules\LMS\Domain\Enums\QuestionType;
use App\Modules\LMS\Models\LmsQuiz;

/** Validasi konfigurasi kuis & bank soal yang dipakai (§17). */
class QuizValidator
{
    public function __construct(private readonly QuestionValidator $questions) {}

    public function validate(LmsQuiz $quiz): ValidationReport
    {
        $report = new ValidationReport;

        $slots = $quiz->questions()->count();

        if ($slots === 0) {
            $report->error('quiz.no_questions', 'Kuis belum memiliki soal.', 'quiz', (int) $quiz->id, 'Tambahkan soal dari bank soal atau buat soal baru.');
        }

        $totalMark = (float) $quiz->totalMarks();

        if ($slots > 0 && $totalMark <= 0) {
            $report->error('quiz.zero_total_mark', 'Seluruh soal kuis bernilai 0 sehingga tidak ada yang bisa dinilai.', 'quiz', (int) $quiz->id);
        }

        $maximum = (float) $quiz->maximum_grade;

        if ($maximum <= 0) {
            $report->error('quiz.maximum_grade_invalid', 'Nilai maksimum kuis harus lebih dari 0.', 'quiz', (int) $quiz->id);
        }

        if ($quiz->passing_grade !== null && (float) $quiz->passing_grade > $maximum) {
            $report->error('quiz.passing_grade_above_maximum', 'Nilai kelulusan melebihi nilai maksimum kuis.', 'quiz', (int) $quiz->id);
        }

        if ($quiz->open_at !== null && $quiz->close_at !== null && $quiz->close_at->lt($quiz->open_at)) {
            $report->error('quiz.window_inverted', 'Waktu penutupan kuis lebih awal daripada waktu buka.', 'quiz', (int) $quiz->id);
        }

        if ($quiz->time_limit_minutes !== null && (int) $quiz->time_limit_minutes < 1) {
            $report->error('quiz.time_limit_invalid', 'Batas waktu kuis harus minimal 1 menit.', 'quiz', (int) $quiz->id);
        }

        if ($quiz->require_password && ! filled($quiz->quiz_password)) {
            $report->error('quiz.password_missing', 'Kuis mewajibkan kata sandi tetapi sandinya belum diisi.', 'quiz', (int) $quiz->id);
        }

        if ($quiz->questions_per_page !== null && (int) $quiz->questions_per_page < 1) {
            $report->error('quiz.questions_per_page_invalid', 'Jumlah soal per halaman harus minimal 1.', 'quiz', (int) $quiz->id);
        }

        foreach ($quiz->questions()->with(['question.options', 'category'])->get() as $slot) {
            if ($slot->question === null && $slot->category_id === null) {
                $report->error(
                    'quiz.slot_without_question',
                    'Ada slot kuis yang tidak menunjuk soal maupun kategori acak.',
                    'quiz',
                    (int) $quiz->id,
                    'Hapus slot tersebut atau pilih soal pengganti.',
                );

                continue;
            }

            if ($slot->isRandomSlot() && (int) $slot->question_count < 1) {
                $report->error('quiz.random_slot_empty', 'Slot acak harus mengambil minimal satu soal.', 'quiz', (int) $quiz->id);
            }

            if ($slot->question !== null) {
                $report->merge($this->questions->validate($slot->question));
            }
        }

        if ((int) $quiz->attemptsAllowed() === 1) {
            $report->info('quiz.single_attempt', 'Kuis hanya boleh dikerjakan sekali; pastikan tenggat & waktu sudah benar.', 'quiz', (int) $quiz->id);
        }

        $types = $quiz->questions()
            ->whereHas('question')
            ->with('question:id,question_type')
            ->get()
            ->map(fn ($slot) => $slot->question?->question_type?->value)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($types === [] && $slots > 0) {
            $report->error('quiz.questions_without_type', 'Soal kuis belum memiliki tipe.', 'quiz', (int) $quiz->id);
        }

        if (in_array(QuestionType::ESSAY->value, $types, true)) {
            $report->info('quiz.has_essay', 'Ada soal esai: penilaian manual wajib diselesaikan guru sebelum nilai dirilis.', 'quiz', (int) $quiz->id);
        }

        return $report;
    }
}
