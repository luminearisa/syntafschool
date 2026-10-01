import { Check, Circle, GitMerge, RotateCcw, Sparkles, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { NodeType, WorkflowGraphNode } from '../types';
import { SlaBadge } from './SlaBadge';

/** Label ringkas tipe node untuk visualizer & builder. */
export const nodeTypeLabels: Record<NodeType, string> = {
  START: 'Mulai',
  APPROVAL: 'Persetujuan',
  REVIEW: 'Peninjauan',
  CONDITION: 'Kondisi',
  PARALLEL: 'Paralel',
  NOTIFICATION: 'Notifikasi',
  AUTOMATIC: 'Otomatis',
  END: 'Selesai',
};

/**
 * Visualizer workflow (§49).
 *
 * Menampilkan jalur sebagai kolom node berurutan dengan status:
 *   ✓ disetujui   ⏳ menunggu   ↩ dikembalikan   ✕ ditolak   ○ belum
 *
 * Bentuk kolom dipilih (bukan canvas bebas) supaya tetap terbaca di layar
 * ponsel, dan setiap node menampilkan tipe + kuorum sehingga pembaca tahu
 * bahwa cabang paralel berjalan bersamaan meskipun ditampilkan berurutan.
 */
export function WorkflowVisualizer({ nodes, currentKeys }: { nodes: WorkflowGraphNode[]; currentKeys?: string[] }) {
  if (nodes.length === 0) {
    return <p className="text-sm text-slate-500">Graf workflow belum tersedia.</p>;
  }

  const ordered = [...nodes].sort((a, b) => a.sequence - b.sequence || a.key.localeCompare(b.key));
  const current = new Set(currentKeys ?? []);

  return (
    <ol className="space-y-2">
      {ordered.map((node) => (
        <li key={node.key}>
          <VisualizerRow node={node} highlighted={current.has(node.key)} />
        </li>
      ))}
    </ol>
  );
}

function VisualizerRow({ node, highlighted }: { node: WorkflowGraphNode; highlighted: boolean }) {
  const style = statusStyle(node.status);

  return (
    <div
      className={cn(
        'flex items-start gap-3 rounded-lg border px-3 py-2.5 transition-colors',
        style.container,
        highlighted && 'ring-2 ring-brand-300',
      )}
    >
      <span className={cn('mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-white', style.icon)}>
        {style.symbol}
      </span>

      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <p className="truncate text-sm font-medium text-slate-800">{node.name}</p>
          <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-slate-500">
            {nodeTypeLabels[node.type] ?? node.type}
          </span>
          {node.quorum ? (
            <span className="text-[10px] font-medium uppercase tracking-wide text-slate-400">
              kuorum {node.quorum}
              {node.approval_mode === 'SEQUENTIAL' ? ' · bertahap' : ''}
            </span>
          ) : null}
          {node.round && node.round > 1 ? (
            <span className="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-700">ronde {node.round}</span>
          ) : null}
        </div>

        <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
          <span>{style.label}</span>
          {node.sla_minutes ? <SlaBadge status={node.sla_status} dueAt={node.due_at} /> : null}
        </div>
      </div>
    </div>
  );
}

function statusStyle(status: WorkflowGraphNode['status']) {
  switch (status) {
    case 'APPROVED':
      return {
        container: 'border-green-200 bg-green-50/60',
        icon: 'bg-green-600',
        symbol: <Check className="h-3.5 w-3.5" />,
        label: 'Disetujui',
      };
    case 'REJECTED':
      return {
        container: 'border-red-200 bg-red-50/60',
        icon: 'bg-red-600',
        symbol: <X className="h-3.5 w-3.5" />,
        label: 'Ditolak',
      };
    case 'RETURNED':
      return {
        container: 'border-amber-200 bg-amber-50/60',
        icon: 'bg-amber-500',
        symbol: <RotateCcw className="h-3.5 w-3.5" />,
        label: 'Dikembalikan untuk revisi',
      };
    case 'WAITING':
      return {
        container: 'border-brand-200 bg-brand-50/70',
        icon: 'bg-brand-600',
        symbol: <Sparkles className="h-3.5 w-3.5" />,
        label: 'Menunggu keputusan',
      };
    case 'SKIPPED':
      return {
        container: 'border-slate-200 bg-slate-50',
        icon: 'bg-slate-400',
        symbol: <GitMerge className="h-3.5 w-3.5" />,
        label: 'Dilewati pada jalur ini',
      };
    default:
      return {
        container: 'border-slate-200 bg-white',
        icon: 'bg-slate-300',
        symbol: <Circle className="h-3 w-3" />,
        label: 'Belum aktif',
      };
  }
}
