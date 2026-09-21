import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Textarea } from '@/components/ui/Textarea';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Select } from '@/components/ui/Select';
import { SectionTitle } from './TahfizUi';
import {
  useHalaqahOptions,
  useMistakeTypeOptions,
  useParticipantOptions,
  useSurahOptions,
  useSurahs,
} from '../hooks/useTahfizOptions';
import { MEMORIZATION_TYPE_LABELS } from '../lib/labels';
import type {
  TahfizMemorizationMistakeInput,
  TahfizMemorizationRecord,
  TahfizMemorizationRecordPayload,
  TahfizMemorizationType,
} from '@/types/tahfiz';

interface Props {
  open: boolean;
  editing: TahfizMemorizationRecord | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: TahfizMemorizationRecordPayload) => void;
}

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

function today(): string {
  const now = new Date();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  const day = String(now.getDate()).padStart(2, '0');
  return `${now.getFullYear()}-${month}-${day}`;
}

const emptyForm: TahfizMemorizationRecordPayload = {
  participant_id: '',
  halaqah_id: '',
  enrollment_id: '',
  date: today(),
  type: 'hafalan_baru',
  surah_id: '',
  ayah_start: '',
  ayah_end: '',
  juz: '',
  page_start: '',
  page_end: '',
  line_count: '',
  duration_minutes: '',
  score: '',
  fluency: '',
  tajwid: '',
  makhraj: '',
  teacher_note: '',
  participant_note: '',
  mistakes: [],
};

/**
 * Form catat setoran hafalan.
 *
 * Status TIDAK diisi di sini — record selalu mulai sebagai `submitted` dan
 * hanya berubah lewat endpoint peninjauan terpisah.
 */
