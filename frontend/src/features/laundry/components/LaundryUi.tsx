import type { ReactNode } from 'react';
import { AxiosError } from 'axios';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { cn } from '@/lib/utils';
import type { ApiError } from '@/types';

/**
 * Komponen tampilan bersama modul laundry.
 *
 * Prinsip desain: halaman operator yang padat data harus tetap tenang dan
 * terstruktur — header konsisten, kartu statistik dengan penanda warna,
 * tabel bergaris dengan antrean yang jelas, dan form create/edit hidup di
 * SideModal (drawer samping) agar konteks baris tetap terlihat.
 */

/* ── Header halaman ──────────────────────────────────────────────────── */

export function PageHeader({ icon, title, description, actions }: {
  icon?: ReactNode;
  title: string;
  description?: ReactNode;
  actions?: ReactNode;
}) {
  return (
    <div className="relative overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-soft">
      <div className="pointer-events-none absolute inset-y-0 right-0 hidden w-64 bg-gradient-to-l from-brand-50/90 via-brand-50/40 to-transparent sm:block" aria-hidden="true" />
      <div className="relative flex flex-wrap items-center justify-between gap-4 px-5 py-4 sm:px-6">
        <div className="flex items-center gap-3.5">
          {icon ? (
            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-600 text-white shadow-sm">
              {icon}
            </span>
          ) : null}
          <div>
            <h1 className="text-lg font-semibold tracking-tight text-slate-900">{title}</h1>
            {description ? <p className="mt-0.5 text-sm leading-relaxed text-slate-500">{description}</p> : null}
          </div>
        </div>
        {actions ? <div className="flex flex-wrap items-center gap-2">{actions}</div> : null}
      </div>
    </div>
  );
}

/* ── Kartu statistik ─────────────────────────────────────────────────── */

const STAT_TONES = {
  default: { chip: 'bg-slate-100 text-slate-600', value: 'text-slate-900' },
  brand: { chip: 'bg-brand-50 text-brand-700', value: 'text-brand-700' },
  good: { chip: 'bg-emerald-50 text-emerald-600', value: 'text-emerald-600' },
  warn: { chip: 'bg-amber-50 text-amber-600', value: 'text-amber-600' },
  bad: { chip: 'bg-rose-50 text-rose-600', value: 'text-rose-600' },
  info: { chip: 'bg-sky-50 text-sky-600', value: 'text-sky-600' },
} as const;

export function Stat({ label, value, hint, icon, tone = 'default' }: {
  label: string;
  value: ReactNode;
  hint?: ReactNode;
  icon?: ReactNode;
  tone?: keyof typeof STAT_TONES;
}) {
  const t = STAT_TONES[tone] ?? STAT_TONES.default;

  return (
    <div className="group rounded-xl border border-slate-200 bg-white p-4 transition-shadow hover:shadow-soft">
      <div className="flex items-start justify-between gap-2">
        <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{label}</p>
        {icon ? (
          <span className={cn('flex h-8 w-8 shrink-0 items-center justify-center rounded-lg', t.chip)}>{icon}</span>
        ) : null}
      </div>
      <p className={cn('mt-2 text-2xl font-semibold tracking-tight', t.value)}>{value}</p>
      {hint ? <p className="mt-1 text-xs leading-relaxed text-slate-500">{hint}</p> : null}
    </div>
  );
}

/* ── Panel ───────────────────────────────────────────────────────────── */

export function Panel({ title, action, children, description, className }: {
  title: string;
  description?: string;
  action?: ReactNode;
  children: ReactNode;
  className?: string;
}) {
  return (
    <Card className={cn('overflow-hidden', className)}>
      <CardHeader className="flex flex-wrap items-center justify-between gap-2 bg-slate-50/60">
        <div>
          <CardTitle className="text-[15px]">{title}</CardTitle>
          {description ? <p className="mt-1 text-xs leading-relaxed text-slate-500">{description}</p> : null}
        </div>
        {action}
      </CardHeader>
      <CardBody className="p-4 sm:p-5">{children}</CardBody>
    </Card>
  );
}

/* ── Tabel data ──────────────────────────────────────────────────────── */

export interface Column<T> {
  key: string;
  header: string;
  render: (row: T) => ReactNode;
  className?: string;
}

