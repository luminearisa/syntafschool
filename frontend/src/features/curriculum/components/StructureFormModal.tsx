import { useEffect, useState, type FormEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Plus, Trash2 } from 'lucide-react';
import { subjectGroupsApi } from '../api/curriculumApi';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Textarea } from '@/components/ui/Textarea';
import { useOptions } from '@/hooks/useOptions';
import type {
  CurriculumStructure,
  CurriculumStructureItemInput,
  CurriculumStructurePayload,
  SemesterNumber,
} from '@/types/curriculum';

interface Props {
  open: boolean;
  editing: CurriculumStructure | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: CurriculumStructurePayload) => void;
}

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

const emptyRow = (): CurriculumStructureItemInput => ({
  subject_id: '',
  subject_group_id: '',
  semester: 1,
  weekly_hours: '',
  is_required: true,
});

const emptyForm: CurriculumStructurePayload = {
  curriculum_id: '',
  education_level_id: '',
  academic_year_id: '',
  version: 1,
  name: '',
  is_active: true,
  notes: '',
  items: [emptyRow()],
};

/** Form struktur kurikulum lengkap dengan editor baris item. */
export function StructureFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [values, setValues] = useState<CurriculumStructurePayload>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { options: curriculumOptions } = useOptions('curricula');
  const { options: levelOptions } = useOptions('education-levels');
  const { options: yearOptions } = useOptions('academic-years');
  const { options: subjectOptions } = useOptions('subjects');

  const groupsQuery = useQuery({
    queryKey: ['subject-groups', 'all'],
    enabled: open,
    staleTime: 1000 * 60 * 5,
    queryFn: () => subjectGroupsApi.list({ all: true }),
  });
  const groupOptions = (groupsQuery.data?.data ?? []).map((g) => ({ value: g.id, label: g.name }));

  useEffect(() => {
    if (!open) return;
    setErrors({});

    setValues(
      editing
        ? {
            curriculum_id: editing.curriculum_id,
            education_level_id: editing.education_level_id,
            academic_year_id: editing.academic_year_id ?? '',
            version: editing.version,
            name: editing.name,
            is_active: editing.is_active,
            notes: editing.notes ?? '',
            items: (editing.items ?? []).map((item) => ({
              subject_id: item.subject_id,
              subject_group_id: item.subject_group_id ?? '',
              semester: (item.semester === 2 ? 2 : 1) as SemesterNumber,
              weekly_hours: item.weekly_hours,
              is_required: item.is_required,
            })),
          }
        : { ...emptyForm, items: [emptyRow()] },
    );
  }, [open, editing]);

  const set = <K extends keyof CurriculumStructurePayload>(
    key: K,
    value: CurriculumStructurePayload[K],
  ) => {
    setValues((prev) => ({ ...prev, [key]: value }));
    setErrors((prev) => {
      const next = { ...prev };
      delete next[key];
      return next;
    });
  };

  const updateRow = <K extends keyof CurriculumStructureItemInput>(
    index: number,
    key: K,
    value: CurriculumStructureItemInput[K],
  ) => {
    setValues((prev) => ({
      ...prev,
      items: prev.items.map((row, i) => (i === index ? { ...row, [key]: value } : row)),
    }));
    setErrors((prev) => {
      const next = { ...prev };
      delete next.items;
      return next;
    });
  };

  const addRow = () => setValues((prev) => ({ ...prev, items: [...prev.items, emptyRow()] }));

  const removeRow = (index: number) =>
    setValues((prev) => ({ ...prev, items: prev.items.filter((_, i) => i !== index) }));

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();

    const local: Record<string, string> = {};
    if (!values.curriculum_id) local.curriculum_id = 'Kurikulum wajib dipilih';
    if (!values.education_level_id) local.education_level_id = 'Jenjang wajib dipilih';
    if (!values.version) local.version = 'Versi wajib diisi';
    if (!values.name.trim()) local.name = 'Nama struktur wajib diisi';
    if (values.items.length === 0) local.items = 'Minimal satu mata pelajaran';

    const seen = new Set<string>();
    for (const row of values.items) {
      if (!row.subject_id) {
        local.items = 'Setiap baris harus memilih mata pelajaran';
        break;
      }
      if (!row.weekly_hours || Number(row.weekly_hours) < 1 || Number(row.weekly_hours) > 12) {
        local.items = 'JP per pekan harus antara 1 dan 12';
        break;
      }
      const key = `${row.subject_id}:${row.semester}`;
      if (seen.has(key)) {
        local.items = 'Satu mapel tidak boleh dobel pada semester yang sama';
        break;
      }
      seen.add(key);
    }

    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }

    onSubmit({
      ...values,
      academic_year_id: values.academic_year_id || '',
      items: values.items.map((row) => ({
        ...row,
        subject_group_id: row.subject_group_id === '' ? null : row.subject_group_id,
      })),
    });
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? 'Edit Struktur Kurikulum' : 'Tambah Struktur Kurikulum'}
      size="xl"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="structure-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="structure-form" onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label required>Kurikulum</Label>
            <SearchSelect
              options={curriculumOptions}
              value={values.curriculum_id}
              error={err('curriculum_id')}
              onChange={(v) => set('curriculum_id', toId(v))}
              placeholder="-- Pilih Kurikulum --"
            />
          </div>

          <div>
            <Label required>Jenjang</Label>
            <SearchSelect
              options={levelOptions}
              value={values.education_level_id}
              error={err('education_level_id')}
              onChange={(v) => set('education_level_id', toId(v))}
              placeholder="-- Pilih Jenjang --"
            />
          </div>

          <div>
            <Label>Tahun Ajaran</Label>
            <SearchSelect
              options={yearOptions}
              value={values.academic_year_id}
              error={err('academic_year_id')}
              onChange={(v) => set('academic_year_id', toId(v))}
              placeholder="-- Opsional --"
            />
          </div>

          <div>
            <Label required>Versi</Label>
            <Input
              type="number"
              min={1}
              value={values.version}
              error={err('version')}
              onChange={(e) => set('version', e.target.value === '' ? '' : Number(e.target.value))}
            />
          </div>

          <div className="sm:col-span-2">
            <Label required>Nama Struktur</Label>
            <Input
              value={values.name}
              error={err('name')}
              placeholder="mis. Struktur Kurikulum Merdeka SMA Kelas X"
              onChange={(e) => set('name', e.target.value)}
            />
          </div>

          <div className="sm:col-span-2">
            <Label>Catatan</Label>
            <Textarea
              value={values.notes}
              error={err('notes')}
              placeholder="Opsional"
              onChange={(e) => set('notes', e.target.value)}
            />
          </div>
        </div>

        {/* ------------------------- Editor item ------------------------- */}
        <div className="rounded-lg border border-slate-200">
          <div className="flex items-center justify-between border-b border-slate-100 px-3 py-2">
            <div>
              <h4 className="text-sm font-semibold text-slate-800">Mata Pelajaran</h4>
              <p className="text-xs text-slate-500">
                Atur mapel, kelompok, semester, dan alokasi JP per pekan.
              </p>
            </div>
            <Button type="button" variant="outline" size="sm" onClick={addRow}>
              <Plus className="h-3.5 w-3.5" /> Tambah Baris
            </Button>
          </div>

          {errors.items && (
            <p className="border-b border-red-100 bg-red-50 px-3 py-2 text-xs text-red-600">
              {errors.items}
            </p>
          )}

          <div className="space-y-2 p-3">
            {values.items.length === 0 && (
              <p className="py-4 text-center text-xs text-slate-400">
                Belum ada mapel. Klik &quot;Tambah Baris&quot;.
              </p>
            )}

            {values.items.map((row, index) => (
              <div
                key={index}
                className="grid grid-cols-1 items-start gap-2 rounded-lg bg-slate-50 p-2 sm:grid-cols-12"
              >
                <div className="sm:col-span-4">
                  <SearchSelect
                    options={subjectOptions}
                    value={row.subject_id}
                    error={serverErrors?.[`items.${index}.subject_id`]?.[0]}
                    onChange={(v) => updateRow(index, 'subject_id', toId(v))}
                    placeholder="-- Mata Pelajaran --"
                  />
                </div>

                <div className="sm:col-span-3">
                  <SearchSelect
                    options={groupOptions}
                    value={row.subject_group_id ?? ''}
                    error={serverErrors?.[`items.${index}.subject_group_id`]?.[0]}
                    onChange={(v) =>
                      updateRow(index, 'subject_group_id', v === '' ? '' : Number(v))
                    }
                    placeholder="-- Kelompok --"
                  />
                </div>

                <div className="sm:col-span-2">
                  <Select
                    value={row.semester}
                    error={serverErrors?.[`items.${index}.semester`]?.[0]}
                    onChange={(e) =>
                      updateRow(index, 'semester', Number(e.target.value) as SemesterNumber)
                    }
                  >
                    <option value={1}>Semester 1</option>
                    <option value={2}>Semester 2</option>
                  </Select>
                </div>

                <div className="sm:col-span-2">
                  <Input
                    type="number"
                    min={1}
                    max={12}
                    value={row.weekly_hours}
                    error={serverErrors?.[`items.${index}.weekly_hours`]?.[0]}
                    placeholder="JP"
                    onChange={(e) =>
                      updateRow(
                        index,
                        'weekly_hours',
                        e.target.value === '' ? '' : Number(e.target.value),
                      )
                    }
                  />
                </div>

                <div className="flex items-center justify-between gap-2 pt-1 sm:col-span-1 sm:justify-end">
                  <label className="flex items-center gap-1 text-xs text-slate-600" title="Wajib">
                    <input
                      type="checkbox"
                      className="h-4 w-4 rounded border-slate-300"
                      checked={row.is_required}
                      onChange={(e) => updateRow(index, 'is_required', e.target.checked)}
                    />
                  </label>
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    title="Hapus baris"
                    onClick={() => removeRow(index)}
                  >
                    <Trash2 className="h-4 w-4 text-red-500" />
                  </Button>
                </div>
              </div>
            ))}
          </div>
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
