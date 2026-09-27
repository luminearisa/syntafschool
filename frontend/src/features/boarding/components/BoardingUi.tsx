import type { ReactNode } from 'react';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Modal } from '@/components/ui/Modal';
import { cn } from '@/lib/utils';
import { STUDENT_STATUS_LABELS, STUDENT_STATUS_TONES } from '../lib/labels';

/** Kartu angka ringkas untuk dashboard & monitoring. */
export function StatCard({
  label,
  value,
  hint,
  tone = 'default',
}: {
  label: string;
  value: ReactNode;
  hint?: string;
  tone?: 'default' | 'brand' | 'green' | 'amber' | 'red';
}) {
  const tones = {
    default: 'text-slate-900',
    brand: 'text-brand-600',
    green: 'text-green-600',
    amber: 'text-amber-600',
    red: 'text-red-600',
  };

  return (
    <Card className="p-4">
      <p className="text-xs font-medium uppercase tracking-wide text-slate-400">{label}</p>
      <p className={cn('mt-1 text-2xl font-semibold', tones[tone])}>{value}</p>
      {hint && <p className="mt-0.5 text-xs text-slate-500">{hint}</p>}
    </Card>
  );
}

/** Badge status keberadaan santri (§42). */
export function StudentStatusBadge({ status }: { status: string }) {
  const tone = (STUDENT_STATUS_TONES[status] ?? 'gray') as
    | 'green'
    | 'blue'
    | 'amber'
    | 'red'
    | 'gray'
    | 'brand';
  return <Badge tone={tone}>{STUDENT_STATUS_LABELS[status] ?? status}</Badge>;
}

/** Label field form + pesan error server. */
export function FormField({
  label,
  required,
  error,
  children,
  className,
}: {
  label: string;
  required?: boolean;
  error?: string[] | string;
  children: ReactNode;
  className?: string;
}) {
  const message = Array.isArray(error) ? error[0] : error;
  return (
    <div className={className}>
      <label className="mb-1 block text-xs font-medium text-slate-600">
        {label}
        {required && <span className="ml-0.5 text-red-500">*</span>}
      </label>
      {children}
      {message && <p className="mt-1 text-xs text-red-600">{message}</p>}
    </div>
  );
}

/** Judul tab sederhana untuk halaman multi-bidang. */
export function Tabs({
  tabs,
  active,
  onChange,
}: {
  tabs: Array<{ key: string; label: string }>;
  active: string;
  onChange: (key: string) => void;
}) {
  return (
    <div className="flex flex-wrap gap-1 rounded-lg bg-slate-100 p-1">
      {tabs.map((t) => (
        <button
          key={t.key}
          type="button"
          onClick={() => onChange(t.key)}
          className={cn(
            'rounded-md px-3 py-1.5 text-sm font-medium transition',
            active === t.key
              ? 'bg-white text-slate-900 shadow-sm'
              : 'text-slate-500 hover:text-slate-800',
          )}
        >
          {t.label}
        </button>
      ))}
    </div>
  );
}

/** Baris tabel kecil untuk modal detail. */
export function DetailRow({ label, value, children }: { label: string; value?: ReactNode; children?: ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-3 border-b border-slate-50 py-1.5 last:border-0">
      <span className="text-xs text-slate-500">{label}</span>
      <span className="text-right text-sm font-medium text-slate-800">{children ?? value ?? '—'}</span>
    </div>
  );
}

/** Tanggal + waktu relatif pendek. */
export function fmtDateTime(value: string | null | undefined): string {
  if (!value) return '—';
  return value.replace('T', ' ').slice(0, 16);
}

export function fmtDate(value: string | null | undefined): string {
  if (!value) return '—';
  return String(value).slice(0, 10);
}

/* ================= form berbasis konfigurasi ================= */

export interface FormFieldConfig {
  name: string;
  label: string;
  type?: 'text' | 'number' | 'date' | 'time' | 'textarea' | 'select' | 'search-select' | 'checkbox';
  options?: Array<{ value: string | number; label: string }>;
  required?: boolean;
  placeholder?: string;
  className?: string;
  /** function sinkron antar-field (mis. kamar menentukan opsi bed). */
  disabled?: boolean;
}

