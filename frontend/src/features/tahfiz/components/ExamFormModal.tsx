import { useEffect, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { useEmployeeOptions } from '@/hooks/useLookupOptions';
import {
  useHalaqahOptions,
  useLevelOptions,
  useProgramOptions,
  useRubricOptions,
} from '../hooks/useTahfizOptions';
import { EXAM_STATUS_LABELS, EXAM_TYPE_LABELS, toOptions } from '../lib/labels';
import type { TahfizExam, TahfizExamPayload } from '@/types/tahfiz';

interface Props {
  open: boolean;
  editing: TahfizExam | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: TahfizExamPayload) => void;
}

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

const today = (): string => new Date().toISOString().slice(0, 10);

const TYPE_OPTIONS = toOptions(EXAM_TYPE_LABELS);
const STATUS_OPTIONS = toOptions(EXAM_STATUS_LABELS);

const emptyForm: TahfizExamPayload = {
  code: '',
  name: '',
  program_id: '',
  halaqah_id: '',
  type: 'ujian_hafalan',
  rubric_id: '',
  exam_date: today(),
  start_time: '',
  duration_minutes: '',
  examiner_id: '',
  level_id: '',
  max_score: '',
  passing_score: '',
  description: '',
  status: 'draft',
};

/**
 * Form ujian Tahfiz.
 *
 * Untuk jenis `kenaikan_level` field level tujuan (`level_id`) wajib diisi —
 * ujian ini menentukan peserta naik ke level berikutnya.
 */
