import type { ReactNode } from 'react';
import { AlertCircle, CircleHelp } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { cn } from '@/lib/utils';

export function ReligiousPageHeader({
  title,
  description,
  actions,
}: {
  title: string;
  description: string;
  actions?: ReactNode;
}) {
  return (
    <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <p className="mb-1 text-xs font-semibold uppercase tracking-[0.16em] text-emerald-700">
          Religius & Masjid
        </p>
        <h1 className="text-xl font-semibold tracking-tight text-slate-900">{title}</h1>
        <p className="mt-1 max-w-3xl text-sm leading-6 text-slate-500">{description}</p>
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}

export function MetricCard({
  label,
  value,
  detail,
  icon,
  tone = 'green',
}: {
  label: string;
  value: string | number;
  detail?: string;
  icon?: ReactNode;
  tone?: 'green' | 'blue' | 'amber' | 'red';
}) {
  const iconTone = {
    green: 'bg-emerald-50 text-emerald-700',
    blue: 'bg-sky-50 text-sky-700',
    amber: 'bg-amber-50 text-amber-700',
    red: 'bg-rose-50 text-rose-700',
  }[tone];

  return (
    <Card className="p-4">
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="text-xs font-medium text-slate-500">{label}</p>
          <p className="mt-2 text-2xl font-semibold tracking-tight text-slate-900">{value}</p>
          {detail && <p className="mt-1 text-xs text-slate-500">{detail}</p>}
        </div>
        {icon && <span className={cn('rounded-xl p-2.5', iconTone)} aria-hidden="true">{icon}</span>}
      </div>
    </Card>
  );
}

export function FormField({
  label,
  children,
  hint,
  className,
}: {
  label: string;
  children: ReactNode;
  hint?: string;
  className?: string;
}) {
  return (
    <label className={cn('block min-w-0 space-y-1.5', className)}>
      <span className="block text-xs font-medium text-slate-600">{label}</span>
      {children}
      {hint && <span className="block text-xs leading-5 text-slate-500">{hint}</span>}
    </label>
  );
}

export function LoadingCard({ label = 'Memuat data...' }: { label?: string }) {
  return (
    <Card className="flex min-h-36 items-center justify-center gap-2 p-8 text-sm text-slate-500">
      <Spinner /> {label}
    </Card>
  );
}

export function ErrorCard({
  title = 'Data tidak dapat dimuat',
  message,
  onRetry,
}: {
  title?: string;
  message: string;
  onRetry: () => void;
}) {
  return (
    <Card className="flex flex-col items-start gap-3 border-rose-200 bg-rose-50/50 p-4 sm:flex-row sm:items-center sm:justify-between">
      <div className="flex items-start gap-3">
        <AlertCircle className="mt-0.5 h-5 w-5 shrink-0 text-rose-600" aria-hidden="true" />
        <div>
          <p className="text-sm font-semibold text-rose-900">{title}</p>
          <p className="mt-1 text-sm text-rose-800">{message}</p>
        </div>
      </div>
      <Button size="sm" variant="outline" onClick={onRetry}>
        Coba lagi
      </Button>
    </Card>
  );
}

export function EmptyHint({ children }: { children: ReactNode }) {
  return (
    <div className="flex items-start gap-2 rounded-lg bg-slate-50 px-3 py-3 text-sm text-slate-500">
      <CircleHelp className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
      <p>{children}</p>
    </div>
  );
}

export function localDate(value = new Date()): string {
  const year = value.getFullYear();
  const month = String(value.getMonth() + 1).padStart(2, '0');
  const day = String(value.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

export function localDateTime(value = new Date()): string {
  const year = value.getFullYear();
  const month = String(value.getMonth() + 1).padStart(2, '0');
  const day = String(value.getDate()).padStart(2, '0');
  const hour = String(value.getHours()).padStart(2, '0');
  const minute = String(value.getMinutes()).padStart(2, '0');
  return `${year}-${month}-${day}T${hour}:${minute}`;
}

export function formatDateTime(value?: string | null): string {
  if (!value) return '—';
  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) return value;
  return parsed.toLocaleString('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

export function prayerLabel(code: string, names: Record<string, string> = {}): string {
  return names[code] ?? ({
    FAJR: 'Subuh',
    DHUHR: 'Dzuhur',
    ASR: 'Ashar',
    MAGHRIB: 'Maghrib',
    ISHA: 'Isya',
    JUMUAH: 'Jumat',
    TARAWIH: 'Tarawih',
    TAHAJJUD: 'Tahajud',
    EID_FITR: 'Idul Fitri',
    EID_ADHA: 'Idul Adha',
    OTHER: 'Lainnya',
  } as Record<string, string>)[code] ?? code;
}

export function statusTone(status?: string): 'success' | 'warning' | 'danger' | 'info' | 'neutral' {
  switch ((status ?? '').toUpperCase()) {
    case 'ACTIVE':
    case 'PRESENT':
    case 'APPROVED':
    case 'PUBLISHED':
    case 'COMPLETED':
      return 'success';
    case 'REQUESTED':
    case 'PENDING':
    case 'PENDING_APPROVAL':
    case 'LATE':
    case 'DRAFT':
    case 'SCHEDULED':
      return 'warning';
    case 'REJECTED':
    case 'ABSENT':
    case 'CLOSED':
    case 'INACTIVE':
      return 'danger';
    case 'EXCUSED':
    case 'UNKNOWN':
    case 'REVIEW':
      return 'info';
    default:
      return 'neutral';
  }
}

export function displayStatus(status?: string): string {
  const labels: Record<string, string> = {
    ACTIVE: 'Aktif',
    INACTIVE: 'Tidak aktif',
    PRESENT: 'Hadir',
    LATE: 'Terlambat',
    ABSENT: 'Tidak hadir',
    EXCUSED: 'Izin',
    AUTHORIZED_ABSENCE: 'Absen berizin',
    UNKNOWN: 'Belum diketahui',
    REQUESTED: 'Menunggu',
    APPROVED: 'Disetujui',
    REJECTED: 'Ditolak',
    PUBLISHED: 'Dipublikasikan',
    DRAFT: 'Draf',
    SCHEDULED: 'Terjadwal',
    COMPLETED: 'Selesai',
    REVIEW: 'Ditinjau',
    CLOSED: 'Ditutup',
  };
  return status ? labels[status.toUpperCase()] ?? status.replace(/_/g, ' ') : '—';
}
