import { useEffect, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Textarea } from '@/components/ui/Textarea';
import { ENROLLMENT_STATUS_LABELS, ENROLLMENT_REASON_REQUIRED } from '../lib/labels';
import { useLevelOptions, useHalaqahOptions } from '../hooks/useTahfizOptions';
import type {
  TahfizEnrollment,
  TahfizEnrollmentStatus,
  TahfizEnrollmentTransitionPayload,
} from '@/types/tahfiz';

interface Props {
  open: boolean;
  enrollment: TahfizEnrollment | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: TahfizEnrollmentTransitionPayload) => void;
}

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

const emptyForm: TahfizEnrollmentTransitionPayload = {
  status: 'active',
  reason: '',
  level_id: '',
  halaqah_id: '',
  completion_note: '',
};

/**
 * Transisi status pendaftaran.
 *
 * Beberapa status (ditangguhkan, mengundurkan diri, ditolak, selesai) wajib
 * disertai alasan sesuai aturan backend.
 */
export function EnrollmentTransitionModal({
  open,
  enrollment,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [values, setValues] = useState<TahfizEnrollmentTransitionPayload>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { options: levelOptions } = useLevelOptions(enrollment?.program_id ?? '');
  const { options: halaqahOptions } = useHalaqahOptions(enrollment?.program_id ?? '');

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setValues({
      ...emptyForm,
      status: enrollment?.status ?? 'active',
      level_id: enrollment?.level_id ?? '',
      halaqah_id: enrollment?.halaqah_id ?? '',
    });
  }, [open, enrollment]);

  const set = <K extends keyof TahfizEnrollmentTransitionPayload>(
    key: K,
    value: TahfizEnrollmentTransitionPayload[K],
  ) => {
    setValues((prev) => ({ ...prev, [key]: value }));
    setErrors((prev) => {
      const next = { ...prev };
      delete next[key];
      return next;
    });
  };

  const reasonRequired = ENROLLMENT_REASON_REQUIRED.includes(values.status);

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (!values.status) local.status = 'Status wajib dipilih';
    if (reasonRequired && !(values.reason ?? '').trim()) {
      local.reason = 'Alasan wajib diisi untuk status ini';
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
      title="Transisi Status Pendaftaran"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="transition-form" loading={submitting}>
            Simpan
          </Button>
        </>
      }
    >
      <form id="transition-form" onSubmit={handleSubmit} className="space-y-4">
        <div className="rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
          Alur pendaftaran: draf → terdaftar → penilaian → disetujui → aktif. Status dapat
          ditangguhkan, diselesaikan, atau dihentikan bila diperlukan. Perubahan ke status
          tertentu wajib disertai alasan.
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="sm:col-span-2">
            <Label required>Status Baru</Label>
            <Select
              value={values.status}
              error={err('status')}
              onChange={(e) => set('status', e.target.value as TahfizEnrollmentStatus)}
            >
              {(Object.keys(ENROLLMENT_STATUS_LABELS) as TahfizEnrollmentStatus[]).map((key) => (
                <option key={key} value={key}>
                  {ENROLLMENT_STATUS_LABELS[key]}
                </option>
              ))}
            </Select>
          </div>

          <div className="sm:col-span-2">
            <Label required={reasonRequired}>Alasan</Label>
            <Textarea
              value={values.reason}
              error={err('reason')}
              placeholder={reasonRequired ? 'Wajib diisi untuk status ini' : 'Opsional'}
              onChange={(e) => set('reason', e.target.value)}
            />
          </div>

          <div>
            <Label>Level</Label>
            <SearchSelect
              options={levelOptions}
              value={values.level_id ?? ''}
              error={err('level_id')}
              onChange={(v) => set('level_id', toId(v))}
              placeholder="-- Tidak berubah --"
            />
          </div>
          <div>
            <Label>Halaqah</Label>
            <SearchSelect
              options={halaqahOptions}
              value={values.halaqah_id ?? ''}
              error={err('halaqah_id')}
              onChange={(v) => set('halaqah_id', toId(v))}
              placeholder="-- Tidak berubah --"
            />
          </div>
          <div className="sm:col-span-2">
            <Label>Catatan Penyelesaian</Label>
            <Textarea
              value={values.completion_note}
              error={err('completion_note')}
              placeholder="Catatan saat menyelesaikan program (opsional)"
              onChange={(e) => set('completion_note', e.target.value)}
            />
          </div>
        </div>
      </form>
    </Modal>
  );
}