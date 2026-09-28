import { useState } from 'react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { ProgressBar } from '@/features/lms/components/ProgressBar';
import { activityApi } from '@/features/lms/api/activityApi';
import { sanitizeHtml } from '@/features/lms/lib/sanitizeHtml';
import type { LessonView } from '@/features/lms/types.phase2';
import { CheckCircle2, ChevronRight, Circle, HelpCircle } from 'lucide-react';
import { cn } from '@/lib/utils';

/** Lesson dengan halaman bercabang (§37). */
export function LessonPlayer({
  activityId,
  view,
  onChanged,
}: {
  activityId: number;
  view: LessonView;
  onChanged: () => void;
}) {
  const [answer, setAnswer] = useState<string>('');
  const [feedback, setFeedback] = useState<{ correct: boolean | null; message: string } | null>(null);
  const [busy, setBusy] = useState(false);

  const current = view.current;
  const percent = view.progress?.percent ?? 0;

  async function record(pageId: number, answerKey?: string) {
    setBusy(true);
    setFeedback(null);
    try {
      const result = await activityApi.saveLessonProgress(activityId, pageId, answerKey);
      if (result.answer_correct !== null) {
        setFeedback({
          correct: result.answer_correct,
          message: result.answer_correct ? 'Jawaban benar! Lanjut ke halaman berikutnya.' : 'Jawaban belum tepat, arahkan ke halaman penguatan.',
        });
      }
      setAnswer('');
      onChanged();
    } finally {
      setBusy(false);
    }
  }

  if (!current) {
    return <p className="text-sm text-slate-500">Lesson ini belum memiliki halaman.</p>;
  }

  return (
    <div className="space-y-4">
      <div className="rounded-lg border border-slate-200 bg-white p-3">
        <div className="flex items-center justify-between text-xs text-slate-600">
          <span>
            Halaman {view.nav.position ?? 1} dari {view.nav.total}
          </span>
          <span>{percent}% selesai</span>
        </div>
        <ProgressBar value={percent} className="mt-2" />
      </div>

      <div className="grid gap-4 lg:grid-cols-[240px_1fr]">
        <ol className="space-y-1">
          {view.pages.map((page) => (
            <li key={page.id}>
              <span
                className={cn(
                  'flex items-center gap-2 rounded-md border px-2 py-1 text-xs',
                  page.id === current.id ? 'border-brand-300 bg-brand-50 text-brand-800' : 'border-slate-200 text-slate-600',
                )}
              >
                {page.visited ? <CheckCircle2 className="h-3.5 w-3.5 text-emerald-600" /> : <Circle className="h-3.5 w-3.5 text-slate-300" />}
                <span className="truncate">{page.title}</span>
                {page.page_type === 'QUESTION' && <HelpCircle className="h-3.5 w-3.5 text-amber-500" />}
              </span>
            </li>
          ))}
        </ol>

        <div className="space-y-4 rounded-lg border border-slate-200 bg-white p-4">
          <h2 className="text-base font-semibold text-slate-900">{current.title}</h2>

          <div className="lms-prose text-sm text-slate-700" dangerouslySetInnerHTML={{ __html: sanitizeHtml(current.content) }} />

          {current.page_type === 'QUESTION' && current.question && (
            <div className="space-y-3 rounded-lg border border-amber-200 bg-amber-50/60 p-3">
              <p className="text-sm font-medium text-slate-800">{current.question.question_text}</p>

              {current.branches.length > 0 ? (
                <div className="space-y-2">
                  {current.branches.map((branch) => (
                    <Button
                      key={branch.id}
                      type="button"
                      variant={answer === branch.answer_key ? 'primary' : 'outline'}
                      size="sm"
                      className="w-full justify-start"
                      onClick={() => setAnswer(branch.answer_key ?? '')}
                    >
                      {branch.label ?? branch.answer_key}
                    </Button>
                  ))}
                </div>
              ) : (
                <div className="flex flex-wrap gap-2">
                  {current.question.options.map((option) => (
                    <Button
                      key={option.id}
                      type="button"
                      variant={answer === String(option.id) ? 'primary' : 'outline'}
                      size="sm"
                      onClick={() => setAnswer(String(option.id))}
                    >
                      {option.text}
                    </Button>
                  ))}
                </div>
              )}

              <Button type="button" size="sm" disabled={busy || answer === ''} onClick={() => void record(current.id, answer)}>
                Periksa jawaban
              </Button>
            </div>
          )}

          {feedback && (
            <div
              className={cn(
                'rounded-md border px-3 py-2 text-sm',
                feedback.correct ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-amber-200 bg-amber-50 text-amber-900',
              )}
            >
              {feedback.message}
            </div>
          )}

          {current.page_type !== 'QUESTION' && (
            <div className="flex items-center justify-between border-t border-slate-200 pt-3">
              <Button
                type="button"
                variant="ghost"
                size="sm"
                disabled={!view.nav.previous || busy}
                onClick={() => view.nav.previous && void record(view.nav.previous.id)}
              >
                {view.nav.previous ? view.nav.previous.title : 'Sebelumnya'}
              </Button>
              <Button
                type="button"
                size="sm"
                disabled={busy}
                onClick={() => void record(current.id).then(() => view.nav.next && void record(view.nav.next.id))}
              >
                {view.nav.next ? 'Lanjut' : 'Selesaikan'} <ChevronRight className="ml-1 h-4 w-4" />
              </Button>
            </div>
          )}

          {view.progress?.completed_at && (
            <Badge tone="green" className="mt-2">
              Lesson selesai
            </Badge>
          )}
        </div>
      </div>
    </div>
  );
}
