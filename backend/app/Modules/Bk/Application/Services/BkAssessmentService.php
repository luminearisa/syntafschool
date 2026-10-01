<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Application\Support\BkNumberGenerator;
use App\Modules\Bk\Domain\Enums\AssessmentQuestionType;
use App\Modules\Bk\Domain\Enums\AssessmentStatus;
use App\Modules\Bk\Domain\Enums\AssessmentTarget;
use App\Modules\Bk\Domain\Enums\AuditAction;
use App\Modules\Bk\Domain\Enums\CaseEventType;
use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use App\Modules\Bk\Domain\Enums\NotificationEvent;
use App\Modules\Bk\Exceptions\BkValidationException;
use App\Modules\Bk\Models\BkAssessment;
use App\Modules\Bk\Models\BkAssessmentQuestion;
use App\Modules\Bk\Models\BkAssessmentResponse;
use App\Modules\Bk\Models\BkAssessmentSection;
use App\Modules\Bk\Models\BkAssessmentTemplate;
use App\Modules\Bk\Models\BkCase;
use Illuminate\Support\Facades\DB;

/**
 * Mesin asesmen yang dapat dikonfigurasi.
 *
 * Sekolah menyusun template → bagian → pertanyaan; konselor mengirimkannya
 * kepada siswa/orang tua/guru/konselor, lalu menelaah jawabannya. Skor hanya
 * **alat bantu**: modul ini tidak pernah menyimpulkan diagnosis, dan pertanyaan
 * yang ditandai sensitif tidak ikut dirangkum untuk audiens umum.
 */
class BkAssessmentService
{
    public function __construct(
        private readonly BkNumberGenerator $numbers,
        private readonly BkTimelineService $timeline,
        private readonly BkAuditService $audit,
        private readonly BkNotificationService $notifications,
        private readonly BkAccessService $access,
    ) {
    }

