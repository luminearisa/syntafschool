import { useEffect, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { useOptions } from '@/hooks/useOptions';
import type { Employee, EmployeePayload, EmployeeType, EmploymentStatus } from '@/types';

interface Props {
  open: boolean;
  editing: Employee | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: EmployeePayload) => void;
}

const EMPLOYEE_TYPES: { value: EmployeeType; label: string }[] = [
  { value: 'guru', label: 'Guru' },
  { value: 'staf', label: 'Staf' },
];

const EMPLOYMENT_STATUSES: EmploymentStatus[] = ['PNS', 'PPPK', 'Honorer', 'GTY'];

const emptyForm = (): EmployeePayload => ({
  school_id: '',
  nip: null,
  full_name: '',
  gender_id: '',
  religion_id: '',
  birth_place: null,
  birth_date: null,
  address: null,
  phone: null,
  email: null,
  position: null,
  employee_type: 'guru',
  employment_status: 'Honorer',
  join_date: null,
  is_teacher: true,
  is_active: true,
});

export function EmployeeFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [values, setValues] = useState<EmployeePayload>(emptyForm());
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { options: genderOptions } = useOptions('genders');
  const { options: religionOptions } = useOptions('religions');
  const { options: schoolOptions } = useOptions('schools');

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setValues(
      editing
        ? {
            school_id: editing.school_id ?? '',
            nip: editing.nip ?? null,
            full_name: editing.full_name ?? '',
            gender_id: editing.gender_id ?? '',
            religion_id: editing.religion_id ?? '',
            birth_place: editing.birth_place ?? null,
            birth_date: editing.birth_date ?? null,
            address: editing.address ?? null,
            phone: editing.phone ?? null,
            email: editing.email ?? null,
            position: editing.position ?? null,
            employee_type: editing.employee_type,
            employment_status: editing.employment_status,
            join_date: editing.join_date ?? null,
            is_teacher: editing.is_teacher,
            is_active: editing.is_active,
          }
        : emptyForm(),
    );
  }, [open, editing]);

  const set = (name: keyof EmployeePayload, value: unknown) => {
    setValues((prev) => {
      const next = { ...prev, [name]: value } as EmployeePayload;
      // Pegawai berjenis guru selalu pengajar.
      if (name === 'employee_type' && value === 'guru') next.is_teacher = true;
      return next;
    });
    setErrors((prev) => {
      const next = { ...prev };
      delete next[name as string];
      return next;
    });
  };

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (!values.full_name.trim()) local.full_name = 'Nama lengkap wajib diisi';
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
      title={editing ? 'Ubah Pegawai' : 'Tambah Pegawai'}
      size="lg"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="employee-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="employee-form" onSubmit={handleSubmit} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2">
          <Label required>Nama Lengkap</Label>
          <Input
            value={values.full_name}
            error={err('full_name')}
            placeholder="Contoh: Budi Santoso, S.Pd."
            onChange={(e) => set('full_name', e.target.value)}
          />
          {err('full_name') && <p className="mt-1 text-xs text-red-600">{err('full_name')}</p>}
        </div>

        <div>
          <Label>NIP</Label>
          <Input
            value={values.nip ?? ''}
            error={err('nip')}
            placeholder="Nomor Induk Pegawai"
            onChange={(e) => set('nip', e.target.value || null)}
          />
          {err('nip') && <p className="mt-1 text-xs text-red-600">{err('nip')}</p>}
        </div>
        <div>
          <Label>Jabatan</Label>
          <Input
            value={values.position ?? ''}
            error={err('position')}
            placeholder="Contoh: Guru Matematika"
            onChange={(e) => set('position', e.target.value || null)}
          />
        </div>

        <div>
          <Label required>Jenis Pegawai</Label>
          <Select
            value={values.employee_type}
            error={err('employee_type')}
            onChange={(e) => set('employee_type', e.target.value)}
          >
            {EMPLOYEE_TYPES.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
          {err('employee_type') && (
            <p className="mt-1 text-xs text-red-600">{err('employee_type')}</p>
          )}
        </div>
        <div>
          <Label required>Status Kepegawaian</Label>
          <Select
            value={values.employment_status}
            error={err('employment_status')}
            onChange={(e) => set('employment_status', e.target.value)}
          >
            {EMPLOYMENT_STATUSES.map((s) => (
              <option key={s} value={s}>
                {s}
              </option>
            ))}
          </Select>
          {err('employment_status') && (
            <p className="mt-1 text-xs text-red-600">{err('employment_status')}</p>
          )}
        </div>

        <div>
          <Label>Jenis Kelamin</Label>
          <SearchSelect
            options={genderOptions}
            value={values.gender_id ?? ''}
            onChange={(v) => set('gender_id', v)}
            placeholder="-- Pilih Jenis Kelamin --"
          />
        </div>
        <div>
          <Label>Agama</Label>
          <SearchSelect
            options={religionOptions}
            value={values.religion_id ?? ''}
            onChange={(v) => set('religion_id', v)}
            placeholder="-- Pilih Agama --"
          />
        </div>

        <div>
          <Label>Tempat Lahir</Label>
          <Input
            value={values.birth_place ?? ''}
            onChange={(e) => set('birth_place', e.target.value || null)}
          />
        </div>
        <div>
          <Label>Tanggal Lahir</Label>
          <Input
            type="date"
            value={values.birth_date ?? ''}
            error={err('birth_date')}
            onChange={(e) => set('birth_date', e.target.value || null)}
          />
          {err('birth_date') && <p className="mt-1 text-xs text-red-600">{err('birth_date')}</p>}
        </div>

        <div>
          <Label>Telepon</Label>
          <Input
            value={values.phone ?? ''}
            onChange={(e) => set('phone', e.target.value || null)}
          />
        </div>
        <div>
          <Label>Email</Label>
          <Input
            type="email"
            value={values.email ?? ''}
            error={err('email')}
            onChange={(e) => set('email', e.target.value || null)}
          />
          {err('email') && <p className="mt-1 text-xs text-red-600">{err('email')}</p>}
        </div>

        <div>
          <Label>Tanggal Masuk</Label>
          <Input
            type="date"
            value={values.join_date ?? ''}
            error={err('join_date')}
            onChange={(e) => set('join_date', e.target.value || null)}
          />
          {err('join_date') && <p className="mt-1 text-xs text-red-600">{err('join_date')}</p>}
        </div>
        <div>
          <Label>Sekolah</Label>
          <SearchSelect
            options={schoolOptions}
            value={values.school_id ?? ''}
            onChange={(v) => set('school_id', v)}
            placeholder="-- Pilih Sekolah --"
          />
        </div>

        <div className="sm:col-span-2">
          <Label>Alamat</Label>
          <Textarea
            value={values.address ?? ''}
            onChange={(e) => set('address', e.target.value || null)}
          />
        </div>

        <div className="flex items-center gap-6 sm:col-span-2">
          <label className="flex cursor-pointer items-center gap-2 text-sm text-slate-700">
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
              checked={values.is_active}
              onChange={(e) => set('is_active', e.target.checked)}
            />
            Pegawai aktif
          </label>
          <label
            className={`flex items-center gap-2 text-sm ${
              values.employee_type === 'guru' ? 'text-slate-400' : 'cursor-pointer text-slate-700'
            }`}
          >
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
              checked={values.is_teacher}
              disabled={values.employee_type === 'guru'}
              onChange={(e) => set('is_teacher', e.target.checked)}
            />
            Pengajar
            {values.employee_type === 'guru' && (
              <span className="text-xs">(otomatis untuk guru)</span>
            )}
          </label>
        </div>
      </form>
    </Modal>
  );
}
