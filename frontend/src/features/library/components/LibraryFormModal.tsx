import { useEffect, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Textarea } from '@/components/ui/Textarea';
import { SearchSelect } from '@/components/ui/SearchSelect';
import type { SelectOption } from '@/constants/masterData';
import type { Library, LibraryPayload } from '@/types/library';

interface Props {
  open: boolean;
  editing: Library | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: LibraryPayload) => void;
  /** Opsional — select sekolah hanya dirender bila daftar ini diberikan. */
  schools?: SelectOption[];
}

/** Zona waktu yang umum dipakai (server tetap memvalidasi). */
export const LIBRARY_TIMEZONES = [
  'Asia/Jakarta',
  'Asia/Makassar',
  'Asia/Jayapura',
  'Asia/Pontianak',
  'Asia/Surabaya',
  'Asia/Bangkok',
  'UTC',
];

const emptyForm: LibraryPayload = {
  school_id: '',
  code: '',
  name: '',
  description: '',
  phone: '',
  email: '',
  address: '',
  timezone: 'Asia/Jakarta',
};

/**
 * Form data dasar perpustakaan.
 *
 * Status sengaja tidak ada di sini — perubahan status punya alur tersendiri
 * lewat `LibraryTransitionModal`.
 */
export function LibraryFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
  schools,
}: Props) {
  const [values, setValues] = useState<LibraryPayload>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (!open) return;
    setErrors({});

    if (editing) {
      setValues({
        school_id: editing.school_id ?? '',
        code: editing.code,
        name: editing.name,
        description: editing.description ?? '',
        phone: editing.phone ?? '',
        email: editing.email ?? '',
        address: editing.address ?? '',
        timezone: editing.timezone,
        logo_path: editing.logo_path ?? '',
      });
    } else {
      setValues({ ...emptyForm });
    }
  }, [open, editing]);

  const codeLocked = !!editing && editing.status !== 'draft';

  const set = <K extends keyof LibraryPayload>(key: K, value: LibraryPayload[K]) => {
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
    if (!values.name.trim()) local.name = 'Nama perpustakaan wajib diisi';
    if (!values.code.trim()) local.code = 'Kode perpustakaan wajib diisi';
    if (!values.timezone.trim()) local.timezone = 'Zona waktu wajib diisi';

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
      title={editing ? 'Edit Perpustakaan' : 'Tambah Perpustakaan'}
      size="lg"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="library-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="library-form" onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label required>Nama Perpustakaan</Label>
            <Input
              value={values.name}
              error={err('name')}
              placeholder="mis. Perpustakaan Utama"
              onChange={(e) => set('name', e.target.value)}
            />
          </div>
          <div>
            <Label required>Kode</Label>
            <Input
              value={values.code}
              error={err('code')}
              disabled={codeLocked}
              placeholder="mis. LIB-MAIN"
              onChange={(e) => set('code', e.target.value)}
            />
            {codeLocked && (
              <p className="mt-1 text-xs text-slate-500">
                Kode hanya dapat diubah saat status masih draf.
              </p>
            )}
          </div>

          <div>
            <Label required>Zona Waktu</Label>
            <Input
              list="library-timezones"
              value={values.timezone}
              error={err('timezone')}
              placeholder="Asia/Jakarta"
              onChange={(e) => set('timezone', e.target.value)}
            />
            <datalist id="library-timezones">
              {LIBRARY_TIMEZONES.map((tz) => (
                <option key={tz} value={tz} />
              ))}
            </datalist>
          </div>
          <div>
            <Label>Telepon</Label>
            <Input
              value={values.phone}
              error={err('phone')}
              placeholder="Opsional"
              onChange={(e) => set('phone', e.target.value)}
            />
          </div>

          <div>
            <Label>Email</Label>
            <Input
              type="email"
              value={values.email}
              error={err('email')}
              placeholder="Opsional"
              onChange={(e) => set('email', e.target.value)}
            />
          </div>

          {schools && (
            <div>
              <Label>Sekolah</Label>
              <SearchSelect
                options={schools}
                value={values.school_id ?? ''}
                error={err('school_id')}
                onChange={(v) => set('school_id', v === '' ? '' : Number(v))}
                placeholder="-- Opsional --"
              />
            </div>
          )}

          <div className="sm:col-span-2">
            <Label>Deskripsi</Label>
            <Textarea
              value={values.description}
              error={err('description')}
              placeholder="Opsional"
              onChange={(e) => set('description', e.target.value)}
            />
          </div>

          <div className="sm:col-span-2">
            <Label>Alamat</Label>
            <Textarea
              value={values.address}
              error={err('address')}
              placeholder="Opsional"
              onChange={(e) => set('address', e.target.value)}
            />
          </div>
        </div>
      </form>
    </Modal>
  );
}