import { useEffect, useState, type FormEvent } from 'react';
import { CODES_REQUIRING_LEAVE_TYPE, useAttendanceStatuses } from '../hooks/useAttendanceStatuses';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { useEmployeeOptions } from '@/hooks/useLookupOptions';
import type { EmployeeAttendance, EmployeeAttendancePayload } from '@/types/attendance';

interface Props {
  open: boolean;
  editing: EmployeeAttendance | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: EmployeeAttendancePayload) => void;
}

function today(): string {
  const now = new Date();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  const day = String(now.getDate()).padStart(2, '0');
  return `${now.getFullYear()}-${month}-${day}`;
}

const emptyForm: EmployeeAttendancePayload = {
  employee_id: '',
  date: today(),
  check_in: '',
  check_out: '',
  attendance_status_id: '',
  leave_type_id: '',
  notes: '',
};

/**
 * Form presensi pegawai.
 *
 * Satu pegawai hanya punya satu baris per tanggal; menyimpan ulang akan
 * memperbarui baris yang sama sehingga jam pulang bisa diisi belakangan.
 */
export function EmployeeAttendanceFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [values, setValues] = useState<EmployeeAttendancePayload>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { options: employeeOptions } = useEmployeeOptions(false);
  const { leaveTypeOptions, statuses } = useAttendanceStatuses();

  useEffect(() => {
    if (!open) return;
    setErrors({});

    setValues(
      editing
        ? {
            employee_id: editing.employee_id,
            date: editing.date,
            check_in: editing.check_in ?? '',
            check_out: editing.check_out ?? '',
            attendance_status_id: editing.attendance_status_id,
            leave_type_id: editing.leave_type_id ?? '',
            notes: editing.notes ?? '',
          }
        : { ...emptyForm },
    );
  }, [open, editing]);

  const set = <K extends keyof EmployeeAttendancePayload>(
    key: K,
    value: EmployeeAttendancePayload[K],
  ) => {
    setValues((prev) => ({ ...prev, [key]: value }));
    setErrors((prev) => {
      const next = { ...prev };
      delete next[key];
      return next;
    });
  };

  const selectedStatus = statuses.find((s) => s.id === Number(values.attendance_status_id));
  const needsLeaveType = selectedStatus
    ? CODES_REQUIRING_LEAVE_TYPE.includes(selectedStatus.code)
    : false;

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();

    const local: Record<string, string> = {};
    if (!values.employee_id) local.employee_id = 'Pegawai wajib dipilih';
    if (!values.date) local.date = 'Tanggal wajib diisi';
    if (!values.attendance_status_id) local.attendance_status_id = 'Status presensi wajib dipilih';
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
      title={editing ? 'Edit Presensi Pegawai' : 'Catat Presensi Pegawai'}
      size="lg"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="employee-attendance-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="employee-attendance-form" onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label required>Pegawai</Label>
            <SearchSelect
              options={employeeOptions}
              value={values.employee_id}
              error={err('employee_id')}
              onChange={(v) => set('employee_id', v === '' ? '' : Number(v))}
              placeholder="-- Pilih Pegawai --"
            />
          </div>
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
            <Label>Jam Masuk</Label>
            <Input
              type="time"
              value={values.check_in}
              error={err('check_in')}
              onChange={(e) => set('check_in', e.target.value)}
            />
          </div>
          <div>
            <Label>Jam Pulang</Label>
            <Input
              type="time"
              value={values.check_out}
              error={err('check_out')}
              onChange={(e) => set('check_out', e.target.value)}
            />
          </div>

          <div>
            <Label required>Status Presensi</Label>
            <Select
              value={values.attendance_status_id}
              error={err('attendance_status_id')}
              onChange={(e) => {
                const next = e.target.value === '' ? '' : Number(e.target.value);
                const status = statuses.find((s) => s.id === Number(next));
                const needsLeave = status
                  ? CODES_REQUIRING_LEAVE_TYPE.includes(status.code)
                  : false;
                setValues((prev) => ({
                  ...prev,
                  attendance_status_id: next,
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