export type FormValues = Record<string, string | number | boolean | ''>;

export function defaultValues(fields: FormFieldConfig[], row?: Record<string, unknown> | null): FormValues {
  const out: FormValues = {};
  for (const f of fields) {
    const raw = row?.[f.name];
    if (typeof raw === 'boolean') out[f.name] = raw;
    else if (typeof raw === 'number') out[f.name] = raw;
    else if (typeof raw === 'string') out[f.name] = raw;
    else out[f.name] = f.type === 'checkbox' ? false : '';
  }
  return out;
}

/**
 * Modal form generik untuk master/data boarding yang sederhana.
 * Field kompleks (picker dependensi) tetap bisa dibuat manual per halaman.
 */
export function ConfigFormModal({
  open,
  title,
  fields,
  values,
  submitting,
  serverErrors,
  onChange,
  onClose,
  onSubmit,
}: {
  open: boolean;
  title: string;
  fields: FormFieldConfig[];
  values: FormValues;
  submitting?: boolean;
  serverErrors?: Record<string, string[]>;
  onChange: (values: FormValues) => void;
  onClose: () => void;
  onSubmit: () => void;
}) {
  const set = (name: string, value: string | number | boolean) =>
    onChange({ ...values, [name]: value });

  return (
    <Modal open={open} onClose={onClose} title={title} size="lg"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>Batal</Button>
          <Button onClick={onSubmit} disabled={submitting}>
            {submitting ? 'Menyimpan...' : 'Simpan'}
          </Button>
        </div>
      }
    >
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        {fields.map((f) => {
          const err = serverErrors?.[f.name];
          const control = (() => {
            switch (f.type) {
              case 'textarea':
                return (
                  <Textarea
                    value={String(values[f.name] ?? '')}
                    placeholder={f.placeholder}
                    onChange={(e) => set(f.name, e.target.value)}
                  />
                );
              case 'select':
                return (
                  <Select value={String(values[f.name] ?? '')} onChange={(e) => set(f.name, e.target.value)}>
                    <option value="">-- Pilih --</option>
                    {(f.options ?? []).map((o) => (
                      <option key={String(o.value)} value={String(o.value)}>{o.label}</option>
                    ))}
                  </Select>
                );
              case 'search-select':
                return (
                  <SearchSelect
                    options={f.options ?? []}
                    value={(values[f.name] as number | '') ?? null}
                    onChange={(v) => set(f.name, v === '' ? '' : Number(v))}
                    placeholder={f.placeholder ?? '-- Pilih --'}
                    disabled={f.disabled}
                  />
                );
              case 'checkbox':
                return (
                  <label className="flex items-center gap-2 py-1.5 text-sm text-slate-700">
                    <input
                      type="checkbox"
                      checked={Boolean(values[f.name])}
                      onChange={(e) => set(f.name, e.target.checked)}
                      className="h-4 w-4 rounded border-slate-300"
                    />
                    {f.label}
                  </label>
                );
              case 'number':
              case 'date':
              case 'time':
                return (
                  <Input
                    type={f.type}
                    value={String(values[f.name] ?? '')}
                    placeholder={f.placeholder}
                    onChange={(e) =>
                      set(f.name, f.type === 'number' ? e.target.value : e.target.value)
                    }
                  />
                );
              default:
                return (
                  <Input
                    value={String(values[f.name] ?? '')}
                    placeholder={f.placeholder}
                    onChange={(e) => set(f.name, e.target.value)}
                  />
                );
            }
          })();
          return (
            <FormField
              key={f.name}
              label={f.type === 'checkbox' ? '' : f.label}
              error={err}
              className={cn(f.type === 'textarea' && 'sm:col-span-2', f.className)}
            >
              {control}
            </FormField>
          );
        })}
      </div>
    </Modal>
  );
}
