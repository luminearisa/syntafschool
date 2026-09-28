import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Textarea } from '@/components/ui/Textarea';
import { activityApi } from '@/features/lms/api/activityApi';
import { formatDate } from '@/features/lms/lib/lmsLabels';
import { sanitizeHtml } from '@/features/lms/lib/sanitizeHtml';
import type { QuizAttemptPayload, QuizAttemptQuestion, QuizStudentView } from '@/features/lms/types.phase2';
import { AlertTriangle, CheckCircle2, Clock, PlayCircle, Send, XCircle } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Pengerjaan kuis (§12, §25–§29).
 *
 * Timer dihitung **server** (`seconds_remaining` dari API) dan disinkronkan
 * berkala; hitungan JavaScript hanya untuk tampilan. Setiap jawaban langsung
 * disimpan sehingga waktu habis tidak menghilangkan pekerjaan siswa.
 */
export function QuizPlayer({
  activityId,
  view,
  onChanged,
}: {
  activityId: number;
  view: QuizStudentView;
  onChanged: () => void;
}) {
  const [attempt, setAttempt] = useState<QuizAttemptPayload | null>(null);
  const [secondsLeft, setSecondsLeft] = useState<number | null>(null);
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [currentIndex, setCurrentIndex] = useState(0);
  const [drafts, setDrafts] = useState<Record<number, Record<string, unknown>>>({});
  const syncRef = useRef<number | null>(null);

  const quiz = view.quiz;
  const inProgress = attempt?.attempt.status === 'IN_PROGRESS';
  // Percobaan terakhir yang sudah selesai → tombol "Lihat hasil" (§29).
  const lastFinished = [...view.attempts].reverse().find((item) => item.status !== 'IN_PROGRESS') ?? null;
  const finished = attempt ? attempt.attempt.status !== 'IN_PROGRESS' : false;

  const loadAttempt = useCallback(
    async (attemptId: number) => {
      const payload = await activityApi.attempt(attemptId);
      setAttempt(payload);
      setSecondsLeft(payload.attempt.seconds_remaining);
      return payload;
    },
    [],
  );

  useEffect(() => {
    if (view.open_attempt_id) {
      void loadAttempt(view.open_attempt_id).catch(() => undefined);
    }
  }, [view.open_attempt_id, loadAttempt]);

  // Timer tampilan: disinkronkan ke server setiap 30 detik.
  useEffect(() => {
    if (!inProgress || secondsLeft === null) return;

    const timer = window.setInterval(() => {
      setSecondsLeft((value) => (value === null ? null : Math.max(0, value - 1)));
    }, 1000);

    syncRef.current = window.setInterval(() => {
      if (attempt) void loadAttempt(attempt.attempt.id).catch(() => undefined);
    }, 30000);

    return () => {
      window.clearInterval(timer);
      if (syncRef.current) window.clearInterval(syncRef.current);
    };
  }, [inProgress, secondsLeft === null, attempt, loadAttempt]);

  // Waktu habis → server yang memutuskan; klien hanya meminta hasil terbaru.
  useEffect(() => {
    if (inProgress && secondsLeft === 0 && attempt) {
      void activityApi
        .attempt(attempt.attempt.id)
        .then((payload) => {
          setAttempt(payload);
          setSecondsLeft(payload.attempt.seconds_remaining);
          onChanged();
        })
        .catch(() => undefined);
    }
  }, [secondsLeft, inProgress, attempt, onChanged]);

  const questions = attempt?.questions ?? [];
  const current = questions[currentIndex];

  const answered = useMemo(() => {
    const map: Record<number, boolean> = {};
    questions.forEach((question) => {
      const answer = question.answer;
      map[question.question_id] = Boolean(answer && (answer.answer || answer.answer_data));
    });
    return map;
  }, [questions]);

  async function handleStart() {
    setBusy(true);
    setError(null);
    try {
      const payload = await activityApi.startAttempt(activityId, quiz.require_password ? password : undefined);
      setAttempt(payload);
      setSecondsLeft(payload.attempt.seconds_remaining);
      setCurrentIndex(0);
      onChanged();
    } catch (err) {
      const detail = (err as { response?: { data?: { message?: string } } }).response?.data?.message;
      setError(detail ?? 'Tidak dapat memulai kuis.');
    } finally {
      setBusy(false);
    }
  }

  async function persist(question: QuizAttemptQuestion, data: Record<string, unknown>) {
    if (!attempt) return;
    setDrafts((prev) => ({ ...prev, [question.question_id]: data }));
    try {
      await activityApi.saveAnswer(
        attempt.attempt.id,
        question.question_id,
        data,
        typeof data.text === 'string' ? (data.text as string) : undefined,
      );
    } catch (err) {
      const detail = (err as { response?: { data?: { message?: string } } }).response?.data?.message;
      setError(detail ?? 'Jawaban gagal disimpan.');
    }
  }

  function currentAnswerValue(question: QuizAttemptQuestion): Record<string, unknown> {
    return (drafts[question.question_id] ?? (question.answer?.answer_data as Record<string, unknown>) ?? {}) as Record<string, unknown>;
  }

  async function handleSubmit() {
    if (!attempt) return;
    setBusy(true);
    setError(null);
    try {
      const payload = await activityApi.submitAttempt(attempt.attempt.id);
      setAttempt(payload);
      setSecondsLeft(0);
      onChanged();
    } catch (err) {
      const detail = (err as { response?: { data?: { message?: string } } }).response?.data?.message;
      setError(detail ?? 'Kuis gagal dikirim.');
    } finally {
      setBusy(false);
    }
  }

  function formatClock(seconds: number) {
    const minutes = Math.floor(seconds / 60);
    const rest = seconds % 60;
    return `${String(minutes).padStart(2, '0')}:${String(rest).padStart(2, '0')}`;
  }

  // ------------------------------------------------------------- belum mulai
  if (!attempt) {
    return (
      <div className="space-y-4">
        <div className="grid gap-3 sm:grid-cols-4">
          <InfoTile label="Jumlah soal" value={String(view.question_count)} />
          <InfoTile label="Total mark" value={String(view.total_marks)} />
          <InfoTile label="Batas waktu" value={quiz.time_limit_minutes ? `${quiz.time_limit_minutes} menit` : 'Tanpa batas'} />
          <InfoTile
            label="Percobaan"
            value={view.attempts_allowed === 0 ? 'Tak terbatas' : `${view.attempts_used} / ${view.attempts_allowed}`}
          />
        </div>

        {view.final_grade !== null && (
          <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">
            Nilai terbaik Anda: <strong>{view.final_grade}</strong> / {quiz.maximum_grade} ({quiz.grade_strategy_label})
          </div>
        )}

        {quiz.require_password && (
          <Input
            type="password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            placeholder="Kata sandi kuis dari guru"
          />
        )}

        <Button type="button" onClick={handleStart} disabled={busy}>
          <PlayCircle className="mr-1 h-4 w-4" /> {view.open_attempt_id ? 'Lanjutkan percobaan' : 'Mulai kuis'}
        </Button>

        {error && <p className="text-sm text-red-600">{error}</p>}

        {view.attempts.length > 0 && (
          <div className="rounded-lg border border-slate-200 bg-white p-4">
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
              <p className="text-sm font-medium text-slate-800">Percobaan sebelumnya</p>
              {lastFinished && (
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() =>
                    void activityApi.attemptResult(lastFinished.id).then((payload) => {
                      setAttempt(payload);
                      setSecondsLeft(payload.attempt.seconds_remaining);
                    })
                  }
                >
                  Lihat hasil & pembahasan
                </Button>
              )}
            </div>
            <ul className="space-y-1 text-xs text-slate-600">
              {view.attempts.map((item) => (
                <li key={item.id} className="flex items-center justify-between">
                  <span>
                    Percobaan {item.attempt_number} · {item.status_label}
                  </span>
                  <span>{item.final_grade !== null ? `${item.final_grade} / ${quiz.maximum_grade}` : formatDate(item.submitted_at, true)}</span>
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
    );
  }

  // ----------------------------------------------------------------- hasil
  if (finished) {
    return (
      <div className="space-y-4">
        <div className="rounded-lg border border-slate-200 bg-white p-4">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div>
              <p className="text-sm text-slate-500">Percobaan {attempt.attempt.attempt_number}</p>
              <p className="text-2xl font-semibold text-slate-900">
                {attempt.attempt.final_grade !== null ? `${attempt.attempt.final_grade} / ${quiz.maximum_grade}` : 'Menunggu penilaian'}
              </p>
              <p className="text-xs text-slate-500">
                Skor mentah {attempt.attempt.raw_score} dari {attempt.attempt.maximum_score}
              </p>
            </div>
            <div className="flex flex-col items-end gap-1">
              <Badge tone={attempt.attempt.status === 'GRADED' || attempt.attempt.status === 'AUTO_GRADED' ? 'green' : 'amber'}>
                {attempt.attempt.status_label}
              </Badge>
              {attempt.attempt.passed !== null && (
                <Badge tone={attempt.attempt.passed ? 'green' : 'red'}>{attempt.attempt.passed ? 'Lulus' : 'Belum lulus'}</Badge>
              )}
              {attempt.aggregate && (
                <span className="text-xs text-slate-500">
                  Nilai akhir ({attempt.aggregate.strategy}): {attempt.aggregate.final_grade ?? '-'}
                </span>
              )}
            </div>
          </div>

          {attempt.attempt.needs_manual_grading && (
            <p className="mt-3 inline-flex items-center gap-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800">
              <AlertTriangle className="h-4 w-4" /> Soal esai menunggu penilaian guru.
            </p>
          )}
        </div>

        {Object.keys(attempt.review ?? {}).length > 0 && (
          <div className="space-y-3">
            {questions.map((question, index) => (
              <div key={question.question_id} className="rounded-lg border border-slate-200 bg-white p-4">
                <div className="flex items-start justify-between gap-2">
                  <p className="text-sm font-medium text-slate-800">
                    {index + 1}. {question.question_text ?? '(soal disembunyikan)'}
                  </p>
                  <div className="flex items-center gap-2">
                    {question.answer?.is_correct === true && <CheckCircle2 className="h-4 w-4 text-emerald-600" />}
                    {question.answer?.is_correct === false && <XCircle className="h-4 w-4 text-red-500" />}
                    <span className="text-xs text-slate-500">
                      {question.answer?.marks ?? 0} / {question.marks}
                    </span>
                  </div>
                </div>

                {question.options.length > 0 && (
                  <ul className="mt-2 space-y-1 text-xs">
                    {question.options.map((option) => {
                      const chosen = selectedIds(question).includes(option.id);
                      const correct = (question.correct_option_ids ?? []).includes(option.id);

                      return (
                        <li
                          key={option.id}
                          className={cn(
                            'rounded-md border px-2 py-1',
                            correct && 'border-emerald-300 bg-emerald-50 text-emerald-900',
                            !correct && chosen && 'border-red-300 bg-red-50 text-red-900',
                            !correct && !chosen && 'border-slate-200 text-slate-600',
                          )}
                        >
                          {option.text}
                          {chosen && <span className="ml-2 text-[10px] uppercase">jawaban Anda</span>}
                          {correct && <span className="ml-2 text-[10px] uppercase">kunci</span>}
                        </li>
                      );
                    })}
                  </ul>
                )}

                {question.answer?.answer && question.question_type !== 'MULTIPLE_CHOICE_SINGLE' && (
                  <p className="mt-2 rounded-md bg-slate-50 px-2 py-1 text-xs text-slate-700">Jawaban: {question.answer.answer}</p>
                )}

                {question.answer?.feedback && <p className="mt-2 text-xs text-slate-600">{question.answer.feedback}</p>}
              </div>
            ))}
          </div>
        )}

        <Button type="button" variant="outline" onClick={onChanged}>
          Muat ulang status kuis
        </Button>
      </div>
    );
  }

  // -------------------------------------------------------------- pengerjaan
  return (
    <div className="space-y-4">
      <div className="sticky top-0 z-10 flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white/95 px-4 py-2 backdrop-blur">
        <div className="flex items-center gap-2 text-sm text-slate-700">
          <Clock className={cn('h-4 w-4', secondsLeft !== null && secondsLeft < 60 ? 'text-red-500' : 'text-slate-500')} />
          <span className={cn('font-mono text-base', secondsLeft !== null && secondsLeft < 60 && 'text-red-600')}>
            {secondsLeft === null ? 'Tanpa batas' : formatClock(secondsLeft)}
          </span>
          <span className="text-xs text-slate-500">(waktu server)</span>
        </div>
        <div className="flex items-center gap-2 text-xs text-slate-600">
          <span>
            {Object.values(answered).filter(Boolean).length} / {questions.length} terjawab
          </span>
          <Button type="button" size="sm" onClick={handleSubmit} disabled={busy}>
            <Send className="mr-1 h-4 w-4" /> Kirim
          </Button>
        </div>
      </div>

      <div className="grid gap-4 lg:grid-cols-[220px_1fr]">
        <ol className="flex flex-wrap gap-1 lg:flex-col">
          {questions.map((question, index) => (
            <li key={question.question_id}>
              <button
                type="button"
                onClick={() => setCurrentIndex(index)}
                disabled={attempt.navigation === 'SEQUENTIAL' && index > currentIndex}
                className={cn(
                  'flex w-full items-center gap-2 rounded-md border px-2 py-1 text-left text-xs',
                  index === currentIndex ? 'border-brand-300 bg-brand-50 text-brand-800' : 'border-slate-200 text-slate-600',
                  answered[question.question_id] && index !== currentIndex && 'bg-emerald-50/60',
                )}
              >
                <span className="font-medium">{index + 1}</span>
                <span className="truncate">{question.question_text?.slice(0, 32) ?? 'Soal'}</span>
              </button>
            </li>
          ))}
        </ol>

        {current && (
          <div className="space-y-4 rounded-lg border border-slate-200 bg-white p-4">
            <div className="flex items-start justify-between gap-2">
              <p className="text-sm font-medium text-slate-900">
                {currentIndex + 1}. {current.question_text}
              </p>
              <span className="shrink-0 text-xs text-slate-500">{current.marks} poin</span>
            </div>

            <QuestionInput
              question={current}
              value={currentAnswerValue(current)}
              onChange={(value) => void persist(current, value)}
            />

            <div className="flex items-center justify-between border-t border-slate-200 pt-3">
              <Button type="button" variant="ghost" size="sm" disabled={currentIndex === 0} onClick={() => setCurrentIndex((index) => index - 1)}>
                Sebelumnya
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={currentIndex >= questions.length - 1}
                onClick={() => setCurrentIndex((index) => index + 1)}
              >
                Soal berikutnya
              </Button>
            </div>
          </div>
        )}
      </div>

      {error && <p className="text-sm text-red-600">{error}</p>}
    </div>
  );
}

function InfoTile({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg border border-slate-200 bg-white p-3">
      <p className="text-xs text-slate-500">{label}</p>
      <p className="mt-1 text-sm font-medium text-slate-800">{value}</p>
    </div>
  );
}

function selectedIds(question: QuizAttemptQuestion): number[] {
  const data = (question.answer?.answer_data ?? {}) as Record<string, unknown>;

  if (Array.isArray(data.option_ids)) return (data.option_ids as number[]).map(Number);
  if (data.option_id !== undefined && data.option_id !== null) return [Number(data.option_id)];

  return [];
}

/** Input jawaban sesuai tipe soal (§15–§21). */
function QuestionInput({
  question,
  value,
  onChange,
}: {
  question: QuizAttemptQuestion;
  value: Record<string, unknown>;
  onChange: (value: Record<string, unknown>) => void;
}) {
  switch (question.question_type) {
    case 'MULTIPLE_CHOICE_SINGLE':
    case 'TRUE_FALSE':
      return (
        <div className="space-y-2">
          {question.options.map((option) => (
            <label key={option.id} className="flex cursor-pointer items-center gap-3 rounded-md border border-slate-200 px-3 py-2 text-sm">
              <input
                type="radio"
                name={`question-${question.question_id}`}
                checked={Number(value.option_id) === option.id}
                onChange={() => onChange({ ...value, option_id: option.id })}
              />
              <span>{option.text}</span>
            </label>
          ))}
        </div>
      );

    case 'MULTIPLE_CHOICE_MULTIPLE':
      return (
        <div className="space-y-2">
          {question.options.map((option) => {
            const list = Array.isArray(value.option_ids) ? (value.option_ids as number[]) : [];
            return (
              <label key={option.id} className="flex cursor-pointer items-center gap-3 rounded-md border border-slate-200 px-3 py-2 text-sm">
                <input
                  type="checkbox"
                  checked={list.includes(option.id)}
                  onChange={() => onChange({ ...value, option_ids: Array.isArray(value.option_ids) ? value.option_ids : [] })}
                />
                <span>{option.text}</span>
              </label>
            );
          })}
        </div>
      );

    case 'SHORT_ANSWER':
      return <Input value={(value.text as string) ?? ''} onChange={(event) => onChange({ ...value, text: event.target.value })} />;

    case 'NUMERICAL':
      return (
        <Input
          type="number"
          step="any"
          value={(value.value as string) ?? ''}
          onChange={(event) => onChange({ ...value, value: event.target.value })}
        />
      );

    case 'ESSAY':
      return (
        <Textarea rows={8} value={(value.text as string) ?? ''} onChange={(event) => onChange({ ...value, text: event.target.value })} />
      );

    case 'MATCHING':
      return (
        <div className="space-y-2">
          {question.options.map((option) => {
            const pairs = Array.isArray(value.pairs) ? (value.pairs as Array<Record<string, unknown>>) : [];
            const current = pairs.find((pair) => Number(pair.left_option_id) === option.id);

            return (
              <div key={option.id} className="flex items-center gap-2 text-sm">
                <span className="w-1/3 truncate">{option.text}</span>
                <Input
                  value={(current?.right as string) ?? ''}
                  placeholder="Pasangan…"
                  onChange={(event) => {
                    const next = pairs.filter((pair) => Number(pair.left_option_id) !== option.id);
                    next.push({ left_option_id: option.id, right: event.target.value });
                    onChange({ ...value, pairs: next });
                  }}
                />
              </div>
            );
          })}
        </div>
      );

    default:
      return (
        <div
          className="lms-prose rounded-md border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700"
          dangerouslySetInnerHTML={{ __html: sanitizeHtml(question.question_text ?? '') }}
        />
      );
  }
}
