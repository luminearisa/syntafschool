import { useEffect, useState, type FormEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { classSubjectsApi } from '@/features/academics/api/classSubjectsApi';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Textarea } from '@/components/ui/Textarea';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Spinner } from '@/components/ui/Spinner';
import { assessmentsApi } from '../api/assessmentsApi';
import type { AssessmentPayload, AssessmentRow } from '../types';

interface Props {
  open: boolean;
  editing: AssessmentRow | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: AssessmentPayload) => void;
}

const blank: AssessmentPayload = {
  class_id: '', subject_id: '', semester_id: '', assessment_type_id: '', teacher_id: '',
  name: '', description: '', max_score: 100, weight: '', date: new Date().toISOString().slice(0, 10),
};

export function AssessmentFormModal({ open, editing, submitting, serverErrors, onClose, onSubmit }: Props) {
  const [values, setValues] = useState<AssessmentPayload>(blank);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const lookups = useQuery({
    queryKey: ['assessment', 'lookups'],
    enabled: open,
    staleTime: 5 * 60_000,
    queryFn: () => assessmentsApi.lookups(),
  });
  const classId = Number(values.class_id) || 0;
  const classSubjects = useQuery({
    queryKey: ['assessment', 'class-subjects', classId],
    enabled: open && classId > 0,
    queryFn: () => classSubjectsApi.list(classId),
  });

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setValues(editing ? {
      class_id: editing.class_id,
      subject_id: editing.subject_id,
      semester_id: editing.semester_id,
      assessment_type_id: editing.assessment_type_id,
      teacher_id: editing.teacher_id ?? '',
      name: editing.name,
      description: editing.description ?? '',
      max_score: editing.max_score,
      weight: editing.weight ?? '',
      date: editing.date ?? '',
    } : { ...blank });
  }, [open, editing]);

  const set = <K extends keyof AssessmentPayload>(key: K, value: AssessmentPayload[K]) => {
    setValues((previous) => ({ ...previous, [key]: value }));
    setErrors((previous) => { const next = { ...previous }; delete next[key]; return next; });
  };
  const errorFor = (key: string) => errors[key] || serverErrors?.[key]?.[0];
  const classOptions = (lookups.data?.classes ?? []).map((row) => ({ value: row.id, label: `${row.name} · ${row.code}` }));
  const semesterOptions = (lookups.data?.semesters ?? []).map((row) => ({ value: row.id, label: `${row.name} · ${row.academic_year?.name ?? ''}` }));
  const typeOptions = (lookups.data?.types ?? []).map((row) => ({ value: row.id, label: row.name }));
  const teacherOptions = (lookups.data?.teachers ?? []).map((row) => ({ value: row.id, label: `${row.full_name}${row.nip ? ` · ${row.nip}` : ''}` }));
  const subjectOptions = (classSubjects.data ?? []).filter((row) => row.subject).map((row) => ({ value: row.subject_id, label: `${row.subject?.name ?? ''}${row.subject?.code ? ` · ${row.subject.code}` : ''}` }));

  const submit = (event: FormEvent) => {
    event.preventDefault();
    const next: Record<string, string> = {};
    if (!values.class_id) next.class_id = 'Kelas wajib dipilih.';
    if (!values.subject_id) next.subject_id = 'Mata pelajaran wajib dipilih.';
    if (!values.semester_id) next.semester_id = 'Semester wajib dipilih.';
    if (!values.assessment_type_id) next.assessment_type_id = 'Jenis penilaian wajib dipilih.';
    if (!values.name.trim()) next.name = 'Nama penilaian wajib diisi.';
    if (!values.max_score || Number(values.max_score) <= 0) next.max_score = 'Nilai maksimum harus lebih dari nol.';
    if (Object.keys(next).length) { setErrors(next); return; }
    onSubmit({
      ...values,
      name: values.name.trim(),
      description: values.description?.trim() || undefined,
      max_score: Number(values.max_score),
      weight: values.weight === '' ? undefined : Number(values.weight),
      teacher_id: values.teacher_id === '' ? undefined : Number(values.teacher_id),
    });
  };

  return (
    <Modal open={open} onClose={onClose} title={editing ? 'Edit Penilaian' : 'Buat Penilaian'} size="lg" footer={<><Button type="button" variant="outline" onClick={onClose} disabled={submitting}>Batal</Button><Button type="submit" form="assessment-form" loading={submitting}>{editing ? 'Simpan Perubahan' : 'Buat Penilaian'}</Button></>}>
      {lookups.isLoading ? <div className="flex justify-center py-12"><Spinner /></div> : lookups.isError ? <p className="text-sm text-red-600">Gagal memuat pilihan kelas, semester, dan jenis penilaian. Coba tutup lalu buka kembali.</p> : (
        <form id="assessment-form" onSubmit={submit} className="space-y-4">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div><Label required>Kelas</Label><SearchSelect options={classOptions} value={values.class_id} error={errorFor('class_id')} onChange={(value) => { setValues((previous) => ({ ...previous, class_id: value === '' ? '' : Number(value), subject_id: '' })); setErrors((previous) => ({ ...previous, class_id: '' })); }} placeholder="Pilih kelas" /></div>
            <div><Label required>Semester</Label><SearchSelect options={semesterOptions} value={values.semester_id} error={errorFor('semester_id')} onChange={(value) => set('semester_id', value === '' ? '' : Number(value))} placeholder="Pilih semester" /></div>
            <div><Label required>Mata Pelajaran</Label><SearchSelect options={subjectOptions} value={values.subject_id} disabled={!classId || classSubjects.isLoading} error={errorFor('subject_id')} onChange={(value) => set('subject_id', value === '' ? '' : Number(value))} placeholder={classId ? (classSubjects.isLoading ? 'Memuat mapel...' : 'Pilih mapel yang diampu di kelas') : 'Pilih kelas terlebih dahulu'} />{classId && !classSubjects.isLoading && subjectOptions.length === 0 && <p className="mt-1 text-xs text-amber-700">Kelas ini belum memiliki mapel yang ditetapkan.</p>}</div>
            <div><Label required>Jenis Penilaian</Label><SearchSelect options={typeOptions} value={values.assessment_type_id} error={errorFor('assessment_type_id')} onChange={(value) => set('assessment_type_id', value === '' ? '' : Number(value))} placeholder="Pilih jenis penilaian" /></div>
            <div className="sm:col-span-2"><Label required>Nama Penilaian</Label><Input value={values.name} error={errorFor('name')} onChange={(event) => set('name', event.target.value)} placeholder="Contoh: Ulangan Harian Bab 1" /></div>
            <div><Label required>Nilai Maksimum</Label><Input type="number" min="0.01" step="0.01" value={values.max_score} error={errorFor('max_score')} onChange={(event) => set('max_score', event.target.value === '' ? '' : Number(event.target.value))} /></div>
            <div><Label>Bobot Khusus (%)</Label><Input type="number" min="0" max="100" step="0.01" value={values.weight} error={errorFor('weight')} onChange={(event) => set('weight', event.target.value === '' ? '' : Number(event.target.value))} placeholder="Gunakan bobot jenis jika kosong" /></div>
            <div><Label>Tanggal Penilaian</Label><Input type="date" value={values.date ?? ''} error={errorFor('date')} onChange={(event) => set('date', event.target.value)} /></div>
            <div><Label>Guru</Label><SearchSelect options={teacherOptions} value={values.teacher_id ?? ''} error={errorFor('teacher_id')} onChange={(value) => set('teacher_id', value === '' ? '' : Number(value))} placeholder="Otomatis mengikuti guru pengampu" /></div>
            <div className="sm:col-span-2"><Label>Deskripsi</Label><Textarea value={values.description ?? ''} error={errorFor('description')} onChange={(event) => set('description', event.target.value)} placeholder="Instruksi atau keterangan penilaian" /></div>
          </div>
        </form>
      )}
    </Modal>
  );
}
