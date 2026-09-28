import { useState } from 'react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { ProgressBar } from '@/features/lms/components/ProgressBar';
import { Textarea } from '@/components/ui/Textarea';
import { activityApi } from '@/features/lms/api/activityApi';
import type { SurveyView } from '@/features/lms/types.phase2';
import { Star } from 'lucide-react';
import { cn } from '@/lib/utils';

/** Survei umpan balik pembelajaran (§39). */
export function SurveyPlayer({
  activityId,
  view,
  onChanged,
}: {
  activityId: number;
  view: SurveyView;
  onChanged: () => void;
}) {
  const [answers, setAnswers] = useState<Record<number, { text?: string; value?: number }>>({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [submitted, setSubmitted] = useState(false);

  const results = view.results;

  function setAnswer(questionId: number, payload: { text?: string; value?: number }) {
    setAnswers((prev) => ({ ...prev, [questionId]: { ...prev[questionId], ...payload } }));
  }

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      await activityApi.submitSurvey(
        activityId,
        Object.entries(answers).map(([questionId, value]) => ({
          question_id: Number(questionId),
          response_text: value.text ?? null,
          response_value: value.value ?? null,
        })),
      );
      setSubmitted(true);
      onChanged();
    } catch (err) {
      const detail = (err as { response?: { data?: { message?: string } } }).response?.data?.message;
      setError(detail ?? 'Jawaban gagal dikirim.');
    } finally {
      setBusy(false);
    }
  }

  if (submitted || view.has_submitted) {
    return (
      <div className="space-y-4">
        <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
          Terima kasih! Jawaban survei Anda sudah tersimpan{view.survey.anonymous ? ' (anonim)' : ''}.
        </div>

        {results && <SurveyResultsPanel results={results} />}
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2 text-xs text-slate-600">
        {view.survey.anonymous && <Badge tone="gray">Anonim</Badge>}
        <span>{view.survey.questions_count} pertanyaan</span>
      </div>

      <div className="space-y-3">
        {view.questions.map((question, index) => (
          <div key={question.id} className="rounded-lg border border-slate-200 bg-white p-4">
            <p className="text-sm font-medium text-slate-800">
              {index + 1}. {question.text}
              {question.is_required && <span className="text-red-500"> *</span>}
            </p>

            {question.question_type === 'RATING' && (
              <div className="mt-3 flex items-center gap-2">
                {Array.from({ length: question.rating_max - question.rating_min + 1 }, (_, i) => question.rating_min + i).map((value) => {
                  const active = (answers[question.id]?.value ?? 0) >= value;

                  return (
                    <button
                      key={value}
                      type="button"
                      onClick={() => setAnswer(question.id, { value })}
                      className={cn('rounded-md p-1', active ? 'text-amber-500' : 'text-slate-300 hover:text-amber-400')}
                      aria-label={`Nilai ${value}`}
                    >
                      <Star className="h-6 w-6" fill={active ? 'currentColor' : 'none'} />
                    </button>
                  );
                })}
                <span className="ml-2 text-xs text-slate-500">
                  {question.rating_min}–{question.rating_max}
                </span>
              </div>
            )}

            {question.question_type === 'YES_NO' && (
              <div className="mt-3 flex gap-2">
                {['Ya', 'Tidak'].map((label) => (
                  <Button
                    key={label}
                    type="button"
                    variant={(answers[question.id]?.text ?? '') === label ? 'primary' : 'outline'}
                    size="sm"
                    onClick={() => setAnswer(question.id, { text: label })}
                  >
                    {label}
                  </Button>
                ))}
              </div>
            )}

            {question.question_type === 'MULTIPLE_CHOICE' && (
              <div className="mt-3 space-y-2">
                {(question.options ?? []).map((option) => (
                  <label key={option} className="flex cursor-pointer items-center gap-2 text-sm">
                    <input
                      type="radio"
                      name={`survey-${question.id}`}
                      checked={(answers[question.id]?.text ?? '') === option}
                      onChange={() => setAnswer(question.id, { text: option })}
                    />
                    {option}
                  </label>
                ))}
              </div>
            )}

            {question.question_type === 'TEXT' && (
              <Textarea
                rows={3}
                className="mt-3"
                value={answers[question.id]?.text ?? ''}
                onChange={(event) => setAnswer(question.id, { text: event.target.value })}
                placeholder="Komentar…"
              />
            )}
          </div>
        ))}
      </div>

      <Button type="button" onClick={submit} disabled={busy}>
        Kirim jawaban survei
      </Button>

      {error && <p className="text-sm text-red-600">{error}</p>}
    </div>
  );
}

export function SurveyResultsPanel({ results }: { results: SurveyView['results'] }) {
  if (!results) return null;

  return (
    <div className="space-y-3">
      <p className="text-xs text-slate-500">
        {results.responses} pengisian{results.anonymous ? ' (anonim)' : ''}
        {results.response_rate !== null && results.response_rate !== undefined ? ` · ${results.response_rate}% peserta` : ''}
      </p>

      {results.questions.map((question) => (
        <div key={question.id} className="rounded-lg border border-slate-200 bg-white p-3">
          <p className="text-sm font-medium text-slate-800">{question.text}</p>

          {question.average !== undefined && question.average !== null && (
            <p className="mt-1 text-xs text-slate-600">Rata-rata: {question.average}</p>
          )}

          {question.distribution && (
            <ul className="mt-2 space-y-1">
              {Object.entries(question.distribution).map(([label, count]) => {
                const percent = question.percentages?.[label] ?? 0;

                return (
                  <li key={label}>
                    <div className="flex items-center justify-between text-xs text-slate-600">
                      <span>{label}</span>
                      <span>
                        {count} · {percent}%
                      </span>
                    </div>
                    <ProgressBar value={percent} size="sm" />
                  </li>
                );
              })}
            </ul>
          )}

          {question.comments && question.comments.length > 0 && (
            <ul className="mt-2 space-y-1 text-xs text-slate-600">
              {question.comments.map((comment, index) => (
                <li key={index} className="rounded-md bg-slate-50 px-2 py-1">
                  {comment}
                </li>
              ))}
            </ul>
          )}
        </div>
      ))}
    </div>
  );
}

export function SurveyAnonymousNote() {
  return <Input readOnly value="Survei ini anonim" className="hidden" />;
}
