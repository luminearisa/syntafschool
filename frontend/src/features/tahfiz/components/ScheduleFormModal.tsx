import { useEffect, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { useEmployeeOptions } from '@/hooks/useLookupOptions';
import { useHalaqahOptions, useProgramOptions } from '../hooks/useTahfizOptions';
import { ACTIVITY_TYPE_LABELS, DAY_LABELS, toOptions } from '../lib/labels';
import type { TahfizActivityType, TahfizSchedule, TahfizSchedulePayload } from '@/types/tahfiz';

interface Props {
  open: boolean;
  editing: TahfizSchedule | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: TahfizSchedulePayload) => void;
}

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

const ACTIVITY_OPTIONS = toOptions(ACTIVITY_TYPE_LABELS);

const DAY_OPTIONS = (Object.keys(DAY_LABELS) as unknown as string[])
  .map((key) => ({ value: Number(key), label: DAY_LABELS[Number(key)] }))
  .sort((a, b) => a.value - b.value);

const STATUS_OPTIONS = [
  { value: 'active', label: 'Aktif' },
  { value: 'inactive', label: 'Tidak Aktif' },
];

/**
 * Form jadwal berulang per jenis aktivitas.
 *
 * Satu halaqah dapat memiliki beberapa jadwal dengan jenis aktivitas berbeda
 * (mis. setoran Senin, tahsin Rabu).
 */
export function ScheduleFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const { options: halaqahOptions } = useHalaqahOptions();
  const { options: programOptions } = useProgramOptions();
  const { options: instructorOptions } = useEmployeeOptions(false);

  const [halaqahId, setHalaqahId] = useState<number | ''>('');
  const [programId, setProgramId] = useState<number | ''>('');
  const [activityType, setActivityType] = useState<TahfizActivityType>('setoran');
  const [dayOfWeek, setDayOfWeek] = useState<number | ''>('');
  const [startTime, setStartTime] = useState('');
  const [endTime, setEndTime] = useState('');
  const [instructorId, setInstructorId] = useState<number | ''>('');
  const [effectiveFrom, setEffectiveFrom] = useState('');
  const [effectiveUntil, setEffectiveUntil] = useState('');
  const [status, setStatus] = useState<'active' | 'inactive'>('active');
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (!open) return;
    setErrors({});
    if (editing) {
      setHalaqahId(editing.halaqah_id ?? '');
      setProgramId(editing.program_id ?? '');
      setActivityType(editing.activity_type);
      setDayOfWeek(editing.day_of_week);
      setStartTime(editing.start_time ?? '');
      setEndTime(editing.end_time ?? '');
      setInstructorId(editing.instructor_id ?? '');
      setEffectiveFrom(editing.effective_from ?? '');
      setEffectiveUntil(editing.effective_until ?? '');
      setStatus(editing.status);
    } else {
      setHalaqahId('');
      setProgramId('');
      setActivityType('setoran');
      setDayOfWeek('');
      setStartTime('');
      setEndTime('');
      setInstructorId('');
      setEffectiveFrom('');
      setEffectiveUntil('');
      setStatus('active');
    }
  }, [open, editing]);

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (!activityType) local.activity_type = 'Jenis aktivitas wajib dipilih';
    if (dayOfWeek === '') local.day_of_week = 'Hari wajib dipilih';
    if (!startTime) local.start_time = 'Jam mulai wajib diisi';
    if (!endTime) local.end_time = 'Jam selesai wajib diisi';
    if (startTime && endTime && endTime <= startTime) {
      local.end_time = 'Jam selesai harus setelah jam mulai';
    }
    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }

    onSubmit({
      halaqah_id: halaqahId,
      program_id: programId,
      activity_type: activityType,
      day_of_week: dayOfWeek,
      start_time: startTime,
      end_time: endTime,
      instructor_id: instructorId,
      effective_from: effectiveFrom,
      effective_until: effectiveUntil,
      status,
    });
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? 'Edit Jadwal Tahfiz' : 'Tambah Jadwal Tahfiz'}
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
          <div>
            <Label>Halaqah</Label>
            <SearchSelect
              options={halaqahOptions}
              value={halaqahId}
              error={err('halaqah_id')}
              onChange={(v) => setHalaqahId(toId(v))}
              placeholder="-- Opsional --"
            />
          </div>
          <div>
            <Label>Program</Label>
            <SearchSelect
              options={programOptions}
              value={programId}
              error={err('program_id')}
              onChange={(v) => setProgramId(toId(v))}
              placeholder="-- Opsional --"
            />
          </div>
          <div>
            <Label required>Jenis Aktivitas</Label>
            <Select
              value={activityType}
              error={err('activity_type')}
              onChange={(e) => setActivityType(e.target.value as TahfizActivityType)}
            >
              {ACTIVITY_OPTIONS.map((o) => (
                <option key={String(o.value)} value={String(o.value)}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label required>Hari</Label>
            <Select
              value={dayOfWeek === '' ? '' : String(dayOfWeek)}
              error={err('day_of_week')}
              onChange={(e) => setDayOfWeek(e.target.value === '' ? '' : Number(e.target.value))}
            >
              <option value="">-- Pilih Hari --</option>
              {DAY_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label required>Jam Mulai</Label>
            <Input
              type="time"
              value={startTime}
              error={err('start_time')}
              onChange={(e) => setStartTime(e.target.value)}
            />
          </div>
          <div>
            <Label required>Jam Selesai</Label>
            <Input
              type="time"
              value={endTime}
              error={err('end_time')}
              onChange={(e) => setEndTime(e.target.value)}
            />
          </div>
          <div>
            <Label>Instruktur</Label>
            <SearchSelect
              options={instructorOptions}
              value={instructorId}
              error={err('instructor_id')}
              onChange={(v) => setInstructorId(toId(v))}
              placeholder="-- Opsional --"
            />
          </div>
          <div>
            <Label>Status</Label>
            <Select
              value={status}
              error={err('status')}
              onChange={(e) => setStatus(e.target.value as 'active' | 'inactive')}
            >
              {STATUS_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label>Berlaku Mulai</Label>
            <Input
              type="date"
              value={effectiveFrom}
              error={err('effective_from')}
              onChange={(e) => setEffectiveFrom(e.target.value)}
            />
          </div>
          <div>
            <Label>Berlaku Hingga</Label>
            <Input
              type="date"
              value={effectiveUntil}
              error={err('effective_until')}
              onChange={(e) => setEffectiveUntil(e.target.value)}
            />
          </div>
        </div>
      </form>
    </Modal>
  );
}