import { useEffect, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Textarea } from '@/components/ui/Textarea';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Spinner } from '@/components/ui/Spinner';
import { useMutabaahItemOptions, useParticipantOptions } from '../hooks/useTahfizOptions';
import { MUTABAAH_CATEGORY_LABELS } from '../lib/labels';
import type { TahfizMutabaah, TahfizMutabaahEntryInput, TahfizMutabaahPayload } from '@/types/tahfiz';

interface Props {
  open: boolean;
  editing: TahfizMutabaah | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: TahfizMutabaahPayload) => void;
}

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

const today = (): string => new Date().toISOString().slice(0, 10);

interface EntryState {
  done: boolean;
  value: number | '';
  note: string;
}

/**
 * Form mutabaah harian.
 *
 * Item tidak di-hardcode — semuanya berasal dari master data item mutabaah
 * aktif. Backend menyimpan satu baris per peserta per tanggal, jadi mengirim
 * ulang tanggal yang sama akan memperbarui data lama, bukan menggandakannya.
 */
export function MutabaahFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const { options: participantOptions } = useParticipantOptions();
  const { items, isLoading: itemsLoading } = useMutabaahItemOptions();

  const [participantId, setParticipantId] = useState<number | ''>('');
  const [date, setDate] = useState(today());
  const [notes, setNotes] = useState('');
  const [entriesState, setEntriesState] = useState<Record<number, EntryState>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (!open) return;
    setErrors({});
    if (editing) {
      setParticipantId(editing.participant_id);
      setDate(editing.date ?? today());
      setNotes(editing.notes ?? '');
      const next: Record<number, EntryState> = {};
      (editing.entries ?? []).forEach((entry) => {
        const itemId = entry.item_id ?? entry.item?.id ?? null;
        if (itemId === null) return;
        next[itemId] = {
          done: entry.done,
          value: entry.value ?? '',
          note: entry.note ?? '',
        };
      });
      setEntriesState(next);
    } else {
      setParticipantId('');
      setDate(today());
      setNotes('');
      setEntriesState({});
    }
  }, [open, editing]);

  const row = (itemId: number): EntryState =>
    entriesState[itemId] ?? { done: false, value: '', note: '' };

  const updateRow = (itemId: number, patch: Partial<EntryState>) => {
    setEntriesState((prev) => ({ ...prev, [itemId]: { ...row(itemId), ...patch } }));
  };

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (!participantId) local.participant_id = 'Peserta wajib dipilih';
    if (!date) local.date = 'Tanggal wajib diisi';
    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }

    const entries: TahfizMutabaahEntryInput[] = items.map((item) => {
      const state = row(item.id);
      const entry: TahfizMutabaahEntryInput = { item_id: item.id };
      if (item.value_type === 'count') {
        if (state.value !== '') entry.value = state.value;
      } else {
        entry.done = state.done;
      }
      const note = state.note.trim();
      if (note) entry.note = note;
      return entry;
    });

    onSubmit({
      participant_id: participantId,
      date,
      notes: notes.trim(),
      entries,
    });
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? 'Edit Mutabaah Harian' : 'Isi Mutabaah Harian'}
      size="xl"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="mutabaah-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="mutabaah-form" onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label required>Peserta</Label>
            <SearchSelect
              options={participantOptions}
              value={participantId}
              error={err('participant_id')}
              onChange={(v) => setParticipantId(toId(v))}
              placeholder="-- Pilih Peserta --"
            />
          </div>
          <div>
            <Label required>Tanggal</Label>
            <Input
              type="date"
              value={date}
              error={err('date')}
              onChange={(e) => setDate(e.target.value)}
            />
          </div>
        </div>

        <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
          Satu baris per peserta per tanggal — mengirim ulang tanggal yang sama akan memperbarui
          data lama, bukan membuat duplikat.
        </p>

        {itemsLoading ? (
          <div className="flex items-center justify-center gap-2 py-8 text-slate-400">
            <Spinner /> Memuat item mutabaah...
          </div>
        ) : items.length === 0 ? (
          <p className="rounded-lg border border-dashed border-slate-200 px-3 py-6 text-center text-sm text-slate-400">
            Belum ada item mutabaah aktif. Tambahkan item terlebih dahulu di master data.
          </p>
        ) : (
          <div className="space-y-2">
            {items.map((item) => {
              const state = row(item.id);
              const isCount = item.value_type === 'count';
              return (
                <div
                  key={item.id}
                  className="grid grid-cols-1 gap-3 rounded-lg border border-slate-200 p-3 sm:grid-cols-12 sm:items-start"
                >
                  <div className="sm:col-span-5">
                    <p className="text-sm font-medium text-slate-800">{item.label}</p>
                    <p className="text-xs text-slate-400">
                      {item.category ? MUTABAAH_CATEGORY_LABELS[item.category] : 'Umum'}
                      {isCount && item.unit ? ` • ${item.unit}` : ''}
                      {isCount && item.target_value ? ` • target ${item.target_value}` : ''}
                    </p>
                  </div>
                  <div className="sm:col-span-3">
                    {isCount ? (
                      <Input
                        type="number"
                        min={0}
                        value={state.value}
                        placeholder="Jumlah"
                        onChange={(e) =>
                          updateRow(item.id, {
                            value: e.target.value === '' ? '' : Number(e.target.value),
                          })
                        }
                      />
                    ) : (
                      <label className="flex h-9 items-center gap-2 text-sm text-slate-700">
                        <input
                          type="checkbox"
                          className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500/40"
                          checked={state.done}
                          onChange={(e) => updateRow(item.id, { done: e.target.checked })}
                        />
                        {state.done ? 'Selesai' : 'Belum'}
                      </label>
                    )}
                  </div>
                  <div className="sm:col-span-4">
                    <Input
                      value={state.note}
                      placeholder="Catatan (opsional)"
                      onChange={(e) => updateRow(item.id, { note: e.target.value })}
                    />
                  </div>
                </div>
              );
            })}
          </div>
        )}

        <div>
          <Label>Catatan Harian</Label>
          <Textarea
            value={notes}
            error={err('notes')}
            placeholder="Catatan tambahan untuk hari ini (opsional)"
            onChange={(e) => setNotes(e.target.value)}
          />
        </div>
      </form>
    </Modal>
  );
}