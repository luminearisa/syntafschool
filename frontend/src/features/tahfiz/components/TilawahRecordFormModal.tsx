import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Textarea } from '@/components/ui/Textarea';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { useParticipantOptions, useSurahOptions, useSurahs } from '../hooks/useTahfizOptions';
import type { TahfizTilawahRecord, TahfizTilawahRecordPayload } from '@/types/tahfiz';

interface Props {
  open: boolean;
  editing: TahfizTilawahRecord | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: TahfizTilawahRecordPayload) => void;
}

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

function today(): string {
  const now = new Date();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  const day = String(now.getDate()).padStart(2, '0');
  return `${now.getFullYear()}-${month}-${day}`;
}

const emptyForm: TahfizTilawahRecordPayload = {
  participant_id: '',
  date: today(),
  surah_id: '',
  ayah_start: '',
  ayah_end: '',
  juz: '',
  page_count: '',
  duration_minutes: '',
  quality: '',
  note: '',
};

/** Form catat tilawah (bacaan, bukan hafalan). */
export function TilawahRecordFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [values, setValues] = useState<TahfizTilawahRecordPayload>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { options: participantOptions } = useParticipantOptions();
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
            date: editing.date ?? today(),
            surah_id: editing.surah_id ?? '',
            ayah_start: editing.ayah_start ?? '',
            ayah_end: editing.ayah_end ?? '',
            juz: editing.juz ?? '',
            page_count: editing.page_count ?? '',
            duration_minutes: editing.duration_minutes ?? '',
            quality: editing.quality ?? '',
            note: editing.note ?? '',
          }
        : emptyForm,
    );
  }, [open, editing]);

  const set = <K extends keyof TahfizTilawahRecordPayload>(
    key: K,
    value: TahfizTilawahRecordPayload[K],
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
      title={editing ? 'Edit Tilawah' : 'Catat Tilawah'}
      size="lg"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="tilawah-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="tilawah-form" onSubmit={handleSubmit} className="space-y-4">
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
            <Label>Jumlah Halaman</Label>
            <Input
              type="number"
              min={0}
              value={values.page_count}
              error={err('page_count')}
              onChange={(e) =>
                set('page_count', e.target.value === '' ? '' : Number(e.target.value))
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
            <Label>Kualitas (1–5)</Label>
            <Input
              type="number"
              min={1}
              max={5}
              value={values.quality}
              error={err('quality')}
              onChange={(e) =>
                set('quality', e.target.value === '' ? '' : Number(e.target.value))
              }
            />
          </div>
          <div className="sm:col-span-2">
            <Label>Catatan</Label>
            <Textarea
              value={values.note}
              error={err('note')}
              placeholder="Opsional"
              onChange={(e) => set('note', e.target.value)}
            />
          </div>
        </div>
      </form>
    </Modal>
  );
}