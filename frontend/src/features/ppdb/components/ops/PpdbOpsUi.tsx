import {
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import {
  AlertTriangle,
  ArrowDown,
  ArrowUp,
  ArrowUpDown,
  Check,
  Copy,
  Inbox,
  RefreshCw,
} from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { cn } from '@/lib/utils';
import {
  ACCOUNT_STATUS_LABELS,
  DOCUMENT_STATUS_LABELS,
  FILE_REVIEW_LABELS,
  FILE_REVIEW_TONES,
  PAYMENT_STATUS_LABELS,
  REGISTRATION_STATUS_LABELS,
  accountStatusTone,
  documentStatusTone,
  paymentStatusTone,
  registrationStatusTone,
} from './labels';
import type {
  PpdbAccountStatus,
  PpdbDocumentStatus,
  PpdbPaymentStatus,
  PpdbRegistration,
  PpdbRegistrationStatus,
  Tone,
} from '@/types/ppdb';

/* ============================ FILTER DI URL ============================ */

export type FilterValue = string | number | '' | null | undefined;

/**
 * Filter list PPDB disimpan di query string — bukan state lokal — supaya
 * kartu dashboard dapat ditautkan langsung ke halaman hasil
 * (`/ppdb/registrations?status=submitted`) dan halaman bisa di-bookmark.
 */
export function useUrlFilters(keys: readonly string[]) {
  const [searchParams, setSearchParams] = useSearchParams();
  const keyList = useMemo(() => ['page', 'per_page', 'search', ...keys], [keys]);

  const values = useMemo(() => {
    const out: Record<string, string> = {};
    for (const key of keyList) out[key] = searchParams.get(key) ?? '';
    return out;
  }, [searchParams, keyList]);

  const build = (patch: Record<string, FilterValue>, keepPage: boolean) => {
    const next = new URLSearchParams(searchParams);
    if (!keepPage) next.delete('page');
    for (const [key, value] of Object.entries(patch)) {
      if (value === '' || value === null || value === undefined) next.delete(key);
      else next.set(key, String(value));
    }
    return next;
  };

  const apiParams = (extra?: Record<string, FilterValue>) => {
    const out: Record<string, string | number> = {};
    for (const [key, value] of Object.entries(values)) {
      if (value !== '' && key !== 'page' && key !== 'per_page') out[key] = value;
    }
    for (const [key, value] of Object.entries(extra ?? {})) {
      if (value === '' || value === null || value === undefined) delete out[key];
      else out[key] = typeof value === 'number' ? value : String(value);
    }
    return out;
  };

  const search = (pathname: string, extra?: Record<string, FilterValue>, keepPage = false) => {
    const params = new URLSearchParams(searchParams);
    if (!keepPage) params.delete('page');
    for (const [key, value] of Object.entries(extra ?? {})) {
      if (value === '' || value === null || value === undefined) params.delete(key);
      else params.set(key, String(value));
    }
    const query = params.toString();
    return `${pathname}${query ? `?${query}` : ''}`;
  };

  const page = Number(values.page) > 0 ? Number(values.page) : 1;

  return {
    values,
    /** Nilai mentah satu kunci. */
    get: (key: string) => values[key] ?? '',
    /** Nilai satu kunci sebagai `number | ''` (siap untuk payload form). */
    numeric: (key: string): number | '' => (values[key] ? Number(values[key]) : ''),
    set: (patch: Record<string, FilterValue>) => setSearchParams(build(patch, false), { replace: true }),
    setRaw: (patch: Record<string, FilterValue>) => setSearchParams(build(patch, true), { replace: true }),
    reset: () => setSearchParams(new URLSearchParams(), { replace: true }),
    page,
    setPage: (next: number) =>
      setSearchParams(next > 1 ? build({ page: next }, true) : build({ page: '' }, true), {
        replace: true,
      }),
    activeCount: Object.keys(values).filter(
      (key) => key !== 'page' && key !== 'per_page' && values[key] !== '',
    ).length,
    apiParams,
    link: (pathname: string, extra?: Record<string, FilterValue>) => search(pathname, extra, false),
    /** Tautan dengan status tertentu sambil mempertahankan lingkup periode. */
    linkWithStatus: (pathname: string, status: FilterValue, key = 'status') =>
      search(pathname, status ? { [key]: status, payment_status: '', document_status: '' } : undefined),
  };
}

export type UrlFilters = ReturnType<typeof useUrlFilters>;

/** Tunda nilai input pencarian sebelum memicu request. */
export function useDebouncedValue<T>(value: T, delay = 350): T {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(value), delay);
    return () => window.clearTimeout(timer);
  }, [value, delay]);
  return debounced;
}

