import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Textarea } from '@/components/ui/Textarea';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { classesApi } from '@/features/academics/api/academicsApi';
import { useEmployeeOptions } from '@/hooks/useLookupOptions';
import { useOptions } from '@/hooks/useOptions';
import type { SelectOption } from '@/constants/masterData';
import type { LessonPlan, LessonPlanPayload } from '@/types/teaching';

interface Props {
  open: boolean;
  editing: LessonPlan | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: LessonPlanPayload) => void;
}

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

/** Daftar teks ⇄ textarea satu poin per baris. */
function listToText(items: string[] | null | undefined): string {
  return (items ?? []).join('\n');
}

function textToList(text: string): string[] {
  return text
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line.length > 0);
}

const emptyForm: LessonPlanPayload = {
  teacher_id: '',
  subject_id: '',
  education_level_id: '',
  class_id: '',
  semester_id: '',
  code: '',
  title: '',
  learning_objectives: [],
  activities: [],
  assessment_plan: '',
  media: '',
  references: '',
  duration_jp: '',
  notes: '',
};

/**
 * Form RPP / modul ajar.
 *
 * Tujuan pembelajaran dan kegiatan diedit sebagai daftar — satu poin per baris —
 * karena backend menyimpannya sebagai array.
 */
export function LessonPlanFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [values, setValues] = useState<LessonPlanPayload>(emptyForm);
  const [objectivesText, setObjectivesText] = useState('');
  const [activitiesText, setActivitiesText] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { options: semesterOptions } = useOptions('semesters');
  const { options: subjectOptions } = useOptions('subjects');
  const { options: levelOptions } = useOptions('education-levels');
  const { options: teacherOptions } = useEmployeeOptions(true);

  const classesQuery = useQuery({
    queryKey: ['classes', 'all-for-lesson-plan'],
    enabled: open,
    staleTime: 1000 * 60 * 10,
    queryFn: () => classesApi.list({ all: true, per_page: 300 }),
  });

  const classOptions = useMemo<SelectOption[]>(
    () => (classesQuery.data?.data ?? []).map((c) => ({ value: c.id, label: `${c.name} — ${c.code}` })),
    [classesQuery.data],
  );

  useEffect(() => {
    if (!open) return;
    setErrors({});

    if (editing) {
      setValues({
        teacher_id: editing.teacher_id ?? '',
        subject_id: editing.subject_id,
        education_level_id: editing.education_level_id ?? '',
        class_id: editing.class_id ?? '',
        semester_id: editing.semester_id,
        code: editing.code,
        title: editing.title,
        learning_objectives: editing.learning_objectives ?? [],
        activities: editing.activities ?? [],
        assessment_plan: editing.assessment_plan ?? '',
        media: editing.media ?? '',
        references: editing.references ?? '',
        duration_jp: editing.duration_jp,
        notes: editing.notes ?? '',
      });
      setObjectivesText(listToText(editing.learning_objectives));
      setActivitiesText(listToText(editing.activities));
    } else {
      setValues({ ...emptyForm });
      setObjectivesText('');
      setActivitiesText('');
    }
  }, [open, editing]);

  const set = <K extends keyof LessonPlanPayload>(key: K, value: LessonPlanPayload[K]) => {
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
    if (!values.semester_id) local.semester_id = 'Semester wajib dipilih';
    if (!values.subject_id) local.subject_id = 'Mata pelajaran wajib dipilih';
    if (!values.code.trim()) local.code = 'Kode RPP wajib diisi';
    if (!values.title.trim()) local.title = 'Judul RPP wajib diisi';
    if (values.duration_jp === '' || Number(values.duration_jp) < 1) {
      local.duration_jp = 'Durasi JP minimal 1 jam pelajaran';
    }

    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }

    onSubmit({
      ...values,
      learning_objectives: textToList(objectivesText),
      activities: textToList(activitiesText),
    });
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? 'Edit RPP / Modul Ajar' : 'Tambah RPP / Modul Ajar'}
      size="xl"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="lesson-plan-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="lesson-plan-form" onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label required>Kode RPP</Label>
            <Input
              value={values.code}
              error={err('code')}
              placeholder="mis. RPP-MAT-X-01"
              onChange={(e) => set('code', e.target.value)}
            />
          </div>
          <div>
            <Label required>Durasi (JP)</Label>
            <Input
              type="number"
              min={1}
              max={99}
              value={values.duration_jp}
              error={err('duration_jp')}
              onChange={(e) =>
                set('duration_jp', e.target.value === '' ? '' : Number(e.target.value))
              }
            />
          </div>
          <div className="sm:col-span-2">
            <Label required>Judul</Label>
            <Input
              value={values.title}
              error={err('title')}
              placeholder="mis. Persamaan Kuadrat — Akar Real"
              onChange={(e) => set('title', e.target.value)}
            />
          </div>

          <div>
            <Label required>Mata Pelajaran</Label>
            <SearchSelect
              options={subjectOptions}
              value={values.subject_id}
              error={err('subject_id')}
              onChange={(v) => set('subject_id', toId(v))}
              placeholder="-- Pilih Mapel --"
            />
          </div>
          <div>
            <Label required>Semester</Label>
            <SearchSelect
              options={semesterOptions}
              value={values.semester_id}
              error={err('semester_id')}
              onChange={(v) => set('semester_id', toId(v))}
              placeholder="-- Pilih Semester --"
            />
          </div>
          <div>
            <Label>Guru Penyusun</Label>
            <SearchSelect
              options={teacherOptions}
              value={values.teacher_id}
              error={err('teacher_id')}
              onChange={(v) => set('teacher_id', toId(v))}
              placeholder="-- Kosongkan = guru pemanggil --"
            />
          </div>
          <div>
            <Label>Jenjang</Label>
            <SearchSelect
              options={levelOptions}
              value={values.education_level_id}
              error={err('education_level_id')}
              onChange={(v) => set('education_level_id', toId(v))}
              placeholder="-- Opsional --"
            />
          </div>
          <div className="sm:col-span-2">
            <Label>Kelas</Label>
            <SearchSelect
              options={classOptions}
              value={values.class_id}
              error={err('class_id')}
              onChange={(v) => set('class_id', toId(v))}
              placeholder="-- Opsional, kosongkan bila berlaku untuk semua kelas --"
            />
          </div>
        </div>

        <div>
          <Label>Tujuan Pembelajaran</Label>
          <Textarea
            value={objectivesText}
            error={err('learning_objectives')}
            rows={4}
            placeholder={'Satu tujuan per baris, mis.\nSiswa dapat menentukan akar persamaan kuadrat'}
            onChange={(e) => setObjectivesText(e.target.value)}
          />
          <p className="mt-1 text-xs text-slate-500">Satu poin per baris.</p>
        </div>

        <div>
          <Label>Kegiatan Pembelajaran</Label>
          <Textarea
            value={activitiesText}
            error={err('activities')}
            rows={4}
            placeholder={'Satu kegiatan per baris, mis.\nPendahuluan: apersepsi 10 menit\nInti: latihan terbimbing\nPenutup: refleksi'}
            onChange={(e) => setActivitiesText(e.target.value)}
          />
          <p className="mt-1 text-xs text-slate-500">Satu poin per baris.</p>
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label>Rencana Penilaian</Label>
            <Textarea
              value={values.assessment_plan}
              error={err('assessment_plan')}
              placeholder="mis. tes tertulis, penilaian kinerja"
              onChange={(e) => set('assessment_plan', e.target.value)}
            />
          </div>
          <div>
            <Label>Media</Label>
            <Textarea
              value={values.media}
              error={err('media')}
              placeholder="mis. papan tulis, proyektor, LKPD"
              onChange={(e) => set('media', e.target.value)}
            />
          </div>
          <div>
            <Label>Sumber Belajar</Label>
            <Textarea
              value={values.references}
              error={err('references')}
              placeholder="mis. Buku Matematika Kelas X hlm. 40–52"
              onChange={(e) => set('references', e.target.value)}
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
        </div>
      </form>
    </Modal>
  );
}
