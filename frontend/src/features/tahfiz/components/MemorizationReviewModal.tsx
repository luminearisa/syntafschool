import { useEffect, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Textarea } from '@/components/ui/Textarea';
import { Select } from '@/components/ui/Select';
import { FieldRow, SectionTitle } from './TahfizUi';
import { REVIEW_STATUS_OPTIONS } from '../lib/labels';
import type { TahfizMemorizationRecord, TahfizMemorizationReviewPayload, TahfizReviewStatus } from '@/types/tahfiz';

interface Props {
  open: boolean;
  record: TahfizMemorizationRecord | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: TahfizMemorizationReviewPayload) => void;
}

const emptyForm: TahfizMemorizationReviewPayload = {
  status: 'accepted',
  score: '',
  fluency: '',
  tajwid: '',
  makhraj: '',
  revision_due_date: '',
  teacher_note: '',
};

/**
 * Form peninjauan (review) setoran hafalan.
 *
 * Status di sini adalah satu-satunya jalan mengubah status record — dibuat
 * `submitted` saat pencatatan. `accepted_with_revision` mewajibkan tanggal
 * batas revisi, mencerminkan aturan backend.
 */
export function MemorizationReviewModal({
  open,
  record,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [values, setValues] = useState<TahfizMemorizationReviewPayload>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setValues({
      ...emptyForm,
      score: record?.score ?? '',
      fluency: record?.fluency ?? '',
      tajwid: record?.tajwid ?? '',
      makhraj: record?.makhraj ?? '',
      teacher_note: record?.teacher_note ?? '',
    });
  }, [open, record]);

  const set = <K extends keyof TahfizMemorizationReviewPayload>(
    key: K,
    value: TahfizMemorizationReviewPayload[K],
  ) => {
    setValues((prev) => ({ ...prev, [key]: value }));
    setErrors((prev) => {
      const next = { ...prev };
      delete next[key];
      return next;
    });
  };

  const requiresRevision = values.status === 'accepted_with_revision';

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();

    const local: Record<string, string> = {};
    if (!values.status) local.status = 'Status penilaian wajib dipilih';
    if (requiresRevision && !values.revision_due_date) {
      local.revision_due_date = 'Tanggal batas revisi wajib diisi';
    }

    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }

    onSubmit(values);
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];

  const ayahRange = record
    ? `${record.surah?.name ?? `Surah #${record.surah_id}`} : ${record.ayah_start}–${record.ayah_end}`
    : '—';

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Tinjau Setoran Hafalan"
      size="lg"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="memorization-review-form" loading={submitting}>
            Simpan Penilaian
          </Button>
        </>
      }
    >
      <form id="memorization-review-form" onSubmit={handleSubmit} className="space-y-4">
        {record && (
          <div className="rounded-lg bg-slate-50 p-3">
            <SectionTitle>Konteks Setoran</SectionTitle>
            <FieldRow label="Peserta">{record.participant?.name ?? '—'}</FieldRow>
            <FieldRow label="Surah / Ayat">{ayahRange}</FieldRow>
            <FieldRow label="Jenis">{record.type_label}</FieldRow>
            <FieldRow label="Catatan Peserta">{record.participant_note ?? '—'}</FieldRow>
          </div>
        )}

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="sm:col-span-2">
            <Label required>Status Penilaian</Label>
            <Select
              value={values.status}
              error={err('status')}
              onChange={(e) => set('status', e.target.value as TahfizReviewStatus)}
            >
              {REVIEW_STATUS_OPTIONS.map((opt) => (
                <option key={String(opt.value)} value={String(opt.value)}>
                  {opt.label}
                </option>
              ))}
            </Select>
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
          <div className="sm:col-span-2">
            <Label required={requiresRevision}>Batas Revisi</Label>
            <Input
              type="date"
              value={values.revision_due_date}
              error={err('revision_due_date')}
              onChange={(e) => set('revision_due_date', e.target.value)}
            />
            {requiresRevision && (
              <p className="mt-1 text-xs text-amber-600">
                Wajib diisi ketika status "Diterima dengan Revisi".
              </p>
            )}
          </div>
          <div className="sm:col-span-2">
            <Label>Catatan Guru</Label>
            <Textarea
              value={values.teacher_note}
              error={err('teacher_note')}
              placeholder="Catatan hasil peninjauan"
              onChange={(e) => set('teacher_note', e.target.value)}
            />
          </div>
        </div>
      </form>
    </Modal>
  );
}