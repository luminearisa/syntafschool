import { Link } from 'react-router-dom';
import { Badge } from '@/components/ui/Badge';
import { Card } from '@/components/ui/Card';
import { ProgressBar } from '@/features/lms/components/ProgressBar';
import { formatDate, relativeTime } from '@/features/lms/lib/lmsLabels';
import type { DueSoonActivity, PendingGradingRow, RecentlyGradedActivity } from '@/features/lms/types.phase2';
import { CalendarClock, ClipboardCheck, GraduationCap } from 'lucide-react';

/**
 * Bagian dasbor Phase 2 (§49–§50): tenggat dekat, nilai terbaru, dan antrean
 * penilaian aktivitas LMS.
 */
export function DueSoonSection({ items }: { items: DueSoonActivity[] }) {
  if (!items || items.length === 0) return null;

  return (
    <Card className="space-y-3 p-4">
      <div className="flex items-center gap-2">
        <CalendarClock className="h-4 w-4 text-amber-600" />
        <h3 className="text-sm font-semibold text-slate-800">Segera dikerjakan</h3>
      </div>

      <ul className="space-y-2">
        {items.map((item) => (
          <li key={item.id} className="flex items-center justify-between gap-3 rounded-lg border border-slate-200 p-3">
            <div className="min-w-0">
              <Link to={`/lms/activities/${item.id}`} className="truncate text-sm font-medium text-slate-800 hover:text-brand-700">
                {item.title}
              </Link>
              <p className="text-xs text-slate-500">
                {item.course_name} · {item.activity_type_label}
                {item.due_at ? ` · tenggat ${formatDate(item.due_at, true)}` : ''}
              </p>
            </div>
            {item.is_past_due ? <Badge tone="red">Melewati tenggat</Badge> : <Badge tone="amber">Segera</Badge>}
          </li>
        ))}
      </ul>
    </Card>
  );
}

export function RecentlyGradedSection({ items }: { items: RecentlyGradedActivity[] }) {
  if (!items || items.length === 0) return null;

  return (
    <Card className="space-y-3 p-4">
      <div className="flex items-center gap-2">
        <GraduationCap className="h-4 w-4 text-emerald-600" />
        <h3 className="text-sm font-semibold text-slate-800">Baru dinilai</h3>
      </div>

      <ul className="space-y-2">
        {items.map((item) => (
          <li key={`${item.activity_id}-${item.graded_at}`} className="space-y-1 rounded-lg border border-slate-200 p-3">
            <div className="flex items-center justify-between gap-2">
              <Link to={`/lms/activities/${item.activity_id}`} className="truncate text-sm font-medium text-slate-800 hover:text-brand-700">
                {item.title ?? 'Aktivitas'}
              </Link>
              <span className="shrink-0 text-sm font-semibold text-slate-900">
                {item.grade} / {item.maximum_grade}
              </span>
            </div>
            <p className="text-xs text-slate-500">
              {item.course_name} · {relativeTime(item.graded_at)}
              {item.passed !== null ? (item.passed ? ' · lulus' : ' · belum lulus') : ''}
            </p>
          </li>
        ))}
      </ul>
    </Card>
  );
}

export function PendingGradingSection({ items }: { items: PendingGradingRow[] }) {
  if (!items || items.length === 0) return null;

  const total = items.reduce((sum, item) => sum + item.pending, 0);

  return (
    <Card className="space-y-3 p-4">
      <div className="flex items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <ClipboardCheck className="h-4 w-4 text-amber-600" />
          <h3 className="text-sm font-semibold text-slate-800">Menunggu penilaian Anda</h3>
        </div>
        <Badge tone="amber">{total} item</Badge>
      </div>

      <ul className="space-y-2">
        {items.map((item) => (
          <li key={item.activity_id} className="flex items-center justify-between gap-3 rounded-lg border border-slate-200 p-3">
            <div className="min-w-0">
              <Link to={`/lms/activities/${item.activity_id}/manage`} className="truncate text-sm font-medium text-slate-800 hover:text-brand-700">
                {item.title}
              </Link>
              <p className="text-xs text-slate-500">
                {item.course_name} · {item.activity_type_label}
              </p>
            </div>
            <Badge tone="amber">{item.pending}</Badge>
          </li>
        ))}
      </ul>
    </Card>
  );
}

export function CourseGradeSummaryCard({
  average,
  graded,
  activities,
}: {
  average: number | null;
  graded: number;
  activities: number;
}) {
  return (
    <Card className="space-y-2 p-4">
      <h3 className="text-sm font-semibold text-slate-800">Nilai aktivitas</h3>
      <p className="text-xs text-slate-500">
        {graded} dari {activities} aktivitas sudah dinilai
      </p>
      <ProgressBar value={average ?? 0} showLabel />
      <p className="text-xs text-slate-600">Rata-rata {average === null ? '—' : `${average}%`}</p>
    </Card>
  );
}
