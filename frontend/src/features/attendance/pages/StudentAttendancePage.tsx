import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CalendarDays, CheckCheck, RefreshCw, Save } from 'lucide-react';
import { toast } from 'sonner';
import { studentAttendancesApi } from '../api/attendanceApi';
import { CODE_LABELS, CODES_REQUIRING_LEAVE_TYPE, useAttendanceStatuses } from '../hooks/useAttendanceStatuses';
import { classesApi } from '@/features/academics/api/academicsApi';
import { schedulesApi } from '@/features/academics/api/schedulesApi';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Can } from '@/components/common/Can';
import { useOptions } from '@/hooks/useOptions';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { SelectOption } from '@/constants/masterData';
import type { BulkAttendanceEntryPayload, BulkAttendancePayload } from '@/types/attendance';

/** SearchSelect mengembalikan `string | number | ''`; state memakai `number | ''`. */
const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

/** Tanggal hari ini dalam format `YYYY-MM-DD` (waktu lokal, bukan UTC). */
function today(): string {
  const now = new Date();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  const day = String(now.getDate()).padStart(2, '0');
  return `${now.getFullYear()}-${month}-${day}`;
}

/** Satu baris input presensi siswa. */
interface RowState {
  attendance_status_id: number | '';
  leave_type_id: number | '';
  time_in: string;
  notes: string;
}

const emptyRow: RowState = {
  attendance_status_id: '',
  leave_type_id: '',
  time_in: '',
  notes: '',
};

/**
 * Lembar presensi siswa per kelas per tanggal.
 *
 * Guru memilih kelas + tanggal, lalu menandai status seluruh siswa dengan
 * tombol cepat. Penyimpanan bersifat upsert sehingga boleh ditekan dua kali
 * tanpa membuat data ganda.
 */
