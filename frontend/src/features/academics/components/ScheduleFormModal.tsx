import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { AlertTriangle, CheckCircle2 } from 'lucide-react';
import { schedulesApi, timeSlotsApi } from '../api/schedulesApi';
import { classSubjectsApi } from '../api/classSubjectsApi';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { useOptions } from '@/hooks/useOptions';
import { useEmployeeOptions } from '@/hooks/useLookupOptions';
import type { Schedule, SchedulePayload, SchoolClass } from '@/types';

interface Props {
  open: boolean;
  editing: Schedule | null;
  defaults?: Partial<SchedulePayload>;
  classes: SchoolClass[];
  semesterId: number | '';
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: SchedulePayload) => void;
}

const emptyForm = (semesterId: number | '' = ''): SchedulePayload => ({
  school_class_id: '',
  subject_id: '',
  employee_id: '',
  room_id: '',
  day_id: '',
  semester_id: semesterId,
  time_slot_id: '',
  start_time: '07:00',
  end_time: '07:45',
  note: '',
});

/**
 * Form jadwal dengan pemeriksaan bentrok langsung.
 *
 * Begitu kelas, hari, dan jam terisi, backend diperiksa lebih dulu
 * (`/schedules/check-conflict`) sehingga guru melihat potensi bentrok
 * sebelum menyimpan — bukan sesudah ditolak.
 */
