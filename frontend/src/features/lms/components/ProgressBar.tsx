import { cn } from '@/lib/utils';

export function ProgressBar({
  value,
  size = 'md',
  showLabel = false,
  className,
  tone,
}: {
  value: number;
  size?: 'sm' | 'md' | 'lg';
  showLabel?: boolean;
  className?: string;
  tone?: 'brand' | 'emerald' | 'amber';
}) {
  const percent = Math.max(0, Math.min(100, Math.round(value)));
  const color = tone ?? (percent >= 100 ? 'emerald' : percent >= 40 ? 'brand' : 'amber');

  const heights = { sm: 'h-1.5', md: 'h-2.5', lg: 'h-3.5' };
  const tones = {
    brand: 'bg-brand-500',
    emerald: 'bg-emerald-500',
    amber: 'bg-amber-500',
  };

  return (
    <div className={cn('lms-progress', className)}>
      <div
        className={cn('lms-progress-track w-full overflow-hidden rounded-full bg-slate-200/80', heights[size])}
        role="progressbar"
        aria-valuenow={percent}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-label="Progress belajar"
      >
        <div
          className={cn('lms-progress-fill h-full rounded-full transition-all duration-500', tones[color])}
          style={{ width: `${percent}%` }}
        />
      </div>
      {showLabel && <span className="lms-progress-label text-xs font-medium text-slate-600">{percent}%</span>}
    </div>
  );
}
