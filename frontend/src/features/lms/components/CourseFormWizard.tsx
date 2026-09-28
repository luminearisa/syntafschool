import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowRight, BookOpen, Check, Layers, Settings2, Sparkles } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { cn } from '@/lib/utils';
import { lmsApi } from '../api/lmsApi';
import { ContentEditor } from './ContentEditor';
import type { CourseFormPayload, LmsCourse, LmsLookups } from '../types';

const STEPS = [
  { key: 'identity', label: 'Identitas', icon: BookOpen },
  { key: 'academic', label: 'Kaitan SIAKAD', icon: Layers },
  { key: 'learning', label: 'Pembelajaran', icon: Sparkles },
  { key: 'settings', label: 'Publikasi', icon: Settings2 },
] as const;

/**
 * Wizard pembuatan/penyuntingan course (§7).
 *
 * Urutannya mengikuti alur kerja guru: pilih kategori → tautkan mapel & rombel
 * SIAKAD (opsional) → susun materi → atur visibilitas. Course pertama kali
 * selalu tersimpan sebagai DRAF.
 */
export function CourseFormWizard({
  course,
  lookups,
  initialValues,
  onSaved,
  onCancel,
}: {
  course?: LmsCourse;
  lookups: LmsLookups;
  initialValues?: Partial<CourseFormPayload>;
  onSaved: (course: LmsCourse) => void;
  onCancel: () => void;
}) {
  const queryClient = useQueryClient();
  const [step, setStep] = useState(0);
  const [form, setForm] = useState<CourseFormPayload>({
    name: '',
    category_id: null,
    academic_course_id: null,
    academic_year_id: null,
    semester_id: null,
    program_id: null,
    class_id: null,
    teacher_id: null,
    code: '',
    short_description: '',
    description: '',
    learning_objectives: '',
    course_type: 'ACADEMIC',
    level: 'BEGINNER',
    language: 'id',
    visibility: 'ENROLLED_ONLY',
    start_date: null,
    end_date: null,
    sync_academic_enrollments: true,
    ...initialValues,
    ...(course
      ? {
          name: course.name,
          category_id: course.category_id,
          academic_course_id: course.academic_course_id,
          academic_year_id: course.academic_year_id,
          semester_id: course.semester_id,
          program_id: course.program_id,
          class_id: course.class_id,
          teacher_id: course.teacher_id,
          code: course.code,
          short_description: course.short_description ?? '',
          description: course.description ?? '',
          learning_objectives: course.learning_objectives ?? '',
          course_type: course.course_type,
          level: course.level,
          language: course.language,
          visibility: course.visibility,
          start_date: course.start_date,
          end_date: course.end_date,
          max_students: course.max_students,
        }
      : {}),
  });

  const isStandalone = lookups.mode === 'standalone';

  const update = <K extends keyof CourseFormPayload>(key: K, value: CourseFormPayload[K]) =>
    setForm((current) => ({ ...current, [key]: value }));

  const classesForYear = useMemo(
    () =>
      lookups.classes.filter(
        (option) => !form.academic_year_id || option.academic_year_id === Number(form.academic_year_id),
      ),
    [lookups.classes, form.academic_year_id],
  );

  const semestersForYear = useMemo(
    () =>
      lookups.semesters.filter(
        (option) => !form.academic_year_id || option.academic_year_id === Number(form.academic_year_id),
      ),
    [lookups.semesters, form.academic_year_id],
  );

  const save = useMutation({
    mutationFn: (payload: CourseFormPayload) =>
      course ? lmsApi.updateCourse(course.id, payload) : lmsApi.createCourse(payload),
    onSuccess: (saved) => {
      toast.success(course ? 'Course berhasil diperbarui' : 'Course dibuat sebagai draf');
      queryClient.invalidateQueries({ queryKey: ['lms', 'courses'] });
      queryClient.invalidateQueries({ queryKey: ['lms', 'course', saved.id] });
      onSaved(saved);
    },
    onError: (error: { response?: { data?: { message?: string } } }) => {
      toast.error(error.response?.data?.message ?? 'Gagal menyimpan course');
    },
  });

  const identityValid = form.name.trim().length >= 3;
  // Standalone Mode tidak punya SIAKAD, jadi penautan mapel tidak diwajibkan.
  const academicRequired = form.course_type === 'ACADEMIC' && !isStandalone;
  const academicValid = !academicRequired || Boolean(form.academic_course_id);

  const canContinue = step === 0 ? identityValid : step === 1 ? academicValid : true;

  // Alasan tombol "Lanjut" nonaktif ditampilkan agar tidak menjadi teka-teki.
  const blockingHint = !canContinue
    ? step === 0
      ? 'Isi nama course minimal 3 karakter untuk melanjutkan.'
      : 'Pilih mata pelajaran SIAKAD (atau ubah jenis course) untuk melanjutkan.'
    : null;

  return (
    <div className="space-y-5">
      <ol className="flex flex-wrap items-center gap-2">
        {STEPS.map((item, index) => {
          const Icon = item.icon;
          const active = index === step;
          const done = index < step;
          return (
            <li key={item.key}>
              <button
                type="button"
                onClick={() => setStep(index)}
                className={cn(
                  'flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-medium transition',
                  active
                    ? 'border-brand-500 bg-brand-50 text-brand-700'
                    : done
                      ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                      : 'border-slate-200 bg-white text-slate-500',
                )}
              >
                {done ? <Check className="h-3.5 w-3.5" aria-hidden="true" /> : <Icon className="h-3.5 w-3.5" aria-hidden="true" />}
                {index + 1}. {item.label}
              </button>
            </li>
          );
        })}
      </ol>

      <Card>
        <CardBody className="space-y-4">
          {step === 0 && (
            <>
              <div className="grid gap-4 md:grid-cols-2">
                <div className="space-y-1.5 md:col-span-2">
                  <Label htmlFor="lms-course-name">Nama course *</Label>
                  <Input
                    id="lms-course-name"
                    value={form.name}
                    onChange={(event) => update('name', event.target.value)}
                    placeholder="Matematika Dasar X IPA 1"
                  />
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="lms-course-category">Kategori</Label>
                  <Select
                    id="lms-course-category"
                    value={form.category_id ?? ''}
                    onChange={(event) => update('category_id', event.target.value ? Number(event.target.value) : null)}
                  >
                    <option value="">— Tanpa kategori —</option>
                    {lookups.categories.map((option) => (
                      <option key={option.id} value={option.id}>
                        {option.label}
                      </option>
                    ))}
                  </Select>
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="lms-course-type">Jenis course</Label>
                  <Select
                    id="lms-course-type"
                    value={form.course_type}
                    onChange={(event) => update('course_type', event.target.value as CourseFormPayload['course_type'])}
                  >
                    {lookups.enums.course_type.map((option) => (
                      <option key={option.value} value={option.value}>
                        {option.label}
                      </option>
                    ))}
                  </Select>
                </div>
                <div className="space-y-1.5 md:col-span-2">
                  <Label htmlFor="lms-course-code">Kode course</Label>
                  <Input
                    id="lms-course-code"
                    value={form.code ?? ''}
                    onChange={(event) => update('code', event.target.value)}
                    placeholder="Kosongkan untuk dibuat otomatis (mis. MAT101-XIPA1-2026)"
                  />
                </div>
                <div className="space-y-1.5 md:col-span-2">
                  <Label htmlFor="lms-course-short">Deskripsi singkat</Label>
                  <Textarea
                    id="lms-course-short"
                    rows={2}
                    value={form.short_description ?? ''}
                    onChange={(event) => update('short_description', event.target.value)}
                    placeholder="Ringkasan satu-dua baris yang muncul di kartu course"
                  />
                </div>
              </div>
              <ContentEditor
                label="Deskripsi lengkap"
                rows={8}
                value={form.description ?? ''}
                onChange={(value) => update('description', value)}
                hint="Tampil pada halaman overview course."
              />
            </>
          )}

          {step === 1 && (
            <>
              {isStandalone && (
                <p className="rounded-xl border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-800">
                  LMS berjalan mandiri (<strong>Standalone Mode</strong>): tidak ada SIAKAD yang perlu ditautkan. Lanjutkan ke langkah berikutnya.
                </p>
              )}
              <div className="grid gap-4 md:grid-cols-2">
                <div className="space-y-1.5">
                  <Label htmlFor="lms-course-subject">Mata pelajaran SIAKAD {academicRequired && '*'}</Label>
                  <Select
                    id="lms-course-subject"
                    value={form.academic_course_id ?? ''}
                    onChange={(event) => {
                      const value = event.target.value ? Number(event.target.value) : null;
                      update('academic_course_id', value);
                      const subject = lookups.subjects.find((option) => option.id === value);
                      if (subject && (!form.name || form.name.startsWith('Course'))) {
                        update('name', subject.name);
                      }
                    }}
                  >
                    <option value="">— Pilih mata pelajaran —</option>
                    {lookups.subjects.map((option) => (
                      <option key={option.id} value={option.id}>
                        {option.code} — {option.name}
                      </option>
                    ))}
                  </Select>
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="lms-course-year">Tahun ajaran</Label>
                  <Select
                    id="lms-course-year"
                    value={form.academic_year_id ?? ''}
                    onChange={(event) => update('academic_year_id', event.target.value ? Number(event.target.value) : null)}
                  >
                    <option value="">— Tidak ditentukan —</option>
                    {lookups.academic_years.map((option) => (
                      <option key={option.id} value={option.id}>
                        {option.name}
                        {option.is_active ? ' (aktif)' : ''}
                      </option>
                    ))}
                  </Select>
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="lms-course-class">Rombel / kelas</Label>
                  <Select
                    id="lms-course-class"
                    value={form.class_id ?? ''}
                    onChange={(event) => update('class_id', event.target.value ? Number(event.target.value) : null)}
                  >
                    <option value="">— Tanpa rombel —</option>
                    {classesForYear.map((option) => (
                      <option key={option.id} value={option.id}>
                        {option.name}
                      </option>
                    ))}
                  </Select>
                  <p className="text-[11px] text-slate-500">
                    Bila rombel dipilih, peserta dapat disinkronkan otomatis dari SIAKAD (§17).
                  </p>
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="lms-course-semester">Semester</Label>
                  <Select
                    id="lms-course-semester"
                    value={form.semester_id ?? ''}
                    onChange={(event) => update('semester_id', event.target.value ? Number(event.target.value) : null)}
                  >
                    <option value="">— Tidak ditentukan —</option>
                    {semestersForYear.map((option) => (
                      <option key={option.id} value={option.id}>
                        {option.name}
                      </option>
                    ))}
                  </Select>
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="lms-course-program">Program / peminatan</Label>
                  <Select
                    id="lms-course-program"
                    value={form.program_id ?? ''}
                    onChange={(event) => update('program_id', event.target.value ? Number(event.target.value) : null)}
                  >
                    <option value="">— Tidak ditentukan —</option>
                    {lookups.programs.map((option) => (
                      <option key={option.id} value={option.id}>
                        {option.name}
                      </option>
                    ))}
                  </Select>
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="lms-course-teacher">Guru pengampu</Label>
                  <Select
                    id="lms-course-teacher"
                    value={form.teacher_id ?? ''}
                    onChange={(event) => {
                      const value = event.target.value ? Number(event.target.value) : null;
                      update('teacher_id', value);
                      update('teachers', value ? [{ teacher_id: value, is_primary: true }] : []);
                    }}
                  >
                    <option value="">— Saya sendiri / belum ditentukan —</option>
                    {lookups.teachers.map((teacher) => (
                      <option key={teacher.id} value={teacher.id}>
                        {teacher.name}
                      </option>
                    ))}
                  </Select>
                </div>
              </div>
              {form.class_id && (
                <label className="flex items-center gap-2 text-sm text-slate-700">
                  <input
                    type="checkbox"
                    className="h-4 w-4 rounded border-slate-300"
                    checked={form.sync_academic_enrollments ?? true}
                    onChange={(event) => update('sync_academic_enrollments', event.target.checked)}
                  />
                  Sinkronkan siswa dari rombel SIAKAD secara otomatis
                </label>
              )}
            </>
          )}

          {step === 2 && (
            <>
              <div className="grid gap-4 md:grid-cols-3">
                <div className="space-y-1.5">
                  <Label htmlFor="lms-course-level">Tingkat</Label>
                  <Select
                    id="lms-course-level"
                    value={form.level}
                    onChange={(event) => update('level', event.target.value as CourseFormPayload['level'])}
                  >
                    {lookups.enums.level.map((option) => (
                      <option key={option.value} value={option.value}>
                        {option.label}
                      </option>
                    ))}
                  </Select>
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="lms-course-language">Bahasa</Label>
                  <Input
                    id="lms-course-language"
                    value={form.language ?? 'id'}
                    onChange={(event) => update('language', event.target.value)}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="lms-course-duration">Estimasi durasi (menit)</Label>
                  <Input
                    id="lms-course-duration"
                    type="number"
                    min={1}
                    value={form.estimated_minutes ?? ''}
                    onChange={(event) =>
                      update('estimated_minutes', event.target.value ? Number(event.target.value) : null)
                    }
                  />
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="lms-course-start">Tanggal mulai</Label>
                  <Input
                    id="lms-course-start"
                    type="date"
                    value={form.start_date ?? ''}
                    onChange={(event) => update('start_date', event.target.value || null)}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="lms-course-end">Tanggal selesai</Label>
                  <Input
                    id="lms-course-end"
                    type="date"
                    value={form.end_date ?? ''}
                    onChange={(event) => update('end_date', event.target.value || null)}
                  />
                </div>
              </div>
              <ContentEditor
                label="Tujuan pembelajaran"
                rows={6}
                value={form.learning_objectives ?? ''}
                onChange={(value) => update('learning_objectives', value)}
                hint="Daftar capaian pembelajaran yang diharapkan."
              />
            </>
          )}

          {step === 3 && (
            <div className="grid gap-4 md:grid-cols-2">
              <div className="space-y-1.5">
                <Label htmlFor="lms-course-visibility">Visibilitas</Label>
                <Select
                  id="lms-course-visibility"
                  value={form.visibility}
                  onChange={(event) => update('visibility', event.target.value as CourseFormPayload['visibility'])}
                >
                  {lookups.enums.visibility.map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </Select>
                <p className="text-[11px] text-slate-500">
                  Course publik terlihat oleh tamu pada halaman katalog; materi tetap hanya untuk peserta.
                </p>
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="lms-course-max">Kuota peserta</Label>
                <Input
                  id="lms-course-max"
                  type="number"
                  min={1}
                  value={form.max_students ?? ''}
                  onChange={(event) => update('max_students', event.target.value ? Number(event.target.value) : null)}
                  placeholder="Tanpa batas"
                />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="lms-course-thumb">URL thumbnail</Label>
                <Input
                  id="lms-course-thumb"
                  value={form.thumbnail ?? ''}
                  onChange={(event) => update('thumbnail', event.target.value)}
                  placeholder="https://…"
                />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="lms-course-cover">URL cover</Label>
                <Input
                  id="lms-course-cover"
                  value={form.cover_image ?? ''}
                  onChange={(event) => update('cover_image', event.target.value)}
                  placeholder="https://…"
                />
              </div>
              <label className="flex items-center gap-2 text-sm text-slate-700 md:col-span-2">
                <input
                  type="checkbox"
                  className="h-4 w-4 rounded border-slate-300"
                  checked={Boolean(form.is_featured)}
                  onChange={(event) => update('is_featured', event.target.checked)}
                />
                Tampilkan sebagai course unggulan di katalog publik
              </label>
              <p className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600 md:col-span-2">
                Course selalu tersimpan sebagai <strong>Draf</strong> terlebih dahulu. Setelah materi siap, terbitkan
                dari halaman kelola course (§7).
              </p>
            </div>
          )}
        </CardBody>
      </Card>

      <div className="flex flex-wrap items-center justify-between gap-2">
        <Button type="button" variant="ghost" onClick={onCancel}>
          Batal
        </Button>
        <div className="flex items-center gap-2">
          {blockingHint && <span className="mr-2 hidden text-xs text-amber-700 sm:inline">{blockingHint}</span>}
          {step > 0 && (
            <Button type="button" variant="outline" onClick={() => setStep((current) => current - 1)}>
              Sebelumnya
            </Button>
          )}
          {step < STEPS.length - 1 ? (
            <Button type="button" onClick={() => setStep((current) => current + 1)} disabled={!canContinue}>
              Lanjut <ArrowRight className="h-4 w-4" aria-hidden="true" />
            </Button>
          ) : (
            <Button type="button" loading={save.isPending} onClick={() => save.mutate(form)} disabled={!identityValid}>
              {course ? 'Simpan perubahan' : 'Simpan sebagai draf'}
            </Button>
          )}
        </div>
      </div>
    </div>
  );
}

/** Hook pembungkus: mengambil referensi lalu merender wizard. */
export function useCourseLookups() {
  return useQuery({ queryKey: ['lms', 'lookups'], queryFn: () => lmsApi.lookups(), staleTime: 5 * 60 * 1000 });
}

export function CourseFormWizardContainer(props: {
  course?: LmsCourse;
  initialValues?: Partial<CourseFormPayload>;
  onSaved: (course: LmsCourse) => void;
  onCancel: () => void;
}) {
  const { data, isLoading } = useCourseLookups();

  useEffect(() => {
    if (!isLoading && !data) toast.error('Referensi LMS tidak dapat dimuat');
  }, [isLoading, data]);

  if (isLoading || !data) {
    return (
      <div className="flex flex-col items-center gap-3 py-16 text-sm text-slate-500">
        <span className="h-6 w-6 animate-spin rounded-full border-2 border-slate-300 border-t-brand-600" />
        Memuat referensi course…
      </div>
    );
  }

  return <CourseFormWizard lookups={data} {...props} />;
}