/* ============================ STATUS BADGES (3 SUMBU) ============================ */

export function AxisBadge({
  axis,
  tone,
  children,
  className,
}: {
  axis: string;
  tone: Tone;
  children: ReactNode;
  className?: string;
}) {
  return (
    <span className={cn('inline-flex items-center gap-1', className)} title={`Sumbu ${axis}`}>
      <span className="text-[10px] font-medium uppercase tracking-wide text-slate-400">{axis}</span>
      <Badge tone={tone}>{children}</Badge>
    </span>
  );
}

export function RegistrationStatusBadge({
  status,
  className,
}: {
  status: PpdbRegistrationStatus;
  className?: string;
}) {
  return (
    <Badge tone={registrationStatusTone(status)} className={className}>
      {REGISTRATION_STATUS_LABELS[status] ?? status}
    </Badge>
  );
}

export function PaymentStatusBadge({
  status,
  className,
}: {
  status: PpdbPaymentStatus;
  className?: string;
}) {
  return (
    <Badge tone={paymentStatusTone(status)} className={className}>
      {PAYMENT_STATUS_LABELS[status] ?? status}
    </Badge>
  );
}

export function DocumentStatusBadge({
  status,
  className,
}: {
  status: PpdbDocumentStatus;
  className?: string;
}) {
  return (
    <Badge tone={documentStatusTone(status)} className={className}>
      {DOCUMENT_STATUS_LABELS[status] ?? status}
    </Badge>
  );
}

export function AccountStatusBadge({ status }: { status: PpdbAccountStatus }) {
  return <Badge tone={accountStatusTone(status)}>{ACCOUNT_STATUS_LABELS[status] ?? status}</Badge>;
}

export function FileReviewBadge({
  status,
}: {
  status: 'uploaded' | PpdbDocumentStatus;
}) {
  return (
    <Badge tone={FILE_REVIEW_TONES[status] ?? 'gray'}>{FILE_REVIEW_LABELS[status] ?? status}</Badge>
  );
}

/** Ketiga sumbu sekaligus, dipakai pada baris tabel & header detail. */
export function StatusTriplet({
  registration,
  className,
}: {
  registration: Pick<PpdbRegistration, 'status' | 'payment_status' | 'document_status'>;
  className?: string;
}) {
  return (
    <div className={cn('flex flex-wrap items-center gap-x-3 gap-y-1', className)}>
      <RegistrationStatusBadge status={registration.status} />
      <PaymentStatusBadge status={registration.payment_status} />
      <DocumentStatusBadge status={registration.document_status} />
    </div>
  );
}

/* ============================ LAYOUT ============================ */

