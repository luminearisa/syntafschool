import { useEffect, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Textarea } from '@/components/ui/Textarea';
import { useOptions } from '@/hooks/useOptions';
import {
  useParticipantOptions,
  useProgramOptions,
  useLevelOptions,
  useHalaqahOptions,
} from '../hooks/useTahfizOptions';
import { ENROLLMENT_STATUS_LABELS } from '../lib/labels';
import type {
  TahfizEnrollment,
  TahfizEnrollmentPayload,
  TahfizEnrollmentStatus,
} from '@/types/tahfiz';

interface Props {
  open: boolean;
  editing: TahfizEnrollment | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: TahfizEnrollmentPayload) => void;
}

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

const emptyForm: TahfizEnrollmentPayload = {
  participant_id: '',
  program_id: '',
  level_id: '',
  halaqah_id: '',
  academic_year_id: '',
  semester_id: '',
  start_date: '',
  expected_end_date: '',
  starting_juz: '',
  target_note: '',
  notes: '',
  status: 'draft',
};

/** Form pendaftaran peserta ke program Tahfiz. */
export function EnrollmentFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [values, setValues] = useState<TahfizEnrollmentPayload>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { options: participantOptions } = useParticipantOptions();
  const { options: programOptions } = useProgramOptions();
  const { options: levelOptions } = useLevelOptions(values.program_id);
  const { options: halaqahOptions } = useHalaqahOptions(values.program_id);
  const { options: yearOptions } = useOptions('academic-years');
  const { options: semesterOptions } = useOptions('semesters');

  useEffect(() => {
    if (!open) return;
    setErrors({});

    if (editing) {
      setValues({
        participant_id: editing.participant_id,
        program_id: editing.program_id,
        level_id: editing.level_id ?? '',
        halaqah_id: editing.halaqah_id ?? '',
        academic_year_id: editing.academic_year_id ?? '',
        semester_id: editing.semester_id ?? '',
        start_date: editing.start_date ?? '',
        expected_end_date: editing.expected_end_date ?? '',
        starting_juz: editing.starting_juz ?? '',
        target_note: editing.target_note ?? '',
        notes: editing.notes ?? '',
        status: editing.status,
      });
    } else {
      setValues({ ...emptyForm });
    }
  }, [open, editing]);

  const set = <K extends keyof TahfizEnrollmentPayload>(
    key: K,
    value: TahfizEnrollmentPayload[K],
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
    if (!values.program_id) local.program_id = 'Program wajib dipilih';
    if (!values.start_date) local.start_date = 'Tanggal mulai wajib diisi';

    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }

    onSubmit({
      ...values,
      participant_id: Number(values.participant_id),
      program_id: Number(values.program_id),
    });
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? 'Edit Pendaftaran' : 'Tambah Pendaftaran'}
      size="xl"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="enrollment-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="enrollment-form" onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="sm:col-span-2">
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
            <Label required>Program</Label>
            <SearchSelect
              options={programOptions}
              value={values.program_id}
              error={err('program_id')}
              onChange={(v) => {
                set('program_id', toId(v));
                setValues((prev) => ({ ...prev, level_id: '', halaqah_id: '' }));
              }}
              placeholder="-- Pilih Program --"
            />
          </div>
          <div>
            <Label>Level</Label>
            <SearchSelect
              options={levelOptions}
              value={values.level_id}
              error={err('level_id')}
              onChange={(v) => set('level_id', toId(v))}
              placeholder="-- Opsional --"
              disabled={!values.program_id}
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
              disabled={!values.program_id}
            />
          </div>
          <div>
            <Label>Status</Label>
            <Select
              value={values.status ?? ''}
              error={err('status')}
              onChange={(e) => set('status', e.target.value as TahfizEnrollmentStatus)}
            >
              <option value="">-- Pilih Status --</option>
              {(Object.keys(ENROLLMENT_STATUS_LABELS) as TahfizEnrollmentStatus[]).map((key) => (
                <option key={key} value={key}>
                  {ENROLLMENT_STATUS_LABELS[key]}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label>Tahun Ajaran</Label>
            <SearchSelect
              options={yearOptions}
              value={values.academic_year_id ?? ''}
              error={err('academic_year_id')}
              onChange={(v) => set('academic_year_id', toId(v))}
              placeholder="-- Opsional --"
            />
          </div>
          <div>
            <Label>Semester</Label>
            <SearchSelect
              options={semesterOptions}
              value={values.semester_id ?? ''}
              error={err('semester_id')}
              onChange={(v) => set('semester_id', toId(v))}
              placeholder="-- Opsional --"
            />
          </div>
          <div>
            <Label required>Tanggal Mulai</Label>
            <Input
              type="date"
              value={values.start_date}
              error={err('start_date')}
              onChange={(e) => set('start_date', e.target.value)}
            />
          </div>
          <div>
            <Label>Perkiraan Selesai</Label>
            <Input
              type="date"
              value={values.expected_end_date}
              error={err('expected_end_date')}
              onChange={(e) => set('expected_end_date', e.target.value)}
            />
          </div>
          <div>
            <Label>Juz Awal</Label>
            <Input
              value={values.starting_juz}
              error={err('starting_juz')}
              placeholder="mis. Juz 1"
              onChange={(e) => set('starting_juz', e.target.value)}
            />
          </div>
          <div className="sm:col-span-2">
            <Label>Catatan Target</Label>
            <Input
              value={values.target_note}
              error={err('target_note')}
              placeholder="mis. Menyelesaikan Juz 30 dalam 6 bulan"
              onChange={(e) => set('target_note', e.target.value)}
            />
          </div>
          <div className="sm:col-span-2">
            <Label>Catatan</Label>
            <Textarea
              value={values.notes}
              error={err('notes')}
              placeholder="Catatan tambahan (opsional)"
              onChange={(e) => set('notes', e.target.value)}
            />
          </div>
        </div>
      </form>
    </Modal>
  );
}