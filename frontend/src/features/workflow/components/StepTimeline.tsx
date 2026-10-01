import { ArrowRight, Ban, Check, FileText, MessageSquare, RotateCcw, SkipForward, X } from 'lucide-react';
import type { WorkflowTimelineEntry } from '../types';
import { formatDateTime } from './SlaBadge';

/**
 * Timeline immutable sebuah request (§47).
 *
 * Menampilkan peristiwa, keputusan, dan submission dalam satu garis waktu —
 * termasuk siapa yang bertindak atas nama siapa (delegasi) dan alasan override.
 * Riwayat ini hanya dibaca: tidak ada aksi tulis dari layar ini.
 */
export function StepTimeline({ entries }: { entries: WorkflowTimelineEntry[] }) {
  if (entries.length === 0) {
    return <p className="text-sm text-slate-500">Belum ada aktivitas pada request ini.</p>;
  }

  return (
    <ol className="relative space-y-3 border-l border-slate-200 pl-4">
      {entries.map((entry, index) => {
        const style = entryStyle(entry.type);

        return (
          <li key={`${entry.type}-${entry.at ?? index}-${index}`} className="relative">
            <span className={`absolute -left-[26px] flex h-5 w-5 items-center justify-center rounded-full text-white ${style.badge}`}>
              {style.symbol}
            </span>
            <div className="rounded-lg border border-slate-100 bg-white px-3 py-2">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm font-medium text-slate-800">{style.label}</p>
                <span className="text-xs text-slate-400">{formatDateTime(entry.at)}</span>
              </div>
              {entry.actor ? (
                <p className="text-xs text-slate-500">
                  oleh {entry.actor}
                  {typeof entry.detail?.original_approver === 'string' && entry.detail.original_approver
                    ? ` (atas nama ${entry.detail.original_approver})`
                    : ''}
                </p>
              ) : null}
              {typeof entry.detail?.comment === 'string' && entry.detail.comment ? (
                <p className="mt-1 rounded bg-slate-50 px-2 py-1 text-xs text-slate-600">
                  <MessageSquare className="mr-1 inline h-3 w-3" />
                  {entry.detail.comment}
                </p>
              ) : null}
              {entry.detail?.is_override === true ? (
                <p className="mt-1 text-xs font-medium text-amber-600">Tindakan override (alasan wajib tercatat)</p>
              ) : null}
            </div>
          </li>
        );
      })}
    </ol>
  );
}

function entryStyle(type: string) {
  if (type.startsWith('decision.APPROVE')) {
    return { label: 'Disetujui', badge: 'bg-green-600', symbol: <Check className="h-3 w-3" /> };
  }

  if (type.startsWith('decision.REJECT')) {
    return { label: 'Ditolak', badge: 'bg-red-600', symbol: <X className="h-3 w-3" /> };
  }

  if (type.startsWith('decision.RETURN') || type.startsWith('decision.REQUEST_CHANGES')) {
    return { label: 'Dikembalikan untuk revisi', badge: 'bg-amber-500', symbol: <RotateCcw className="h-3 w-3" /> };
  }

  if (type.startsWith('decision.SKIP')) {
    return { label: 'Step dilewati', badge: 'bg-slate-500', symbol: <SkipForward className="h-3 w-3" /> };
  }

  if (type.startsWith('submission.')) {
    return { label: 'Request dikirim', badge: 'bg-brand-600', symbol: <FileText className="h-3 w-3" /> };
  }

  if (type === 'instance.completed') {
    return { label: 'Workflow selesai', badge: 'bg-green-600', symbol: <Check className="h-3 w-3" /> };
  }

  if (type === 'instance.rejected') {
    return { label: 'Request ditolak', badge: 'bg-red-600', symbol: <X className="h-3 w-3" /> };
  }

  if (type === 'instance.returned') {
    return { label: 'Request dikembalikan', badge: 'bg-amber-500', symbol: <RotateCcw className="h-3 w-3" /> };
  }

  if (type === 'instance.cancelled' || type === 'steps.cancelled') {
    return { label: 'Request dibatalkan', badge: 'bg-slate-500', symbol: <Ban className="h-3 w-3" /> };
  }

  if (type === 'instance.failed') {
    return { label: 'Workflow gagal', badge: 'bg-red-700', symbol: <X className="h-3 w-3" /> };
  }

  if (type.startsWith('step.')) {
    return { label: 'Peralihan langkah', badge: 'bg-blue-500', symbol: <ArrowRight className="h-3 w-3" /> };
  }

  return { label: type, badge: 'bg-slate-400', symbol: <ArrowRight className="h-3 w-3" /> };
}
