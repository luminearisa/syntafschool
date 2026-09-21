import type { ReactNode } from 'react';
import { getErrorMessage } from '@/lib/axios';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Label } from '@/components/ui/Label';
import { Spinner } from '@/components/ui/Spinner';
import { formatDate } from '@/lib/utils';
import type {
  PpdbFeeType,
  PpdbFieldType,
  PpdbFieldSection,
  PpdbPeriodStatus,
  PpdbQuestionType,
  PpdbSimpleStatus,
  Tone,
} from '@/types/ppdb';

/* ============================ KOSA-KATA ============================ */

export const PERIOD_STATUS_LABELS: Record<PpdbPeriodStatus, string> = {
  draft: 'Draf',
  scheduled: 'Terjadwal',
  open: 'Dibuka',
  closed: 'Ditutup',
  archived: 'Arsip',
};

export const PERIOD_STATUS_TONES: Record<PpdbPeriodStatus, Tone> = {
  draft: 'gray',
  scheduled: 'blue',
  open: 'green',
  closed: 'amber',
  archived: 'gray',
};

export const PERIOD_STATUSES = Object.keys(PERIOD_STATUS_LABELS) as PpdbPeriodStatus[];

export const SIMPLE_STATUS_LABELS: Record<PpdbSimpleStatus, string> = {
  active: 'Aktif',
  inactive: 'Tidak Aktif',
};

export const FIELD_TYPE_LABELS: Record<PpdbFieldType, string> = {
  text: 'Teks satu baris',
  textarea: 'Uraian panjang',
  number: 'Angka',
  date: 'Tanggal',
  email: 'Email',
  phone: 'Nomor telepon',
  select: 'Dropdown (satu pilihan)',
  radio: 'Pilihan tunggal (radio)',
  checkbox: 'Centang (ya/tidak)',
  multi_select: 'Pilihan ganda',
  file: 'Unggah berkas',
};

export const FIELD_TYPE_ORDER: PpdbFieldType[] = [
  'text',
  'textarea',
  'number',
  'date',
  'email',
  'phone',
  'select',
  'radio',
  'checkbox',
  'multi_select',
  'file',
];

export const SECTION_LABELS: Record<PpdbFieldSection, string> = {
  data_calon: 'Data Calon Siswa',
  data_orang_tua: 'Data Orang Tua',
  data_wali: 'Data Wali',
  alamat: 'Alamat',
  sekolah_asal: 'Sekolah Asal',
  kesehatan: 'Kesehatan',
  tambahan: 'Data Tambahan',
};

export const SECTION_ORDER: PpdbFieldSection[] = [
  'data_calon',
  'data_orang_tua',
  'data_wali',
  'alamat',
  'sekolah_asal',
  'kesehatan',
  'tambahan',
];

export const QUESTION_TYPE_LABELS: Record<PpdbQuestionType, string> = {
  text: 'Jawaban singkat',
  textarea: 'Uraian',
  number: 'Angka',
  date: 'Tanggal',
  yes_no: 'Ya / Tidak',
  single_choice: 'Pilihan tunggal',
  multiple_choice: 'Pilihan ganda',
  dropdown: 'Dropdown',
  file: 'Unggah berkas',
};

export const QUESTION_TYPE_ORDER: PpdbQuestionType[] = [
  'text',
  'textarea',
  'number',
  'date',
  'yes_no',
  'single_choice',
  'multiple_choice',
  'dropdown',
  'file',
];

/** Tipe pertanyaan yang membutuhkan daftar pilihan. */
export const CHOICE_QUESTION_TYPES: PpdbQuestionType[] = ['single_choice', 'multiple_choice', 'dropdown'];

/** Tipe field yang membutuhkan daftar pilihan. */
export const CHOICE_FIELD_TYPES: PpdbFieldType[] = ['select', 'radio', 'checkbox', 'multi_select'];

export const FEE_TYPE_LABELS: Record<PpdbFeeType, string> = {
  registration: 'Biaya Pendaftaran',
  selection: 'Biaya Seleksi',
  test: 'Biaya Tes',
  psychology: 'Tes Psikologi',
  other: 'Lainnya',
};

export const FEE_TYPE_TONES: Record<PpdbFeeType, Tone> = {
  registration: 'brand',
  selection: 'blue',
  test: 'amber',
  psychology: 'blue',
  other: 'gray',
};

export const FEE_TYPE_ORDER = Object.keys(FEE_TYPE_LABELS) as PpdbFeeType[];

export function toOptions<T extends string>(labels: Record<T, string>): { value: T; label: string }[] {
  return (Object.keys(labels) as T[]).map((value) => ({ value, label: labels[value] }));
}

/* ============================ UTIL ============================ */

/** Input number → nilai payload (`''` berarti tidak diisi). */
export function toNumOrEmpty(value: string): number | '' {
  if (value.trim() === '') return '';
  const num = Number(value.replace(/[^0-9.-]/g, ''));
  return Number.isFinite(num) ? num : '';
}

/** Potong daftar koma/virgular menjadi array trip. */
export function splitList(value: string): string[] {
  return value
    .split(/[,\n]/)
    .map((item) => item.trim())
    .filter(Boolean);
}