export function StudentAttendancePage() {
  const queryClient = useQueryClient();

  const [classId, setClassId] = useState<number | ''>('');
  const [semesterId, setSemesterId] = useState<number | ''>('');
  const [date, setDate] = useState(today());
  const [scheduleId, setScheduleId] = useState<number | ''>('');
  const [rows, setRows] = useState<Record<number, RowState>>({});
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const { options: semesterOptions } = useOptions('semesters');
  const { options: leaveTypeOptions } = useOptions('leave-types');
  const { statuses } = useAttendanceStatuses();

  const classesQuery = useQuery({
    queryKey: ['classes', 'all-for-attendance'],
    staleTime: 1000 * 60 * 10,
    queryFn: () => classesApi.list({ all: true, per_page: 300 }),
  });

  const classOptions = useMemo<SelectOption[]>(
    () => (classesQuery.data?.data ?? []).map((c) => ({ value: c.id, label: `${c.name} — ${c.code}` })),
    [classesQuery.data],
  );

  /** Jadwal hanya relevan setelah kelas + semester dipilih. */
  const schedulesQuery = useQuery({
    queryKey: ['schedules', 'for-attendance', classId, semesterId],
    enabled: !!classId && !!semesterId,
    staleTime: 1000 * 60 * 5,
    queryFn: () =>
      schedulesApi.list({
        all: true,
        filter: { school_class_id: classId as number, semester_id: semesterId as number },
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

  const sheetQuery = useQuery({
    queryKey: ['attendance-sheet', classId, semesterId, date, scheduleId],
    enabled: !!classId && !!semesterId && !!date,
    queryFn: () =>
      studentAttendancesApi.sheet({
        class_id: classId,
        semester_id: semesterId,
        date,
        schedule_id: scheduleId,
      }),
  });

  const sheet = sheetQuery.data;

  /** Muat ulang lembar → bangun ulang state baris dari data server. */
  useEffect(() => {
    if (!sheet) return;

    const next: Record<number, RowState> = {};
    for (const item of sheet.items) {
      next[item.student_id] = {
        attendance_status_id: item.attendance_status_id ?? '',
        leave_type_id: item.leave_type_id ?? '',
        time_in: item.time_in ?? '',
        notes: item.notes ?? '',
      };
    }

    setRows(next);
    setErrors({});
  }, [sheet]);

  const statusByCode = useMemo(() => {
    const map = new Map<string, number>();
    for (const s of statuses) map.set(s.code, s.id);
    return map;
  }, [statuses]);

  const update = (studentId: number, patch: Partial<RowState>) => {
    setRows((prev) => ({
      ...prev,
      [studentId]: { ...(prev[studentId] ?? emptyRow), ...patch },
    }));
    setErrors((prev) => {
      const next = { ...prev };
      delete next[`entries.${studentId}.leave_type_id`];
      delete next[`entries.${studentId}.attendance_status_id`];
      return next;
    });
  };

  /** Pilih status: jenis izin dibersihkan bila status baru tidak memerlukannya. */
  const setStatus = (studentId: number, statusId: number) => {
    const status = statuses.find((s) => s.id === statusId);
    const needsLeave = status ? CODES_REQUIRING_LEAVE_TYPE.includes(status.code) : false;

    update(studentId, {
      attendance_status_id: statusId,
      ...(needsLeave ? {} : { leave_type_id: '' as const }),
    });
  };

  const markAllPresent = () => {
    const presentId = statusByCode.get('H');
    if (!presentId) {
      toast.error('Status "Hadir" belum tersedia di master data.');
      return;
    }

    setRows((prev) => {
      const next: Record<number, RowState> = {};
      for (const [key, row] of Object.entries(prev)) {
        next[Number(key)] = { ...row, attendance_status_id: presentId, leave_type_id: '' };
      }
      return next;
    });
  };

  const filled = useMemo(
    () => Object.values(rows).filter((row) => row.attendance_status_id !== '').length,
    [rows],
  );

  const saveMutation = useMutation({
    mutationFn: () => {
      const entries: BulkAttendanceEntryPayload[] = [];

      for (const [key, row] of Object.entries(rows)) {
        if (row.attendance_status_id === '') continue;

        entries.push({
          student_id: Number(key),
          attendance_status_id: Number(row.attendance_status_id),
          leave_type_id: row.leave_type_id === '' ? null : Number(row.leave_type_id),
          time_in: row.time_in || null,
          notes: row.notes || null,
        });
      }

      const payload: BulkAttendancePayload = {
        class_id: classId,
        semester_id: semesterId,
        date,
        schedule_id: scheduleId,
        entries,
      };

      return studentAttendancesApi.bulk(payload);
    },
    onSuccess: (res) => {
      toast.success(res.message);
      setErrors({});
      queryClient.invalidateQueries({ queryKey: ['attendance-sheet'] });
      queryClient.invalidateQueries({ queryKey: ['attendance-recap'] });
    },
    onError: (err) => {
      const validation = getValidationErrors(err);
      if (validation) setErrors(validation);
      toast.error(getErrorMessage(err));
    },
  });

  const handleSave = () => {
    if (!classId || !semesterId || !date) return;

    if (filled === 0) {
      toast.error('Belum ada siswa yang ditandai. Pilih status minimal satu siswa.');
      return;
    }

    // Jenis izin wajib untuk status Izin/Sakit — dicegah di klien agar guru
    // tidak menunggu perjalanan bolak-balik ke server.
    const local: Record<string, string[]> = {};
    for (const [key, row] of Object.entries(rows)) {
      if (row.attendance_status_id === '') continue;

      const status = statuses.find((s) => s.id === Number(row.attendance_status_id));
      if (status && CODES_REQUIRING_LEAVE_TYPE.includes(status.code) && row.leave_type_id === '') {
        local[`entries.${key}.leave_type_id`] = [
          `Jenis izin wajib diisi untuk status ${status.name}.`,
        ];
      }
    }

    if (Object.keys(local).length) {
      setErrors(local);
      toast.error('Lengkapi jenis izin untuk siswa yang berstatus izin/sakit.');
      return;
    }

    saveMutation.mutate();
  };

  const ready = !!classId && !!semesterId && !!date;
  const loading = sheetQuery.isLoading;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Presensi Siswa</h1>
          <p className="text-sm text-slate-500">
            Catat kehadiran satu kelas pada satu tanggal. Simpan bersifat upsert — aman diulang.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button
            variant="ghost"
            size="icon"
            title="Muat ulang"
            onClick={() => sheetQuery.refetch()}
          >
            <RefreshCw className={sheetQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm={['academic.attendance', 'academic.create']}>
            <Button variant="outline" disabled={!sheet} onClick={markAllPresent}>
              <CheckCheck className="h-4 w-4" /> Tandai Semua Hadir
            </Button>
            <Button
              disabled={!ready || saveMutation.isPending}
              loading={saveMutation.isPending}
              onClick={handleSave}
            >
              <Save className="h-4 w-4" /> Simpan Presensi
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Kelas</label>
            <SearchSelect
              options={classOptions}
              value={classId}
              onChange={(v) => setClassId(toId(v))}
              placeholder="-- Pilih Kelas --"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Semester</label>
            <SearchSelect
              options={semesterOptions}
              value={semesterId}
              onChange={(v) => setSemesterId(toId(v))}
              placeholder="-- Pilih Semester --"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Tanggal</label>
            <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">
              Jadwal (opsional)
            </label>
            <SearchSelect
              options={scheduleOptions}
              value={scheduleId}
              onChange={(v) => setScheduleId(toId(v))}
              placeholder="-- Tanpa Jadwal --"
              disabled={!classId || !semesterId}
            />
          </div>
        </div>
      </Card>

      {!ready ? (
        <Card>
          <EmptyState
            title="Lengkapi pilihan dulu"
            description="Tentukan kelas, semester, dan tanggal untuk memunculkan daftar siswa."
          />
        </Card>
      ) : loading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat daftar siswa...
          </div>
        </Card>
      ) : sheetQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat lembar presensi"
            description={getErrorMessage(sheetQuery.error)}
            action={
              <Button size="sm" variant="outline" className="mt-2" onClick={() => sheetQuery.refetch()}>
                Coba lagi
              </Button>
            }
          />
        </Card>
      ) : !sheet || sheet.items.length === 0 ? (
        <Card>
          <EmptyState
            title="Tidak ada siswa di kelas ini"
            description="Pastikan kelas sudah punya anggota aktif pada tahun ajaran berjalan."
          />
        </Card>
      ) : (
        <Card>
          <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
            <div className="flex flex-wrap items-center gap-2">
              <CalendarDays className="h-4 w-4 text-slate-400" />
              <span className="text-sm font-medium text-slate-700">{date}</span>
              {sheet.is_school_day === false ? (
                <Badge tone="red">Bukan hari efektif</Badge>
              ) : sheet.is_school_day === true ? (
                <Badge tone="green">Hari efektif</Badge>
              ) : (
                <Badge tone="gray">Hari efektif belum dihitung</Badge>
              )}
            </div>
            <div className="flex flex-wrap items-center gap-2 text-xs text-slate-500">
              <Badge tone="gray">{sheet.total} siswa</Badge>
              <Badge tone={filled > 0 ? 'brand' : 'gray'}>{filled} ditandai</Badge>
              <Badge tone="blue">{sheet.recorded} tersimpan</Badge>
            </div>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="w-10 px-4 py-2 font-medium">#</th>
                  <th className="px-4 py-2 font-medium">Siswa</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="w-28 px-4 py-2 font-medium">Jam Masuk</th>
                  <th className="w-40 px-4 py-2 font-medium">Jenis Izin</th>
                  <th className="w-56 px-4 py-2 font-medium">Catatan</th>
                </tr>
              </thead>
              <tbody>
                {sheet.items.map((item, index) => {
                  const row = rows[item.student_id] ?? emptyRow;
                  const activeCode = statuses.find(
                    (s) => s.id === Number(row.attendance_status_id),
                  )?.code;
                  const leaveError = errors[`entries.${item.student_id}.leave_type_id`]?.[0];

                  return (
                    <tr key={item.student_id} className="border-b border-slate-50 last:border-0">
                      <td className="px-4 py-2 text-xs text-slate-400">{index + 1}</td>
                      <td className="px-4 py-2">
                        <div className="font-medium text-slate-800">{item.full_name}</div>
                        <div className="font-mono text-xs text-slate-400">{item.nis}</div>
                      </td>
                      <td className="px-4 py-2">
                        <div className="flex flex-wrap gap-1">
                          {statuses.map((status) => (
                            <button
                              key={status.id}
                              type="button"
                              title={status.name}
                              onClick={() => setStatus(item.student_id, status.id)}
                              className={
                                'h-7 w-7 rounded-md border text-xs font-semibold transition-colors ' +
                                (activeCode === status.code
                                  ? 'border-brand-600 bg-brand-600 text-white'
                                  : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50')
                              }
                            >
                              {status.code}
                            </button>
                          ))}
                          {activeCode && (
                            <span className="ml-1 self-center text-xs text-slate-500">
                              {CODE_LABELS[activeCode] ?? activeCode}
                            </span>
                          )}
                        </div>
                      </td>
                      <td className="px-4 py-2">
                        <Input
                          type="time"
                          value={row.time_in}
                          onChange={(e) => update(item.student_id, { time_in: e.target.value })}
                        />
                      </td>
                      <td className="px-4 py-2">
                        <Select
                          value={row.leave_type_id}
                          error={leaveError}
                          onChange={(e) =>
                            update(item.student_id, {
                              leave_type_id:
                                e.target.value === '' ? '' : Number(e.target.value),
                            })
                          }
                        >
                          <option value="">-- Tidak ada --</option>
                          {leaveTypeOptions.map((opt) => (
                            <option key={opt.value} value={opt.value}>
                              {opt.label}
                            </option>
                          ))}
                        </Select>
                        {leaveError && <p className="mt-1 text-xs text-red-500">{leaveError}</p>}
                      </td>
                      <td className="px-4 py-2">
                        <Input
                          value={row.notes}
                          placeholder="Opsional"
                          onChange={(e) => update(item.student_id, { notes: e.target.value })}
                        />
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </Card>
      )}
    </div>
  );
}
