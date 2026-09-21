import { useEffect, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import type { EventType, EventTypePayload } from '@/types/academicCalendar';

interface Props {
  open: boolean;
  editing: EventType | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: EventTypePayload) => void;
}

const emptyForm: EventTypePayload = {
  code: '',
  name: '',
  color: '#2563eb',
  is_holiday: false,
  is_active: true,
};

/** Form tambah/edit jenis event kalender. */
export function EventTypeModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [values, setValues] = useState<EventTypePayload>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (!open) return;
    setErrors({});

    setValues(
      editing
        ? {
            code: editing.code,
            name: editing.name,
            color: editing.color ?? '#2563eb',
            is_holiday: editing.is_holiday,
            is_active: editing.is_active,
          }
        : { ...emptyForm },
    );
  }, [open, editing]);

  const set = <K extends keyof EventTypePayload>(key: K, value: EventTypePayload[K]) => {
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
      title={editing ? 'Edit Jenis Event' : 'Tambah Jenis Event'}
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="event-type-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="event-type-form" onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label required>Kode</Label>
            <Input
              value={values.code}
              error={err('code')}
              placeholder="mis. LIBUR_NASIONAL"
              onChange={(e) => set('code', e.target.value.toUpperCase())}
            />
          </div>

          <div>
            <Label required>Nama</Label>
            <Input
              value={values.name}
              error={err('name')}
              placeholder="mis. Libur Nasional"
              onChange={(e) => set('name', e.target.value)}
            />
          </div>

          <div>
            <Label>Warna Kalender</Label>
            <div className="flex items-center gap-2">
              <input
                type="color"
                value={values.color || '#2563eb'}
                onChange={(e) => set('color', e.target.value)}
                className="h-9 w-12 cursor-pointer rounded border border-slate-300 bg-white"
              />
              <Input
                value={values.color}
                error={err('color')}
                placeholder="#2563eb"
                onChange={(e) => set('color', e.target.value)}
              />
            </div>
          </div>

          <div className="flex items-end gap-4 pb-1">
            <label className="flex items-center gap-2 text-sm text-slate-700">
              <input
                type="checkbox"
                className="h-4 w-4 rounded border-slate-300"
                checked={values.is_holiday}
                onChange={(e) => set('is_holiday', e.target.checked)}
              />
              Hari libur
            </label>

            <label className="flex items-center gap-2 text-sm text-slate-700">
              <input
                type="checkbox"
                className="h-4 w-4 rounded border-slate-300"
                checked={values.is_active}
                onChange={(e) => set('is_active', e.target.checked)}
              />
              Aktif
            </label>
          </div>
        </div>
      </form>
    </Modal>
  );
}
