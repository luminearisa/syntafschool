import { useEffect, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Textarea } from '@/components/ui/Textarea';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { useOptions } from '@/hooks/useOptions';
import type { Guardian, GuardianPayload } from '@/types';

interface Props {
  open: boolean;
  editing: Guardian | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: GuardianPayload) => void;
}

const toId = (value: string | number): number | '' => (value === '' ? '' : Number(value));

const emptyForm = (): GuardianPayload => ({
  full_name: '',
  gender_id: '',
  religion_id: '',
  address: null,
  phone: null,
  email: null,
  occupation_id: '',
  income_range_id: '',
  education_background_id: '',
});

export function GuardianFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [values, setValues] = useState<GuardianPayload>(emptyForm());
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { options: genderOptions } = useOptions('genders');
  const { options: religionOptions } = useOptions('religions');
  const { options: occupationOptions } = useOptions('occupations');
  const { options: incomeOptions } = useOptions('income-ranges', 'label');
  const { options: educationOptions } = useOptions('education-backgrounds');

  useEffect(() => {
    if (!open) return;
    setErrors({});

    setValues(
      editing
        ? {
            full_name: editing.full_name ?? '',
            gender_id: editing.gender_id ?? '',
            religion_id: editing.religion_id ?? '',
            address: editing.address ?? null,
            phone: editing.phone ?? null,
            email: editing.email ?? null,
            occupation_id: editing.occupation_id ?? '',
            income_range_id: editing.income_range_id ?? '',
            education_background_id: editing.education_background_id ?? '',
          }
        : emptyForm(),
    );
  }, [open, editing]);

  const set = (name: keyof GuardianPayload, value: unknown) => {
    setValues((prev) => ({ ...prev, [name]: value }) as GuardianPayload);
    setErrors((prev) => {
      const next = { ...prev };
      delete next[name as string];
      return next;
    });
  };

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    if (!values.full_name.trim()) {
      setErrors({ full_name: 'Nama lengkap wajib diisi' });
      return;
    }
    onSubmit(values);
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? 'Ubah Wali Murid' : 'Tambah Wali Murid'}
      size="lg"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="guardian-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="guardian-form" onSubmit={handleSubmit} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2">
          <Label required>Nama Lengkap</Label>
          <Input
            value={values.full_name}
            error={err('full_name')}
            placeholder="Contoh: Bapak Santoso"
            onChange={(e) => set('full_name', e.target.value)}
          />
          {err('full_name') && <p className="mt-1 text-xs text-red-600">{err('full_name')}</p>}
        </div>

        <div>
          <Label>Jenis Kelamin</Label>
          <SearchSelect
            options={genderOptions}
            value={values.gender_id ?? ''}
            onChange={(v) => set('gender_id', toId(v))}
            placeholder="-- Pilih Jenis Kelamin --"
          />
        </div>
        <div>
          <Label>Agama</Label>
          <SearchSelect
            options={religionOptions}
            value={values.religion_id ?? ''}
            onChange={(v) => set('religion_id', toId(v))}
            placeholder="-- Pilih Agama --"
          />
        </div>

        <div>
          <Label>Pekerjaan</Label>
          <SearchSelect
            options={occupationOptions}
            value={values.occupation_id ?? ''}
            onChange={(v) => set('occupation_id', toId(v))}
            placeholder="-- Pilih Pekerjaan --"
          />
        </div>
        <div>
          <Label>Rentang Penghasilan</Label>
          <SearchSelect
            options={incomeOptions}
            value={values.income_range_id ?? ''}
            onChange={(v) => set('income_range_id', toId(v))}
            placeholder="-- Pilih Rentang --"
          />
        </div>

        <div>
          <Label>Pendidikan Terakhir</Label>
          <SearchSelect
            options={educationOptions}
            value={values.education_background_id ?? ''}
            onChange={(v) => set('education_background_id', toId(v))}
            placeholder="-- Pilih Pendidikan --"
          />
        </div>
        <div>
          <Label>Telepon</Label>
          <Input
            value={values.phone ?? ''}
            onChange={(e) => set('phone', e.target.value || null)}
          />
        </div>

        <div className="sm:col-span-2">
          <Label>Email</Label>
          <Input
            type="email"
            value={values.email ?? ''}
            error={err('email')}
            onChange={(e) => set('email', e.target.value || null)}
          />
          {err('email') && <p className="mt-1 text-xs text-red-600">{err('email')}</p>}
        </div>

        <div className="sm:col-span-2">
          <Label>Alamat</Label>
          <Textarea
            value={values.address ?? ''}
            onChange={(e) => set('address', e.target.value || null)}
          />
        </div>
      </form>
    </Modal>
  );
}
