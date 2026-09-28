<?php

namespace App\Modules\LMS\Application\Services;

use App\Modules\LMS\Application\Support\QuestionTypeRegistry;
use App\Modules\LMS\Models\LmsQuestion;

/**
 * Menilai satu jawaban memakai registry tipe soal (§15, §20).
 *
 * Selalu menerima **snapshot** soal sehingga penilaian memakai soal pada versi
 * yang benar-benar dilihat siswa (§22).
 */
class QuestionGradingService
{
    public function __construct(private readonly QuestionTypeRegistry $registry) {}

    /**
     * @param  array<string, mixed>  $response
     * @return array{marks: float, is_correct: bool|null, feedback: string|null, needs_manual: bool}
     */
    public function gradeQuestion(LmsQuestion $question, array $response, float $marks): array
    {
        return $this->registry->grade($question->toSnapshot(), $response, $marks);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $response
     * @return array{marks: float, is_correct: bool|null, feedback: string|null, needs_manual: bool}
     */
    public function gradeSnapshot(array $snapshot, array $response, float $marks): array
    {
        return $this->registry->grade($snapshot, $response, $marks);
    }

    /** Apakah tipe ini dinilai otomatis? */
    public function isAutoGradable(array $snapshot): bool
    {
        $type = (string) ($snapshot['question_type'] ?? '');

        return $type !== 'ESSAY';
    }
}
