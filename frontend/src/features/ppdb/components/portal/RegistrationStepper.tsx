import { useEffect, useRef } from 'react';
import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { PpdbProgressKey } from '@/types/ppdbPortal';

/**
 * Kunci langkah yang dapat ditampilkan stepper.
 *
 * Sebelahnya adalah kunci progres dari server
 * (`PpdbRegistrationQueryService::progress()`). `'pilihan'` satu-satunya kunci
 * lokal: program & gelombang terkunci saat pendaftaran dibuat sehingga tidak ada
 * langkah server untuk itu, tetapi wizard tetap menampilkannya.
 */
export type StepKey = PpdbProgressKey | 'pilihan';

export interface StepDescriptor {
  key: StepKey;
  label: string;
  done: boolean;
  required: boolean;
}

interface Props {
  steps: StepDescriptor[];
  current: number;
  onSelect: (index: number) => void;
  canVisit?: (index: number) => boolean;
}

/**
 * Penghitung langkah yang dapat diklik & dinavigasi keyboard.
 * Pada layar sempit langkah dapat digeser mendatar, langkah aktif otomatis terlihat.
 */
export function RegistrationStepper({ steps, current, onSelect, canVisit }: Props) {
  const listRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const node = listRef.current?.querySelector<HTMLElement>(`[data-step="${current}"]`);
    node?.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' });
  }, [current]);

  const doneCount = steps.filter((step) => step.done).length;
  const percent = steps.length ? Math.round((doneCount / steps.length) * 100) : 0;

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
      <div className="mb-2 flex items-center justify-between gap-2 text-xs text-slate-500">
        <span>
          Langkah {current + 1} dari {steps.length}
        </span>
        <span className="font-medium text-slate-600">{percent}% lengkap</span>
      </div>
      <div className="mb-3 h-1.5 overflow-hidden rounded-full bg-slate-100">
        <div className="h-full rounded-full bg-brand-600 transition-all" style={{ width: `${percent}%` }} />
      </div>
      <div ref={listRef} className="flex gap-1.5 overflow-x-auto pb-1">
        {steps.map((step, index) => {
          const reachable = canVisit ? canVisit(index) : true;
          const isCurrent = index === current;
          const isDone = step.done;
          return (
            <button
              key={`${step.key}-${index}`}
              type="button"
              data-step={index}
              disabled={!reachable}
              aria-current={isCurrent ? 'step' : undefined}
              onClick={() => onSelect(index)}
              className={cn(
                'flex min-h-11 shrink-0 items-center gap-2 rounded-lg border px-3 text-xs font-medium transition-colors',
                isCurrent
                  ? 'border-brand-500 bg-brand-50 text-brand-700'
                  : 'border-slate-200 bg-white text-slate-600',
                isDone && !isCurrent && 'border-green-200 bg-green-50 text-green-700',
                !reachable && 'cursor-not-allowed opacity-50',
              )}
              title={step.required ? 'Langkah wajib' : 'Langkah opsional'}
            >
              <span
                className={cn(
                  'flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold',
                  isDone ? 'bg-green-600 text-white' : isCurrent ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-500',
                )}
              >
                {isDone ? <Check className="h-3 w-3" /> : index + 1}
              </span>
              <span className="whitespace-nowrap">{step.label}</span>
              {!step.required && <span className="text-[10px] font-normal text-slate-400">opsional</span>}
            </button>
          );
        })}
      </div>
    </div>
  );
}