    /**
     * Buat template beserta bagian dan pertanyaannya sekaligus.
     *
     * @param  array<string, mixed>  $data
     */
    public function createTemplate(User $actor, array $data): BkAssessmentTemplate
    {
        return DB::transaction(function () use ($actor, $data): BkAssessmentTemplate {
            $template = BkAssessmentTemplate::query()->create([
                'code' => $data['code'],
                'name' => $data['name'],
                'target' => $data['target'] ?? AssessmentTarget::STUDENT->value,
                'description' => $data['description'] ?? null,
                'purpose' => $data['purpose'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'is_default' => (bool) ($data['is_default'] ?? false),
                'requires_consent' => (bool) ($data['requires_consent'] ?? false),
                'created_by' => $actor->id,
            ]);

            foreach ((array) ($data['sections'] ?? []) as $sectionIndex => $section) {
                $createdSection = BkAssessmentSection::query()->create([
                    'template_id' => $template->id,
                    'code' => $section['code'] ?? 'S'.($sectionIndex + 1),
                    'title' => $section['title'],
                    'description' => $section['description'] ?? null,
                    'sort_order' => $section['sort_order'] ?? $sectionIndex + 1,
                ]);

                foreach ((array) ($section['questions'] ?? []) as $questionIndex => $question) {
                    $type = AssessmentQuestionType::from($question['question_type'] ?? AssessmentQuestionType::TEXT->value);

                    if ($type->requiresOptions() && empty($question['options'])) {
                        throw new BkValidationException([
                            'questions' => [sprintf('Pertanyaan "%s" bertipe %s dan wajib memiliki opsi.', $question['question'], $type->label())],
                        ]);
                    }

                    BkAssessmentQuestion::query()->create([
                        'template_id' => $template->id,
                        'section_id' => $createdSection->id,
                        'code' => $question['code'] ?? 'Q'.($questionIndex + 1),
                        'question' => $question['question'],
                        'question_type' => $type->value,
                        'options' => $question['options'] ?? null,
                        'min_value' => $question['min_value'] ?? null,
                        'max_value' => $question['max_value'] ?? null,
                        'is_required' => (bool) ($question['is_required'] ?? false),
                        'is_sensitive' => (bool) ($question['is_sensitive'] ?? false),
                        'is_scored' => (bool) ($question['is_scored'] ?? $type->isNumericAnswer()),
                        'help_text' => $question['help_text'] ?? null,
                        'sort_order' => $question['sort_order'] ?? $questionIndex + 1,
                    ]);
                }
            }

            $this->audit->record(AuditAction::CREATE, $template, $actor, ['changes' => ['code' => ['from' => null, 'to' => $template->code]]]);

            return $template->load('sections.questions');
        });
    }

    /**
     * Kirim asesmen kepada responden.
     *
     * Untuk target STUDENT/PARENT asesmen memerlukan persetujuan orang tua bila
     * template menandainya; pengiriman tetap dicatat walaupun persetujuan belum
     * ada, tetapi statusnya ditahan sebagai DRAFT dan konselor diberi tahu.
     *
     * @param  array<string, mixed>  $data
     */
    public function assign(User $actor, array $data): BkAssessment
    {
        $case = isset($data['case_id']) ? BkCase::query()->findOrFail($data['case_id']) : null;

        if ($case !== null) {
            $this->access->assertCanViewCase($actor, $case, 'Mengirim asesmen');
        }

        $template = BkAssessmentTemplate::query()->findOrFail($data['template_id']);
        $target = AssessmentTarget::from($data['target'] ?? $template->target->value);

        return DB::transaction(function () use ($actor, $data, $case, $template, $target): BkAssessment {
            $assessment = BkAssessment::query()->create([
                'assessment_number' => $this->numbers->next('BK_ASSESSMENT', 'BK-A'),
                'template_id' => $template->id,
                'case_id' => $case?->id,
                'referral_id' => $data['referral_id'] ?? $case?->source_referral_id,
                'student_id' => $data['student_id'] ?? $case?->student_id,
                'target' => $target->value,
                'respondent_user_id' => $data['respondent_user_id'] ?? null,
                'respondent_name' => $data['respondent_name'] ?? null,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
                'due_date' => $data['due_date'] ?? now()->addDays(7)->toDateString(),
                'status' => AssessmentStatus::SENT->value,
                'sent_at' => now(),
                'confidentiality_level' => $data['confidentiality_level']
                    ?? ($case?->confidentiality_level->value ?? ConfidentialityLevel::COUNSELOR_ONLY->value),
                'consent_required' => $template->requires_consent,
            ]);

            if ($case !== null) {
                $this->timeline->record(CaseEventType::ASSESSMENT_SENT, $actor, $case,
                    summary: sprintf('Asesmen %s dikirim kepada %s.', $template->name, $target->label()),
                    related: $assessment, payload: ['template' => $template->code, 'target' => $target->value]);
            }

            $this->audit->record(AuditAction::CREATE, $assessment, $actor, ['student_id' => $assessment->student_id]);

            $recipient = $assessment->respondent_user_id;
            if ($recipient !== null) {
                $this->notifications->notifyUser(
                    (int) $recipient,
                    NotificationEvent::CONSENT_REQUIRED,
                    sprintf('Ada asesmen BK yang perlu Anda isi%s.', $assessment->due_date ? ' sebelum '.$assessment->due_date->toDateString() : ''),
                    ['assessment_id' => $assessment->id, 'case_id' => $case?->id],
                );
            }

            return $assessment->refresh();
        });
    }

    /**
     * Simpan jawaban peserta.
     *
     * @param  list<array{question_id: int, value_text?: ?string, value_number?: ?float,
     *     value_boolean?: ?bool, value_date?: ?string, value_options?: ?array, score?: ?float}>  $answers
     */
    public function submitResponses(User $actor, BkAssessment $assessment, array $answers): BkAssessment
    {
        if (in_array($assessment->status, [AssessmentStatus::COMPLETED, AssessmentStatus::REVIEWED, AssessmentStatus::CANCELLED], true)) {
            throw new BkValidationException(['status' => ['Asesmen ini sudah selesai dan tidak dapat diisi lagi.']]);
        }

        $questions = $assessment->template->questions()->get()->keyBy('id');

        return DB::transaction(function () use ($actor, $assessment, $answers, $questions): BkAssessment {
            $totalScore = 0.0;
            $answered = 0;

            foreach ($answers as $answer) {
                $question = $questions->get((int) $answer['question_id']);

                if ($question === null) {
                    throw new BkValidationException(['answers' => ['Ada jawaban untuk pertanyaan yang bukan milik template ini.']]);
                }

                $score = $answer['score'] ?? $this->scoreFor($question, $answer);

                BkAssessmentResponse::query()->updateOrCreate(
                    ['assessment_id' => $assessment->id, 'question_id' => $question->id],
                    [
                        'value_text' => $answer['value_text'] ?? null,
                        'value_number' => $answer['value_number'] ?? null,
                        'value_boolean' => $answer['value_boolean'] ?? null,
                        'value_date' => $answer['value_date'] ?? null,
                        'value_options' => $answer['value_options'] ?? null,
                        'score' => $score,
                        'responded_by' => $actor->id,
                        'responded_at' => now(),
                    ],
                );

                $answered++;

                if ($question->is_scored && $score !== null) {
                    $totalScore += (float) $score;
                }
            }

            $required = $questions->where('is_required', true)->pluck('id');
            $provided = collect($answers)->pluck('question_id')->map(fn ($id): int => (int) $id);
            $missing = $required->diff($provided);

            $assessment->forceFill([
                'status' => $missing->isEmpty() ? AssessmentStatus::COMPLETED->value : AssessmentStatus::IN_PROGRESS->value,
                'completed_at' => $missing->isEmpty() ? now() : null,
                'total_score' => $totalScore > 0 ? round($totalScore, 2) : null,
            ])->save();

            $this->audit->record(AuditAction::UPDATE, $assessment->refresh(), $actor, [
                'changes' => ['responses' => ['from' => null, 'to' => $answered.' jawaban']],
            ]);

            if ($assessment->case !== null && $assessment->status === AssessmentStatus::COMPLETED) {
                $this->timeline->record(CaseEventType::ASSESSMENT_COMPLETED, $actor, $assessment->case,
                    summary: 'Asesmen selesai diisi ('.$answered.' jawaban).', related: $assessment);
            }

            return $assessment->refresh();
        });
    }

    /** Telaah hasil asesmen (ringkasan konselor — bukan diagnosis). */
    public function review(User $actor, BkAssessment $assessment, string $summary): BkAssessment
    {
        if ($assessment->case !== null) {
            $this->access->assertCanViewCase($actor, $assessment->case, 'Menelaah asesmen');
        }

        $assessment->forceFill([
            'status' => AssessmentStatus::REVIEWED->value,
            'reviewed_at' => now(),
            'reviewed_by' => $actor->id,
            'summary' => $summary,
        ])->save();

        $this->audit->record(AuditAction::UPDATE, $assessment->refresh(), $actor, ['changes' => ['summary' => ['from' => null, 'to' => '[disunting]']]]);

        return $assessment->refresh();
    }

    /**
     * Susun ringkasan jawaban yang aman ditampilkan.
     *
     * Pertanyaan bertanda sensitif hanya ditampilkan kepada pemegang akses
     * konseling; audiens lain menerima penanda "disembunyikan".
     *
     * @return list<array{question: string, type: string, answer: mixed, hidden: bool}>
     */
    public function summarize(User $actor, BkAssessment $assessment): array
    {
        $canSeeSensitive = $assessment->case !== null
            ? $this->access->canViewCounselingContent($actor, $assessment->case)
            : $this->access->isBkStaff($actor);

        return $assessment->responses()
            ->with('question:id,question,question_type,is_sensitive,sort_order')
            ->get()
            ->sortBy(fn (BkAssessmentResponse $response): int => (int) ($response->question?->sort_order ?? 0))
            ->map(function (BkAssessmentResponse $response) use ($canSeeSensitive): array {
                $question = $response->question;
                $hidden = (bool) ($question?->is_sensitive ?? false) && ! $canSeeSensitive;

                return [
                    'question' => (string) ($question?->question ?? ''),
                    'type' => (string) ($question?->question_type->value ?? 'TEXT'),
                    'answer' => $hidden ? null : $this->answerValue($response),
                    'hidden' => $hidden,
                ];
            })
            ->values()
            ->all();
    }

    private function answerValue(BkAssessmentResponse $response): mixed
    {
        return $response->value_options
            ?? $response->value_text
            ?? $response->value_number
            ?? $response->value_boolean
            ?? $response->value_date?->toDateString();
    }

    /** @param array<string, mixed> $answer */
    private function scoreFor(BkAssessmentQuestion $question, array $answer): ?float
    {
        $type = $question->question_type;

        if (! $question->is_scored) {
            return null;
        }

        if ($type->isNumericAnswer() && isset($answer['value_number'])) {
            return (float) $answer['value_number'];
        }

        if ($type === AssessmentQuestionType::SCALE && isset($answer['value_number'])) {
            return (float) $answer['value_number'];
        }

        return null;
    }
}
