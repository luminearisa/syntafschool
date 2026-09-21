import { useEffect, useState, type FormEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { eventTypesApi } from '../api/academicCalendarApi';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Textarea } from '@/components/ui/Textarea';
import { useOptions } from '@/hooks/useOptions';
import type { AcademicEvent, AcademicEventPayload } from '@/types/academicCalendar';

interface Props {
  open: boolean;
  editing: AcademicEvent | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: AcademicEventPayload) => void;
}

const emptyForm: AcademicEventPayload = {
  semester_id: '',
  academic_year_id: '',
  event_type_id: '',
  title: '',
  start_date: '',
  end_date: '',
  description: '',
  location: '',
  is_holiday: false,
  is_active: true,
};

/** SearchSelect mengembalikan `string | number | ''`; payload memakai `number | ''`. */
const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

/** Form tambah/edit event akademik. */
export function EventFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [values, setValues] = useState<AcademicEventPayload>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { options: semesterOptions } = useOptions('semesters');

  const typesQuery = useQuery({
    queryKey: ['event-types', 'all'],
    enabled: open,
    staleTime: 1000 * 60 * 5,
    queryFn: () => eventTypesApi.list({ all: true }),
  });

  const eventTypes = typesQuery.data?.data ?? [];

  useEffect(() => {
    if (!open) return;
    setErrors({});

    setValues(
      editing
        ? {
            semester_id: editing.semester_id,
            academic_year_id: editing.academic_year_id ?? '',
            event_type_id: editing.event_type_id,
            title: editing.title,
            start_date: editing.start_date,
            end_date: editing.end_date,
            description: editing.description ?? '',
            location: editing.location ?? '',
            is_holiday: editing.is_holiday,
            is_active: editing.is_active,
          }
        : { ...emptyForm },
    );
  }, [open, editing]);

  const set = <K extends keyof AcademicEventPayload>(key: K, value: AcademicEventPayload[K]) => {
    setValues((prev) => ({ ...prev, [key]: value }));
    setErrors((prev) => {
      const next = { ...prev };
      delete next[key];
      return next;
    });
  };

  /** Memilih jenis event ikut menentukan penanda libur (bisa diubah manual). */
  const pickEventType = (value: string | number | '') => {
    const type = eventTypes.find((t) => t.id === Number(value));
    setValues((prev) => ({
      ...prev,
      event_type_id: value === '' ? '' : Number(value),
      is_holiday: type ? type.is_holiday : prev.is_holiday,
    }));
  };

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();

    const local: Record<string, string> = {};
    if (!values.semester_id) local.semester_id = 'Semester wajib dipilih';
    if (!values.event_type_id) local.event_type_id = 'Jenis event wajib dipilih';
    if (!values.title.trim()) local.title = 'Judul wajib diisi';
    if (!values.start_date) local.start_date = 'Tanggal mulai wajib diisi';
    if (!values.end_date) local.end_date = 'Tanggal selesai wajib diisi';
    if (values.start_date && values.end_date && values.end_date < values.start_date) {
      local.end_date = 'Tanggal selesai tidak boleh lebih awal dari tanggal mulai';
    }

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
      title={editing ? 'Edit Event Akademik' : 'Tambah Event Akademik'}
      size="lg"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="event-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="event-form" onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
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
            <Label required>Jenis Event</Label>
            <SearchSelect
              options={eventTypes.map((t) => ({ value: t.id, label: t.name }))}
              value={values.event_type_id}
              error={err('event_type_id')}
              onChange={pickEventType}
              placeholder="-- Pilih Jenis Event --"
            />
          </div>

          <div className="sm:col-span-2">
            <Label required>Judul</Label>
            <Input
              value={values.title}
              error={err('title')}
              placeholder="mis. Penilaian Tengah Semester"
              onChange={(e) => set('title', e.target.value)}
            />
          </div>

          <div>
            <Label required>Tanggal Mulai</Label>
            <Input
              type="date"
              value={values.start_date}
              error={err('start_date')}
              onChange={(e) => set('start_date', e.target.value)}
            />
          </div>

          <div>
            <Label required>Tanggal Selesai</Label>
            <Input
              type="date"
              value={values.end_date}
              error={err('end_date')}
              onChange={(e) => set('end_date', e.target.value)}
            />
          </div>

          <div className="sm:col-span-2">
            <Label>Lokasi</Label>
            <Input
              value={values.location}
              error={err('location')}
              placeholder="Opsional"
              onChange={(e) => set('location', e.target.value)}
            />
          </div>

          <div className="sm:col-span-2">
            <Label>Keterangan</Label>
            <Textarea
              value={values.description}
              error={err('description')}
              placeholder="Opsional"
              onChange={(e) => set('description', e.target.value)}
            />
          </div>

          <div className="flex items-center gap-4 sm:col-span-2">
            <label className="flex items-center gap-2 text-sm text-slate-700">
              <input
                type="checkbox"
                className="h-4 w-4 rounded border-slate-300"
                checked={values.is_holiday}
                onChange={(e) => set('is_holiday', e.target.checked)}
              />
              Hari libur (meniadakan hari sekolah)
            </label>

            <label className="flex items-center gap-2 text-sm text-slate-700">
              <input
                type="checkbox"
                className="h-4 w-4 rounded border-slate-300"
                checked={values.is_active}
                onChange={(e) => set('is_active', e.target.checked)}
              />
              Aktif
            </label>
          </div>
        </div>
      </form>
    </Modal>
  );
}
