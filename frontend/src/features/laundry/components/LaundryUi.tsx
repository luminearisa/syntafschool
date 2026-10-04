import type { ReactNode } from 'react';
import { AxiosError } from 'axios';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import type { ApiError } from '@/types';

/**
 * Komponen tampilan bersama modul laundry.
 * Mengikuti pola TmsUi: tabel dapat digulir horizontal agar tetap terbaca
 * di meja penerimaan (desktop) maupun ponsel operator.
 */

export function Stat({ label, value, hint, tone = 'default' }: {
  label: string;
  value: ReactNode;
  hint?: ReactNode;
  tone?: 'default' | 'good' | 'warn' | 'bad';
}) {
  const toneClass = {
    default: 'text-slate-900',
    good: 'text-emerald-600',
    warn: 'text-amber-600',
    bad: 'text-rose-600',
  }[tone];

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-3">
      <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</p>
      <p className={`mt-1 text-2xl font-semibold ${toneClass}`}>{value}</p>
      {hint ? <p className="mt-1 text-xs text-slate-500">{hint}</p> : null}
    </div>
  );
}

export function Panel({ title, action, children, description }: {
  title: string;
  description?: string;
  action?: ReactNode;
  children: ReactNode;
}) {
  return (
    <Card>
      <CardHeader className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <CardTitle>{title}</CardTitle>
          {description ? <p className="mt-1 text-xs text-slate-500">{description}</p> : null}
        </div>
        {action}
      </CardHeader>
      <CardBody>{children}</CardBody>
    </Card>
  );
}

export interface Column<T> {
  key: string;
  header: string;
  render: (row: T) => ReactNode;
  className?: string;
}

export function DataTable<T>({ columns, rows, empty = 'Belum ada data.', loading = false }: {
  columns: Array<Column<T>>;
  rows: T[];
  empty?: string;
  loading?: boolean;
}) {
  if (loading) {
    return (
      <div className="flex items-center gap-2 py-6 text-sm text-slate-500">
        <Spinner /> Memuat data…
      </div>
    );
  }

  if (rows.length === 0) {
    return <EmptyState title={empty} />;
  }

  return (
    <div className="-mx-3 overflow-x-auto px-3">
      <table className="w-full min-w-[640px] border-collapse text-sm">
        <thead>
          <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
            {columns.map((column) => (
              <th key={column.key} className={`py-2 pr-3 font-medium ${column.className ?? ''}`}>{column.header}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row, index) => (
            <tr key={index} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
              {columns.map((column) => (
                <td key={column.key} className={`py-2 pr-3 align-top ${column.className ?? ''}`}>{column.render(row)}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

const STATUS_TONES: Record<string, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  COMPLETED: 'success', PAID: 'success', PASSED: 'success', RESOLVED: 'success', CLOSED: 'success',
  PUBLISHED: 'success', AVAILABLE: 'success', OPERATIONAL: 'success', ACTIVE: 'success', RETURNED: 'success',
  NOT_REQUIRED: 'success',
  RECEIVED: 'info', WEIGHED: 'info', QUEUED: 'info', WASHING: 'info', DRYING: 'info', IRONING: 'info',
  FOLDING: 'info', QUALITY_CHECK: 'info', PACKAGED: 'info', READY: 'info', DELIVERED: 'info',
  IN_PROGRESS: 'info', IN_USE: 'info', ASSIGNED: 'info', PLANNED: 'info', WAITING: 'info',
  PAYMENT_REQUIRED: 'warning', PENDING: 'warning', FAILED: 'warning', REWORK: 'warning',
  OPEN: 'warning', INVESTIGATING: 'warning', MAINTENANCE: 'warning', EXHAUSTED: 'warning',
  LOW: 'warning', MEDIUM: 'warning',
  CANCELLED: 'danger', LOST: 'danger', DAMAGED: 'danger', REFUNDED: 'danger', MISSING: 'danger',
  HIGH: 'danger', CRITICAL: 'danger', OUT_OF_SERVICE: 'danger', RETIRED: 'danger', EXPIRED: 'danger',
  WAIVED: 'neutral', ARCHIVED: 'neutral', DRAFT: 'neutral',
};

export function StatusPill({ status, label }: { status: string; label?: string }) {
  const tone = STATUS_TONES[status?.toUpperCase?.() ?? ''] ?? 'neutral';

  return <Badge tone={tone}>{label ?? status}</Badge>;
}

export function Money({ value, compact = false }: { value: number | string | null | undefined; compact?: boolean }) {
  const numeric = typeof value === 'string' ? Number(value) : value ?? 0;
  if (numeric === null || Number.isNaN(numeric)) {
    return <span className="text-slate-400">—</span>;
  }

  return (
    <span>
      {compact && Math.abs(numeric) >= 1_000_000
        ? `Rp${(numeric / 1_000_000).toFixed(1)} jt`
        : `Rp${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(numeric)}`}
    </span>
  );
}

export function Weight({ value, unit = 'kg' }: { value: number | string | null | undefined; unit?: string }) {
  const numeric = typeof value === 'string' ? Number(value) : value;
  if (numeric === null || numeric === undefined || Number.isNaN(numeric)) {
    return <span className="text-slate-400">—</span>;
  }

  return <span>{new Intl.NumberFormat('id-ID', { maximumFractionDigits: 3 }).format(numeric)} {unit}</span>;
}

export function DateTime({ value, timeOnly = false }: { value: string | null | undefined; timeOnly?: boolean }) {
  if (!value) {
    return <span className="text-slate-400">—</span>;
  }
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return <span>{value}</span>;
  }
  const label = date.toLocaleString('id-ID', timeOnly
    ? { hour: '2-digit', minute: '2-digit' }
    : { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });

  return <span>{label}</span>;
}

export function DateOnly({ value }: { value: string | null | undefined }) {
  if (!value) {
    return <span className="text-slate-400">—</span>;
  }
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return <span>{value}</span>;
  }

  return <span>{date.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })}</span>;
}

export function Progress({ value, max }: { value: number; max: number }) {
  const percent = max > 0 ? Math.min(100, Math.round((value / max) * 100)) : 0;
  const tone = percent >= 95 ? 'bg-rose-500' : percent >= 75 ? 'bg-amber-500' : 'bg-emerald-500';

  return (
    <div className="flex items-center gap-2">
      <div className="h-2 w-24 overflow-hidden rounded-full bg-slate-200">
        <div className={`h-full ${tone}`} style={{ width: `${percent}%` }} />
      </div>
      <span className="text-xs text-slate-600">{value}/{max}</span>
    </div>
  );
}

export function FilterBar({ children }: { children: ReactNode }) {
  return <div className="mb-3 flex flex-wrap items-end gap-2">{children}</div>;
}

export function Field({ label, children, required }: { label: string; children: ReactNode; required?: boolean }) {
  return (
    <label className="block">
      <span className="mb-1 block text-sm font-medium text-slate-700">
        {label}{required ? <span className="ml-0.5 font-semibold text-red-600">*</span> : null}
      </span>
      {children}
    </label>
  );
}

export function readError(error: unknown, fallback: string): string {
  const err = error as AxiosError<ApiError>;
  const data = err?.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined;
  if (data?.message) return data.message;
  const firstField = data?.errors ? Object.values(data.errors).flat()[0] : undefined;
  if (firstField) return firstField;
  if (err?.message) return err.message;

  return fallback;
}

/** Kunci idempotensi pembayaran: unik per percobaan bayar. */
export function newIdempotencyKey(prefix = 'pay'): string {
  const random = Math.random().toString(36).slice(2, 10);
  return `${prefix}-${Date.now().toString(36)}-${random}`;
}
