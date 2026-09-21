import { useEffect, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Textarea } from '@/components/ui/Textarea';
import { useOptions } from '@/hooks/useOptions';
import type { Program, ProgramPayload } from '@/types/curriculum';

interface Props {
  open: boolean;
  editing: Program | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: ProgramPayload) => void;
}

const emptyForm: ProgramPayload = {
  code: '',
  name: '',
  education_level_id: '',
  description: '',
  is_active: true,
};

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

/** Form tambah/edit program / peminatan. */
export function ProgramFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [values, setValues] = useState<ProgramPayload>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { options: levelOptions } = useOptions('education-levels');

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setValues(
      editing
        ? {
            code: editing.code,
            name: editing.name,
            education_level_id: editing.education_level_id ?? '',
            description: editing.description ?? '',
            is_active: editing.is_active,
          }
        : { ...emptyForm },
    );
  }, [open, editing]);

  const set = <K extends keyof ProgramPayload>(key: K, value: ProgramPayload[K]) => {
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
    if (!values.code.trim()) local.code = 'Kode wajib diisi';
    if (!values.name.trim()) local.name = 'Nama wajib diisi';

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
      title={editing ? 'Edit Program' : 'Tambah Program'}
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="program-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="program-form" onSubmit={handleSubmit} className="space-y-4">
        <div>
          <Label required>Kode</Label>
          <Input
            value={values.code}
            error={err('code')}
            placeholder="mis. IPA"
            onChange={(e) => set('code', e.target.value)}
          />
        </div>

        <div>
          <Label required>Nama</Label>
          <Input
            value={values.name}
            error={err('name')}
            placeholder="mis. Ilmu Pengetahuan Alam"
            onChange={(e) => set('name', e.target.value)}
          />
        </div>

        <div>
          <Label>Jenjang</Label>
          <SearchSelect
            options={levelOptions}
            value={values.education_level_id}
            error={err('education_level_id')}
            onChange={(v) => set('education_level_id', toId(v))}
            placeholder="-- Semua Jenjang --"
          />
        </div>

        <div>
          <Label>Keterangan</Label>
          <Textarea
            value={values.description}
            error={err('description')}
            placeholder="Opsional"
            onChange={(e) => set('description', e.target.value)}
          />
        </div>

        <label className="flex items-center gap-2 text-sm text-slate-700">
          <input
            type="checkbox"
            className="h-4 w-4 rounded border-slate-300"
            checked={values.is_active}
            onChange={(e) => set('is_active', e.target.checked)}
          />
          Aktif
        </label>
      </form>
    </Modal>
  );
}