export function PageHeader({
  title,
  description,
  actions,
  badge,
}: {
  title: string;
  description?: ReactNode;
  actions?: ReactNode;
  badge?: ReactNode;
}) {
  return (
    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
      <div className="min-w-0">
        <div className="flex flex-wrap items-center gap-2">
          <h1 className="text-lg font-semibold text-slate-900">{title}</h1>
          {badge}
        </div>
        {description && <p className="mt-0.5 text-sm text-slate-500">{description}</p>}
      </div>
      {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}

export function ReloadButton({ onClick, busy }: { onClick: () => void; busy?: boolean }) {
  return (
    <Button variant="ghost" size="icon" title="Muat ulang" onClick={onClick}>
      <RefreshCw className={cn('h-4 w-4', busy && 'animate-spin')} />
    </Button>
  );
}

export function SectionCard({
  title,
  description,
  action,
  children,
  className,
  bodyClassName,
}: {
  title: string;
  description?: ReactNode;
  action?: ReactNode;
  children: ReactNode;
  className?: string;
  bodyClassName?: string;
}) {
  return (
    <Card className={cn('p-4', className)}>
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <h3 className="text-sm font-semibold text-slate-900">{title}</h3>
          {description && <p className="mt-0.5 text-xs text-slate-500">{description}</p>}
        </div>
        {action}
      </div>
      <div className={cn('mt-3', bodyClassName)}>{children}</div>
    </Card>
  );
}

export function TabsNav<T extends string>({
  tabs,
  active,
  onChange,
  className,
}: {
  tabs: { key: T; label: string; count?: number }[];
  active: T;
  onChange: (key: T) => void;
  className?: string;
}) {
  return (
    <div className={cn('flex flex-wrap gap-1.5', className)}>
      {tabs.map((tab) => (
        <button
          key={tab.key}
          type="button"
          onClick={() => onChange(tab.key)}
          className={cn(
            'rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
            tab.key === active ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100',
          )}
        >
          {tab.label}
          {typeof tab.count === 'number' && (
            <span className={cn('ml-1.5 text-xs', tab.key === active ? 'text-brand-100' : 'text-slate-400')}>
              {tab.count}
            </span>
          )}
        </button>
      ))}
    </div>
  );
}

export function FieldRow({
  label,
  children,
  className,
}: {
  label: ReactNode;
  children: ReactNode;
  className?: string;
}) {
  return (
    <div className={cn('flex flex-col gap-0.5 py-1.5 sm:flex-row sm:gap-3', className)}>
      <dt className="shrink-0 text-xs font-medium uppercase tracking-wide text-slate-400 sm:w-52">
        {label}
      </dt>
      <dd className="min-w-0 text-sm text-slate-700">{children}</dd>
    </div>
  );
}

export function FieldList({ children, className }: { children: ReactNode; className?: string }) {
  return <dl className={cn('divide-y divide-slate-50', className)}>{children}</dl>;
}

export function Notice({
  tone = 'blue',
  children,
  className,
}: {
  tone?: 'blue' | 'amber' | 'red' | 'green' | 'gray';
  children: ReactNode;
  className?: string;
}) {
  const tones: Record<string, string> = {
    blue: 'bg-blue-50 text-blue-800 border-blue-100',
    amber: 'bg-amber-50 text-amber-800 border-amber-100',
    red: 'bg-red-50 text-red-800 border-red-100',
    green: 'bg-green-50 text-green-800 border-green-100',
    gray: 'bg-slate-50 text-slate-600 border-slate-200',
  };
  return (
    <div className={cn('rounded-lg border px-3 py-2 text-xs leading-relaxed', tones[tone], className)}>
      {children}
    </div>
  );
}

/* ============================ STATE CARDS ============================ */

const STAT_TONES: Record<Tone, string> = {
  gray: 'text-slate-800',
  green: 'text-green-600',
  red: 'text-red-600',
  amber: 'text-amber-600',
  blue: 'text-blue-600',
  brand: 'text-brand-700',
};

export function StatCard({
  label,
  value,
  hint,
  tone = 'gray',
  to,
  onClick,
  active,
}: {
  label: string;
  value: ReactNode;
  hint?: ReactNode;
  tone?: Tone;
  to?: string;
  onClick?: () => void;
  active?: boolean;
}) {
  const inner = (
    <>
      <p className="text-xs font-medium uppercase tracking-wide text-slate-400">{label}</p>
      <p className={cn('mt-1 text-xl font-semibold', STAT_TONES[tone])}>{value}</p>
      {hint && <p className="mt-0.5 text-xs text-slate-400">{hint}</p>}
    </>
  );

  const base = cn(
    'block rounded-xl border bg-white p-4 text-left shadow-sm transition-colors',
    active ? 'border-brand-500 ring-1 ring-brand-500/30' : 'border-slate-200',
    to || onClick ? 'hover:border-brand-300 hover:bg-brand-50/40' : '',
  );

  if (to) {
    return (
      <Link to={to} className={base}>
        {inner}
      </Link>
    );
  }
  if (onClick) {
    return (
      <button type="button" onClick={onClick} className={base}>
        {inner}
      </button>
    );
  }
  return <div className={base}>{inner}</div>;
}

/** Diagram batang CSS murni (tidak ada pustaka grafik di proyek ini). */
export function BarChart({
  items,
  formatValue,
  barClass = 'bg-brand-500',
  emptyLabel = 'Tidak ada data.',
}: {
  items: { label: string; value: number; to?: string; hint?: string }[];
  formatValue?: (value: number) => string;
  barClass?: string;
  emptyLabel?: string;
}) {
  if (items.length === 0) return <p className="text-sm text-slate-400">{emptyLabel}</p>;
  const max = Math.max(1, ...items.map((item) => item.value));
  return (
    <ul className="space-y-2.5">
      {items.map((item, index) => {
        const labelNode = item.to ? (
          <Link to={item.to} className="truncate text-xs text-brand-700 hover:underline">
            {item.label}
          </Link>
        ) : (
          <span className="truncate text-xs text-slate-600">{item.label}</span>
        );
        return (
          <li key={`${item.label}-${index}`}>
            <div className="flex items-center justify-between gap-2 text-xs">
              {labelNode}
              <span className="shrink-0 font-medium text-slate-700">
                {formatValue ? formatValue(item.value) : item.value}
                {item.hint && <span className="ml-1 font-normal text-slate-400">{item.hint}</span>}
              </span>
            </div>
            <div className="mt-1 h-2 w-full overflow-hidden rounded-full bg-slate-100">
              <div
                className={cn('h-2 rounded-full', barClass)}
                style={{ width: `${Math.round((item.value / max) * 100)}%` }}
              />
            </div>
          </li>
        );
      })}
    </ul>
  );
}

/* ============================ TABEL ============================ */

export interface OpsColumn<T> {
  key: string;
  label: string;
  render: (row: T, index: number) => ReactNode;
  sortable?: boolean;
  align?: 'left' | 'right' | 'center';
  className?: string;
  headClassName?: string;
  /** Sembunyikan kolom di layar sempit. */
  quiet?: boolean;
}

export interface OpsSort {
  key: string;
  dir: 'asc' | 'desc';
}

export function OpsTable<T>({
  columns,
  rows,
  rowKey,
  loading,
  error,
  emptyTitle = 'Belum ada data',
  emptyDescription,
  onRetry,
  sort,
  onSort,
  footer,
  compact,
}: {
  columns: OpsColumn<T>[];
  rows: T[];
  rowKey: (row: T, index: number) => string | number;
  loading?: boolean;
  error?: unknown;
  emptyTitle?: string;
  emptyDescription?: string;
  onRetry?: () => void;
  sort?: OpsSort | null;
  onSort?: (key: string) => void;
  footer?: ReactNode;
  compact?: boolean;
}) {
  const align = (value?: 'left' | 'right' | 'center') =>
    value === 'right' ? 'text-right' : value === 'center' ? 'text-center' : 'text-left';

  if (loading) {
    return (
      <Card>
        <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
          <Spinner /> Memuat data...
        </div>
      </Card>
    );
  }

  if (error) {
    return (
      <Card>
        <EmptyState
          title="Gagal memuat data"
          description={typeof error === 'string' ? error : undefined}
          action={
            onRetry ? (
              <Button size="sm" variant="outline" className="mt-2" onClick={onRetry}>
                Coba lagi
              </Button>
            ) : undefined
          }
        />
      </Card>
    );
  }

  if (rows.length === 0) {
    return (
      <Card>
        <EmptyState title={emptyTitle} description={emptyDescription} />
      </Card>
    );
  }

  return (
    <Card>
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-slate-100 text-xs uppercase tracking-wide text-slate-400">
              {columns.map((column) => {
                const isSorted = sort?.key === column.key;
                return (
                  <th
                    key={column.key}
                    className={cn(
                      'px-4 py-2 font-medium',
                      align(column.align),
                      column.quiet && 'hidden lg:table-cell',
                      column.headClassName,
                    )}
                  >
                    {column.sortable && onSort ? (
                      <button
                        type="button"
                        onClick={() => onSort(column.key)}
                        className={cn(
                          'inline-flex items-center gap-1 hover:text-slate-600',
                          isSorted && 'text-brand-700',
                        )}
                      >
                        {column.label}
                        {isSorted ? (
                          sort?.dir === 'asc' ? (
                            <ArrowUp className="h-3 w-3" />
                          ) : (
                            <ArrowDown className="h-3 w-3" />
                          )
                        ) : (
                          <ArrowUpDown className="h-3 w-3 opacity-50" />
                        )}
                      </button>
                    ) : (
                      column.label
                    )}
                  </th>
                );
              })}
            </tr>
          </thead>
          <tbody>
            {rows.map((row, index) => (
              <tr key={rowKey(row, index)} className="border-b border-slate-50 align-top last:border-0">
                {columns.map((column) => (
                  <td
                    key={column.key}
                    className={cn(
                      compact ? 'px-4 py-1.5' : 'px-4 py-2.5',
                      align(column.align),
                      column.quiet && 'hidden lg:table-cell',
                      column.className,
                    )}
                  >
                    {column.render(row, index)}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {footer}
    </Card>
  );
}

export function EmptyList({ title, description }: { title: string; description?: string }) {
  return (
    <div className="flex flex-col items-center gap-1 py-8 text-center">
      <div className="rounded-full bg-slate-100 p-2.5 text-slate-400">
        <Inbox className="h-5 w-5" />
      </div>
      <p className="text-sm font-medium text-slate-700">{title}</p>
      {description && <p className="max-w-md text-xs text-slate-500">{description}</p>}
    </div>
  );
}

export function LoadingLine({ label = 'Memuat...' }: { label?: string }) {
  return (
    <div className="flex items-center justify-center gap-2 py-10 text-slate-400">
      <Spinner /> {label}
    </div>
  );
}

export function ErrorLine({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <div className="flex flex-col items-center gap-2 py-8 text-center">
      <div className="rounded-full bg-red-50 p-2.5 text-red-500">
        <AlertTriangle className="h-5 w-5" />
      </div>
      <p className="text-sm font-medium text-slate-700">Permintaan gagal</p>
      <p className="max-w-md text-xs text-slate-500">{message}</p>
      {onRetry && (
        <Button size="sm" variant="outline" onClick={onRetry}>
          Coba lagi
        </Button>
      )}
    </div>
  );
}

/* ============================ PANEL SAMPING (DRAWER) ============================ */

export function SidePanel({
  open,
  title,
  description,
  onClose,
  children,
  footer,
}: {
  open: boolean;
  title: string;
  description?: ReactNode;
  onClose: () => void;
  children: ReactNode;
  footer?: ReactNode;
}) {
  useEffect(() => {
    if (!open) return;
    const handler = (event: KeyboardEvent) => event.key === 'Escape' && onClose();
    document.addEventListener('keydown', handler);
    return () => document.removeEventListener('keydown', handler);
  }, [open, onClose]);

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex justify-end bg-slate-900/50">
      <div className="h-full w-full max-w-xl overflow-y-auto bg-white shadow-xl" role="dialog">
        <div className="sticky top-0 flex items-start justify-between gap-3 border-b border-slate-100 bg-white px-5 py-3.5">
          <div className="min-w-0">
            <h3 className="text-base font-semibold text-slate-900">{title}</h3>
            {description && <p className="mt-0.5 text-xs text-slate-500">{description}</p>}
          </div>
          <Button variant="ghost" size="sm" onClick={onClose}>
            Tutup
          </Button>
        </div>
        <div className="px-5 py-4">{children}</div>
        {footer && <div className="border-t border-slate-100 px-5 py-3.5">{footer}</div>}
      </div>
    </div>
  );
}

/* ============================ MODAL AKSI ============================ */

/** Modal berisi satu catatan wajib — untuk tolak / revisi / batal / verifikasi. */
export function NoteModal({
  open,
  title,
  description,
  noteLabel = 'Catatan petugas',
  noteRequired = true,
  placeholder,
  confirmLabel = 'Simpan',
  tone = 'primary',
  busy,
  errors,
  errorKeys = ['note', 'reason', 'catatan'],
  children,
  onClose,
  onSubmit,
}: {
  open: boolean;
  title: string;
  description?: ReactNode;
  noteLabel?: string;
  noteRequired?: boolean;
  placeholder?: string;
  confirmLabel?: string;
  tone?: 'primary' | 'danger';
  busy?: boolean;
  errors?: Record<string, string[]>;
  errorKeys?: string[];
  children?: ReactNode;
  onClose: () => void;
  onSubmit: (note: string) => void;
}) {
  const [note, setNote] = useState('');
  const [localError, setLocalError] = useState('');

  useEffect(() => {
    if (!open) return;
    setNote('');
    setLocalError('');
  }, [open]);

  const serverError = errorKeys.map((key) => errors?.[key]?.[0]).find(Boolean);

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={title}
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={busy}>
            Batal
          </Button>
          <Button
            variant={tone === 'danger' ? 'danger' : 'primary'}
            loading={busy}
            onClick={() => {
              const trimmed = note.trim();
              if (noteRequired && trimmed.length < 5) {
                setLocalError('Tuliskan alasan minimal 5 karakter agar orang tua paham.');
                return;
              }
              onSubmit(trimmed);
            }}
          >
            {confirmLabel}
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        {description && <p className="text-sm text-slate-600">{description}</p>}
        {children}
        <div>
          <Label required={noteRequired}>{noteLabel}</Label>
          <Textarea
            rows={4}
            value={note}
            placeholder={placeholder}
            error={localError ?? serverError}
            onChange={(event) => {
              setNote(event.target.value);
              setLocalError('');
            }}
          />
          {(localError ?? serverError) && (
            <p className="mt-1 text-xs text-red-500">{localError ?? serverError}</p>
          )}
          <p className="mt-1 text-xs text-slate-400">
            Catatan tersimpan permanen pada riwayat pendaftaran.
          </p>
        </div>
      </div>
    </Modal>
  );
}

/** Konfirmasi aksi berat; bisa minta satu ceklis pengakuan sebelum tombol aktif. */
export function ConfirmModal({
  open,
  title,
  description,
  points,
  warning,
  acknowledgeLabel,
  confirmLabel = 'Ya, lanjutkan',
  tone = 'primary',
  busy,
  onClose,
  onConfirm,
}: {
  open: boolean;
  title: string;
  description?: ReactNode;
  points?: string[];
  warning?: ReactNode;
  acknowledgeLabel?: string;
  confirmLabel?: string;
  tone?: 'primary' | 'danger';
  busy?: boolean;
  onClose: () => void;
  onConfirm: () => void;
}) {
  const [acknowledged, setAcknowledged] = useState(false);

  useEffect(() => {
    if (!open) return;
    setAcknowledged(false);
  }, [open]);

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={title}
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={busy}>
            Batal
          </Button>
          <Button
            variant={tone === 'danger' ? 'danger' : 'primary'}
            loading={busy}
            disabled={Boolean(acknowledgeLabel) && !acknowledged}
            onClick={onConfirm}
          >
            {confirmLabel}
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        {description && <p className="text-sm text-slate-600">{description}</p>}
        {points && points.length > 0 && (
          <ul className="list-disc space-y-1 pl-5 text-sm text-slate-600">
            {points.map((point) => (
              <li key={point}>{point}</li>
            ))}
          </ul>
        )}
        {warning && (
          <Notice tone={tone === 'danger' ? 'red' : 'amber'}>{warning}</Notice>
        )}
        {acknowledgeLabel && (
          <label className="flex items-start gap-2 text-sm text-slate-700">
            <input
              type="checkbox"
              className="mt-0.5 h-4 w-4 rounded border-slate-300"
              checked={acknowledged}
              onChange={(event) => setAcknowledged(event.target.checked)}
            />
            {acknowledgeLabel}
          </label>
        )}
      </div>
    </Modal>
  );
}

/* ============================ KECIL ============================ */

export function CopyButton({ text, label = 'Salin' }: { text: string; label?: string }) {
  const [copied, setCopied] = useState(false);

  const copy = async () => {
    try {
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(text);
      } else {
        const field = document.createElement('textarea');
        field.value = text;
        document.body.appendChild(field);
        field.select();
        document.execCommand('copy');
        document.body.removeChild(field);
      }
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    } catch {
      setCopied(false);
    }
  };

  return (
    <Button variant="outline" size="sm" onClick={copy}>
      {copied ? <Check className="h-3.5 w-3.5" /> : <Copy className="h-3.5 w-3.5" />}
      {copied ? 'Tersalin' : label}
    </Button>
  );
}

/** Kolom "cari" seragam di semua list PPDB. */
export function SearchInput({
  value,
  onChange,
  placeholder = 'Cari...',
  label = 'Cari',
}: {
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
  label?: string;
}) {
  return (
    <div>
      <label className="mb-1 block text-xs font-medium text-slate-600">{label}</label>
      <Input
        value={value}
        placeholder={placeholder}
        onChange={(event) => onChange(event.target.value)}
      />
    </div>
  );
}

export function FilterField({
  label,
  children,
  className,
}: {
  label: string;
  children: ReactNode;
  className?: string;
}) {
  return (
    <div className={className}>
      <label className="mb-1 block text-xs font-medium text-slate-600">{label}</label>
      {children}
    </div>
  );
}
