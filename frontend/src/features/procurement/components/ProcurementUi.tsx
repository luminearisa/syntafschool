import type { ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Lock } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { cn, formatRupiah } from '@/lib/utils';
import { usePermission } from '@/hooks/usePermission';
import { metaApi } from '../api/procurementApi';
import type { EnumOptions, SelectOption } from '../types';

type Tone = 'green' | 'amber' | 'red' | 'blue' | 'gray' | 'brand';

/** Peta label/warna enum (bentuk yang dikembalikan `lib/labels.ts`). */
export interface LabelMap {
  label: (v?: string | null) => string;
  tone: (v?: string | null) => Tone;
}

/** Badge status generik dari peta label/warna modul. */
export function StatusBadge({ value, map }: { value?: string | null; map: LabelMap }) {
  return <Badge tone={map.tone(value)}>{map.label(value)}</Badge>;
}

/** Label + isi, dipakai di kartu detail agar tinggi barisnya konsisten. */
export function DetailRow({
  label,
  children,
  className,
}: {
  label: string;
  children: ReactNode;
  className?: string;
}) {
  return (
    <div className={cn('flex flex-col gap-0.5 py-1.5', className)}>
      <span className="text-[11px] uppercase tracking-wide text-slate-500">{label}</span>
      <span className="text-sm text-slate-800">{children ?? '-'}</span>
    </div>
  );
}

/** Kolom formulir dengan pesan galat dari validasi backend. */
export function FormField({
  label,
  required,
  error,
  hint,
  children,
  className,
}: {
  label: string;
  required?: boolean;
  error?: string;
  hint?: string;
  children: ReactNode;
  className?: string;
}) {
  return (
    <div className={cn('flex flex-col gap-1', className)}>
      <label className="text-xs font-medium text-slate-600">
        {label}
        {required && (
          <span className="text-red-600" aria-label="wajib diisi">
            {' '}
            *
          </span>
        )}
      </label>
      {children}
      {hint && !error && <p className="text-[11px] text-slate-500">{hint}</p>}
      {error && <p className="text-[11px] text-red-600">{error}</p>}
    </div>
  );
}

export function SectionCard({
  title,
  description,
  action,
  children,
  className,
}: {
  title: string;
  description?: string;
  action?: ReactNode;
  children: ReactNode;
  className?: string;
}) {
  return (
    <section className={cn('rounded-xl border border-slate-200 bg-white shadow-sm', className)}>
      <header className="flex flex-wrap items-start justify-between gap-2 border-b border-slate-100 px-5 py-4">
        <div>
          <h2 className="text-sm font-semibold text-slate-800">{title}</h2>
          {description && <p className="mt-0.5 text-xs text-slate-500">{description}</p>}
        </div>
        {action}
      </header>
      <div className="px-5 py-4">{children}</div>
    </section>
  );
}

/**
 * Angka pengadaan TIDAK dirender untuk pengguna tanpa izin terkait — bukan
 * sekadar disamarkan. Backend juga tidak mengirimkannya, sehingga tidak ada
 * yang bisa "dilihat dari Inspect Element".
 */
export function Money({
  value,
  className,
  perm = 'procurement.view',
  maskedText = 'Terbatas',
}: {
  value?: string | number | null;
  className?: string;
  perm?: string;
  maskedText?: string;
}) {
  const { can } = usePermission();

  if (!can(perm)) {
    return (
      <span
        className={cn('inline-flex items-center gap-1 text-xs text-slate-400', className)}
        title="Perlu izin terkait"
      >
        <Lock className="h-3 w-3" />
        {maskedText}
      </span>
    );
  }

  return <span className={cn('tabular-nums', className)}>{formatRupiah(value ?? 0)}</span>;
}

/** Sembunyikan seluruh blok dari pengguna tanpa izin. */
export function RestrictedNotice({ perm, children }: { perm: string; children: ReactNode }) {
  const { can } = usePermission();
  if (can(perm)) return <>{children}</>;
  return (
    <div className="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800">
      <Lock className="h-4 w-4" />
      Bagian ini hanya dapat dibuka oleh pemegang izin terkait.
    </div>
  );
}

/* ── Metadata & opsi rujukan ─────────────────────────────────────────────── */

/** Daftar nilai enum dari backend, sehingga layar tidak pernah mengarang pilihan. */
export function useEnumMetadata() {
  const { data, isLoading } = useQuery({
    queryKey: ['procurement', 'metadata'],
    queryFn: () => metaApi.metadata(),
    staleTime: 30 * 60 * 1000,
  });

  const enums: EnumOptions = data ?? {};
  const optionsOf = (key: string): SelectOption[] => enums[key] ?? [];

  return { enums, optionsOf, isLoading };
}

/** Opsi vendor yang layak dipakai (SearchSelect). */
export function useVendorOptions() {
  const { data, isLoading } = useQuery({
    queryKey: ['procurement', 'options', 'vendor'],
    queryFn: () => metaApi.options('vendor'),
    staleTime: 5 * 60 * 1000,
  });

  const options: SelectOption[] = (data ?? []).map((v) => ({
    value: v.id,
    label: `${String(v.code ?? '')} — ${String(v.legal_name ?? v.name ?? '')}`.trim(),
  }));

  return { options, isLoading };
}

/** Opsi item katalog (SearchSelect). */
export function useItemOptions() {
  const { data, isLoading } = useQuery({
    queryKey: ['procurement', 'options', 'item'],
    queryFn: () => metaApi.options('item'),
    staleTime: 5 * 60 * 1000,
  });

  const options: SelectOption[] = (data ?? []).map((i) => ({
    value: i.id,
    label: `${String(i.sku ?? '')} ${String(i.name ?? '')}`.trim(),
  }));

  return { options, isLoading };
}

/** Opsi kategori (SearchSelect). */
export function useCategoryOptions() {
  const { data, isLoading } = useQuery({
    queryKey: ['procurement', 'options', 'category'],
    queryFn: () => metaApi.options('category'),
    staleTime: 10 * 60 * 1000,
  });

  const options: SelectOption[] = (data ?? []).map((c) => ({
    value: c.id,
    label: `${c.code ?? ''} — ${c.name}`.trim(),
  }));

  return { options, isLoading };
}

/** Kesehatan integrasi modul lain (Accountant/Inventory/Asset/Workflow). */
export function useIntegrationHealth() {
  const { data, isLoading } = useQuery({
    queryKey: ['procurement', 'health'],
    queryFn: () => metaApi.health(),
    staleTime: 5 * 60 * 1000,
    retry: false,
  });

  return { health: data, isLoading };
}
