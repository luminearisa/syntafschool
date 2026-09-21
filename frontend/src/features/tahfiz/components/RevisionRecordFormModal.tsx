import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Textarea } from '@/components/ui/Textarea';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Select } from '@/components/ui/Select';
import { useHalaqahOptions, useParticipantOptions, useSurahOptions, useSurahs } from '../hooks/useTahfizOptions';
import { REVISION_METHOD_LABELS } from '../lib/labels';
import type { TahfizRevisionMethod, TahfizRevisionRecord, TahfizRevisionRecordPayload } from '@/types/tahfiz';

interface Props {
  open: boolean;
  editing: TahfizRevisionRecord | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: TahfizRevisionRecordPayload) => void;
}

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

function today(): string {
  const now = new Date();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  const day = String(now.getDate()).padStart(2, '0');
  return `${now.getFullYear()}-${month}-${day}`;
}

const emptyForm: TahfizRevisionRecordPayload = {
  participant_id: '',
  halaqah_id: '',
  date: today(),
  surah_id: '',
  ayah_start: '',
  ayah_end: '',
  juz: '',
  page_start: '',
  page_end: '',
  method: '',
  duration_minutes: '',
  score: '',
  mistakes_count: '',
  teacher_note: '',
};

/** Form catat murojaah (revisi hafalan). */
export function RevisionRecordFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [values, setValues] = useState<TahfizRevisionRecordPayload>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { options: participantOptions } = useParticipantOptions();
  const { options: halaqahOptions } = useHalaqahOptions();
  const { options: surahOptions } = useSurahOptions();
  const { surahs } = useSurahs();

  const maxAyah = useMemo(() => {
    const s = surahs.find((item) => String(item.id) === String(values.surah_id ?? ''));
    return s?.ayah_count ?? null;
  }, [surahs, values.surah_id]);

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setValues(
      editing
        ? {
            participant_id: editing.participant_id,
            halaqah_id: editing.halaqah_id ?? '',
            date: editing.date ?? today(),
            surah_id: editing.surah_id ?? '',
            ayah_start: editing.ayah_start ?? '',
            ayah_end: editing.ayah_end ?? '',
            juz: editing.juz ?? '',
            page_start: editing.page_start ?? '',
            page_end: editing.page_end ?? '',
            method: editing.method ?? '',
            duration_minutes: editing.duration_minutes ?? '',
            score: editing.score ?? '',
            mistakes_count: editing.mistakes_count ?? '',
            teacher_note: editing.teacher_note ?? '',
          }
        : emptyForm,
    );
  }, [open, editing]);

  const set = <K extends keyof TahfizRevisionRecordPayload>(
    key: K,
    value: TahfizRevisionRecordPayload[K],
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
    if (maxAyah !== null && values.ayah_end !== '' && Number(values.ayah_end) > maxAyah) {
      local.ayah_end = `Maksimal ${maxAyah} ayat untuk surah ini`;
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
      title={editing ? 'Edit Murojaah' : 'Catat Murojaah'}
      size="lg"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="revision-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="revision-form" onSubmit={handleSubmit} className="space-y-4">
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
            <Label>Halaqah</Label>
            <SearchSelect
              options={halaqahOptions}
              value={values.halaqah_id}
              error={err('halaqah_id')}
              onChange={(v) => set('halaqah_id', toId(v))}
              placeholder="-- Opsional --"
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
            <Label>Metode</Label>
            <Select
              value={values.method}
              error={err('method')}
              onChange={(e) => set('method', e.target.value as TahfizRevisionMethod | '')}
            >
              <option value="">-- Pilih Metode --</option>
              {(Object.keys(REVISION_METHOD_LABELS) as TahfizRevisionMethod[]).map((key) => (
                <option key={key} value={key}>
                  {REVISION_METHOD_LABELS[key]}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label>Surah</Label>
            <SearchSelect
              options={surahOptions}
              value={values.surah_id}
              error={err('surah_id')}
              onChange={(v) => set('surah_id', toId(v))}
              placeholder="-- Opsional --"
            />
          </div>
          <div>
            <Label>Juz</Label>
            <Input
              type="number"
              min={1}
              max={30}
              value={values.juz}
              error={err('juz')}
              onChange={(e) => set('juz', e.target.value === '' ? '' : Number(e.target.value))}
            />
          </div>
          <div>
            <Label>Ayat Awal</Label>
            <Input
              type="number"
              min={1}
              value={values.ayah_start}
              error={err('ayah_start')}
              onChange={(e) =>
                set('ayah_start', e.target.value === '' ? '' : Number(e.target.value))
              }
            />
          </div>
          <div>
            <Label>Ayat Akhir</Label>
            <Input
              type="number"
              min={1}
              max={maxAyah ?? undefined}
              value={values.ayah_end}
              error={err('ayah_end')}
              onChange={(e) =>
                set('ayah_end', e.target.value === '' ? '' : Number(e.target.value))
              }
            />
            {maxAyah !== null && (
              <p className="mt-1 text-xs text-slate-400">Jumlah ayat surah ini: {maxAyah}</p>
            )}
          </div>
          <div>
            <Label>Halaman Awal</Label>
            <Input
              type="number"
              min={1}
              value={values.page_start}
              error={err('page_start')}
              onChange={(e) =>
                set('page_start', e.target.value === '' ? '' : Number(e.target.value))
              }
            />
          </div>
          <div>
            <Label>Halaman Akhir</Label>
            <Input
              type="number"
              min={1}
              value={values.page_end}
              error={err('page_end')}
              onChange={(e) =>
                set('page_end', e.target.value === '' ? '' : Number(e.target.value))
              }
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
          <div className="sm:col-span-2">
            <Label>Catatan Guru</Label>
            <Textarea
              value={values.teacher_note}
              error={err('teacher_note')}
              placeholder="Opsional"
              onChange={(e) => set('teacher_note', e.target.value)}
            />
          </div>
        </div>
      </form>
    </Modal>
  );
}