import { useEffect, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Textarea } from '@/components/ui/Textarea';
import type { SubjectGroup, SubjectGroupPayload } from '@/types/curriculum';

interface Props {
  open: boolean;
  editing: SubjectGroup | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: SubjectGroupPayload) => void;
}

const emptyForm: SubjectGroupPayload = {
  code: '',
  name: '',
  description: '',
  sort_order: '',
  is_active: true,
};

/** Form tambah/edit kelompok mata pelajaran. */
export function SubjectGroupFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [values, setValues] = useState<SubjectGroupPayload>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setValues(
      editing
        ? {
            code: editing.code,
            name: editing.name,
            description: editing.description ?? '',
            sort_order: editing.sort_order,
            is_active: editing.is_active,
          }
        : { ...emptyForm },
    );
  }, [open, editing]);

  const set = <K extends keyof SubjectGroupPayload>(key: K, value: SubjectGroupPayload[K]) => {
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
      title={editing ? 'Edit Kelompok Mapel' : 'Tambah Kelompok Mapel'}
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="subject-group-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="subject-group-form" onSubmit={handleSubmit} className="space-y-4">
        <div>
          <Label required>Kode</Label>
          <Input
            value={values.code}
            error={err('code')}
            placeholder="mis. MIPA"
            onChange={(e) => set('code', e.target.value)}
          />
        </div>

        <div>
          <Label required>Nama</Label>
          <Input
            value={values.name}
            error={err('name')}
            placeholder="mis. Matematika dan Ilmu Alam"
            onChange={(e) => set('name', e.target.value)}
          />
        </div>

        <div>
          <Label>Urutan</Label>
          <Input
            type="number"
            min={0}
            value={values.sort_order}
            error={err('sort_order')}
            placeholder="0"
            onChange={(e) => set('sort_order', e.target.value === '' ? '' : Number(e.target.value))}
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