/** `snake_case` dari label field — aturan backend: huruf kecil, angka, garis bawah. */
export function snakeCaseKey(value: string): string {
  return value
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 50);
}

export function formatKb(kb: number | string | null | undefined): string {
  const num = Number(kb ?? 0);
  if (!num) return '—';
  return num >= 1024 ? `${(num / 1024).toFixed(num % 1024 === 0 ? 0 : 1)} MB` : `${num} KB`;
}

export function formatRange(from?: string | null, to?: string | null): string {
  if (!from && !to) return '—';
  if (from && to) return `${formatDate(from)} — ${formatDate(to)}`;
  return formatDate(from ?? to);
}

/** Nilai tanggal/ datetime API → nilai siap pakai untuk `<input type="date">`. */
export function toDateInput(value?: string | null): string {
  if (!value) return '';
  return value.slice(0, 10);
}

/** Pesan error paling informatif: validasi kolom didahulukan di atas pesan umum. */
export function describeError(error: unknown): string {
  const first = describeFirstValidationError(error);
  return first ?? getErrorMessage(error);
}

function describeFirstValidationError(error: unknown): string | undefined {
  const errors = (error as { response?: { data?: { errors?: Record<string, string[]> } } })?.response?.data
    ?.errors;
  const first = errors ? Object.values(errors).flat()[0] : undefined;
  return first;
}

/* ============================ KOMPONEN ============================ */

export function PpdbPageHeader({
  title,
  description,
  actions,
}: {
  title: string;
  description: string;
  actions?: ReactNode;
}) {
  return (
    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">{title}</h1>
        <p className="text-sm text-slate-500">{description}</p>
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}

/** Wadah filter seragam di atas tabel. */
export function PpdbFilterCard({ children }: { children: ReactNode }) {
  return (
    <Card className="grid grid-cols-1 gap-3 p-3 sm:grid-cols-2 lg:grid-cols-4">{children}</Card>
  );
}

/** Label + kontrol + error/petunjuk, dipakai di semua form konfigurasi. */
export function PpdbField({
  label,
  required,
  error,
  hint,
  htmlFor,
  className,
  children,
}: {
  label: string;
  required?: boolean;
  error?: string;
  hint?: ReactNode;
  htmlFor?: string;
  className?: string;
  children: ReactNode;
}) {
  return (
    <div className={className}>
      <Label htmlFor={htmlFor} required={required}>
        {label}
      </Label>
      {children}
      {error ? (
        <p className="mt-1 text-xs text-red-500">{error}</p>
      ) : (
        hint && <p className="mt-1 text-xs text-slate-400">{hint}</p>
      )}
    </div>
  );
}

export function PpdbPeriodStatusBadge({ status }: { status: PpdbPeriodStatus }) {
  return <Badge tone={PERIOD_STATUS_TONES[status] ?? 'gray'}>{PERIOD_STATUS_LABELS[status] ?? status}</Badge>;
}

export function PpdbSimpleStatusBadge({ status }: { status: PpdbSimpleStatus }) {
  return (
    <Badge tone={status === 'active' ? 'green' : 'gray'}>
      {SIMPLE_STATUS_LABELS[status] ?? status}
    </Badge>
  );
}

export function PpdbActiveBadge({ active }: { active: boolean }) {
  return <Badge tone={active ? 'green' : 'gray'}>{active ? 'Aktif' : 'Nonaktif'}</Badge>;
}

export function PpdbRequiredBadge({ required }: { required: boolean }) {
  return <Badge tone={required ? 'red' : 'gray'}>{required ? 'Wajib' : 'Opsional'}</Badge>;
}

/** Baris "sedang memuat" di dalam kartu, seragam untuk semua halaman. */
export function PpdbLoadingCard({ label = 'Memuat data...' }: { label?: string }) {
  return (
    <Card>
      <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
        <Spinner /> {label}
      </div>
    </Card>
  );
}

export function PpdbErrorCard({ error, onRetry }: { error: unknown; onRetry: () => void }) {
  return (
    <Card>
      <EmptyState
        title="Gagal memuat data"
        description={getErrorMessage(error)}
        action={
          <Button size="sm" variant="outline" className="mt-2" onClick={onRetry}>
            Coba lagi
          </Button>
        }
      />
    </Card>
  );
}

/** Sel kuota terpakai/sisa — dipakai halaman gelombang & program. */
export function PpdbQuotaCell({ quota, used }: { quota: number | null; used: number | undefined }) {
  if (quota === null) {
    return <span className="text-slate-400">Tanpa batas</span>;
  }
  const taken = used ?? 0;
  const remaining = Math.max(0, quota - taken);
  const tone: Tone = remaining === 0 ? 'red' : remaining <= quota * 0.2 ? 'amber' : 'green';

  return (
    <div className="leading-tight">
      <span className="font-medium text-slate-800">
        {taken}/{quota}
      </span>
      <div className="mt-0.5">
        <Badge tone={tone}>sisa {remaining}</Badge>
      </div>
    </div>
  );
}
