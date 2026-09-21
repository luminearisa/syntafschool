import { useEffect, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Textarea } from '@/components/ui/Textarea';
import { useEmployeeOptions } from '@/hooks/useLookupOptions';
import { useProgramOptions, useLevelOptions } from '../hooks/useTahfizOptions';
import { HALAQAH_STATUS_LABELS } from '../lib/labels';
import type {
  TahfizHalaqah,
  TahfizHalaqahPayload,
  TahfizHalaqahStatus,
} from '@/types/tahfiz';

interface Props {
  open: boolean;
  editing: TahfizHalaqah | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: TahfizHalaqahPayload) => void;
}

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

const emptyForm: TahfizHalaqahPayload = {
  program_id: '',
  level_id: '',
  name: '',
  code: '',
  capacity: '',
  instructor_id: '',
  assistant_id: '',
  musyrif_id: '',
  schedule_summary: '',
  status: 'active',
  notes: '',
};

/** Form halaqah (kelompok binaan). */
export function HalaqahFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [values, setValues] = useState<TahfizHalaqahPayload>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { options: programOptions } = useProgramOptions();
  const { options: levelOptions } = useLevelOptions(values.program_id);
  const { options: employeeOptions } = useEmployeeOptions(false);

  useEffect(() => {
    if (!open) return;
    setErrors({});

    if (editing) {
      setValues({
        program_id: editing.program_id,
        level_id: editing.level_id ?? '',
        name: editing.name,
        code: editing.code,
        capacity: editing.capacity ?? '',
        instructor_id: editing.instructor_id ?? '',
        assistant_id: editing.assistant_id ?? '',
        musyrif_id: editing.musyrif_id ?? '',
        schedule_summary: editing.schedule_summary ?? '',
        status: editing.status,
        notes: editing.notes ?? '',
      });
    } else {
      setValues({ ...emptyForm });
    }
  }, [open, editing]);

  const set = <K extends keyof TahfizHalaqahPayload>(key: K, value: TahfizHalaqahPayload[K]) => {
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
    if (!values.program_id) local.program_id = 'Program wajib dipilih';
    if (!values.name.trim()) local.name = 'Nama halaqah wajib diisi';
    if (!values.code.trim()) local.code = 'Kode halaqah wajib diisi';

    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }

    onSubmit({ ...values, program_id: Number(values.program_id) });
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? 'Edit Halaqah' : 'Tambah Halaqah'}
      size="xl"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="halaqah-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="halaqah-form" onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label required>Program</Label>
            <SearchSelect
              options={programOptions}
              value={values.program_id}
              error={err('program_id')}
              onChange={(v) => {
                set('program_id', toId(v));
                set('level_id', '');
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
            <Label required>Nama Halaqah</Label>
            <Input
              value={values.name}
              error={err('name')}
              placeholder="mis. Halaqah Umar bin Khattab"
              onChange={(e) => set('name', e.target.value)}
            />
          </div>
          <div>
            <Label required>Kode</Label>
            <Input
              value={values.code}
              error={err('code')}
              placeholder="mis. HQ-01"
              onChange={(e) => set('code', e.target.value)}
            />
          </div>
          <div>
            <Label>Kapasitas</Label>
            <Input
              type="number"
              min={1}
              value={values.capacity}
              error={err('capacity')}
              placeholder="Opsional"
              onChange={(e) => set('capacity', e.target.value === '' ? '' : Number(e.target.value))}
            />
          </div>
          <div>
            <Label>Status</Label>
            <Select
              value={values.status ?? ''}
              error={err('status')}
              onChange={(e) => set('status', e.target.value as TahfizHalaqahStatus)}
            >
              {(Object.keys(HALAQAH_STATUS_LABELS) as TahfizHalaqahStatus[]).map((key) => (
                <option key={key} value={key}>
                  {HALAQAH_STATUS_LABELS[key]}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label>Instruktur</Label>
            <SearchSelect
              options={employeeOptions}
              value={values.instructor_id}
              error={err('instructor_id')}
              onChange={(v) => set('instructor_id', toId(v))}
              placeholder="-- Opsional --"
            />
          </div>
          <div>
            <Label>Asisten</Label>
            <SearchSelect
              options={employeeOptions}
              value={values.assistant_id}
              error={err('assistant_id')}
              onChange={(v) => set('assistant_id', toId(v))}
              placeholder="-- Opsional --"
            />
          </div>
          <div>
            <Label>Musyrif</Label>
            <SearchSelect
              options={employeeOptions}
              value={values.musyrif_id}
              error={err('musyrif_id')}
              onChange={(v) => set('musyrif_id', toId(v))}
              placeholder="-- Opsional --"
            />
          </div>
          <div className="sm:col-span-2">
            <Label>Ringkasan Jadwal</Label>
            <Input
              value={values.schedule_summary}
              error={err('schedule_summary')}
              placeholder="mis. Senin & Rabu, 07.00–08.30"
              onChange={(e) => set('schedule_summary', e.target.value)}
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