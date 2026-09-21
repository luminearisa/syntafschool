import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { Card } from '@/components/ui/Card';

/** Kartu angka ringkas untuk dashboard & laporan. */
export function StatCard({
  label,
  value,
  hint,
  tone = 'default',
  icon,
}: {
  label: string;
  value: ReactNode;
  hint?: string;
  tone?: 'default' | 'brand' | 'green' | 'amber' | 'red';
  icon?: ReactNode;
}) {
  const tones = {
    default: 'text-slate-900',
    brand: 'text-brand-600',
    green: 'text-green-600',
    amber: 'text-amber-600',
    red: 'text-red-600',
  };

  return (
    <Card className="p-4">
      <div className="flex items-start justify-between gap-2">
        <p className="text-xs font-medium uppercase tracking-wide text-slate-400">{label}</p>
        {icon && <span className="text-slate-300">{icon}</span>}
      </div>
      <p className={cn('mt-1 text-2xl font-semibold', tones[tone])}>{value}</p>
      {hint && <p className="mt-0.5 text-xs text-slate-500">{hint}</p>}
    </Card>
  );
}

/** Batang persentase sederhana. */
export function ProgressBar({
  percent,
  tone = 'brand',
  className,
}: {
  percent: number;
  tone?: 'brand' | 'green' | 'amber' | 'red';
  className?: string;
}) {
  const tones = {
    brand: 'bg-brand-500',
    green: 'bg-green-500',
    amber: 'bg-amber-500',
    red: 'bg-red-500',
  };
  const clamped = Math.max(0, Math.min(100, percent));

  return (
    <div className={cn('h-2 w-full overflow-hidden rounded-full bg-slate-100', className)}>
      <div className={cn('h-full rounded-full transition-all', tones[tone])} style={{ width: `${clamped}%` }} />
    </div>
  );
}

/** Baris label–nilai untuk panel detail. */
export function FieldRow({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-3 border-b border-slate-50 py-1.5 last:border-0">
      <span className="text-xs text-slate-500">{label}</span>
      <span className="text-right text-sm font-medium text-slate-800">{children}</span>
    </div>
  );
}

/** Judul bagian kecil di dalam kartu. */
export function SectionTitle({ children }: { children: ReactNode }) {
  return (
    <h3 className="mb-2 text-sm font-semibold text-slate-800">{children}</h3>
  );
}

/** Label field pada form. */
export function FieldLabel({ children }: { children: ReactNode }) {
  return <label className="mb-1 block text-xs font-medium text-slate-600">{children}</label>;
}