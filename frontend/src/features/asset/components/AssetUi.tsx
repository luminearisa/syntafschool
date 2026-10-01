import type { ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Lock } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { cn, formatRupiah } from '@/lib/utils';
import { usePermission } from '@/hooks/usePermission';
import { locationsApi, metaApi } from '../api/assetApi';
import type { SelectOption } from '../types';
import { assetCondition, assetStatus, criticality } from '../lib/labels';

type Tone = 'green' | 'amber' | 'red' | 'blue' | 'gray' | 'brand';

const mapBadge = (
  value?: string | null,
  map?: { label: (v?: string | null) => string; tone: (v?: string | null) => Tone },
) => {
  const label = map ? map.label(value) : value ?? '-';
  const tone = map ? map.tone(value) : 'gray';
  return <Badge tone={tone}>{label}</Badge>;
};

export function AssetStatusBadge({ value }: { value?: string | null }) {
  return mapBadge(value, assetStatus);
}

export function ConditionBadge({ value }: { value?: string | null }) {
  return mapBadge(value, assetCondition);
}

export function CriticalityBadge({ value }: { value?: string | null }) {
  return mapBadge(value, criticality);
}

export function ToneBadge({ value, map }: { value?: string | null; map: { label: (v?: string | null) => string; tone: (v?: string | null) => Tone } }) {
  return mapBadge(value, map);
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
        {required && <span className="text-red-600" aria-label="wajib diisi"> *</span>}
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
 * Angka keuangan TIDAK dirender untuk pengguna tanpa izin keuangan — bukan
 * sekadar disamarkan. Backend juga tidak mengirimkannya, jadi tidak ada yang
 * bisa "dilihat dari Inspect Element".
 */
export function Money({
  value,
  className,
  perm = 'asset.financial.view',
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
      <span className={cn('inline-flex items-center gap-1 text-xs text-slate-400', className)} title="Perlu izin keuangan">
        <Lock className="h-3 w-3" />
        {maskedText}
      </span>
    );
  }

  return <span className={cn('tabular-nums', className)}>{formatRupiah(value ?? 0)}</span>;
}

/** Sembunyikan seluruh blok (mis. tab biaya) dari pengguna tanpa izin. */
export function RestrictedNotice({ perm, children }: { perm: string; children: ReactNode }) {
  const { can } = usePermission();
  if (can(perm)) return <>{children}</>;
  return (
    <div className="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800">
      <Lock className="h-4 w-4" />
      Bagian ini hanya dapat dibuka oleh pemegang izin keuangan.
    </div>
  );
}

/* ── Opsi rujukan ────────────────────────────────────────────────────────── */

export function useLocationOptions() {
  const { data, isLoading } = useQuery({
    queryKey: ['asset', 'locations', 'options'],
    queryFn: () => locationsApi.list({ per_page: 300, status: 'ACTIVE' }),
    staleTime: 5 * 60 * 1000,
  });

  const options: SelectOption[] = (data?.data ?? []).map((loc) => ({
    value: loc.id,
    label: loc.path ? `${loc.path}` : loc.name,
  }));

  return { options, isLoading };
}

export function useCustodianOptions() {
  const { data, isLoading } = useQuery({
    queryKey: ['asset', 'custodians', 'options'],
    queryFn: () => metaApi.custodians({ per_page: 300 }),
    staleTime: 5 * 60 * 1000,
  });

  const options: SelectOption[] = (data ?? []).map((c) => ({ value: c.id, label: c.name }));

  return { options, isLoading };
}

export function useCategoryOptions() {
  const { data, isLoading } = useQuery({
    queryKey: ['asset', 'categories', 'options'],
    queryFn: () => metaApi.categories({ per_page: 200 }),
    staleTime: 10 * 60 * 1000,
  });

  const options: SelectOption[] = (data ?? []).map((c) => ({ value: c.id, label: `${c.code} — ${c.name}` }));

  return { options, categories: data ?? [], isLoading };
}

/** Nomor hari → nama hari untuk jam operasional fasilitas. */
export const DAY_LABELS = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