export function MemorizationRecordFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [values, setValues] = useState<TahfizMemorizationRecordPayload>(emptyForm);
  const [mistakes, setMistakes] = useState<TahfizMemorizationMistakeInput[]>([]);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { options: participantOptions } = useParticipantOptions();
  const { options: halaqahOptions } = useHalaqahOptions();
  const { options: surahOptions } = useSurahOptions();
  const { options: mistakeTypeOptions } = useMistakeTypeOptions();
  const { surahs } = useSurahs();

  const selectedSurah = useMemo(
    () => surahs.find((s) => String(s.id) === String(values.surah_id ?? '')),
    [surahs, values.surah_id],
  );
  const maxAyah = selectedSurah?.ayah_count ?? null;

  useEffect(() => {
    if (!open) return;
    setErrors({});

    if (editing) {
      setValues({
        participant_id: editing.participant_id,
        halaqah_id: editing.halaqah_id ?? '',
        enrollment_id: editing.enrollment_id ?? '',
        date: editing.date ?? today(),
        type: editing.type,
        surah_id: editing.surah_id,
        ayah_start: editing.ayah_start,
        ayah_end: editing.ayah_end,
        juz: editing.juz ?? '',
        page_start: editing.page_start ?? '',
        page_end: editing.page_end ?? '',
        line_count: editing.line_count ?? '',
        duration_minutes: editing.duration_minutes ?? '',
        score: editing.score ?? '',
        fluency: editing.fluency ?? '',
        tajwid: editing.tajwid ?? '',
        makhraj: editing.makhraj ?? '',
        teacher_note: editing.teacher_note ?? '',
        participant_note: editing.participant_note ?? '',
      });
      setMistakes(
        (editing.mistakes ?? []).map((m) => ({
          mistake_type_id: m.mistake_type_id ?? '',
          ayah: m.ayah ?? '',
          count: m.count ?? '',
          note: m.note ?? '',
        })),
      );
    } else {
      setValues(emptyForm);
      setMistakes([]);
    }
  }, [open, editing]);

  const set = <K extends keyof TahfizMemorizationRecordPayload>(
    key: K,
    value: TahfizMemorizationRecordPayload[K],
  ) => {
    setValues((prev) => ({ ...prev, [key]: value }));
    setErrors((prev) => {
      const next = { ...prev };
      delete next[key];
      return next;
    });
  };

  const addMistake = () =>
    setMistakes((prev) => [...prev, { mistake_type_id: '', ayah: '', count: '', note: '' }]);

  const removeMistake = (index: number) =>
    setMistakes((prev) => prev.filter((_, i) => i !== index));

  const updateMistake = (
    index: number,
    key: keyof TahfizMemorizationMistakeInput,
    value: number | '' | string,
  ) =>
    setMistakes((prev) =>
      prev.map((row, i) => (i === index ? { ...row, [key]: value } : row)),
    );

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();

    const local: Record<string, string> = {};
    if (!values.participant_id) local.participant_id = 'Peserta wajib dipilih';
    if (!values.date) local.date = 'Tanggal wajib diisi';
    if (!values.type) local.type = 'Jenis setoran wajib dipilih';
    if (!values.surah_id) local.surah_id = 'Surah wajib dipilih';
    if (values.ayah_start === '') local.ayah_start = 'Ayat awal wajib diisi';
    if (values.ayah_end === '') local.ayah_end = 'Ayat akhir wajib diisi';
    if (
      values.ayah_start !== '' &&
      values.ayah_end !== '' &&
      Number(values.ayah_end) < Number(values.ayah_start)
    ) {
      local.ayah_end = 'Ayat akhir tidak boleh lebih kecil dari ayat awal';
    }
    if (maxAyah !== null && values.ayah_end !== '' && Number(values.ayah_end) > maxAyah) {
      local.ayah_end = `Maksimal ${maxAyah} ayat untuk surah ini`;
    }

    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }

    onSubmit({
      ...values,
      mistakes: mistakes.filter((m) => m.mistake_type_id !== '' || m.note || m.ayah !== ''),
    });
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? 'Edit Setoran Hafalan' : 'Catat Setoran Hafalan'}
      size="xl"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="memorization-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="memorization-form" onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
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
            <Label required>Jenis Setoran</Label>
            <Select
              value={values.type}
              error={err('type')}
              onChange={(e) => set('type', e.target.value as TahfizMemorizationType)}
            >
              {(Object.keys(MEMORIZATION_TYPE_LABELS) as TahfizMemorizationType[]).map((key) => (
                <option key={key} value={key}>
                  {MEMORIZATION_TYPE_LABELS[key]}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label required>Surah</Label>
            <SearchSelect
              options={surahOptions}
              value={values.surah_id}
              error={err('surah_id')}
              onChange={(v) => set('surah_id', toId(v))}
              placeholder="-- Pilih Surah --"
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
            <Label required>Ayat Awal</Label>
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
            <Label required>Ayat Akhir</Label>
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
            <Label>Jumlah Baris</Label>
            <Input
              type="number"
              min={0}
              value={values.line_count}
              error={err('line_count')}
              onChange={(e) =>
                set('line_count', e.target.value === '' ? '' : Number(e.target.value))
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
            <Label>Kelancaran (1–5)</Label>
            <Input
              type="number"
              min={1}
              max={5}
              value={values.fluency}
              error={err('fluency')}
              onChange={(e) =>
                set('fluency', e.target.value === '' ? '' : Number(e.target.value))
              }
            />
          </div>
          <div>
            <Label>Tajwid (1–5)</Label>
            <Input
              type="number"
              min={1}
              max={5}
              value={values.tajwid}
              error={err('tajwid')}
              onChange={(e) => set('tajwid', e.target.value === '' ? '' : Number(e.target.value))}
            />
          </div>
          <div>
            <Label>Makhraj (1–5)</Label>
            <Input
              type="number"
              min={1}
              max={5}
              value={values.makhraj}
              error={err('makhraj')}
              onChange={(e) =>
                set('makhraj', e.target.value === '' ? '' : Number(e.target.value))
              }
            />
          </div>
          <div className="sm:col-span-2 lg:col-span-3">
            <Label>Catatan Guru</Label>
            <Textarea
              value={values.teacher_note}
              error={err('teacher_note')}
              placeholder="Catatan untuk peserta"
              onChange={(e) => set('teacher_note', e.target.value)}
            />
          </div>
          <div className="sm:col-span-2 lg:col-span-3">
            <Label>Catatan Peserta</Label>
            <Textarea
              value={values.participant_note}
              error={err('participant_note')}
              placeholder="Opsional"
              onChange={(e) => set('participant_note', e.target.value)}
            />
          </div>
        </div>

        <div className="rounded-lg border border-slate-200 p-3">
          <div className="mb-2 flex items-center justify-between">
            <SectionTitle>Rincian Kesalahan</SectionTitle>
            <Button type="button" size="sm" variant="outline" onClick={addMistake}>
              <Plus className="h-3.5 w-3.5" /> Tambah
            </Button>
          </div>

          {mistakes.length === 0 ? (
            <p className="py-2 text-xs text-slate-400">Belum ada kesalahan dicatat.</p>
          ) : (
            <div className="space-y-2">
              {mistakes.map((row, index) => (
                <div
                  key={index}
                  className="grid grid-cols-1 gap-2 sm:grid-cols-[1.5fr_0.7fr_0.7fr_2fr_auto] sm:items-start"
                >
                  <SearchSelect
                    options={mistakeTypeOptions}
                    value={row.mistake_type_id ?? ''}
                    onChange={(v) => updateMistake(index, 'mistake_type_id', toId(v))}
                    placeholder="-- Jenis --"
                  />
                  <Input
                    type="number"
                    min={1}
                    placeholder="Ayat"
                    value={row.ayah ?? ''}
                    onChange={(e) =>
                      updateMistake(
                        index,
                        'ayah',
                        e.target.value === '' ? '' : Number(e.target.value),
                      )
                    }
                  />
                  <Input
                    type="number"
                    min={1}
                    placeholder="Jumlah"
                    value={row.count ?? ''}
                    onChange={(e) =>
                      updateMistake(
                        index,
                        'count',
                        e.target.value === '' ? '' : Number(e.target.value),
                      )
                    }
                  />
                  <Input
                    placeholder="Catatan"
                    value={row.note ?? ''}
                    onChange={(e) => updateMistake(index, 'note', e.target.value)}
                  />
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    title="Hapus baris"
                    onClick={() => removeMistake(index)}
                  >
                    <Trash2 className="h-4 w-4 text-red-500" />
                  </Button>
                </div>
              ))}
            </div>
          )}
        </div>
      </form>
    </Modal>
  );
}