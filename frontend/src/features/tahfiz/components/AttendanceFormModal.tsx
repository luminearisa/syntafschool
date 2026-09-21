import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { sessionsApi } from '../api/tahfizApi';
import { useHalaqahOptions, useParticipantOptions } from '../hooks/useTahfizOptions';
import { ACTIVITY_TYPE_LABELS, ATTENDANCE_STATUS_LABELS, toOptions } from '../lib/labels';
import type { SelectOption } from '@/constants/masterData';
import type {
  TahfizAttendance,
  TahfizAttendancePayload,
  TahfizAttendanceStatus,
} from '@/types/tahfiz';

interface Props {
  open: boolean;
  editing: TahfizAttendance | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: TahfizAttendancePayload) => void;
}

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

const today = (): string => new Date().toISOString().slice(0, 10);

const STATUS_OPTIONS = toOptions(ATTENDANCE_STATUS_LABELS);

const NEEDS_REASON: TahfizAttendanceStatus[] = ['izin', 'sakit'];

/**
 * Catat kehadiran satu peserta.
 *
 * Status izin/sakit wajib disertai keterangan — aturan ini dicerminkan
 * backend sehingga divalidasi juga di klien.
 */
export function AttendanceFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const { options: participantOptions } = useParticipantOptions();
  const { options: halaqahOptions } = useHalaqahOptions();

  const [sessionId, setSessionId] = useState<number | ''>('');
  const [halaqahId, setHalaqahId] = useState<number | ''>('');
  const [participantId, setParticipantId] = useState<number | ''>('');
  const [date, setDate] = useState(today());
  const [status, setStatus] = useState<TahfizAttendanceStatus>('hadir');
  const [arrivalTime, setArrivalTime] = useState('');
  const [permissionReason, setPermissionReason] = useState('');
  const [notes, setNotes] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});

  const sessionsQuery = useQuery({
    queryKey: ['tahfiz', 'sessions', 'options', halaqahId],
    enabled: open,
    staleTime: 1000 * 60 * 5,
    queryFn: () =>
      sessionsApi.list({
        all: true,
        filter: halaqahId ? { halaqah_id: halaqahId } : undefined,
      }),
  });

  const sessionOptions = useMemo<SelectOption[]>(
    () =>
      (sessionsQuery.data?.data ?? []).map((s) => ({
        value: s.id,
        label: `${s.date ?? '-'} — ${ACTIVITY_TYPE_LABELS[s.activity_type] ?? s.activity_type}`,
      })),
    [sessionsQuery.data],
  );

  useEffect(() => {
    if (!open) return;
    setErrors({});
    if (editing) {
      setSessionId(editing.session_id ?? '');
      setHalaqahId(editing.halaqah_id ?? '');
      setParticipantId(editing.participant_id);
      setDate(editing.date ?? today());
      setStatus(editing.status);
      setArrivalTime(editing.arrival_time ?? '');
      setPermissionReason(editing.permission_reason ?? '');
      setNotes(editing.notes ?? '');
    } else {
      setSessionId('');
      setHalaqahId('');
      setParticipantId('');
      setDate(today());
      setStatus('hadir');
      setArrivalTime('');
      setPermissionReason('');
      setNotes('');
    }
  }, [open, editing]);

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (!participantId) local.participant_id = 'Peserta wajib dipilih';
    if (!date) local.date = 'Tanggal wajib diisi';
    if (!status) local.status = 'Status wajib dipilih';
    if (NEEDS_REASON.includes(status) && !permissionReason.trim()) {
      local.permission_reason = `Keterangan wajib diisi untuk status ${ATTENDANCE_STATUS_LABELS[status]}.`;
    }
    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }

    onSubmit({
      session_id: sessionId,
      halaqah_id: halaqahId,
      participant_id: participantId,
      date,
      status,
      arrival_time: arrivalTime,
      permission_reason: permissionReason,
      notes,
    });
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];
  const reasonRequired = NEEDS_REASON.includes(status);

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? 'Edit Kehadiran' : 'Catat Kehadiran'}
      size="lg"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="attendance-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="attendance-form" onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label>Halaqah</Label>
            <SearchSelect
              options={halaqahOptions}
              value={halaqahId}
              error={err('halaqah_id')}
              onChange={(v) => {
                setHalaqahId(toId(v));
                setSessionId('');
              }}
              placeholder="-- Opsional --"
            />
          </div>
          <div>
            <Label>Sesi</Label>
            <SearchSelect
              options={sessionOptions}
              value={sessionId}
              error={err('session_id')}
              onChange={(v) => setSessionId(toId(v))}
              placeholder="-- Opsional, pilih halaqah dulu --"
              disabled={sessionsQuery.isLoading}
            />
          </div>
          <div>
            <Label required>Peserta</Label>
            <SearchSelect
              options={participantOptions}
              value={participantId}
              error={err('participant_id')}
              onChange={(v) => setParticipantId(toId(v))}
              placeholder="-- Pilih Peserta --"
            />
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
            <Label required>Status</Label>
            <Select
              value={status}
              error={err('status')}
              onChange={(e) => setStatus(e.target.value as TahfizAttendanceStatus)}
            >
              {STATUS_OPTIONS.map((o) => (
                <option key={String(o.value)} value={String(o.value)}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label>Jam Datang</Label>
            <Input
              type="time"
              value={arrivalTime}
              error={err('arrival_time')}
              onChange={(e) => setArrivalTime(e.target.value)}
            />
          </div>
          <div className="sm:col-span-2">
            <Label required={reasonRequired}>Keterangan Izin/Sakit</Label>
            <Input
              value={permissionReason}
              error={err('permission_reason')}
              maxLength={150}
              placeholder={reasonRequired ? 'Wajib diisi untuk izin/sakit' : 'Opsional'}
              onChange={(e) => setPermissionReason(e.target.value)}
            />
            {reasonRequired && (
              <p className="mt-1 text-xs text-amber-600">
                Status {ATTENDANCE_STATUS_LABELS[status]} memerlukan keterangan.
              </p>
            )}
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