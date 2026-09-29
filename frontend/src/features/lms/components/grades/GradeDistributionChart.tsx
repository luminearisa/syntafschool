import { ProgressBar } from '../ProgressBar';

/**
 * Distribusi nilai (§37) — batang persentase sederhana tanpa dependensi chart
 * baru, cukup untuk dibaca guru di kelas maupun di ponsel.
 */
export function GradeDistributionChart({
  buckets,
  total,
}: {
  buckets: { label: string; count: number; percent: number }[];
  total?: number;
}) {
  if (buckets.length === 0) {
    return <p className="text-xs text-slate-500">Belum ada nilai untuk digambarkan.</p>;
  }

  return (
    <div className="space-y-2">
      {buckets.map((bucket) => (
        <div key={bucket.label} className="flex items-center gap-3">
          <span className="w-16 shrink-0 text-xs font-medium text-slate-600">{bucket.label}</span>
          <div className="flex-1">
            <ProgressBar value={bucket.percent} tone={bucket.percent >= 60 ? 'emerald' : 'amber'} size="sm" />
          </div>
          <span className="w-20 shrink-0 text-right text-xs text-slate-500">
            {bucket.count} ({bucket.percent.toFixed(0)}%)
          </span>
        </div>
      ))}
      {total !== undefined && <p className="text-xs text-slate-400">Total {total} siswa.</p>}
    </div>
  );
}
