import { useEffect, useMemo, useState } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Spinner } from '@/components/ui/Spinner';
import { useParticipantOptions } from '../hooks/useTahfizOptions';

interface Props {
  open: boolean;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  /** Peserta yang sudah terdaftar — tidak bisa dipilih lagi. */
  existingIds?: number[];
  onClose: () => void;
  onSubmit: (participantIds: number[]) => void;
}

/**
 * Pilih beberapa peserta sekaligus untuk didaftarkan ke ujian.
 */
export function ExamParticipantPickerModal({
  open,
  submitting,
  serverErrors,
  existingIds = [],
  onClose,
  onSubmit,
}: Props) {
  const { options, isLoading } = useParticipantOptions();
  const [selected, setSelected] = useState<number[]>([]);
  const [query, setQuery] = useState('');

  const existing = useMemo(() => new Set(existingIds), [existingIds]);

  useEffect(() => {
    if (!open) return;
    setSelected([]);
    setQuery('');
  }, [open]);

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    return options.filter((o) => {
      if (existing.has(Number(o.value))) return false;
      return !q || o.label.toLowerCase().includes(q);
    });
  }, [options, query, existing]);

  const toggle = (id: number) => {
    setSelected((prev) => (prev.includes(id) ? prev.filter((v) => v !== id) : [...prev, id]));
  };

  const err = serverErrors?.participant_ids?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Daftarkan Peserta Ujian"
      size="lg"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button
            loading={submitting}
            disabled={selected.length === 0}
            onClick={() => onSubmit(selected)}
          >
            Daftarkan {selected.length > 0 ? `(${selected.length})` : ''}
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        <Input
          value={query}
          placeholder="Cari nama peserta..."
          onChange={(e) => setQuery(e.target.value)}
        />
        {err && <p className="text-xs text-red-500">{err}</p>}
        {selected.length > 0 && (
          <p className="text-xs text-slate-500">{selected.length} peserta dipilih.</p>
        )}

        <div className="max-h-72 divide-y divide-slate-50 overflow-y-auto rounded-lg border border-slate-200">
          {isLoading ? (
            <div className="flex items-center justify-center gap-2 py-10 text-slate-400">
              <Spinner /> Memuat peserta...
            </div>
          ) : filtered.length === 0 ? (
            <p className="py-8 text-center text-sm text-slate-400">
              Tidak ada peserta yang bisa didaftarkan.
            </p>
          ) : (
            filtered.map((o) => {
              const id = Number(o.value);
              return (
                <label
                  key={String(o.value)}
                  className="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm hover:bg-slate-50"
                >
                  <input
                    type="checkbox"
                    className="h-4 w-4 rounded border-slate-300"
                    checked={selected.includes(id)}
                    onChange={() => toggle(id)}
                  />
                  <span className="text-slate-700">{o.label}</span>
                </label>
              );
            })
          )}
        </div>
      </div>
    </Modal>
  );
}