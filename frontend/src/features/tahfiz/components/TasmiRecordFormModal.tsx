import { useEffect, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Textarea } from '@/components/ui/Textarea';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Select } from '@/components/ui/Select';
import { useParticipantOptions } from '../hooks/useTahfizOptions';
import { useEmployeeOptions } from '@/hooks/useLookupOptions';
import { TASMI_RESULT_LABELS } from '../lib/labels';
import type { TahfizTasmiRecord, TahfizTasmiRecordPayload, TahfizTasmiResult } from '@/types/tahfiz';

interface Props {
  open: boolean;
  editing: TahfizTasmiRecord | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: TahfizTasmiRecordPayload) => void;
}

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

function today(): string {
  const now = new Date();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  const day = String(now.getDate()).padStart(2, '0');
  return `${now.getFullYear()}-${month}-${day}`;
}

const emptyForm: TahfizTasmiRecordPayload = {
  participant_id: '',
  date: today(),
  juz_from: '',
  juz_to: '',
  duration_minutes: '',
  mistakes_count: '',
  score: '',
  examiner_id: '',
  result: '',
  notes: '',
};

/** Form catat tasmi' (setoran porsi besar di depan penguji). */
export function TasmiRecordFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [values, setValues] = useState<TahfizTasmiRecordPayload>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { options: participantOptions } = useParticipantOptions();
  const { options: examinerOptions } = useEmployeeOptions(false);

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setValues(
      editing
        ? {
            participant_id: editing.participant_id,
            date: editing.date ?? today(),
            juz_from: editing.juz_from,
            juz_to: editing.juz_to,
            duration_minutes: editing.duration_minutes ?? '',
            mistakes_count: editing.mistakes_count ?? '',
            score: editing.score ?? '',
            examiner_id: editing.examiner_id ?? '',
            result: editing.result ?? '',
            notes: editing.notes ?? '',
          }
        : emptyForm,
    );
  }, [open, editing]);

  const set = <K extends keyof TahfizTasmiRecordPayload>(
    key: K,
    value: TahfizTasmiRecordPayload[K],
  ) => {
    setValues((prev) => ({ ...prev, [key]: value }));
    setErrors((prev) => {
      const next = { ...prev };
      delete next[key];
      return next;
    });
  };

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();

    const local: Record<string, string> = {};
    if (!values.participant_id) local.participant_id = 'Peserta wajib dipilih';
    if (!values.date) local.date = 'Tanggal wajib diisi';
    if (values.juz_from === '') local.juz_from = 'Juz awal wajib diisi';
    if (values.juz_to === '') local.juz_to = 'Juz akhir wajib diisi';
    if (
      values.juz_from !== '' &&
      values.juz_to !== '' &&
      Number(values.juz_to) < Number(values.juz_from)
    ) {
      local.juz_to = 'Juz akhir tidak boleh lebih kecil dari juz awal';
    }

    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }

    onSubmit(values);
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? "Edit Tasmi'" : "Catat Tasmi'"}
      size="lg"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="tasmi-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="tasmi-form" onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label required>Peserta</Label>
            <SearchSelect
              options={participantOptions}
              value={values.participant_id}
              error={err('participant_id')}
              onChange={(v) => set('participant_id', toId(v))}
              placeholder="-- Pilih Peserta --"
            />
          </div>
          <div>
            <Label required>Tanggal</Label>
            <Input
              type="date"
              value={values.date}
              error={err('date')}
              onChange={(e) => set('date', e.target.value)}
            />
          </div>
          <div>
            <Label required>Juz Awal</Label>
            <Input
              type="number"
              min={1}
              max={30}
              value={values.juz_from}
              error={err('juz_from')}
              onChange={(e) =>
                set('juz_from', e.target.value === '' ? '' : Number(e.target.value))
              }
            />
          </div>
          <div>
            <Label required>Juz Akhir</Label>
            <Input
              type="number"
              min={1}
              max={30}
              value={values.juz_to}
              error={err('juz_to')}
              onChange={(e) => set('juz_to', e.target.value === '' ? '' : Number(e.target.value))}
            />
          </div>
          <div>
            <Label>Durasi (menit)</Label>
            <Input
              type="number"
              min={0}
              value={values.duration_minutes}
              error={err('duration_minutes')}
              onChange={(e) =>
                set('duration_minutes', e.target.value === '' ? '' : Number(e.target.value))
              }
            />
          </div>
          <div>
            <Label>Jumlah Kesalahan</Label>
            <Input
              type="number"
              min={0}
              value={values.mistakes_count}
              error={err('mistakes_count')}
              onChange={(e) =>
                set('mistakes_count', e.target.value === '' ? '' : Number(e.target.value))
              }
            />
          </div>
          <div>
            <Label>Nilai (0–100)</Label>
            <Input
              type="number"
              min={0}
              max={100}
              value={values.score}
              error={err('score')}
              onChange={(e) => set('score', e.target.value === '' ? '' : Number(e.target.value))}
            />
          </div>
          <div>
            <Label>Penguji</Label>
            <SearchSelect
              options={examinerOptions}
              value={values.examiner_id}
              error={err('examiner_id')}
              onChange={(v) => set('examiner_id', toId(v))}
              placeholder="-- Opsional --"
            />
          </div>
          <div>
            <Label>Hasil</Label>
            <Select
              value={values.result}
              error={err('result')}
              onChange={(e) => set('result', e.target.value as TahfizTasmiResult | '')}
            >
              <option value="">-- Pilih Hasil --</option>
              {(Object.keys(TASMI_RESULT_LABELS) as TahfizTasmiResult[]).map((key) => (
                <option key={key} value={key}>
                  {TASMI_RESULT_LABELS[key]}
                </option>
              ))}
            </Select>
          </div>
          <div className="sm:col-span-2">
            <Label>Catatan</Label>
            <Textarea
              value={values.notes}
              error={err('notes')}
              placeholder="Opsional"
              onChange={(e) => set('notes', e.target.value)}
            />
          </div>
        </div>
      </form>
    </Modal>
  );
}