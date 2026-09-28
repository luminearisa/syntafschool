import { useState } from 'react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { ProgressBar } from '@/features/lms/components/ProgressBar';
import { activityApi } from '@/features/lms/api/activityApi';
import type { ChoiceView } from '@/features/lms/types.phase2';
import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';

/** Polling sederhana (§38). */
export function ChoicePlayer({
  activityId,
  view,
  onChanged,
}: {
  activityId: number;
  view: ChoiceView;
  onChanged: () => void;
}) {
  const [selected, setSelected] = useState<number[]>(view.my_response);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const multiple = view.choice.allow_multiple;
  const results = view.results;

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      await activityApi.respondChoice(activityId, selected);
      onChanged();
    } catch (err) {
      const detail = (err as { response?: { data?: { message?: string } } }).response?.data?.message;
      setError(detail ?? 'Jawaban gagal disimpan.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2 text-xs text-slate-600">
        <Badge tone="brand">{view.choice.allow_multiple ? 'Boleh memilih lebih dari satu' : 'Pilih satu'}</Badge>
        {view.choice.anonymous && <Badge tone="gray">Anonim</Badge>}
        <span>Hasil: {view.choice.results_visibility_label}</span>
      </div>

      <div className="space-y-2">
        {view.options.map((option) => {
          const active = selected.includes(option.id);
          const result = results?.options.find((item) => item.id === option.id);

          return (
            <button
              key={option.id}
              type="button"
              onClick={() =>
                setSelected((prev) => {
                  if (!multiple) return [option.id];
                  return prev.includes(option.id) ? prev.filter((id) => id !== option.id) : [...prev, option.id];
                })
              }
              className={cn(
                'w-full rounded-lg border bg-white p-3 text-left text-sm transition-colors',
                active ? 'border-brand-400 ring-1 ring-brand-200' : 'border-slate-200 hover:border-brand-300',
              )}
            >
              <div className="flex items-center justify-between gap-3">
                <span className="flex items-center gap-2">
                  <span
                    className={cn(
                      'flex h-5 w-5 items-center justify-center rounded-full border',
                      active ? 'border-brand-500 bg-brand-500 text-white' : 'border-slate-300',
                    )}
                  >
                    {active && <Check className="h-3 w-3" />}
                  </span>
                  {option.text}
                </span>
                {result && <span className="text-xs text-slate-500">{result.percent}%</span>}
              </div>

              {result && <ProgressBar value={result.percent} size="sm" className="mt-2" />}
            </button>
          );
        })}
      </div>

      {!view.has_responded && (
        <Button type="button" onClick={submit} disabled={busy || selected.length === 0}>
          Kirim jawaban
        </Button>
      )}

      {view.has_responded && !view.results_visible && (
        <p className="text-sm text-slate-600">Terima kasih, jawaban Anda tersimpan. Hasil akan ditampilkan sesuai pengaturan guru.</p>
      )}

      {results && (
        <div className="rounded-lg border border-slate-200 bg-white p-3 text-xs text-slate-600">
          Total {results.total_responses} jawaban dari {results.participants} peserta.
        </div>
      )}

      {error && <p className="text-sm text-red-600">{error}</p>}
    </div>
  );
}
