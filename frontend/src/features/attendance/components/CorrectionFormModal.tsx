import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { studentAttendancesApi } from '../api/attendanceApi';
import { CODES_REQUIRING_LEAVE_TYPE, codeTone, useAttendanceStatuses } from '../hooks/useAttendanceStatuses';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { useStudentOptions } from '@/hooks/useLookupOptions';
import { useOptions } from '@/hooks/useOptions';
import type { AttendanceCorrectionPayload } from '@/types/attendance';

interface Props {
  open: boolean;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: AttendanceCorrectionPayload) => void;
}

/** Tanggal hari ini dalam format `YYYY-MM-DD`. */
function today(): string {
  const now = new Date();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  const day = String(now.getDate()).padStart(2, '0');
  return `${now.getFullYear()}-${month}-${day}`;
}

const emptyForm: AttendanceCorrectionPayload = {
  student_attendance_id: '',
  new_status_id: '',
  leave_type_id: '',
  reason: '',
};

/**
 * Pengajuan koreksi presensi.
 *
 * Presensi yang sudah tercatat tidak diubah langsung — guru memilih baris
 * presensi yang salah, mengajukan status baru beserta alasannya, lalu pemegang
 * `academic.approve` yang memutuskan.
 */
export function CorrectionFormModal({ open, submitting, serverErrors, onClose, onSubmit }: Props) {
  const [studentId, setStudentId] = useState<number | ''>('');
  const [date, setDate] = useState(today());
  const [values, setValues] = useState<AttendanceCorrectionPayload>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { options: studentOptions } = useStudentOptions();
  const { options: leaveTypeOptions } = useOptions('leave-types');
  const { statuses } = useAttendanceStatuses();

  useEffect(() => {
    if (!open) return;
    setStudentId('');
    setDate(today());
    setValues(emptyForm);
    setErrors({});
  }, [open]);

  /** Presensi siswa terpilih pada tanggal terpilih. */
  const recordsQuery = useQuery({
    queryKey: ['student-attendances', 'for-correction', studentId, date],
    enabled: open && !!studentId && !!date,
    queryFn: () =>
      studentAttendancesApi.list({
        all: true,
        from: date,
        to: date,
        filter: { student_id: studentId as number },
      }),
  });

  const records = useMemo(() => recordsQuery.data?.data ?? [], [recordsQuery.data]);

  const set = <K extends keyof AttendanceCorrectionPayload>(
    key: K,
    value: AttendanceCorrectionPayload[K],
  ) => {
    setValues((prev) => ({ ...prev, [key]: value }));
    setErrors((prev) => {
      const next = { ...prev };
      delete next[key];
      return next;
    });
  };

  const selectedStatus = statuses.find((s) => s.id === Number(values.new_status_id));
  const needsLeaveType = selectedStatus
    ? CODES_REQUIRING_LEAVE_TYPE.includes(selectedStatus.code)
    : false;

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();

    const local: Record<string, string> = {};
    if (!values.student_attendance_id) local.student_attendance_id = 'Pilih presensi yang ingin dikoreksi';
    if (!values.new_status_id) local.new_status_id = 'Status baru wajib dipilih';
    if (!values.reason.trim()) local.reason = 'Alasan koreksi wajib diisi';
    if (needsLeaveType && !values.leave_type_id) {
      local.leave_type_id = `Jenis izin wajib diisi untuk status ${selectedStatus?.name}.`;
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
      title="Ajukan Koreksi Presensi"
      size="lg"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="correction-form" loading={submitting}>
            Kirim Pengajuan
          </Button>
        </>
      }
    >
      <form id="correction-form" onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label required>Siswa</Label>
            <SearchSelect
              options={studentOptions}
              value={studentId}
              onChange={(v) => {
                setStudentId(v === '' ? '' : Number(v));
                set('student_attendance_id', '');
              }}
              placeholder="-- Pilih Siswa --"
            />
          </div>
          <div>
            <Label required>Tanggal Presensi</Label>
            <Input
              type="date"
              value={date}
              onChange={(e) => {
                setDate(e.target.value);
                set('student_attendance_id', '');
              }}
            />
          </div>
        </div>

        <div>
          <Label required>Presensi yang Dikoreksi</Label>
          {!studentId ? (
            <p className="text-xs text-slate-500">Pilih siswa dan tanggal terlebih dahulu.</p>
          ) : recordsQuery.isLoading ? (
            <div className="flex items-center gap-2 py-3 text-xs text-slate-400">
              <Spinner /> Mencari presensi...
            </div>
          ) : records.length === 0 ? (
            <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700">
              Tidak ada presensi tercatat untuk siswa ini pada tanggal tersebut.
            </p>
          ) : (
            <div className="space-y-2">
              {records.map((record) => (
                <label
                  key={record.id}
                  className={
                    'flex cursor-pointer items-center justify-between gap-3 rounded-lg border px-3 py-2 text-sm ' +
                    (Number(values.student_attendance_id) === record.id
                      ? 'border-brand-500 bg-brand-50'
                      : 'border-slate-200 hover:bg-slate-50')
                  }
                >
                  <div className="flex items-center gap-3">
                    <input
                      type="radio"
                      name="student_attendance_id"
                      className="h-4 w-4"
                      checked={Number(values.student_attendance_id) === record.id}
                      onChange={() => set('student_attendance_id', record.id)}
                    />
                    <div>
                      <div className="text-slate-800">
                        {record.subject?.name ?? record.school_class?.name ?? 'Presensi harian'}
                      </div>
                      <div className="text-xs text-slate-500">
                        {record.date}
                        {record.time_in ? ` · masuk ${record.time_in}` : ''}
                        {record.status === 'locked' ? ' · terkunci' : ''}
                      </div>
                    </div>
                  </div>
                  <Badge tone={codeTone(record.status_code)}>{record.status_name ?? '-'}</Badge>
                </label>
              ))}
            </div>
          )}
          {err('student_attendance_id') && (
            <p className="mt-1 text-xs text-red-500">{err('student_attendance_id')}</p>
          )}
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label required>Status Baru</Label>
            <Select
              value={values.new_status_id}
              error={err('new_status_id')}
              onChange={(e) => {
                const next = e.target.value === '' ? '' : Number(e.target.value);
                const status = statuses.find((s) => s.id === Number(next));
                const needsLeave = status
                  ? CODES_REQUIRING_LEAVE_TYPE.includes(status.code)
                  : false;
                setValues((prev) => ({
                  ...prev,
                  new_status_id: next,
                  leave_type_id: needsLeave ? prev.leave_type_id : '',
                }));
              }}
            >
              <option value="">-- Pilih Status --</option>
              {statuses.map((status) => (
                <option key={status.id} value={status.id}>
                  {status.name}
                </option>
              ))}
            </Select>
            {err('new_status_id') && (
              <p className="mt-1 text-xs text-red-500">{err('new_status_id')}</p>
            )}
          </div>

          <div>
            <Label required={needsLeaveType}>Jenis Izin</Label>
            <Select
              value={values.leave_type_id}
              error={err('leave_type_id')}
              disabled={!needsLeaveType}
              onChange={(e) =>
                set('leave_type_id', e.target.value === '' ? '' : Number(e.target.value))
              }
            >
              <option value="">-- Tidak ada --</option>
              {leaveTypeOptions.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </Select>
            {err('leave_type_id') && (
              <p className="mt-1 text-xs text-red-500">{err('leave_type_id')}</p>
            )}
          </div>
        </div>

        <div>
          <Label required>Alasan Koreksi</Label>
          <Textarea
            value={values.reason}
            error={err('reason')}
            placeholder="mis. siswa terlambat datang karena mengikuti lomba, surat izin menyusul"
            onChange={(e) => set('reason', e.target.value)}
          />
          {err('reason') && <p className="mt-1 text-xs text-red-500">{err('reason')}</p>}
          <p className="mt-1 text-xs text-slate-500">
            Alasan disimpan bersama status lama sehingga perubahan tetap bisa diaudit.
          </p>
        </div>
      </form>
    </Modal>
  );
}
