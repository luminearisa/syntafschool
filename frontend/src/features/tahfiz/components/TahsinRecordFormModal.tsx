import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Textarea } from '@/components/ui/Textarea';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { SectionTitle } from './TahfizUi';
import { rubricsApi } from '../api/tahfizApi';
import { useHalaqahOptions, useParticipantOptions, useRubricOptions, useSurahOptions, useSurahs } from '../hooks/useTahfizOptions';
import { useEmployeeOptions } from '@/hooks/useLookupOptions';
import type { TahfizTahsinRecord, TahfizTahsinRecordPayload, TahfizTahsinScoreInput } from '@/types/tahfiz';

interface Props {
  open: boolean;
  editing: TahfizTahsinRecord | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: TahfizTahsinRecordPayload) => void;
}

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

function today(): string {
  const now = new Date();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  const day = String(now.getDate()).padStart(2, '0');
  return `${now.getFullYear()}-${month}-${day}`;
}

const emptyForm: TahfizTahsinRecordPayload = {
  participant_id: '',
  halaqah_id: '',
  date: today(),
  rubric_id: '',
  instructor_id: '',
  surah_id: '',
  ayah_start: '',
  ayah_end: '',
  notes: '',
};

/**
 * Form catat tahsin berbasis rubrik.
 *
 * Nilai akhir (`overall_score`) dihitung server dari skor per-kriteria — klien
 * tidak menghitungnya, hanya mengirim daftar skor.
 */
export function TahsinRecordFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [values, setValues] = useState<TahfizTahsinRecordPayload>(emptyForm);
  const [scores, setScores] = useState<TahfizTahsinScoreInput[]>([]);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { options: participantOptions } = useParticipantOptions();
  const { options: halaqahOptions } = useHalaqahOptions();
  const { options: surahOptions } = useSurahOptions();
  const { options: rubricOptions } = useRubricOptions('tahsin');
  const { options: instructorOptions } = useEmployeeOptions(false);
  const { surahs } = useSurahs();

  const maxAyah = useMemo(() => {
    const s = surahs.find((item) => String(item.id) === String(values.surah_id ?? ''));
    return s?.ayah_count ?? null;
  }, [surahs, values.surah_id]);

  const rubricQuery = useQuery({
    queryKey: ['tahfiz', 'rubric', values.rubric_id],
    enabled: open && values.rubric_id !== '',
    queryFn: () => rubricsApi.show(Number(values.rubric_id)),
  });

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setValues(
      editing
        ? {
            participant_id: editing.participant_id,
            halaqah_id: editing.halaqah_id ?? '',
            date: editing.date ?? today(),
            rubric_id: editing.rubric_id ?? '',
            instructor_id: editing.instructor_id ?? '',
            surah_id: editing.surah_id ?? '',
            ayah_start: editing.ayah_start ?? '',
            ayah_end: editing.ayah_end ?? '',
            notes: editing.notes ?? '',
          }
        : emptyForm,
    );
    setScores(
      (editing?.scores ?? []).map((s) => ({
        rubric_item_id: s.rubric_item_id ?? '',
        criterion: s.criterion ?? '',
        score: s.score ?? '',
        note: s.note ?? '',
      })),
    );
  }, [open, editing]);

  // Ketika item rubrik termuat, bangun baris skor per-kriteria (nilai lama dipertahankan).
  useEffect(() => {
    const items = rubricQuery.data?.items;
    if (!items) return;
    setScores((prev) =>
      items.map((item) => {
        const existing = prev.find((p) => String(p.rubric_item_id) === String(item.id));
        return {
          rubric_item_id: item.id,
          criterion: item.criterion,
          score: existing?.score ?? '',
          note: existing?.note ?? '',
        };
      }),
    );
  }, [rubricQuery.data]);

  const set = <K extends keyof TahfizTahsinRecordPayload>(
    key: K,
    value: TahfizTahsinRecordPayload[K],
  ) => {
    setValues((prev) => ({ ...prev, [key]: value }));
    setErrors((prev) => {
      const next = { ...prev };
      delete next[key];
      return next;
    });
  };

  const updateScore = (index: number, key: 'score' | 'note', value: number | '' | string) =>
    setScores((prev) => prev.map((row, i) => (i === index ? { ...row, [key]: value } : row)));

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();

    const local: Record<string, string> = {};
    if (!values.participant_id) local.participant_id = 'Peserta wajib dipilih';
    if (!values.date) local.date = 'Tanggal wajib diisi';
    if (!values.rubric_id) local.rubric_id = 'Rubrik wajib dipilih';
    if (maxAyah !== null && values.ayah_end !== '' && Number(values.ayah_end) > maxAyah) {
      local.ayah_end = `Maksimal ${maxAyah} ayat untuk surah ini`;
    }

    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }

    onSubmit({ ...values, scores });
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? 'Edit Tahsin' : 'Catat Tahsin'}
      size="xl"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="tahsin-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="tahsin-form" onSubmit={handleSubmit} className="space-y-4">
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
            <Label required>Rubrik Tahsin</Label>
            <SearchSelect
              options={rubricOptions}
              value={values.rubric_id}
              error={err('rubric_id')}
              onChange={(v) => set('rubric_id', toId(v))}
              placeholder="-- Pilih Rubrik --"
            />
          </div>
          <div>
            <Label>Pengajar</Label>
            <SearchSelect
              options={instructorOptions}
              value={values.instructor_id}
              error={err('instructor_id')}
              onChange={(v) => set('instructor_id', toId(v))}
              placeholder="-- Opsional --"
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
          <div className="sm:col-span-2 lg:col-span-3">
            <Label>Catatan</Label>
            <Textarea
              value={values.notes}
              error={err('notes')}
              placeholder="Opsional"
              onChange={(e) => set('notes', e.target.value)}
            />
          </div>
        </div>

        <div className="rounded-lg border border-slate-200 p-3">
          <SectionTitle>Penilaian per Kriteria</SectionTitle>
          <p className="mb-2 text-xs text-slate-500">
            Nilai akhir (overall score) dihitung otomatis oleh server berdasarkan bobot rubrik.
          </p>

          {values.rubric_id === '' ? (
            <p className="py-2 text-xs text-slate-400">Pilih rubrik untuk memuat kriteria.</p>
          ) : rubricQuery.isLoading ? (
            <p className="py-2 text-xs text-slate-400">Memuat kriteria...</p>
          ) : scores.length === 0 ? (
            <p className="py-2 text-xs text-slate-400">Rubrik ini belum punya kriteria.</p>
          ) : (
            <div className="space-y-2">
              {scores.map((row, index) => (
                <div
                  key={`${row.rubric_item_id}-${index}`}
                  className="grid grid-cols-1 gap-2 sm:grid-cols-[2fr_0.7fr_2fr] sm:items-start"
                >
                  <div className="flex h-9 items-center text-sm text-slate-700">
                    {row.criterion || `Kriteria #${index + 1}`}
                  </div>
                  <Input
                    type="number"
                    min={0}
                    placeholder="Skor"
                    value={row.score}
                    onChange={(e) =>
                      updateScore(
                        index,
                        'score',
                        e.target.value === '' ? '' : Number(e.target.value),
                      )
                    }
                  />
                  <Input
                    placeholder="Catatan"
                    value={row.note ?? ''}
                    onChange={(e) => updateScore(index, 'note', e.target.value)}
                  />
                </div>
              ))}
            </div>
          )}
        </div>
      </form>
    </Modal>
  );
}