export function ExamFormModal({ open, editing, submitting, serverErrors, onClose, onSubmit }: Props) {
  const [values, setValues] = useState<TahfizExamPayload>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { options: programOptions } = useProgramOptions();
  const { options: halaqahOptions } = useHalaqahOptions(values.program_id);
  const { options: rubricOptions } = useRubricOptions('exam');
  const { options: examinerOptions } = useEmployeeOptions(false);
  const { options: levelOptions } = useLevelOptions();

  useEffect(() => {
    if (!open) return;
    setErrors({});
    if (editing) {
      setValues({
        code: editing.code,
        name: editing.name,
        program_id: editing.program_id ?? '',
        halaqah_id: editing.halaqah_id ?? '',
        type: editing.type,
        rubric_id: editing.rubric_id ?? '',
        exam_date: editing.exam_date ?? today(),
        start_time: editing.start_time ?? '',
        duration_minutes: editing.duration_minutes ?? '',
        examiner_id: editing.examiner_id ?? '',
        level_id: editing.level_id ?? '',
        max_score: editing.max_score,
        passing_score: editing.passing_score,
        description: editing.description ?? '',
        status: editing.status,
      });
    } else {
      setValues({ ...emptyForm, exam_date: today() });
    }
  }, [open, editing]);

  const set = <K extends keyof TahfizExamPayload>(key: K, value: TahfizExamPayload[K]) => {
    setValues((prev) => ({ ...prev, [key]: value }));
    setErrors((prev) => {
      const next = { ...prev };
      delete next[key as string];
      return next;
    });
  };

  const isLevelUp = values.type === 'kenaikan_level';

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();

    const local: Record<string, string> = {};
    if (!values.code.trim()) local.code = 'Kode ujian wajib diisi';
    if (!values.name.trim()) local.name = 'Nama ujian wajib diisi';
    if (!values.type) local.type = 'Jenis ujian wajib dipilih';
    if (!values.exam_date) local.exam_date = 'Tanggal ujian wajib diisi';
    if (isLevelUp && !values.level_id) {
      local.level_id = 'Level tujuan wajib dipilih untuk ujian kenaikan level';
    }
    if (
      values.max_score !== '' &&
      values.passing_score !== '' &&
      Number(values.passing_score) > Number(values.max_score)
    ) {
      local.passing_score = 'Nilai kelulusan tidak boleh melebihi nilai maksimal';
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
      title={editing ? 'Edit Ujian Tahfiz' : 'Tambah Ujian Tahfiz'}
      size="xl"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="exam-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="exam-form" onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label required>Kode Ujian</Label>
            <Input
              value={values.code}
              error={err('code')}
              placeholder="mis. UJN-HFZ-01"
              onChange={(e) => set('code', e.target.value)}
            />
          </div>
          <div>
            <Label required>Jenis Ujian</Label>
            <Select
              value={values.type}
              error={err('type')}
              onChange={(e) => set('type', e.target.value as TahfizExamPayload['type'])}
            >
              {TYPE_OPTIONS.map((o) => (
                <option key={String(o.value)} value={String(o.value)}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
          <div className="sm:col-span-2">
            <Label required>Nama Ujian</Label>
            <Input
              value={values.name}
              error={err('name')}
              placeholder="mis. Ujian Hafalan Juz 30"
              onChange={(e) => set('name', e.target.value)}
            />
          </div>

          <div>
            <Label>Program</Label>
            <SearchSelect
              options={programOptions}
              value={values.program_id}
              error={err('program_id')}
              onChange={(v) => set('program_id', toId(v))}
              placeholder="-- Opsional --"
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
            <Label>Rubrik (Ujian)</Label>
            <SearchSelect
              options={rubricOptions}
              value={values.rubric_id}
              error={err('rubric_id')}
              onChange={(v) => set('rubric_id', toId(v))}
              placeholder="-- Opsional --"
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
            <Label required>Tanggal Ujian</Label>
            <Input
              type="date"
              value={values.exam_date}
              error={err('exam_date')}
              onChange={(e) => set('exam_date', e.target.value)}
            />
          </div>
          <div>
            <Label>Jam Mulai</Label>
            <Input
              type="time"
              value={values.start_time}
              error={err('start_time')}
              onChange={(e) => set('start_time', e.target.value)}
            />
          </div>
          <div>
            <Label>Durasi (menit)</Label>
            <Input
              type="number"
              min={1}
              value={values.duration_minutes}
              error={err('duration_minutes')}
              onChange={(e) =>
                set('duration_minutes', e.target.value === '' ? '' : Number(e.target.value))
              }
            />
          </div>
          <div>
            <Label>Status</Label>
            <Select
              value={values.status}
              error={err('status')}
              onChange={(e) => set('status', e.target.value as TahfizExamPayload['status'])}
            >
              {STATUS_OPTIONS.map((o) => (
                <option key={String(o.value)} value={String(o.value)}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>

          <div>
            <Label required={isLevelUp}>Level Tujuan</Label>
            <SearchSelect
              options={levelOptions}
              value={values.level_id}
              error={err('level_id')}
              onChange={(v) => set('level_id', toId(v))}
              placeholder={isLevelUp ? '-- Wajib untuk kenaikan level --' : '-- Opsional --'}
            />
            {isLevelUp && (
              <p className="mt-1 text-xs text-slate-500">
                Ujian kenaikan level memerlukan level tujuan.
              </p>
            )}
          </div>
          <div>
            <Label>Nilai Maksimal</Label>
            <Input
              type="number"
              min={0}
              value={values.max_score}
              error={err('max_score')}
              placeholder="mis. 100"
              onChange={(e) => set('max_score', e.target.value === '' ? '' : Number(e.target.value))}
            />
          </div>
          <div>
            <Label>Nilai Kelulusan</Label>
            <Input
              type="number"
              min={0}
              value={values.passing_score}
              error={err('passing_score')}
              placeholder="mis. 70"
              onChange={(e) =>
                set('passing_score', e.target.value === '' ? '' : Number(e.target.value))
              }
            />
            <p className="mt-1 text-xs text-slate-500">
              Nilai kelulusan tidak boleh melebihi nilai maksimal.
            </p>
          </div>

          <div className="sm:col-span-2">
            <Label>Deskripsi</Label>
            <Textarea
              value={values.description}
              error={err('description')}
              placeholder="Opsional — cakupan materi ujian"
              onChange={(e) => set('description', e.target.value)}
            />
          </div>
        </div>
      </form>
    </Modal>
  );
}