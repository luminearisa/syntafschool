<?php

namespace App\Modules\CBT\Http\Controllers;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Assessment;
use App\Models\Score;
use App\Models\Student;
use App\Modules\CBT\Application\Services\CbtExamService;
use App\Modules\CBT\Application\Services\CbtQuestionService;
use App\Modules\CBT\Domain\Enums\AttemptStatus;
use App\Modules\CBT\Http\Requests\EnrollRequest;
use App\Modules\CBT\Http\Requests\GradeEssayRequest;
use App\Modules\CBT\Http\Requests\IncidentRequest;
use App\Modules\CBT\Http\Requests\PushScoreRequest;
use App\Modules\CBT\Http\Requests\SaveAnswerRequest;
use App\Modules\CBT\Http\Requests\StartAttemptRequest;
use App\Modules\CBT\Http\Requests\StoreBlueprintRequest;
use App\Modules\CBT\Http\Requests\StoreQuestionRequest;
use App\Modules\CBT\Http\Requests\StoreSessionRequest;
use App\Modules\CBT\Http\Requests\UpdateQuestionRequest;
use App\Modules\CBT\Models\CbtAnswer;
use App\Modules\CBT\Models\CbtAttempt;
use App\Modules\CBT\Models\CbtBlueprint;
use App\Modules\CBT\Models\CbtQuestion;
use App\Modules\CBT\Models\CbtSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CbtController extends BaseApiController
{
    public function __construct(
        private readonly CbtQuestionService $questions,
        private readonly CbtExamService $exams,
    ) {}

    public function dashboard(): JsonResponse
    {
        return $this->successResponse([
            'questions' => CbtQuestion::query()->count(),
            'blueprints' => CbtBlueprint::query()->count(),
            'sessions_open' => CbtSession::query()->where('status', 'open')->count(),
            'sessions_scheduled' => CbtSession::query()->where('status', 'scheduled')->count(),
            'attempts_live' => CbtAttempt::query()->where('status', AttemptStatus::IN_PROGRESS)->count(),
            'needs_grading' => CbtAttempt::query()->where('needs_manual_grade', true)->count(),
        ]);
    }

    public function questionsIndex(Request $request): JsonResponse
    {
        $rows = CbtQuestion::query()
            ->with('options')
            ->withCount('options')
            ->when($request->filled('subject_id'), fn ($q) => $q->where('subject_id', $request->integer('subject_id')))
            ->when($request->filled('topic'), fn ($q) => $q->where('topic', $request->string('topic')))
            ->when($request->filled('difficulty'), fn ($q) => $q->where('difficulty', $request->string('difficulty')))
            ->when($request->filled('question_type'), fn ($q) => $q->where('question_type', $request->string('question_type')))
            ->when($request->filled('q'), fn ($q) => $q->where('stem', 'like', '%'.$request->string('q').'%'))
            ->latest('id')
            ->paginate(min(100, $request->integer('per_page', 20)));

        return $this->successResponse($rows);
    }

    public function questionsStore(StoreQuestionRequest $request): JsonResponse
    {
        $data = $request->safe()->except('options');
        $question = $this->questions->create($data, $request->input('options', []), $request->user()?->id);

        return $this->createdResponse($this->presentQuestion($question, true));
    }

    public function questionsShow(CbtQuestion $question): JsonResponse
    {
        $question->load('options', 'revisions');

        return $this->successResponse($this->presentQuestion($question, true));
    }

    public function questionsUpdate(UpdateQuestionRequest $request, CbtQuestion $question): JsonResponse
    {
        $data = $request->safe()->except('options');
        $options = $request->exists('options') ? $request->input('options', []) : null;
        $question = $this->questions->update($question, $data, $options, $request->user()?->id);

        return $this->successResponse($this->presentQuestion($question, true), 'Soal diperbarui');
    }

    public function questionsDestroy(CbtQuestion $question): JsonResponse
    {
        $question->delete();

        return $this->successResponse(null, 'Soal diarsipkan');
    }

    public function importQuestions(Request $request): JsonResponse
    {
        $request->validate([
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'rows' => ['required', 'array', 'min:1', 'max:500'],
            'rows.*.stem' => ['required', 'string'],
            'rows.*.question_type' => ['required', 'string'],
            'rows.*.options' => ['nullable', 'array'],
            'rows.*.payload' => ['nullable', 'array'],
        ]);

        $created = 0;
        $errors = [];
        foreach ($request->input('rows') as $i => $row) {
            try {
                $this->questions->create([
                    'subject_id' => $row['subject_id'] ?? $request->input('subject_id'),
                    'question_type' => $row['question_type'],
                    'difficulty' => $row['difficulty'] ?? 'medium',
                    'topic' => $row['topic'] ?? null,
                    'stem' => $row['stem'],
                    'explanation' => $row['explanation'] ?? null,
                    'points' => $row['points'] ?? 1,
                    'payload' => $row['payload'] ?? null,
                ], $row['options'] ?? [], $request->user()?->id);
                $created++;
            } catch (ValidationException $e) {
                $errors[] = ['row' => $i + 1, 'messages' => $e->errors()];
            }
        }

        return $this->successResponse(['created' => $created, 'failed' => $errors], 'Impor selesai');
    }

    public function blueprintsIndex(Request $request): JsonResponse
    {
        $rows = CbtBlueprint::query()
            ->withCount(['rules', 'items'])
            ->when($request->filled('subject_id'), fn ($q) => $q->where('subject_id', $request->integer('subject_id')))
            ->latest('id')
            ->paginate(min(100, $request->integer('per_page', 20)));

        return $this->successResponse($rows);
    }

    public function blueprintsStore(StoreBlueprintRequest $request): JsonResponse
    {
        $blueprint = $this->exams->createBlueprint($request->safe()->except(['rules', 'items']), $request->user()?->id);
        $blueprint = $this->exams->syncBlueprint($blueprint, $request->input('rules', []), $request->input('items', []));

        return $this->createdResponse($blueprint);
    }

    public function blueprintsShow(CbtBlueprint $blueprint): JsonResponse
    {
        return $this->successResponse($blueprint->load(['rules', 'items.question']));
    }

    public function blueprintsUpdate(StoreBlueprintRequest $request, CbtBlueprint $blueprint): JsonResponse
    {
        $blueprint->fill($request->safe()->except(['rules', 'items', 'code']))->save();
        $blueprint = $this->exams->syncBlueprint($blueprint, $request->input('rules', []), $request->input('items', []));

        return $this->successResponse($blueprint, 'Kisi diperbarui');
    }

    public function sessionsIndex(Request $request): JsonResponse
    {
        $rows = CbtSession::query()
            ->with(['blueprint:id,name', 'subject:id,name', 'schoolClass:id,name'])
            ->withCount('participants')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest('id')
            ->paginate(min(100, $request->integer('per_page', 20)));

        return $this->successResponse($rows);
    }

    public function sessionsStore(StoreSessionRequest $request): JsonResponse
    {
        $session = $this->exams->createSession($request->validated(), $request->user()?->id);

        return $this->createdResponse($session);
    }

    public function sessionsShow(CbtSession $session): JsonResponse
    {
        $session->load(['blueprint', 'participants.student:id,full_name,nis', 'subject:id,name', 'schoolClass:id,name']);
        $session->loadCount(['attempts as live_attempts_count' => fn ($q) => $q->where('status', AttemptStatus::IN_PROGRESS)]);

        return $this->successResponse($session);
    }

    public function enroll(EnrollRequest $request, CbtSession $session): JsonResponse
    {
        $added = 0;
        if ($request->boolean('from_class')) {
            $added += $this->exams->enrollClass($session);
        }
        $added += $this->exams->enrollStudents($session, $request->input('student_ids', []));

        return $this->successResponse(['added' => $added, 'total' => $session->participants()->count()], 'Peserta diperbarui');
    }

    public function schedule(CbtSession $session): JsonResponse
    {
        return $this->successResponse($this->exams->schedule($session), 'Sesi dijadwalkan');
    }

    public function open(CbtSession $session): JsonResponse
    {
        return $this->successResponse($this->exams->open($session), 'Sesi dibuka');
    }

    public function close(CbtSession $session): JsonResponse
    {
        return $this->successResponse($this->exams->close($session), 'Sesi ditutup');
    }

    public function rotateToken(CbtSession $session): JsonResponse
    {
        $session = $this->exams->rotateToken($session);

        return $this->successResponse(['access_token' => $session->access_token], 'Token diganti');
    }

    public function release(CbtSession $session): JsonResponse
    {
        return $this->successResponse($this->exams->releaseResults($session), 'Hasil dirilis');
    }

    public function monitor(CbtSession $session): JsonResponse
    {
        $rows = $session->participants()->with('student:id,full_name,nis')->get()->map(function ($p) use ($session) {
            $attempt = CbtAttempt::query()
                ->where('session_id', $session->id)
                ->where('student_id', $p->student_id)
                ->latest('id')
                ->first();

            return [
                'participant_id' => $p->id,
                'student' => $p->student,
                'attendance' => $p->attendance,
                'eligible' => $p->is_eligible,
                'attempt' => $attempt ? [
                    'id' => $attempt->id,
                    'status' => $attempt->status,
                    'started_at' => $attempt->started_at,
                    'deadline_at' => $attempt->deadline_at,
                    'last_seen_at' => $attempt->last_seen_at,
                    'integrity_score' => $attempt->integrity_score,
                    'answered' => $attempt->answers()->whereNotNull('response')->count(),
                    'total' => $attempt->answers()->count(),
                ] : null,
            ];
        });

        return $this->successResponse(['session' => $session->only(['id', 'title', 'status', 'access_token', 'ends_at']), 'rows' => $rows]);
    }

    public function report(CbtSession $session): JsonResponse
    {
        $this->exams->recomputeStats($session);

        return $this->successResponse([
            'summary' => $this->exams->sessionReport($session),
            'items' => $session->itemStats()->with('question:id,stem,topic,difficulty')->get(),
        ]);
    }

    public function exportCsv(CbtSession $session): StreamedResponse
    {
        $attempts = $session->attempts()->with('student:id,full_name,nis')->orderBy('student_id')->get();
        $filename = 'cbt-'.$session->code.'.csv';

        return response()->streamDownload(function () use ($attempts) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['nis', 'nama', 'percobaan', 'status', 'skor', 'maks', 'persen', 'lulus', 'integritas']);
            foreach ($attempts as $attempt) {
                fputcsv($out, [
                    $attempt->student?->nis,
                    $attempt->student?->full_name,
                    $attempt->attempt_number,
                    $attempt->status,
                    $attempt->score,
                    $attempt->max_score,
                    $attempt->percentage,
                    $attempt->passed === null ? '' : ($attempt->passed ? 'ya' : 'tidak'),
                    $attempt->integrity_score,
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function pushScores(PushScoreRequest $request, CbtSession $session): JsonResponse
    {
        if (! $session->school_class_id || ! $session->subject_id || ! $session->semester_id) {
            throw ValidationException::withMessages(['session' => 'Sesi harus terikat rombel, mapel, dan semester untuk masuk rapor.']);
        }

        $assessment = DB::transaction(function () use ($request, $session) {
            $assessment = Assessment::create([
                'school_class_id' => $session->school_class_id,
                'subject_id' => $session->subject_id,
                'semester_id' => $session->semester_id,
                'employee_id' => $session->proctor_employee_id,
                'assessment_type_id' => $request->integer('assessment_type_id'),
                'name' => $request->input('name') ?: ('CBT '.$session->title),
                'description' => 'Diimpor dari sesi CBT '.$session->code,
                'max_score' => 100,
                'date' => optional($session->starts_at)->toDateString() ?? now()->toDateString(),
                'status' => 'draft',
                'extra' => ['cbt_session_id' => $session->id],
            ]);

            $best = $session->attempts()
                ->whereIn('status', [AttemptStatus::GRADED, AttemptStatus::SUBMITTED, AttemptStatus::EXPIRED])
                ->whereNotNull('percentage')
                ->orderByDesc('percentage')
                ->get()
                ->unique('student_id');

            foreach ($best as $attempt) {
                Score::updateOrCreate(
                    ['assessment_id' => $assessment->id, 'student_id' => $attempt->student_id],
                    ['score' => $attempt->percentage, 'notes' => 'CBT attempt #'.$attempt->id],
                );
            }

            return $assessment;
        });

        return $this->createdResponse([
            'assessment_id' => $assessment->id,
            'scores' => $assessment->scores()->count(),
        ], 'Nilai masuk ke modul penilaian');
    }

    public function mySessions(Request $request): JsonResponse
    {
        $student = $this->exams->studentOf($request->user());
        $rows = CbtSession::query()
            ->whereHas('participants', fn ($q) => $q->where('student_id', $student->id)->where('is_eligible', true))
            ->whereIn('status', ['scheduled', 'open', 'closed'])
            ->with('subject:id,name')
            ->latest('starts_at')
            ->get()
            ->map(fn (CbtSession $s) => $this->studentSessionCard($s, $student));

        return $this->successResponse($rows);
    }

    public function start(StartAttemptRequest $request, CbtSession $session): JsonResponse
    {
        $attempt = $this->exams->startAttempt(
            $session,
            $request->user(),
            $request->input('token'),
            $request->ip(),
            $request->userAgent(),
        );

        return $this->successResponse($this->playerPayload($attempt, false), 'Ujian dimulai');
    }

    public function player(Request $request, CbtAttempt $attempt): JsonResponse
    {
        $this->assertOwns($request, $attempt);

        return $this->successResponse($this->playerPayload($attempt, false));
    }

    public function save(SaveAnswerRequest $request, CbtAttempt $attempt, CbtQuestion $question): JsonResponse
    {
        $this->assertOwns($request, $attempt);
        $attempt->refresh();
        $answer = $this->exams->saveAnswer($attempt, $question->id, $request->input('response'), $request->has('flagged') ? $request->boolean('flagged') : null);

        return $this->successResponse($answer->only(['id', 'question_id', 'flagged', 'answered_at']), 'Tersimpan');
    }

    public function heartbeat(Request $request, CbtAttempt $attempt): JsonResponse
    {
        $this->assertOwns($request, $attempt);
        $attempt = $this->exams->heartbeat($attempt);

        return $this->successResponse([
            'status' => $attempt->status,
            'deadline_at' => $attempt->deadline_at,
            'server_now' => now(),
        ]);
    }

    public function incident(IncidentRequest $request, CbtAttempt $attempt): JsonResponse
    {
        $this->assertOwns($request, $attempt);
        $row = $this->exams->reportIncident($attempt, $request->string('type'), $request->input('note'), $request->user()->id);

        return $this->successResponse($row->only(['id', 'type', 'severity']), 'Insiden tercatat');
    }

    public function submit(Request $request, CbtAttempt $attempt): JsonResponse
    {
        $this->assertOwns($request, $attempt);
        $attempt = $this->exams->submit($attempt);
        $session = $attempt->session;
        $show = (bool) (($session->settings['show_score'] ?? false) || $session->results_released_at);

        return $this->successResponse([
            'status' => $attempt->status,
            'needs_manual_grade' => $attempt->needs_manual_grade,
            'score' => $show ? $attempt->score : null,
            'max_score' => $show ? $attempt->max_score : null,
            'percentage' => $show ? $attempt->percentage : null,
            'passed' => $show ? $attempt->passed : null,
        ], 'Ujian dikumpulkan');
    }

    public function review(Request $request, CbtAttempt $attempt): JsonResponse
    {
        $user = $request->user();
        $staff = $user->can('cbt.grade') || $user->can('cbt.manage') || $user->hasRole('super-admin');
        if (! $staff) {
            $this->assertOwns($request, $attempt);
            $session = $attempt->session;
            if (! $session->results_released_at && ! ($session->settings['show_score'] ?? false)) {
                throw ValidationException::withMessages(['results' => 'Hasil belum dirilis.']);
            }
        }

        $attempt->load(['answers.question.options', 'student:id,full_name,nis', 'incidents']);

        return $this->successResponse([
            'attempt' => $attempt->only(['id', 'status', 'score', 'max_score', 'percentage', 'passed', 'needs_manual_grade', 'integrity_score', 'submitted_at']),
            'student' => $attempt->student,
            'answers' => $attempt->answers->map(fn (CbtAnswer $a) => [
                'id' => $a->id,
                'question_id' => $a->question_id,
                'stem' => $a->question?->stem,
                'type' => $a->question?->question_type,
                'response' => $a->response,
                'awarded' => $a->awarded,
                'max_points' => $a->max_points,
                'is_correct' => $a->is_correct,
                'auto_graded' => $a->auto_graded,
                'explanation' => $staff || $attempt->session->results_released_at ? $a->question?->explanation : null,
                'correct_keys' => $staff ? $a->question?->options->where('is_correct', true)->pluck('option_key')->values() : null,
            ]),
            'incidents' => $staff ? $attempt->incidents : [],
        ]);
    }

    public function gradeQueue(Request $request): JsonResponse
    {
        $rows = CbtAnswer::query()
            ->whereNull('awarded')
            ->whereHas('question', fn ($q) => $q->where('question_type', 'essay'))
            ->whereHas('attempt', fn ($q) => $q->whereIn('status', [AttemptStatus::SUBMITTED, AttemptStatus::EXPIRED, AttemptStatus::GRADED]))
            ->with(['question:id,stem,points', 'attempt.student:id,full_name,nis', 'attempt.session:id,title'])
            ->latest('id')
            ->paginate(30);

        return $this->successResponse($rows);
    }

    public function gradeEssay(GradeEssayRequest $request, CbtAnswer $answer): JsonResponse
    {
        $answer = $this->exams->gradeEssay($answer, (float) $request->input('awarded'), $request->input('note'), $request->user()->id);

        return $this->successResponse($answer, 'Nilai esai tersimpan');
    }

    public function voidAttempt(Request $request, CbtAttempt $attempt): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $attempt = $this->exams->voidAttempt($attempt, $request->string('reason'), $request->user()->id);

        return $this->successResponse($attempt->only(['id', 'status']), 'Percobaan dibatalkan');
    }

    private function assertOwns(Request $request, CbtAttempt $attempt): void
    {
        $student = $this->exams->studentOf($request->user());
        if ((int) $attempt->student_id !== (int) $student->id) {
            abort(403, 'Percobaan ini bukan milik Anda.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function playerPayload(CbtAttempt $attempt, bool $reveal): array
    {
        $attempt->loadMissing(['answers.question.options', 'session.blueprint']);
        $orders = $attempt->option_orders ?? [];

        $questions = $attempt->answers->map(function (CbtAnswer $answer) use ($orders, $reveal) {
            $question = $answer->question;
            $keys = $orders[(string) $answer->question_id] ?? $question->options->pluck('option_key')->all();
            $byKey = $question->options->keyBy('option_key');

            return [
                'id' => $question->id,
                'type' => $question->question_type,
                'stem' => $question->stem,
                'points' => $answer->max_points,
                'flagged' => $answer->flagged,
                'response' => $answer->response,
                'options' => collect($keys)->map(function ($key) use ($byKey, $reveal) {
                    $option = $byKey->get($key);
                    if (! $option) {
                        return null;
                    }

                    return [
                        'key' => $option->option_key,
                        'text' => $option->text,
                        'is_correct' => $reveal ? $option->is_correct : null,
                    ];
                })->filter()->values(),
                'payload_public' => $this->publicPayload($question),
            ];
        });

        $orderedIds = $attempt->question_order ?? [];
        if ($orderedIds) {
            $questions = $questions->sortBy(fn ($q) => array_search($q['id'], $orderedIds, true))->values();
        }

        return [
            'attempt' => $attempt->only(['id', 'status', 'started_at', 'deadline_at', 'attempt_number', 'integrity_score']),
            'server_now' => now(),
            'session' => [
                'id' => $attempt->session_id,
                'title' => $attempt->session->title,
                'lock_navigation' => (bool) ($attempt->session->settings['lock_navigation'] ?? false),
                'instructions' => $attempt->session->blueprint->instructions,
            ],
            'questions' => $questions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publicPayload(CbtQuestion $question): array
    {
        $payload = $question->payload ?? [];

        return match ($question->question_type) {
            'numeric' => ['tolerance' => $payload['tolerance'] ?? 0],
            'matching' => ['prompts' => collect($payload['prompts'] ?? [])->values()],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function presentQuestion(CbtQuestion $question, bool $withKey): array
    {
        $data = $question->toArray();
        if (! $withKey) {
            unset($data['payload']);
            $data['options'] = collect($data['options'] ?? [])->map(fn ($o) => collect($o)->except(['is_correct', 'match_key']))->all();
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function studentSessionCard(CbtSession $session, Student $student): array
    {
        $attempt = CbtAttempt::query()
            ->where('session_id', $session->id)
            ->where('student_id', $student->id)
            ->latest('id')
            ->first();
        $show = (bool) $session->results_released_at;

        return [
            'id' => $session->id,
            'title' => $session->title,
            'code' => $session->code,
            'status' => $session->status,
            'starts_at' => $session->starts_at,
            'ends_at' => $session->ends_at,
            'subject' => $session->subject?->name,
            'require_token' => (bool) (($session->settings['require_token'] ?? true)),
            'attempt' => $attempt ? [
                'id' => $attempt->id,
                'status' => $attempt->status,
                'percentage' => $show ? $attempt->percentage : null,
                'passed' => $show ? $attempt->passed : null,
            ] : null,
        ];
    }
}
