import { useEffect, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { useOptions } from '@/hooks/useOptions';
import { useEmployeeOptions } from '@/hooks/useLookupOptions';
import type { SchoolClass, SchoolClassPayload } from '@/types';

interface Props {
  open: boolean;
  editing: SchoolClass | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: SchoolClassPayload) => void;
}

const emptyForm = (): SchoolClassPayload => ({
  academic_year_id: '',
  education_level_id: '',
  room_id: '',
  school_id: '',
  name: '',
  code: '',
  homeroom_teacher_id: '',
  capacity: '',
  is_active: true,
});

export function ClassFormModal({ open, editing, submitting, serverErrors, onClose, onSubmit }: Props) {
  const [values, setValues] = useState<SchoolClassPayload>(emptyForm());
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { options: yearOptions } = useOptions('academic-years');
  const { options: levelOptions } = useOptions('education-levels');
  const { options: roomOptions } = useOptions('rooms');
  const { options: schoolOptions } = useOptions('schools');
  const { options: teacherOptions } = useEmployeeOptions(true);

  useEffect(() => {
    if (open) {
      setErrors({});
      setValues(
        editing
          ? {
              academic_year_id: editing.academic_year_id ?? '',
              education_level_id: editing.education_level_id ?? '',
              room_id: editing.room_id ?? '',
              school_id: editing.school_id ?? '',
              name: editing.name ?? '',
              code: editing.code ?? '',
              homeroom_teacher_id: editing.homeroom_teacher_id ?? '',
              capacity: editing.capacity ?? '',
              is_active: editing.is_active ?? true,
            }
          : emptyForm(),
      );
    }
  }, [open, editing]);

  const set = (name: keyof SchoolClassPayload, value: unknown) => {
    setValues((prev) => ({ ...prev, [name]: value }));
    setErrors((prev) => {
      const next = { ...prev };
      delete next[name];
      return next;
    });
  };

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (!values.academic_year_id) local.academic_year_id = 'Tahun ajaran wajib dipilih';
    if (!values.name.trim()) local.name = 'Nama kelas wajib diisi';
    if (!values.code.trim()) local.code = 'Kode kelas wajib diisi';
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
      title={editing ? 'Edit Kelas' : 'Tambah Kelas'}
      size="lg"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>Batal</Button>
          <Button type="submit" form="class-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="class-form" onSubmit={handleSubmit} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="sm:col-span-2">
          <Label required>Tahun Ajaran</Label>
          <SearchSelect
            options={yearOptions}
            value={values.academic_year_id}
            onChange={(v) => set('academic_year_id', v)}
            error={err('academic_year_id')}
            placeholder="-- Pilih Tahun Ajaran --"
          />
        </div>

        <div>
          <Label required>Nama Kelas</Label>
          <Input
            value={values.name}
            error={err('name')}
            placeholder="Contoh: X IPA 1"
            onChange={(e) => set('name', e.target.value)}
          />
        </div>
        <div>
          <Label required>Kode Kelas</Label>
          <Input
            value={values.code}
            error={err('code')}
            placeholder="Contoh: X-IPA-1"
            onChange={(e) => set('code', e.target.value)}
          />
        </div>

        <div>
          <Label>Tingkat</Label>
          <SearchSelect
            options={levelOptions}
            value={values.education_level_id}
            onChange={(v) => set('education_level_id', v)}
            placeholder="-- Pilih Tingkat --"
          />
        </div>
        <div>
          <Label>Ruangan</Label>
          <SearchSelect
            options={roomOptions}
            value={values.room_id}
            onChange={(v) => set('room_id', v)}
            placeholder="-- Pilih Ruangan --"
          />
        </div>

        <div>
          <Label>Wali Kelas</Label>
          <SearchSelect
            options={teacherOptions}
            value={values.homeroom_teacher_id}
            onChange={(v) => set('homeroom_teacher_id', v)}
            placeholder="-- Pilih Wali Kelas --"
          />
        </div>
        <div>
          <Label>Kapasitas</Label>
          <Input
            type="number"
            min={0}
            value={values.capacity}
            error={err('capacity')}
            onChange={(e) => set('capacity', e.target.value === '' ? '' : Number(e.target.value))}
          />
        </div>

        {schoolOptions.length > 0 && (
          <div className="sm:col-span-2">
            <Label>Sekolah</Label>
            <SearchSelect
              options={schoolOptions}
              value={values.school_id ?? ''}
              onChange={(v) => set('school_id', v)}
              placeholder="-- Pilih Sekolah --"
            />
          </div>
        )}

        <div className="sm:col-span-2">
          <label className="flex cursor-pointer items-center gap-2 text-sm text-slate-700">
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
              checked={values.is_active}
              onChange={(e) => set('is_active', e.target.checked)}
            />
            Aktif
          </label>
        </div>
      </form>
    </Modal>
  );
}
