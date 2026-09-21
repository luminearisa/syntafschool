import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Textarea } from '@/components/ui/Textarea';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { classesApi } from '@/features/academics/api/academicsApi';
import { schedulesApi, timeSlotsApi } from '@/features/academics/api/schedulesApi';
import { useEmployeeOptions } from '@/hooks/useLookupOptions';
import { useOptions } from '@/hooks/useOptions';
import type { SelectOption } from '@/constants/masterData';
import type { TeachingJournal, TeachingJournalPayload } from '@/types/teaching';

interface Props {
  open: boolean;
  editing: TeachingJournal | null;
  /** Nilai awal saat mencatat dari jadwal hari ini. */
  defaults?: Partial<TeachingJournalPayload> | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: TeachingJournalPayload) => void;
}

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

/** Tanggal hari ini dalam format `YYYY-MM-DD` (waktu lokal). */
function today(): string {
  const now = new Date();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  const day = String(now.getDate()).padStart(2, '0');
  return `${now.getFullYear()}-${month}-${day}`;
}

const emptyForm: TeachingJournalPayload = {
  schedule_id: '',
  class_id: '',
  subject_id: '',
  teacher_id: '',
  semester_id: '',
  time_slot_id: '',
  date: today(),
  topic: '',
  material: '',
  activities: '',
  notes: '',
  student_present_count: '',
  realized_jp: '',
};

/**
 * Form jurnal mengajar.
 *
 * Satu jurnal = satu jam mengajar yang benar-benar terjadi: apa yang diajarkan,
 * berapa JP terealisasi, dan berapa siswa hadir.
 */
