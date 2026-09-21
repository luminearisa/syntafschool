import { Link } from 'react-router-dom';
import { CalendarClock } from 'lucide-react';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Select } from '@/components/ui/Select';
import type { SelectOption } from '@/constants/masterData';

/**
 * Pemilih periode — konteks semua halaman konfigurasi kecuali daftar periode.
 *
 * `value` adalah periode efektif yang sudah diisi otomatis dengan periode
 * pertama, jadi komponen ini tidak pernah menampilkan keadaan kosong palsu.
 */
export function PpdbPeriodPicker({
  value,
  options,
  isLoading,
  onChange,
  label = 'Periode',
}: {
  value: number | '';
  options: SelectOption[];
  isLoading?: boolean;
  onChange: (value: number | '') => void;
  label?: string;
}) {
  return (
    <div>
      <label className="mb-1 block text-xs font-medium text-slate-600">{label}</label>
      <Select
        value={String(value)}
        disabled={isLoading || options.length === 0}
        onChange={(e) => onChange(e.target.value === '' ? '' : Number(e.target.value))}
      >
        {options.length === 0 && <option value="">Belum ada periode</option>}
        {options.map((option) => (
          <option key={String(option.value)} value={String(option.value)}>
            {option.label}
          </option>
        ))}
      </Select>
    </div>
  );
}

/** Keadaan kosong ketika konfigurasi menuntut satu periode tetapi belum ada. */
export function PpdbNoPeriodState() {
  return (
    <Card>
      <EmptyState
        title="Belum ada periode PPDB"
        description="Semua konfigurasi mengikuti periode. Buat periode pertama terlebih dahulu."
        action={
          <Link
            to="/ppdb/periods"
            className="mt-2 inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
          >
            <CalendarClock className="h-4 w-4" /> Buat Periode
          </Link>
        }
      />
    </Card>
  );
}