export function DataTable<T>({ columns, rows, empty = 'Belum ada data.', loading = false, footer }: {
  columns: Array<Column<T>>;
  rows: T[];
  empty?: string;
  loading?: boolean;
  footer?: ReactNode;
}) {
  if (loading) {
    return (
      <div className="flex items-center justify-center gap-2 py-10 text-sm text-slate-500">
        <Spinner /> Memuat data…
      </div>
    );
  }

  if (rows.length === 0) {
    return <EmptyState title={empty} />;
  }

  return (
    <div className="-mx-1 overflow-x-auto px-1">
      <table className="w-full min-w-[640px] border-separate border-spacing-0 text-sm">
        <thead>
          <tr className="text-left">
            {columns.map((column, i) => (
              <th
                key={column.key}
                className={cn(
                  'whitespace-nowrap border-b border-slate-200 bg-slate-50/80 px-3 py-2.5 text-[11px] font-semibold uppercase tracking-wider text-slate-500',
                  i === 0 && 'rounded-tl-lg',
                  i === columns.length - 1 && 'rounded-tr-lg',
                  column.className ?? '',
                )}
              >
                {column.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row, index) => (
            <tr key={index} className="group/row transition-colors hover:bg-brand-50/40">
              {columns.map((column) => (
                <td
                  key={column.key}
                  className={cn(
                    'border-b border-slate-100 px-3 py-2.5 align-top',
                    index === rows.length - 1 && 'border-b-0',
                    column.className ?? '',
                  )}
                >
                  {column.render(row)}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
      {footer}
    </div>
  );
}

/* ── Status ──────────────────────────────────────────────────────────── */

const STATUS_TONES: Record<string, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  COMPLETED: 'success', PAID: 'success', PASSED: 'success', RESOLVED: 'success', CLOSED: 'success',
  PUBLISHED: 'success', AVAILABLE: 'success', OPERATIONAL: 'success', ACTIVE: 'success', RETURNED: 'success',
  NOT_REQUIRED: 'success',
  RECEIVED: 'info', WEIGHED: 'info', QUEUED: 'info', WASHING: 'info', DRYING: 'info', IRONING: 'info',
  FOLDING: 'info', QUALITY_CHECK: 'info', PACKAGED: 'info', READY: 'info', DELIVERED: 'info',
  IN_PROGRESS: 'info', IN_USE: 'info', ASSIGNED: 'info', PLANNED: 'info', WAITING: 'info',
  ADJUSTMENT: 'info', USAGE: 'info', ALLOCATION: 'info', CARRY_OVER: 'info',
  PAYMENT_REQUIRED: 'warning', PENDING: 'warning', FAILED: 'warning', REWORK: 'warning',
  OPEN: 'warning', INVESTIGATING: 'warning', MAINTENANCE: 'warning', EXHAUSTED: 'warning',
  REPAIR: 'warning', LOW: 'warning', MEDIUM: 'warning', PROPOSED: 'warning',
  CANCELLED: 'danger', LOST: 'danger', DAMAGED: 'danger', REFUNDED: 'danger', MISSING: 'danger',
  HIGH: 'danger', CRITICAL: 'danger', OUT_OF_SERVICE: 'danger', RETIRED: 'danger', EXPIRED: 'danger',
  WAIVED: 'neutral', ARCHIVED: 'neutral', DRAFT: 'neutral',
};

const STATUS_LABELS: Record<string, string> = {
  DRAFT: 'Draf', RECEIVED: 'Diterima', WEIGHED: 'Ditimbang', PAYMENT_REQUIRED: 'Perlu Bayar',
  PAID: 'Lunas', QUEUED: 'Mengantre', WASHING: 'Mencuci', DRYING: 'Mengeringkan',
  IRONING: 'Menyetrika', FOLDING: 'Melipat', QUALITY_CHECK: 'QC', PACKAGING: 'Pengemasan',
  PACKAGED: 'Dikemas', READY: 'Siap Diambil', DELIVERED: 'Terkirim', COMPLETED: 'Selesai',
  CANCELLED: 'Dibatalkan', LOST: 'Hilang', DAMAGED: 'Rusak',
  PENDING: 'Menunggu', NOT_REQUIRED: 'Tanpa Biaya', FAILED: 'Gagal', REFUNDED: 'Direfund', WAIVED: 'Dibebaskan',
  OPEN: 'Terbuka', INVESTIGATING: 'Diselidiki', RESOLVED: 'Terselesaikan', CLOSED: 'Ditutup',
  OPERATIONAL: 'Operasional', MAINTENANCE: 'Perawatan', OUT_OF_SERVICE: 'Tidak Aktif',
  AVAILABLE: 'Tersedia', ASSIGNED: 'Ditautkan', IN_USE: 'Dipakai', REPAIR: 'Perbaikan', RETIRED: 'Dipensiunkan',
  ACTIVE: 'Aktif', ARCHIVED: 'Arsip', PUBLISHED: 'Terbit', EXPIRED: 'Kedaluwarsa', EXHAUSTED: 'Habis',
  MISSING: 'Item Hilang', STAIN: 'Terkena Noda', COLOR_TRANSFER: 'Luntur', OTHER: 'Lainnya',
  PLANNED: 'Direncanakan', IN_PROGRESS: 'Berjalan', PROPOSED: 'Diusulkan',
};

const PILL_DOTS: Record<string, string> = {
  success: 'bg-emerald-500',
  warning: 'bg-amber-500',
  danger: 'bg-rose-500',
  info: 'bg-sky-500',
  neutral: 'bg-slate-400',
};

export function StatusPill({ status, label }: { status: string; label?: string }) {
  const key = status?.toUpperCase?.() ?? '';
  const tone = STATUS_TONES[key] ?? 'neutral';

  return (
    <Badge tone={tone} title={status}>
      <span className={cn('mr-1.5 inline-block h-1.5 w-1.5 rounded-full', PILL_DOTS[tone])} />
      {label ?? STATUS_LABELS[key] ?? status}
    </Badge>
  );
}

/* ── Format ──────────────────────────────────────────────────────────── */

export function Money({ value, compact = false }: { value: number | string | null | undefined; compact?: boolean }) {
  const numeric = typeof value === 'string' ? Number(value) : value ?? 0;
  if (numeric === null || Number.isNaN(numeric)) {
    return <span className="text-slate-400">—</span>;
  }

  return (
    <span className="tabular-nums">
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

  return <span className="tabular-nums">{new Intl.NumberFormat('id-ID', { maximumFractionDigits: 3 }).format(numeric)} {unit}</span>;
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

  return <span className="tabular-nums">{label}</span>;
}

export function DateOnly({ value }: { value: string | null | undefined }) {
  if (!value) {
    return <span className="text-slate-400">—</span>;
  }
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return <span>{value}</span>;
  }

  return <span className="tabular-nums">{date.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })}</span>;
}

export function Progress({ value, max }: { value: number; max: number }) {
  const percent = max > 0 ? Math.min(100, Math.round((value / max) * 100)) : 0;
  const tone = percent >= 95 ? 'bg-rose-500' : percent >= 75 ? 'bg-amber-500' : 'bg-emerald-500';

  return (
    <div className="flex items-center gap-2">
      <div className="h-2 w-24 overflow-hidden rounded-full bg-slate-200">
        <div className={cn('h-full rounded-full transition-all', tone)} style={{ width: `${percent}%` }} />
      </div>
      <span className="text-xs tabular-nums text-slate-600">{value}/{max}</span>
    </div>
  );
}

/* ── Form & filter ───────────────────────────────────────────────────── */

export function FilterBar({ children }: { children: ReactNode }) {
  return <div className="mb-4 flex flex-wrap items-end gap-2">{children}</div>;
}

export function Field({ label, children, required, hint }: { label: string; children: ReactNode; required?: boolean; hint?: string }) {
  return (
    <label className="block">
      <span className="mb-1.5 block text-sm font-medium text-slate-700">
        {label}
        {required ? <span className="ml-0.5 font-semibold text-rose-500">*</span> : null}
      </span>
      {children}
      {hint ? <span className="mt-1 block text-xs text-slate-400">{hint}</span> : null}
    </label>
  );
}

/** Baris definisi ringkas untuk detail di dalam drawer. */
export function DetailRow({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-4 border-b border-slate-100 py-2 last:border-0">
      <dt className="shrink-0 text-sm text-slate-500">{label}</dt>
      <dd className="text-right text-sm font-medium text-slate-900">{children}</dd>
    </div>
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