export function JournalFormModal({
  open,
  editing,
  defaults,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [values, setValues] = useState<TeachingJournalPayload>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { options: semesterOptions } = useOptions('semesters');
  const { options: subjectOptions } = useOptions('subjects');
  const { options: teacherOptions } = useEmployeeOptions(true);

  const classesQuery = useQuery({
    queryKey: ['classes', 'all-for-journal'],
    enabled: open,
    staleTime: 1000 * 60 * 10,
    queryFn: () => classesApi.list({ all: true, per_page: 300 }),
  });

  const slotsQuery = useQuery({
    queryKey: ['time-slots', 'all-for-journal'],
    enabled: open,
    staleTime: 1000 * 60 * 10,
    queryFn: () => timeSlotsApi.list({ all: true }),
  });

  const classOptions = useMemo<SelectOption[]>(
    () => (classesQuery.data?.data ?? []).map((c) => ({ value: c.id, label: `${c.name} — ${c.code}` })),
    [classesQuery.data],
  );

  const slotOptions = useMemo<SelectOption[]>(
    () =>
      (slotsQuery.data ?? []).map((slot) => ({
        value: slot.id,
        label: `${slot.day?.name ?? '-'} JP${slot.slot_number} ${slot.start_time}–${slot.end_time}`,
      })),
    [slotsQuery.data],
  );

  const schedulesQuery = useQuery({
    queryKey: ['schedules', 'for-journal', values.class_id, values.semester_id],
    enabled: open && !!values.class_id && !!values.semester_id,
    staleTime: 1000 * 60 * 5,
    queryFn: () =>
      schedulesApi.list({
        all: true,
        filter: {
          school_class_id: values.class_id as number,
          semester_id: values.semester_id as number,
        },
      }),
  });

  const scheduleOptions = useMemo<SelectOption[]>(
    () =>
      (schedulesQuery.data?.data ?? []).map((s) => ({
        value: s.id,
        label: `${s.day?.name ?? '-'} ${s.start_time}–${s.end_time} · ${s.subject?.name ?? '-'}`,
      })),
    [schedulesQuery.data],
  );

  useEffect(() => {
    if (!open) return;
    setErrors({});

    setValues(
      editing
        ? {
            schedule_id: editing.schedule_id ?? '',
            class_id: editing.class_id,
            subject_id: editing.subject_id,
            teacher_id: editing.teacher_id,
            semester_id: editing.semester_id,
            time_slot_id: editing.time_slot_id ?? '',
            date: editing.date,
            topic: editing.topic,
            material: editing.material ?? '',
            activities: editing.activities ?? '',
            notes: editing.notes ?? '',
            student_present_count: editing.student_present_count,
            realized_jp: editing.realized_jp,
          }
        : { ...emptyForm, ...(defaults ?? {}) },
    );
  }, [open, editing, defaults]);

  const set = <K extends keyof TeachingJournalPayload>(
    key: K,
    value: TeachingJournalPayload[K],
  ) => {
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
    if (!values.semester_id) local.semester_id = 'Semester wajib dipilih';
    if (!values.class_id) local.class_id = 'Kelas wajib dipilih';
    if (!values.subject_id) local.subject_id = 'Mata pelajaran wajib dipilih';
    if (!values.topic.trim()) local.topic = 'Topik wajib diisi';

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
      title={editing ? 'Edit Jurnal Mengajar' : 'Catat Jurnal Mengajar'}
      size="lg"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="journal-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="journal-form" onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
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
            <Label required>Kelas</Label>
            <SearchSelect
              options={classOptions}
              value={values.class_id}
              error={err('class_id')}
              onChange={(v) => {
                setValues((prev) => ({
                  ...prev,
                  class_id: toId(v),
                  schedule_id: '',
                }));
              }}
              placeholder="-- Pilih Kelas --"
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
            <Label>Guru</Label>
            <SearchSelect
              options={teacherOptions}
              value={values.teacher_id}
              error={err('teacher_id')}
              onChange={(v) => set('teacher_id', toId(v))}
              placeholder="-- Kosongkan = guru pemanggil --"
            />
          </div>
          <div>
            <Label>Jam Pelajaran</Label>
            <SearchSelect
              options={slotOptions}
              value={values.time_slot_id}
              error={err('time_slot_id')}
              onChange={(v) => set('time_slot_id', toId(v))}
              placeholder="-- Opsional --"
            />
          </div>
          <div className="sm:col-span-2">
            <Label>Jadwal Terkait</Label>
            <SearchSelect
              options={scheduleOptions}
              value={values.schedule_id}
              error={err('schedule_id')}
              onChange={(v) => set('schedule_id', toId(v))}
              placeholder="-- Opsional, untuk menautkan ke jadwal --"
              disabled={!values.class_id || !values.semester_id}
            />
          </div>
          <div className="sm:col-span-2">
            <Label required>Topik</Label>
            <Input
              value={values.topic}
              error={err('topic')}
              placeholder="mis. Persamaan Kuadrat — akar-akar real"
              onChange={(e) => set('topic', e.target.value)}
            />
          </div>
          <div className="sm:col-span-2">
            <Label>Materi</Label>
            <Textarea
              value={values.material}
              error={err('material')}
              placeholder="Ringkasan materi yang disampaikan"
              onChange={(e) => set('material', e.target.value)}
            />
          </div>
          <div className="sm:col-span-2">
            <Label>Kegiatan Pembelajaran</Label>
            <Textarea
              value={values.activities}
              error={err('activities')}
              placeholder="mis. diskusi kelompok, latihan soal, presentasi"
              onChange={(e) => set('activities', e.target.value)}
            />
          </div>
          <div>
            <Label>Jumlah Siswa Hadir</Label>
            <Input
              type="number"
              min={0}
              value={values.student_present_count}
              error={err('student_present_count')}
              onChange={(e) =>
                set(
                  'student_present_count',
                  e.target.value === '' ? '' : Number(e.target.value),
                )
              }
            />
          </div>
          <div>
            <Label>JP Terealisasi</Label>
            <Input
              type="number"
              min={1}
              value={values.realized_jp}
              error={err('realized_jp')}
              onChange={(e) =>
                set('realized_jp', e.target.value === '' ? '' : Number(e.target.value))
              }
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
      </form>
    </Modal>
  );
}

/** Ekspor helper agar halaman lain bisa memakai tanggal yang sama. */
export { today as todayDate };