export function ScheduleFormModal({
  open,
  editing,
  defaults,
  classes,
  semesterId,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [values, setValues] = useState<SchedulePayload>(emptyForm(semesterId));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [debounced, setDebounced] = useState('');

  const { options: dayOptions } = useOptions('days');
  const { options: roomOptions } = useOptions('rooms');
  const { options: teacherOptions } = useEmployeeOptions(true);

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setValues(
      editing
        ? {
            school_class_id: editing.school_class_id ?? '',
            subject_id: editing.subject_id ?? '',
            employee_id: editing.employee_id ?? '',
            room_id: editing.room_id ?? '',
            day_id: editing.day_id ?? '',
            semester_id: editing.semester_id ?? semesterId,
            time_slot_id: editing.time_slot_id ?? '',
            start_time: editing.start_time ?? '07:00',
            end_time: editing.end_time ?? '07:45',
            note: editing.note ?? '',
          }
        : { ...emptyForm(semesterId), ...defaults },
    );
  }, [open, editing, defaults, semesterId]);

  // Mapel yang boleh dijadwalkan = mapel yang terdaftar di kelas tersebut.
  const classId = Number(values.school_class_id) || 0;

  const classSubjectsQuery = useQuery({
    queryKey: ['class-subjects', classId],
    enabled: open && classId > 0,
    queryFn: () => classSubjectsApi.list(classId),
  });

  const slotsQuery = useQuery({
    queryKey: ['time-slots'],
    enabled: open,
    staleTime: 1000 * 60 * 5,
    queryFn: () => timeSlotsApi.list({ all: true }),
  });

  const subjectOptions = useMemo(
    () =>
      (classSubjectsQuery.data ?? []).map((cs) => ({
        value: cs.subject_id,
        label: cs.subject ? `${cs.subject.name}${cs.teacher ? ` — ${cs.teacher.full_name}` : ''}` : `Mapel #${cs.subject_id}`,
      })),
    [classSubjectsQuery.data],
  );

  const slotOptions = useMemo(
    () =>
      (slotsQuery.data ?? [])
        .filter((s) => Number(s.day_id) === Number(values.day_id))
        .map((s) => ({
          value: s.id,
          label: `${s.label ?? `Jam ke-${s.slot_number}`} (${s.start_time}–${s.end_time})`,
        })),
    [slotsQuery.data, values.day_id],
  );

  // Tunda sedikit agar pemeriksaan tidak berjalan pada tiap ketikan.
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebounced(
        [
          values.school_class_id,
          values.day_id,
          values.semester_id,
          values.start_time,
          values.end_time,
          values.employee_id,
          values.room_id,
        ].join('|'),
      );
    }, 400);

    return () => clearTimeout(timer);
  }, [values]);

  const conflictReady =
    !!values.school_class_id &&
    !!values.day_id &&
    !!values.semester_id &&
    !!values.start_time &&
    !!values.end_time;

  const conflictQuery = useQuery({
    queryKey: ['schedule-conflict', debounced, editing?.id ?? null],
    enabled: open && conflictReady && debounced !== '',
    queryFn: () =>
      schedulesApi.checkConflict({
        school_class_id: values.school_class_id,
        day_id: values.day_id,
        semester_id: values.semester_id,
        start_time: values.start_time,
        end_time: values.end_time,
        employee_id: values.employee_id,
        room_id: values.room_id,
        ignore_id: editing?.id,
      }),
  });

  const set = (name: keyof SchedulePayload, value: unknown) => {
    setValues((prev) => ({ ...prev, [name]: value }));
    setErrors((prev) => {
      const next = { ...prev };
      delete next[name];
      return next;
    });
  };

  // Memilih jam pelajaran mengisi jam mulai/selesai secara otomatis.
  const pickSlot = (slotId: string | number | '') => {
    if (slotId === '') {
      set('time_slot_id', '');
      return;
    }
    const slot = (slotsQuery.data ?? []).find((s) => s.id === Number(slotId));
    setValues((prev) => ({
      ...prev,
      time_slot_id: Number(slotId),
      start_time: slot?.start_time ?? prev.start_time,
      end_time: slot?.end_time ?? prev.end_time,
    }));
  };

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (!values.school_class_id) local.school_class_id = 'Kelas wajib dipilih';
    if (!values.subject_id) local.subject_id = 'Mata pelajaran wajib dipilih';
    if (!values.day_id) local.day_id = 'Hari wajib dipilih';
    if (!values.start_time) local.start_time = 'Jam mulai wajib diisi';
    if (!values.end_time) local.end_time = 'Jam selesai wajib diisi';
    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }
    onSubmit(values);
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];

  const conflicts = conflictQuery.data?.conflicts ?? [];
  const conflictChecked = conflictReady && conflictQuery.isSuccess;

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? 'Edit Jadwal' : 'Tambah Jadwal'}
      size="lg"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="schedule-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="schedule-form" onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="sm:col-span-2">
            <Label required>Kelas</Label>
            <Select
              value={values.school_class_id}
              error={err('school_class_id')}
              disabled={!!editing}
              onChange={(e) => {
                // Ganti kelas berarti daftar mapel & gurunya ikut berubah.
                setValues((prev) => ({
                  ...prev,
                  school_class_id: e.target.value === '' ? '' : Number(e.target.value),
                  subject_id: '',
                }));
              }}
            >
              <option value="">-- Pilih Kelas --</option>
              {classes.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name} ({c.code})
                </option>
              ))}
            </Select>
          </div>

          <div className="sm:col-span-2">
            <Label required>Mata Pelajaran</Label>
            <SearchSelect
              options={subjectOptions}
              value={values.subject_id}
              error={err('subject_id')}
              disabled={!values.school_class_id}
              onChange={(v) => set('subject_id', v)}
              placeholder={
                !values.school_class_id
                  ? '-- Pilih kelas lebih dulu --'
                  : subjectOptions.length === 0
                    ? '-- Kelas ini belum punya mapel --'
                    : '-- Pilih Mapel --'
              }
            />
            {values.school_class_id && subjectOptions.length === 0 && !classSubjectsQuery.isLoading && (
              <p className="mt-1 text-xs text-amber-600">
                Kelas ini belum punya mata pelajaran. Tambahkan dulu lewat tombol Mapel di halaman Kelas & Rombel.
              </p>
            )}
          </div>

          <div>
            <Label>Guru Pengajar</Label>
            <SearchSelect
              options={teacherOptions}
              value={values.employee_id}
              error={err('employee_id')}
              onChange={(v) => set('employee_id', v)}
              placeholder="-- Pilih Guru --"
            />
          </div>
          <div>
            <Label>Ruangan</Label>
            <SearchSelect
              options={roomOptions}
              value={values.room_id}
              error={err('room_id')}
              onChange={(v) => set('room_id', v)}
              placeholder="-- Pilih Ruangan --"
            />
          </div>

          <div>
            <Label required>Hari</Label>
            <Select
              value={values.day_id}
              error={err('day_id')}
              onChange={(e) =>
                setValues((prev) => ({
                  ...prev,
                  day_id: e.target.value === '' ? '' : Number(e.target.value),
                  time_slot_id: '',
                }))
              }
            >
              <option value="">-- Pilih Hari --</option>
              {dayOptions.map((o) => (
                <option key={String(o.value)} value={String(o.value)}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label>Jam Pelajaran</Label>
            <SearchSelect
              options={slotOptions}
              value={values.time_slot_id}
              disabled={!values.day_id}
              onChange={pickSlot}
              placeholder={values.day_id ? '-- Pilih Jam --' : '-- Pilih hari lebih dulu --'}
            />
          </div>

          <div>
            <Label required>Jam Mulai</Label>
            <Input
              type="time"
              value={values.start_time}
              error={err('start_time')}
              onChange={(e) => set('start_time', e.target.value)}
            />
          </div>
          <div>
            <Label required>Jam Selesai</Label>
            <Input
              type="time"
              value={values.end_time}
              error={err('end_time')}
              onChange={(e) => set('end_time', e.target.value)}
            />
          </div>

          <div className="sm:col-span-2">
            <Label>Catatan</Label>
            <Input
              value={values.note}
              error={err('note')}
              placeholder="Opsional — mis. hanya semester ganjil"
              onChange={(e) => set('note', e.target.value)}
            />
          </div>
        </div>

        {conflictChecked && (
          <div
            className={
              conflicts.length === 0
                ? 'flex items-start gap-2 rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-700'
                : 'flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700'
            }
          >
            {conflicts.length === 0 ? (
              <>
                <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" />
                <span>Tidak ada bentrok jadwal pada jam ini.</span>
              </>
            ) : (
              <>
                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                <div className="space-y-1">
                  <p className="font-medium">Ditemukan bentrok jadwal:</p>
                  <ul className="list-disc space-y-0.5 pl-4">
                    {conflicts.map((c) => (
                      <li key={`${c.type}-${c.schedule_id ?? c.message}`}>{c.message}</li>
                    ))}
                  </ul>
                </div>
              </>
            )}
          </div>
        )}
      </form>
    </Modal>
  );
}
