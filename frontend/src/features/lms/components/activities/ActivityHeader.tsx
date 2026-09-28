import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { sanitizeHtml } from '@/features/lms/lib/sanitizeHtml';
import { formatDate } from '@/features/lms/lib/lmsLabels';
import type { ActivityNeighbour, ActivityState, LmsActivity } from '@/features/lms/types.phase2';
import { cn } from '@/lib/utils';
import { ArrowLeft, ArrowRight, Clock, Lock, Sparkles } from 'lucide-react';

/**
 * Kepala halaman aktivitas: judul, status, tenggat, alasan terkunci, dan
 * navigasi Previous/Next antar aktivitas (§42).
 */
export function ActivityHeader({
  activity,
  state,
  neighbours,
  canManage = false,
  onNavigate,
  actions,
}: {
  activity: LmsActivity;
  state?: ActivityState;
  neighbours?: { previous: ActivityNeighbour | null; next: ActivityNeighbour | null };
  canManage?: boolean;
  onNavigate?: (activityId: number) => void;
  actions?: React.ReactNode;
}) {
  const locked = state?.locked ?? false;
  const completed = state?.completed ?? false;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0 space-y-1">
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone="brand">{activity.activity_type_label}</Badge>
            <Badge tone={activity.status === 'PUBLISHED' ? 'green' : activity.status === 'DRAFT' ? 'gray' : 'amber'}>
              {activity.status_label}
            </Badge>
            {completed && <Badge tone="green">Selesai</Badge>}
            {locked && !completed && <Badge tone="amber">Terkunci</Badge>}
            {state?.past_due && <Badge tone="red">Melewati tenggat</Badge>}
          </div>

          <h1 className="text-xl font-semibold text-slate-900 sm:text-2xl">{activity.title}</h1>

          {activity.description && <p className="text-sm text-slate-600">{activity.description}</p>}

          <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-600">
            {activity.due_at && (
              <span className="inline-flex items-center gap-1">
                <Clock className="h-3.5 w-3.5" />
                Tenggat: <strong className="font-medium text-slate-700">{formatDate(activity.due_at, true)}</strong>
              </span>
            )}
            {activity.start_at && <span>Dibuka: {formatDate(activity.start_at, true)}</span>}
            {activity.close_at && <span>Ditutup: {formatDate(activity.close_at, true)}</span>}
            {activity.learning_item?.estimated_minutes ? <span>± {activity.learning_item.estimated_minutes} menit</span> : null}
          </div>
        </div>

        <div className="flex shrink-0 items-center gap-2">{actions}</div>
      </div>

      {locked && state?.reason_label && (
        <div className="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
          <Lock className="mt-0.5 h-4 w-4" />
          <div>
            <p className="font-medium">Aktivitas belum dapat dikerjakan</p>
            <p>{state.reason_label}</p>
          </div>
        </div>
      )}

      {canManage && (
        <div className="flex items-center gap-2 rounded-lg border border-brand-100 bg-brand-50/60 p-3 text-xs text-brand-800">
          <Sparkles className="h-4 w-4" />
          Anda melihat aktivitas ini sebagai pengajar — siswa hanya melihat tampilan pengerjaan.
        </div>
      )}

      {activity.instructions && (
        <div
          className={cn('lms-prose rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-700')}
          dangerouslySetInnerHTML={{ __html: sanitizeHtml(activity.instructions) }}
        />
      )}

      {neighbours && onNavigate && (
        <div className="flex items-center justify-between gap-2 border-t border-slate-200 pt-3">
          <Button
            type="button"
            variant="ghost"
            size="sm"
            disabled={!neighbours.previous}
            onClick={() => neighbours.previous && onNavigate(neighbours.previous.id)}
          >
            <ArrowLeft className="mr-1 h-4 w-4" />
            {neighbours.previous ? neighbours.previous.title : 'Aktivitas sebelumnya'}
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            disabled={!neighbours.next}
            onClick={() => neighbours.next && onNavigate(neighbours.next.id)}
          >
            {neighbours.next ? neighbours.next.title : 'Aktivitas berikutnya'}
            <ArrowRight className="ml-1 h-4 w-4" />
          </Button>
        </div>
      )}
    </div>
  );
}
