<?php

namespace App\Modules\LMS\Application\Validation;

use App\Modules\LMS\Domain\Enums\QuestionType;
use App\Modules\LMS\Models\LmsQuestion;
use App\Modules\LMS\Models\LmsQuestionOption;
use Illuminate\Support\Collection;

/**
 * Validasi soal (§17).
 *
 * Soal tanpa kunci jawaban tidak akan pernah bisa dinilai otomatis, dan soal
 * dengan lebih dari satu kunci pada tipe pilihan tunggal membuat penilaian
 * tidak dapat dipertanggungjawabkan.
 */
class QuestionValidator
{
    public function validate(LmsQuestion $question): ValidationReport
    {
        $report = new ValidationReport;
        /** @var Collection<int, LmsQuestionOption> $options */
        $options = $question->relationLoaded('options') ? $question->options : $question->options()->get();

        if (! filled($question->question_text)) {
            $report->error('question.text_missing', 'Teks soal kosong.', 'question', (int) $question->id);
        }

        if ((float) $question->default_mark <= 0) {
            $report->warning('question.zero_mark', 'Soal "'.$question->name.'" bernilai 0 poin.', 'question', (int) $question->id);
        }

        $correct = $options->where('is_correct', true);

        switch ($question->question_type) {
            case QuestionType::MULTIPLE_CHOICE_SINGLE:
            case QuestionType::TRUE_FALSE:
                if ($options->count() < 2) {
                    $report->error('question.option_missing', 'Soal pilihan tunggal memerlukan minimal dua opsi.', 'question', (int) $question->id);
                }

                if ($correct->count() !== 1) {
                    $report->error(
                        'question.correct_count_invalid',
                        'Soal "'.$question->name.'" memiliki '.$correct->count().' kunci jawaban; harus tepat satu.',
                        'question',
                        (int) $question->id,
                        'Tandai satu opsi sebagai jawaban benar.',
                    );
                }

                break;

            case QuestionType::MULTIPLE_CHOICE_MULTIPLE:
                if ($correct->count() === 0) {
                    $report->error('question.correct_missing', 'Soal "'.$question->name.'" belum memiliki kunci jawaban.', 'question', (int) $question->id);
                }

                break;

            case QuestionType::MATCHING:
                $withTarget = $options->filter(fn ($option) => filled($option->match_target));

                if ($options->count() < 2 || $withTarget->count() !== $options->count()) {
                    $report->error(
                        'question.matching_incomplete',
                        'Soal menjodohkan "'.$question->name.'" belum memiliki pasangan lengkap.',
                        'question',
                        (int) $question->id,
                    );
                }

                break;

            case QuestionType::SHORT_ANSWER:
                $settings = (array) ($question->settings ?? []);
                $answers = array_values(array_filter((array) ($settings['accepted_answers'] ?? []), fn ($answer) => trim((string) $answer) !== ''));

                if ($answers === []) {
                    $report->error(
                        'question.answer_key_missing',
                        'Soal "'.$question->name.'" belum memiliki daftar jawaban yang diterima.',
                        'question',
                        (int) $question->id,
                        'Isi jawaban yang dianggap benar pada pengaturan soal.',
                    );
                }

                break;

            case QuestionType::NUMERICAL:
                $settings = (array) ($question->settings ?? []);

                if (! is_numeric($settings['correct_answer'] ?? null)) {
                    $report->error(
                        'question.numeric_answer_missing',
                        'Soal numerik "'.$question->name.'" belum memiliki jawaban benar.',
                        'question',
                        (int) $question->id,
                        'Isi jawaban benar (angka) dan toleransi pada pengaturan soal.',
                    );
                }

                if ((float) ($settings['tolerance'] ?? 0) < 0) {
                    $report->error('question.numeric_tolerance_invalid', 'Toleransi jawaban numerik tidak boleh negatif.', 'question', (int) $question->id);
                }

                break;

            case QuestionType::ESSAY:
                $settings = (array) ($question->settings ?? []);

                if ((float) ($settings['max_words'] ?? 0) > 0 && (float) ($settings['min_words'] ?? 0) > (float) $settings['max_words']) {
                    $report->error('question.essay_word_range', 'Batas minimum kata esai melebihi batas maksimum.', 'question', (int) $question->id);
                }

                break;

            case QuestionType::DRAG_DROP:
            case QuestionType::CLOZE:
            case QuestionType::ORDERING:
                // Penilai tipe ini belum aktif (Phase 2 menandainya "belum
                // aktif"). Menyimpan soal seperti ini tidak dilarang, tetapi
                // guru perlu tahu bahwa nilainya akan menunggu penilaian manual.
                $report->warning(
                    'question.type_not_implemented',
                    'Tipe soal "'.$question->question_type->label().'" belum aktif; jawaban siswa tidak akan dinilai otomatis.',
                    'question',
                    (int) $question->id,
                );

                break;

            default:
                if ($correct->count() === 0 && $question->question_type->isAutoGradable()) {
                    $report->error('question.correct_missing', 'Soal "'.$question->name.'" belum memiliki kunci jawaban otomatis.', 'question', (int) $question->id);
                }
        }

        return $report;
    }
}
