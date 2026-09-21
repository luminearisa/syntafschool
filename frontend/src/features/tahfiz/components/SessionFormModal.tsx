import { useEffect, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { useEmployeeOptions } from '@/hooks/useLookupOptions';
import { useOptions } from '@/hooks/useOptions';
import { useHalaqahOptions } from '../hooks/useTahfizOptions';
import { ACTIVITY_TYPE_LABELS, SESSION_STATUS_LABELS, toOptions } from '../lib/labels';
import type {
  TahfizActivityType,
  TahfizSession,
  TahfizSessionPayload,
  TahfizSessionStatus,
} from '@/types/tahfiz';

interface Props {
  open: boolean;
  editing: TahfizSession | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: TahfizSessionPayload) => void;
}

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

const today = (): string => new Date().toISOString().slice(0, 10);

const ACTIVITY_OPTIONS = toOptions(ACTIVITY_TYPE_LABELS);
const STATUS_OPTIONS = toOptions(SESSION_STATUS_LABELS);

/**
 * Form sesi konkret: satu pertemuan pada satu tanggal.
 *
 * Sesi dapat dibuat manual atau di-generate dari jadwal berulang.
 */
export function SessionFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const { options: halaqahOptions } = useHalaqahOptions();
  const { options: instructorOptions } = useEmployeeOptions(false);
  const { options: roomOptions } = useOptions('rooms');

  const hasRooms = roomOptions.length > 0;

  const [halaqahId, setHalaqahId] = useState<number | ''>('');
  const [activityType, setActivityType] = useState<TahfizActivityType>('halaqah');
  const [date, setDate] = useState(today());
  const [startTime, setStartTime] = useState('');
  const [endTime, setEndTime] = useState('');
  const [roomId, setRoomId] = useState<number | ''>('');
  const [instructorId, setInstructorId] = useState<number | ''>('');
  const [topic, setTopic] = useState('');
  const [status, setStatus] = useState<TahfizSessionStatus>('planned');
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (!open) return;
    setErrors({});
    if (editing) {
      setHalaqahId(editing.halaqah_id ?? '');
      setActivityType(editing.activity_type);
      setDate(editing.date ?? today());
      setStartTime(editing.start_time ?? '');
      setEndTime(editing.end_time ?? '');
      setRoomId(editing.room_id ?? '');
      setInstructorId(editing.instructor_id ?? '');
      setTopic(editing.topic ?? '');
      setStatus(editing.status);
      setNotes(editing.notes ?? '');
    } else {
      setHalaqahId('');
      setActivityType('halaqah');
      setDate(today());
      setStartTime('');
      setEndTime('');
      setRoomId('');
      setInstructorId('');
      setTopic('');
      setStatus('planned');
      setNotes('');
    }
  }, [open, editing]);

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (!activityType) local.activity_type = 'Jenis aktivitas wajib dipilih';
    if (!date) local.date = 'Tanggal wajib diisi';
    if (startTime && endTime && endTime <= startTime) {
      local.end_time = 'Jam selesai harus setelah jam mulai';
    }
    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }

    onSubmit({
      halaqah_id: halaqahId,
      activity_type: activityType,
      date,
      start_time: startTime,
      end_time: endTime,
      room_id: hasRooms ? roomId : '',
      instructor_id: instructorId,
      topic,
      status,
      notes,
    });
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? 'Edit Sesi Tahfiz' : 'Tambah Sesi Tahfiz'}
      size="lg"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="session-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="session-form" onSubmit={handleSubmit} className="space-y-4">
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
            <Label required>Tanggal</Label>
            <Input
              type="date"
              value={date}
              error={err('date')}
              onChange={(e) => setDate(e.target.value)}
            />
          </div>
          <div>
            <Label>Status</Label>
            <Select
              value={status}
              error={err('status')}
              onChange={(e) => setStatus(e.target.value as TahfizSessionStatus)}
            >
              {STATUS_OPTIONS.map((o) => (
                <option key={String(o.value)} value={String(o.value)}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label>Jam Mulai</Label>
            <Input
              type="time"
              value={startTime}
              error={err('start_time')}
              onChange={(e) => setStartTime(e.target.value)}
            />
          </div>
          <div>
            <Label>Jam Selesai</Label>
            <Input
              type="time"
              value={endTime}
              error={err('end_time')}
              onChange={(e) => setEndTime(e.target.value)}
            />
          </div>
          {hasRooms && (
            <div>
              <Label>Ruangan</Label>
              <SearchSelect
                options={roomOptions}
                value={roomId}
                error={err('room_id')}
                onChange={(v) => setRoomId(toId(v))}
                placeholder="-- Opsional --"
              />
            </div>
          )}
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
          <div className="sm:col-span-2">
            <Label>Topik</Label>
            <Input
              value={topic}
              error={err('topic')}
              maxLength={150}
              placeholder="mis. Murojaah Juz 29"
              onChange={(e) => setTopic(e.target.value)}
            />
          </div>
          <div className="sm:col-span-2">
            <Label>Catatan</Label>
            <Textarea
              value={notes}
              error={err('notes')}
              placeholder="Opsional"
              onChange={(e) => setNotes(e.target.value)}
            />
          </div>
        </div>
      </form>
    </Modal>
  );
}