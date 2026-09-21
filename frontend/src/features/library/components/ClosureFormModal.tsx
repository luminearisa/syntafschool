import { useEffect, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { CLOSURE_STATUS_LABELS, CLOSURE_TYPE_LABELS, toOptions } from '../lib/labels';
import type {
  LibraryClosure,
  LibraryClosurePayload,
  LibraryClosureStatus,
  LibraryClosureType,
} from '@/types/library';

interface Props {
  open: boolean;
  editing: LibraryClosure | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: LibraryClosurePayload) => void;
}

interface FormState {
  date: string;
  start_time: string;
  end_time: string;
  reason: string;
  type: LibraryClosureType | '';
  status: LibraryClosureStatus;
}

const emptyForm: FormState = {
  date: '',
  start_time: '',
  end_time: '',
  reason: '',
  type: '',
  status: 'active',
};

/**
 * Form penutupan perpustakaan.
 *
 * Jam mulai/selesai boleh dikosongkan (tutup sehari penuh) atau diisi
 * berpasangan untuk menutup sebagian hari saja.
 */
export function ClosureFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [values, setValues] = useState<FormState>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (!open) return;
    setErrors({});

    if (editing) {
      setValues({
        date: editing.date ?? '',
        start_time: editing.start_time ?? '',
        end_time: editing.end_time ?? '',
        reason: editing.reason,
        type: editing.type,
        status: editing.status,
      });
    } else {
      setValues({ ...emptyForm });
    }
  }, [open, editing]);

  const set = <K extends keyof FormState>(key: K, value: FormState[K]) => {
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
    if (!values.date) local.date = 'Tanggal wajib diisi';
    if (!values.reason.trim()) local.reason = 'Alasan penutupan wajib diisi';
    if (!values.type) local.type = 'Jenis penutupan wajib dipilih';

    const hasStart = values.start_time !== '';
    const hasEnd = values.end_time !== '';
    if (hasStart !== hasEnd) {
      local.start_time = 'Jam mulai dan jam selesai harus diisi bersama';
      local.end_time = 'Jam mulai dan jam selesai harus diisi bersama';
    } else if (hasStart && hasEnd && values.end_time <= values.start_time) {
      local.end_time = 'Jam selesai harus setelah jam mulai';
    }

    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }

    onSubmit({
      date: values.date,
      start_time: values.start_time,
      end_time: values.end_time,
      reason: values.reason.trim(),
      type: values.type as LibraryClosureType,
      status: values.status,
    });
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? 'Edit Penutupan' : 'Tambah Penutupan'}
      size="lg"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="library-closure-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="library-closure-form" onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
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
            <Label>Jam Mulai</Label>
            <Input
              type="time"
              value={values.start_time}
              error={err('start_time')}
              onChange={(e) => set('start_time', e.target.value)}
            />
          </div>
          <div>
            <Label>Jam Selesai</Label>
            <Input
              type="time"
              value={values.end_time}
              error={err('end_time')}
              onChange={(e) => set('end_time', e.target.value)}
            />
          </div>
        </div>
        <p className="text-xs text-slate-500">
          Kosongkan jam mulai dan selesai untuk menutup sehari penuh.
        </p>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label required>Jenis</Label>
            <Select
              value={values.type}
              error={err('type')}
              onChange={(e) => set('type', e.target.value as LibraryClosureType | '')}
            >
              <option value="">-- Pilih jenis --</option>
              {toOptions(CLOSURE_TYPE_LABELS).map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label>Status</Label>
            <Select
              value={values.status}
              error={err('status')}
              onChange={(e) => set('status', e.target.value as LibraryClosureStatus)}
            >
              {toOptions(CLOSURE_STATUS_LABELS).map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </Select>
          </div>
        </div>

        <div>
          <Label required>Alasan</Label>
          <Textarea
            value={values.reason}
            error={err('reason')}
            maxLength={200}
            rows={3}
            placeholder="mis. Libur nasional Hari Kemerdekaan"
            onChange={(e) => set('reason', e.target.value)}
          />
          <p className="mt-1 text-xs text-slate-500">{values.reason.length}/200 karakter</p>
        </div>
      </form>
    </Modal>
  );
}