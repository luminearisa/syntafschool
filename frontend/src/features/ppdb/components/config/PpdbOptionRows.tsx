import { GripVertical, Plus, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import type { PpdbFieldOption } from '@/types/ppdb';
import type { PpdbQuestionOption } from '@/types/ppdb';

/** Bentuk baris pilihan di dalam form (selalu string agar terkendali sebagai input). */
export interface OptionRow {
  label: string;
  value: string;
}

export function toOptionRows(
  options: PpdbFieldOption[] | PpdbQuestionOption[] | null | undefined,
): OptionRow[] {
  return (options ?? []).map((option) => ({
    label: String(option.label ?? ''),
    value: String(option.value ?? ''),
  }));
}

/** Buang baris kosong; nilai dikosongkan bila sama dengan label agar backend mendeduksinya. */
export function fromOptionRows(rows: OptionRow[]): OptionRow[] {
  return rows
    .map((row) => ({ label: row.label.trim(), value: row.value.trim() }))
    .filter((row) => row.label !== '');
}

/**
 * Editor baris label/nilai untuk field & pertanyaan bertipe pilihan.
 *
 * Pertanyaan menyimpan `PpdbQuestionOption` (punya `id` & `order`), field
 * menyimpan `PpdbFieldOption`; keduanya cocok dengan bentuk keluaran ini
 * sehingga satu editor dipakai dua halaman.
 */
export function PpdbOptionRows({
  rows,
  onChange,
  label = 'Pilihan',
  valueLabel = 'Nilai',
  emptyHint = 'Belum ada pilihan. Minimal satu pilihan diperlukan untuk tipe pilihan.',
  error,
}: {
  rows: OptionRow[];
  onChange: (rows: OptionRow[]) => void;
  label?: string;
  valueLabel?: string;
  emptyHint?: string;
  error?: string;
}) {
  const patch = (index: number, next: Partial<OptionRow>) =>
    onChange(rows.map((row, i) => (i === index ? { ...row, ...next } : row)));

  const remove = (index: number) => onChange(rows.filter((_, i) => i !== index));

  return (
    <div>
      <div className="mb-1 flex items-center justify-between">
        <span className="text-sm font-medium text-slate-700">{label}</span>
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => onChange([...rows, { label: '', value: '' }])}
        >
          <Plus className="h-3.5 w-3.5" /> Tambah
        </Button>
      </div>

      {rows.length === 0 ? (
        <p className="rounded-lg border border-dashed border-slate-200 px-3 py-2 text-xs text-slate-400">
          {emptyHint}
        </p>
      ) : (
        <ul className="space-y-2">
          {rows.map((row, index) => (
            <li key={index} className="flex items-start gap-2">
              <span className="mt-2 text-slate-300">
                <GripVertical className="h-4 w-4" />
              </span>
              <div className="flex-1 space-y-1">
                <Input
                  value={row.label}
                  placeholder={`Label pilihan ${index + 1}`}
                  error={index === 0 ? error : undefined}
                  onChange={(e) => patch(index, { label: e.target.value })}
                />
                <Input
                  value={row.value}
                  placeholder={valueLabel}
                  onChange={(e) => patch(index, { value: e.target.value })}
                />
              </div>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                title="Hapus pilihan"
                onClick={() => remove(index)}
              >
                <Trash2 className="h-3.5 w-3.5 text-red-500" />
              </Button>
            </li>
          ))}
        </ul>
      )}
      {error && rows.length > 0 && <p className="mt-1 text-xs text-red-500">{error}</p>}
    </div>
  );
}
