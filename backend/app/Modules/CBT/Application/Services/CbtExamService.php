<?php

namespace App\Modules\CBT\Application\Services;

use App\Models\ClassStudent;
use App\Models\Student;
use App\Models\User;
use App\Modules\CBT\Domain\Enums\AttemptStatus;
use App\Modules\CBT\Domain\Enums\IncidentType;
use App\Modules\CBT\Domain\Enums\QuestionType;
use App\Modules\CBT\Domain\Enums\SessionStatus;
use App\Modules\CBT\Models\CbtAnswer;
use App\Modules\CBT\Models\CbtAttempt;
use App\Modules\CBT\Models\CbtBlueprint;
use App\Modules\CBT\Models\CbtIncident;
use App\Modules\CBT\Models\CbtItemStat;
use App\Modules\CBT\Models\CbtParticipant;
use App\Modules\CBT\Models\CbtQuestion;
use App\Modules\CBT\Models\CbtSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CbtExamService
{
    public function __construct(
        private readonly CbtAssemblyService $assembly,
        private readonly CbtScoringService $scoring,
    ) {}

    public function createBlueprint(array $data, ?int $userId): CbtBlueprint
    {
        return CbtBlueprint::create([
            ...$data,
            'code' => $data['code'] ?? $this->uniqueCode('KISI'),
            'created_by' => $userId,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     * @param  list<array<string, mixed>>  $items
     */
    public function syncBlueprint(CbtBlueprint $blueprint, array $rules, array $items): CbtBlueprint
    {
        return DB::transaction(function () use ($blueprint, $rules, $items) {
            $blueprint->rules()->delete();
            foreach (array_values($rules) as $i => $rule) {
                $blueprint->rules()->create([
                    'topic' => $rule['topic'] ?? null,
                    'difficulty' => $rule['difficulty'] ?? null,
                    'question_type' => $rule['question_type'] ?? null,
                    'count' => (int) ($rule['count'] ?? 1),
                    'points' => $rule['points'] ?? null,
                    'position' => $i,
                ]);
            }

            $blueprint->items()->delete();
            foreach (array_values($items) as $i => $item) {
                $blueprint->items()->create([
                    'question_id' => $item['question_id'],
                    'points' => $item['points'] ?? null,
                    'required' => (bool) ($item['required'] ?? true),
                    'position' => $i,
                ]);
            }

            if ((int) $blueprint->question_count < 1) {
                $blueprint->question_count = max(count($items), collect($rules)->sum('count'));
                $blueprint->save();
            }

            return $blueprint->fresh(['rules', 'items.question']);
        });
    }

    public function createSession(array $data, ?int $userId): CbtSession
    {
        $blueprint = CbtBlueprint::query()->findOrFail($data['blueprint_id']);

        return CbtSession::create([
            'title' => $data['title'],
            'code' => $data['code'] ?? $this->uniqueCode('CBT'),
            'blueprint_id' => $blueprint->id,
            'subject_id' => $data['subject_id'] ?? $blueprint->subject_id,
            'school_class_id' => $data['school_class_id'] ?? null,
            'semester_id' => $data['semester_id'] ?? null,
            'room_id' => $data['room_id'] ?? null,
            'proctor_employee_id' => $data['proctor_employee_id'] ?? null,
            'status' => SessionStatus::DRAFT,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'access_token' => $this->token(),
            'settings' => $data['settings'] ?? [
                'require_token' => true,
                'show_score' => false,
                'lock_navigation' => false,
                'max_blur' => 5,
            ],
            'created_by' => $userId,
        ]);
    }

    public function enrollClass(CbtSession $session): int
    {
        if (! $session->school_class_id) {
            throw ValidationException::withMessages(['school_class_id' => 'Sesi tidak terikat rombel.']);
        }

        $yearId = $session->schoolClass()->value('academic_year_id');
        $studentIds = ClassStudent::query()
            ->where('school_class_id', $session->school_class_id)
            ->where('status', 'aktif')
            ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))
            ->pluck('student_id');

        $added = 0;
        foreach ($studentIds as $studentId) {
            $row = CbtParticipant::firstOrCreate(
                ['session_id' => $session->id, 'student_id' => $studentId],
                ['is_eligible' => true, 'attendance' => 'expected'],
            );
            if ($row->wasRecentlyCreated) {
                $added++;
            }
        }

        return $added;
    }

    /**
     * @param  list<int>  $studentIds
     */
    public function enrollStudents(CbtSession $session, array $studentIds): int
    {
        $added = 0;
        foreach (array_unique($studentIds) as $studentId) {
            if (! Student::query()->whereKey($studentId)->exists()) {
                continue;
            }
            $row = CbtParticipant::firstOrCreate(
                ['session_id' => $session->id, 'student_id' => $studentId],
                ['is_eligible' => true, 'attendance' => 'expected'],
            );
            $added += $row->wasRecentlyCreated ? 1 : 0;
        }

        return $added;
    }

    public function open(CbtSession $session): CbtSession
    {
        if (! in_array($session->status, [SessionStatus::DRAFT, SessionStatus::SCHEDULED, SessionStatus::CLOSED], true)) {
            throw ValidationException::withMessages(['status' => 'Sesi tidak dapat dibuka dari status '.$session->status.'.']);
        }
        if ($session->participants()->count() < 1) {
            throw ValidationException::withMessages(['participants' => 'Daftarkan peserta sebelum membuka sesi.']);
        }

        $session->forceFill([
            'status' => SessionStatus::OPEN,
            'opened_at' => $session->opened_at ?? now(),
            'access_token' => $session->access_token ?: $this->token(),
        ])->save();

        return $session->fresh();
    }

    public function schedule(CbtSession $session): CbtSession
    {
        if ($session->status !== SessionStatus::DRAFT) {
            throw ValidationException::withMessages(['status' => 'Hanya draf yang dapat dijadwalkan.']);
        }
        $session->forceFill(['status' => SessionStatus::SCHEDULED])->save();

        return $session;
    }

    public function close(CbtSession $session): CbtSession
    {
        return DB::transaction(function () use ($session) {
            $session->attempts()->where('status', AttemptStatus::IN_PROGRESS)->each(function (CbtAttempt $attempt) {
                $this->finalize($attempt, AttemptStatus::EXPIRED, 'Sesi ditutup pengawas.');
            });
            $session->forceFill([
                'status' => SessionStatus::CLOSED,
                'closed_at' => now(),
            ])->save();
            $this->recomputeStats($session);

            return $session->fresh();
        });
    }

    public function rotateToken(CbtSession $session): CbtSession
    {
        $session->forceFill(['access_token' => $this->token()])->save();

        return $session;
    }

    public function releaseResults(CbtSession $session): CbtSession
    {
        if (! in_array($session->status, [SessionStatus::CLOSED, SessionStatus::ARCHIVED], true)) {
            throw ValidationException::withMessages(['status' => 'Hasil hanya dapat dirilis setelah sesi ditutup.']);
        }
        $session->forceFill(['results_released_at' => now()])->save();

        return $session;
    }

    public function startAttempt(CbtSession $session, User $user, ?string $token, ?string $ip, ?string $agent): CbtAttempt
    {
        $student = $this->studentOf($user);
        $this->assertWindow($session);

        $settings = $session->settings ?? [];
        if (($settings['require_token'] ?? true) && ! hash_equals((string) $session->access_token, strtoupper(trim((string) $token)))) {
            CbtIncident::create([
                'session_id' => $session->id,
                'type' => IncidentType::TOKEN_REJECTED,
                'severity' => 2,
                'note' => 'Token ditolak untuk siswa #'.$student->id,
                'reported_by' => $user->id,
            ]);
            throw ValidationException::withMessages(['token' => 'Token ujian tidak valid.']);
        }

        $participant = CbtParticipant::query()
            ->where('session_id', $session->id)
            ->where('student_id', $student->id)
            ->first();

        if (! $participant || ! $participant->is_eligible) {
            throw ValidationException::withMessages(['student' => 'Anda tidak terdaftar atau tidak berhak mengikuti ujian ini.']);
        }

        $open = CbtAttempt::query()
            ->where('session_id', $session->id)
            ->where('student_id', $student->id)
            ->where('status', AttemptStatus::IN_PROGRESS)
            ->first();
        if ($open) {
            if ($open->deadline_at && $open->deadline_at->isPast()) {
                $this->finalize($open, AttemptStatus::EXPIRED, 'Waktu habis saat masuk kembali.');
            } else {
                $open->forceFill(['last_seen_at' => now()])->save();

                return $open->fresh(['answers']);
            }
        }

        $blueprint = $session->blueprint()->firstOrFail();
        $used = CbtAttempt::query()
            ->where('session_id', $session->id)
            ->where('student_id', $student->id)
            ->where('status', '!=', AttemptStatus::VOIDED)
            ->count();
        if ($used >= (int) $blueprint->attempts_allowed) {
            throw ValidationException::withMessages(['attempt' => 'Kesempatan mengerjakan sudah habis.']);
        }

        return DB::transaction(function () use ($session, $participant, $student, $blueprint, $user, $ip, $agent, $used) {
            $seed = crc32($session->id.'-'.$student->id.'-'.($used + 1).'-'.$session->code);
            $pack = $this->assembly->assemble($blueprint, $seed);
            if ($blueprint->shuffle_questions) {
                $pack = $pack->shuffle(fn () => ($seed % 97) / 100);
                $pack = collect($this->seededShuffle($pack->all(), $seed));
            }

            $order = [];
            $optionOrders = [];
            foreach ($pack->values() as $row) {
                /** @var CbtQuestion $question */
                $question = $row['question'];
                $order[] = $question->id;
                $keys = $question->options->pluck('option_key')->all();
                $optionOrders[(string) $question->id] = $blueprint->shuffle_options
                    ? $this->assembly->shuffleKeys($keys, $seed + $question->id)
                    : array_values($keys);
            }

            $minutes = (int) $blueprint->duration_minutes + (int) $participant->extra_minutes;
            $deadline = now()->addMinutes(max(1, $minutes));
            if ($session->ends_at && $session->ends_at->lt($deadline)) {
                $deadline = $session->ends_at->copy();
            }

            $attempt = CbtAttempt::create([
                'session_id' => $session->id,
                'participant_id' => $participant->id,
                'student_id' => $student->id,
                'attempt_number' => $used + 1,
                'status' => AttemptStatus::IN_PROGRESS,
                'started_at' => now(),
                'deadline_at' => $deadline,
                'last_seen_at' => now(),
                'question_order' => $order,
                'option_orders' => $optionOrders,
                'max_score' => round($pack->sum('points'), 2),
                'client_ip' => $ip,
                'user_agent' => $agent ? Str::limit($agent, 250, '') : null,
            ]);

            foreach ($pack as $row) {
                CbtAnswer::create([
                    'attempt_id' => $attempt->id,
                    'question_id' => $row['question']->id,
                    'question_version' => $row['question']->version,
                    'max_points' => $row['points'],
                    'response' => null,
                ]);
            }

            $participant->forceFill([
                'attendance' => 'present',
                'checked_in_at' => $participant->checked_in_at ?? now(),
            ])->save();

            return $attempt->fresh(['answers.question.options']);
        });
    }

    /**
     * @param  array<string, mixed>|null  $response
     */
    public function saveAnswer(CbtAttempt $attempt, int $questionId, ?array $response, ?bool $flagged): CbtAnswer
    {
        $this->assertWritable($attempt);
        $answer = $attempt->answers()->where('question_id', $questionId)->first();
        if (! $answer) {
            throw ValidationException::withMessages(['question_id' => 'Soal tidak ada pada paket percobaan ini.']);
        }

        $answer->forceFill([
            'response' => $response,
            'flagged' => $flagged ?? $answer->flagged,
            'answered_at' => $response === null ? $answer->answered_at : now(),
        ])->save();
        $attempt->forceFill(['last_seen_at' => now()])->save();

        return $answer->fresh();
    }

    public function heartbeat(CbtAttempt $attempt): CbtAttempt
    {
        if ($attempt->status !== AttemptStatus::IN_PROGRESS) {
            return $attempt;
        }
        if ($attempt->deadline_at && $attempt->deadline_at->isPast()) {
            return $this->finalize($attempt, AttemptStatus::EXPIRED, 'Waktu habis.');
        }

        $gap = $attempt->last_seen_at ? $attempt->last_seen_at->diffInSeconds(now()) : 0;
        $attempt->forceFill(['last_seen_at' => now()])->save();
        if ($gap > 90) {
            $this->logIncident($attempt, IncidentType::HEARTBEAT_GAP, 1, 'Jeda heartbeat '.$gap.' detik.');
        }

        return $attempt->fresh();
    }

    public function reportIncident(CbtAttempt $attempt, string $type, ?string $note, ?int $userId): CbtIncident
    {
        if (! in_array($type, IncidentType::all(), true)) {
            throw ValidationException::withMessages(['type' => 'Jenis insiden tidak dikenal.']);
        }
        $severity = match ($type) {
            IncidentType::MANUAL_FLAG, IncidentType::COPY_ATTEMPT => 3,
            IncidentType::FULLSCREEN_EXIT, IncidentType::TOKEN_REJECTED => 2,
            default => 1,
        };

        return $this->logIncident($attempt, $type, $severity, $note, $userId);
    }

    public function submit(CbtAttempt $attempt, bool $forced = false): CbtAttempt
    {
        if (in_array($attempt->status, AttemptStatus::terminal(), true)) {
            return $attempt->fresh(['answers']);
        }
        if (! $forced && $attempt->deadline_at && $attempt->deadline_at->copy()->addSeconds(15)->isPast()) {
            return $this->finalize($attempt, AttemptStatus::EXPIRED, 'Waktu habis saat pengumpulan.');
        }

        return $this->finalize($attempt, AttemptStatus::SUBMITTED, null);
    }

    public function gradeEssay(CbtAnswer $answer, float $awarded, ?string $note, int $userId): CbtAnswer
    {
        $attempt = $answer->attempt()->firstOrFail();
        if (! in_array($attempt->status, [AttemptStatus::SUBMITTED, AttemptStatus::EXPIRED, AttemptStatus::GRADED], true)) {
            throw ValidationException::withMessages(['status' => 'Esai hanya dinilai setelah percobaan dikumpulkan.']);
        }
        $max = (float) $answer->max_points;
        if ($awarded < 0 || $awarded > $max + 0.001) {
            throw ValidationException::withMessages(['awarded' => 'Nilai harus di antara 0 dan '.$max.'.']);
        }

        $answer->forceFill([
            'awarded' => round($awarded, 2),
            'is_correct' => $max > 0 && $awarded >= $max - 0.001,
            'auto_graded' => false,
            'grader_note' => $note,
            'graded_by' => $userId,
            'graded_at' => now(),
        ])->save();

        $this->recomputeAttempt($attempt->fresh('answers'));

        return $answer->fresh();
    }

    public function voidAttempt(CbtAttempt $attempt, string $reason, int $userId): CbtAttempt
    {
        $attempt->forceFill(['status' => AttemptStatus::VOIDED, 'passed' => null])->save();
        $this->logIncident($attempt, IncidentType::MANUAL_FLAG, 3, 'Dibatalkan: '.$reason, $userId);

        return $attempt;
    }

    public function recomputeStats(CbtSession $session): void
    {
        $attempts = $session->attempts()
            ->whereIn('status', [AttemptStatus::SUBMITTED, AttemptStatus::EXPIRED, AttemptStatus::GRADED])
            ->with('answers')
            ->get();

        $byQuestion = [];
        foreach ($attempts as $attempt) {
            foreach ($attempt->answers as $answer) {
                $byQuestion[$answer->question_id][] = $answer;
            }
        }

        $totals = $attempts->map(fn (CbtAttempt $a) => (float) ($a->percentage ?? 0))->sort()->values();
        $cut = (int) floor($totals->count() * 0.27);
        $lowIds = $cut > 0
            ? $attempts->sortBy('percentage')->take($cut)->pluck('id')->all()
            : [];
        $highIds = $cut > 0
            ? $attempts->sortByDesc('percentage')->take($cut)->pluck('id')->all()
            : [];

        foreach ($byQuestion as $questionId => $answers) {
            $n = count($answers);
            $correct = collect($answers)->where('is_correct', true)->count();
            $avg = collect($answers)->avg(fn ($a) => (float) ($a->awarded ?? 0));
            $disc = null;
            if ($cut > 0) {
                $high = collect($answers)->whereIn('attempt_id', $highIds);
                $low = collect($answers)->whereIn('attempt_id', $lowIds);
                $pHigh = $high->count() ? $high->where('is_correct', true)->count() / $high->count() : 0;
                $pLow = $low->count() ? $low->where('is_correct', true)->count() / $low->count() : 0;
                $disc = round($pHigh - $pLow, 4);
            }

            CbtItemStat::updateOrCreate(
                ['session_id' => $session->id, 'question_id' => $questionId],
                [
                    'attempts' => $n,
                    'correct' => $correct,
                    'p_value' => $n ? round($correct / $n, 4) : null,
                    'discrimination' => $disc,
                    'avg_points' => round((float) $avg, 2),
                ],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function sessionReport(CbtSession $session): array
    {
        $attempts = $session->attempts()
            ->whereIn('status', [AttemptStatus::SUBMITTED, AttemptStatus::EXPIRED, AttemptStatus::GRADED])
            ->get();
        $scores = $attempts->map(fn ($a) => (float) ($a->percentage ?? 0));
        $mean = $scores->count() ? round($scores->avg(), 2) : null;
        $variance = $scores->count() > 1
            ? $scores->map(fn ($s) => ($s - $mean) ** 2)->avg()
            : 0;

        return [
            'participants' => $session->participants()->count(),
            'submitted' => $attempts->count(),
            'in_progress' => $session->attempts()->where('status', AttemptStatus::IN_PROGRESS)->count(),
            'passed' => $attempts->where('passed', true)->count(),
            'mean_percentage' => $mean,
            'stdev' => $scores->count() > 1 ? round(sqrt($variance), 2) : null,
            'highest' => $scores->count() ? round($scores->max(), 2) : null,
            'lowest' => $scores->count() ? round($scores->min(), 2) : null,
            'incidents' => $session->attempts()->withCount('incidents')->get()->sum('incidents_count')
                + CbtIncident::query()->where('session_id', $session->id)->whereNull('attempt_id')->count(),
            'needs_manual' => $attempts->where('needs_manual_grade', true)->count(),
        ];
    }

    public function studentOf(User $user): Student
    {
        if ($user->userable_type !== Student::class || ! $user->userable_id) {
            throw ValidationException::withMessages(['user' => 'Akun ini tidak tertaut ke data siswa.']);
        }
        $student = Student::query()->find($user->userable_id);
        if (! $student) {
            throw ValidationException::withMessages(['user' => 'Data siswa tidak ditemukan.']);
        }

        return $student;
    }

    private function assertWindow(CbtSession $session): void
    {
        if ($session->status !== SessionStatus::OPEN) {
            throw ValidationException::withMessages(['session' => 'Sesi ujian belum dibuka.']);
        }
        if ($session->starts_at && $session->starts_at->isFuture()) {
            throw ValidationException::withMessages(['session' => 'Ujian belum dimulai.']);
        }
        if ($session->ends_at && $session->ends_at->isPast()) {
            throw ValidationException::withMessages(['session' => 'Jendela ujian sudah berakhir.']);
        }
    }

    private function assertWritable(CbtAttempt $attempt): void
    {
        $attempt->refresh();
        if ($attempt->status !== AttemptStatus::IN_PROGRESS) {
            throw ValidationException::withMessages(['attempt' => 'Percobaan sudah ditutup.']);
        }
        $rawDeadline = $attempt->getRawOriginal('deadline_at') ?? $attempt->deadline_at;
        if ($rawDeadline !== null && now()->gte(\Illuminate\Support\Carbon::parse($rawDeadline))) {
            $fresh = CbtAttempt::query()->findOrFail($attempt->id);
            if ($fresh->status === AttemptStatus::IN_PROGRESS) {
                $this->finalize($fresh, AttemptStatus::EXPIRED, 'Waktu habis.');
            }
            throw ValidationException::withMessages(['attempt' => 'Waktu pengerjaan sudah habis.']);
        }
        $session = $attempt->session;
        if ($session && $session->status !== SessionStatus::OPEN) {
            throw ValidationException::withMessages(['session' => 'Sesi sudah ditutup pengawas.']);
        }
    }

    private function finalize(CbtAttempt $attempt, string $status, ?string $note): CbtAttempt
    {
        return DB::transaction(function () use ($attempt, $status, $note) {
            $attempt = CbtAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if (in_array($attempt->status, AttemptStatus::terminal(), true)) {
                return $attempt;
            }

            $blueprint = $attempt->session->blueprint;
            $negative = (bool) $blueprint->negative_marking;
            $fraction = (float) $blueprint->negative_fraction;
            $needsManual = false;

            foreach ($attempt->answers()->with('question.options')->get() as $answer) {
                $question = $answer->question;
                if (! $question) {
                    continue;
                }
                $result = $this->scoring->grade(
                    $question,
                    $answer->response,
                    (float) $answer->max_points,
                    $negative,
                    $fraction,
                );
                if (! $result['auto']) {
                    $needsManual = true;
                    $answer->forceFill([
                        'auto_graded' => false,
                        'awarded' => null,
                        'is_correct' => null,
                        'max_points' => $result['max'],
                    ])->save();
                    continue;
                }
                $answer->forceFill([
                    'auto_graded' => true,
                    'awarded' => $result['awarded'],
                    'is_correct' => $result['correct'],
                    'graded_at' => now(),
                ])->save();
            }

            if ($note && $status === AttemptStatus::EXPIRED) {
                $this->logIncident($attempt, IncidentType::LATE_SUBMIT, 1, $note);
            }

            $attempt->forceFill([
                'status' => $status,
                'submitted_at' => $attempt->submitted_at ?? now(),
                'needs_manual_grade' => $needsManual,
            ])->save();

            return $this->recomputeAttempt($attempt->fresh('answers'));
        });
    }

    private function recomputeAttempt(CbtAttempt $attempt): CbtAttempt
    {
        $answers = $attempt->answers;
        $auto = $answers->where('auto_graded', true);
        $pending = $answers->contains(fn ($a) => $a->question && $a->question->question_type === QuestionType::ESSAY && $a->awarded === null);
        $score = round((float) $answers->sum(fn ($a) => (float) ($a->awarded ?? 0)), 2);
        $max = round((float) ($attempt->max_score ?: $answers->sum('max_points')), 2);
        $percentage = $max > 0 ? round(($score / $max) * 100, 2) : 0;
        $passing = (float) ($attempt->session->blueprint->passing_score ?? 0);

        $status = $attempt->status;
        if (! in_array($status, [AttemptStatus::EXPIRED, AttemptStatus::VOIDED], true)) {
            $status = $pending ? AttemptStatus::SUBMITTED : AttemptStatus::GRADED;
        }

        $attempt->forceFill([
            'score' => $score,
            'max_score' => $max,
            'percentage' => $percentage,
            'passed' => $pending ? null : $percentage >= $passing,
            'needs_manual_grade' => $pending,
            'status' => $status,
        ])->save();

        return $attempt->fresh();
    }

    private function logIncident(CbtAttempt $attempt, string $type, int $severity, ?string $note, ?int $userId = null): CbtIncident
    {
        $incident = CbtIncident::create([
            'session_id' => $attempt->session_id,
            'attempt_id' => $attempt->id,
            'type' => $type,
            'severity' => $severity,
            'note' => $note,
            'reported_by' => $userId,
        ]);
        $penalty = $severity * 4;
        $attempt->forceFill([
            'integrity_score' => max(0, (int) $attempt->integrity_score - $penalty),
        ])->save();

        return $incident;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<mixed>
     */
    private function seededShuffle(array $items, int $seed): array
    {
        $n = count($items);
        for ($i = $n - 1; $i > 0; $i--) {
            $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
            $j = $seed % ($i + 1);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return $items;
    }

    private function token(): string
    {
        return strtoupper(Str::random(6));
    }

    private function uniqueCode(string $prefix): string
    {
        return $prefix.'-'.now()->format('ymd').'-'.strtoupper(Str::random(4));
    }
}
