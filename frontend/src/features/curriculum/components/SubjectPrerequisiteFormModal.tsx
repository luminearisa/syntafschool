import { useEffect, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Label } from '@/components/ui/Label';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Textarea } from '@/components/ui/Textarea';
import { useOptions } from '@/hooks/useOptions';
import type { SubjectPrerequisite, SubjectPrerequisitePayload } from '@/types/curriculum';

interface Props {
  open: boolean;
  editing: SubjectPrerequisite | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: SubjectPrerequisitePayload) => void;
}

const emptyForm: SubjectPrerequisitePayload = {
  subject_id: '',
  prerequisite_subject_id: '',
  notes: '',
};

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

/** Form tambah/edit prasyarat mata pelajaran. */
export function SubjectPrerequisiteFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [values, setValues] = useState<SubjectPrerequisitePayload>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { options: subjectOptions } = useOptions('subjects');

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setValues(
      editing
        ? {
            subject_id: editing.subject_id,
            prerequisite_subject_id: editing.prerequisite_subject_id,
            notes: editing.notes ?? '',
          }
        : { ...emptyForm },
    );
  }, [open, editing]);

  const set = <K extends keyof SubjectPrerequisitePayload>(
    key: K,
    value: SubjectPrerequisitePayload[K],
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
    if (!values.subject_id) local.subject_id = 'Mata pelajaran wajib dipilih';
    if (!values.prerequisite_subject_id) {
      local.prerequisite_subject_id = 'Mata pelajaran prasyarat wajib dipilih';
    }
    if (values.subject_id && values.subject_id === values.prerequisite_subject_id) {
      local.prerequisite_subject_id = 'Prasyarat tidak boleh sama dengan mata pelajaran';
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
      title={editing ? 'Edit Prasyarat Mapel' : 'Tambah Prasyarat Mapel'}
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="prerequisite-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="prerequisite-form" onSubmit={handleSubmit} className="space-y-4">
        <div>
          <Label required>Mata Pelajaran</Label>
          <SearchSelect
            options={subjectOptions}
            value={values.subject_id}
            error={err('subject_id')}
            onChange={(v) => set('subject_id', toId(v))}
            placeholder="-- Pilih Mata Pelajaran --"
          />
        </div>

        <div>
          <Label required>Prasyarat</Label>
          <SearchSelect
            options={subjectOptions}
            value={values.prerequisite_subject_id}
            error={err('prerequisite_subject_id')}
            onChange={(v) => set('prerequisite_subject_id', toId(v))}
            placeholder="-- Pilih Mapel Prasyarat --"
          />
        </div>

        <div>
          <Label>Catatan</Label>
          <Textarea
            value={values.notes}
            error={err('notes')}
            placeholder="Opsional"
            onChange={(e) => set('notes', e.target.value)}
          />
        </div>
      </form>
    </Modal>
  );
}
