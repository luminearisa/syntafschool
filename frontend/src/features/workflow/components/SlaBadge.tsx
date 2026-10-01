import { Badge } from '@/components/ui/Badge';
import { cn } from '@/lib/utils';
import type { SlaStatus } from '../types';

/**
 * Lencana SLA (§42).
 *
 * Menampilkan sisa waktu dalam satuan yang dibaca manusia ("6 jam", "2 hari"),
 * bukan angka menit — pengguna inbox perlu tahu seberapa mendesak, bukan
 * konfigurasi mesin.
 */
export function SlaBadge({
  status,
  dueAt,
  remainingMinutes,
  className,
}: {
  status?: SlaStatus | null;
  dueAt?: string | null;
  remainingMinutes?: number | null;
  className?: string;
}) {
  if (!status && !dueAt) return null;

  const tone: Record<SlaStatus, 'neutral' | 'warning' | 'danger' | 'info' | 'success'> = {
    ON_TIME: 'info',
    DUE_SOON: 'warning',
    OVERDUE: 'danger',
    ESCALATED: 'danger',
    COMPLETED: 'success',
    CANCELLED: 'neutral',
  };

  const minutes = remainingMinutes ?? minutesUntil(dueAt);

  return (
    <Badge tone={status ? tone[status] : 'neutral'} className={className} title={dueAt ? `Tenggat ${formatDateTime(dueAt)}` : undefined}>
      {humanizeRemaining(minutes, status)}
    </Badge>
  );
}

export function humanizeRemaining(minutes?: number | null, status?: SlaStatus | null): string {
  if (status === 'COMPLETED') return 'Selesai';
  if (status === 'CANCELLED') return 'Dibatalkan';
  if (minutes === null || minutes === undefined) return status === 'ON_TIME' ? 'Tepat waktu' : 'Tanpa SLA';

  const late = minutes < 0;
  const abs = Math.abs(minutes);

  const text =
    abs < 60
      ? `${abs} menit`
      : abs < 60 * 24
        ? `${Math.round(abs / 60)} jam`
        : `${Math.round(abs / (60 * 24))} hari`;

  return late ? `Lewat ${text}` : `Sisa ${text}`;
}

export function minutesUntil(dueAt?: string | null): number | null {
  if (!dueAt) return null;

  const diff = new Date(dueAt).getTime() - Date.now();

  return Number.isNaN(diff) ? null : Math.round(diff / 60000);
}

export function formatDateTime(value?: string | null): string {
  if (!value) return '—';

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) return '—';

  return date.toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' });
}

/** Warna status instance — dipakai bersama oleh daftar, kartu, dan visualizer. */
export function instanceTone(status?: string | null): 'neutral' | 'warning' | 'danger' | 'info' | 'success' {
  switch (status) {
    case 'DRAFT':
    case 'SUBMITTED':
    case 'IN_PROGRESS':
      return 'info';
    case 'WAITING_APPROVAL':
    case 'WAITING_REVIEW':
    case 'WAITING_FOR_APPROVER':
      return 'warning';
    case 'RETURNED':
      return 'warning';
    case 'APPROVED':
      return 'success';
    case 'REJECTED':
    case 'FAILED':
    case 'EXPIRED':
      return 'danger';
    default:
      return 'neutral';
  }
}

export function priorityTone(priority?: string | null): 'neutral' | 'warning' | 'danger' | 'info' {
  switch ((priority ?? '').toUpperCase()) {
    case 'URGENT':
      return 'danger';
    case 'HIGH':
      return 'warning';
    case 'LOW':
      return 'neutral';
    default:
      return 'info';
  }
}

export function formatAmount(amount?: string | number | null, currency = 'IDR'): string | null {
  if (amount === null || amount === undefined || amount === '') return null;

  const value = typeof amount === 'string' ? Number(amount) : amount;

  if (Number.isNaN(value)) return null;

  return new Intl.NumberFormat('id-ID', { style: 'currency', currency, maximumFractionDigits: 0 }).format(value);
}

/** Ringkasan progres sebagai bilah kecil (dipakai di daftar request). */
export function ProgressBar({ percent, className }: { percent: number; className?: string }) {
  return (
    <div className={cn('h-1.5 w-full overflow-hidden rounded-full bg-slate-100', className)}>
      <div className="h-full rounded-full bg-brand-500 transition-all" style={{ width: `${Math.min(100, Math.max(0, percent))}%` }} />
    </div>
  );
}
