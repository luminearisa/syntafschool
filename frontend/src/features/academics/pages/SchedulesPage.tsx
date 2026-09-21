import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  AlertTriangle,
  CalendarClock,
  CalendarDays,
  Clock,
  Pencil,
  Plus,
  RefreshCw,
  Trash2,
} from 'lucide-react';
import { toast } from 'sonner';
import { academicsApi, classesApi } from '../api/academicsApi';
import { schedulesApi } from '../api/schedulesApi';
import { ScheduleFormModal } from '../components/ScheduleFormModal';
import { ScheduleChangeModal } from '../components/ScheduleChangeModal';
import { TimeSlotsModal } from '../components/TimeSlotsModal';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { Can } from '@/components/common/Can';
import { usePermission } from '@/hooks/usePermission';
import { useOptions } from '@/hooks/useOptions';
import { useEmployeeOptions } from '@/hooks/useLookupOptions';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type {
  Schedule,
  SchedulePayload,
  TimeSlot,
} from '@/types';

type ViewMode = 'class' | 'teacher' | 'room';

const VIEW_LABEL: Record<ViewMode, string> = {
  class: 'Per Kelas',
  teacher: 'Per Guru',
  room: 'Per Ruang',
};

/** SearchSelect mengembalikan `string | number | ''`; API mengharapkan `number | ''`. */
const toId = (value: string | number): number | '' =>
  value === '' ? '' : Number(value);

/** Warna kartu mapel agar satu mapel terlihat konsisten di seluruh grid. */
const SUBJECT_TONES = [
  'border-brand-200 bg-brand-50',
  'border-blue-200 bg-blue-50',
  'border-amber-200 bg-amber-50',
  'border-green-200 bg-green-50',
  'border-purple-200 bg-purple-50',
  'border-rose-200 bg-rose-50',
];

function toneFor(subjectId: number) {
  return SUBJECT_TONES[subjectId % SUBJECT_TONES.length];
}

export function SchedulesPage() {
  const queryClient = useQueryClient();
  const { can } = usePermission();

  const [semesterId, setSemesterId] = useState<string | number>('');
  const [viewMode, setViewMode] = useState<ViewMode>('class');
  const [classId, setClassId] = useState<string | number>('');
  const [teacherId, setTeacherId] = useState<string | number>('');
  const [roomId, setRoomId] = useState<string | number>('');
  const [date, setDate] = useState('');

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<Schedule | null>(null);
  const [defaults, setDefaults] = useState<Partial<SchedulePayload> | undefined>();
  const [deleting, setDeleting] = useState<Schedule | null>(null);
  const [changeTarget, setChangeTarget] = useState<Schedule | null>(null);
  const [slotsOpen, setSlotsOpen] = useState(false);

  const { options: semesterOptions } = useOptions('semesters');
  const { options: roomOptions } = useOptions('rooms');
  const { options: teacherOptions } = useEmployeeOptions(true);

  const activePeriodQuery = useQuery({
    queryKey: ['academic', 'active'],
    queryFn: () => academicsApi.activePeriod(),
  });

  const classesQuery = useQuery({
    queryKey: ['classes', 'all-for-schedule'],
    staleTime: 1000 * 60 * 5,
    queryFn: () => classesApi.list({ all: true, per_page: 200 }),
  });

  // Semester aktif dipakai sebagai default supaya halaman langsung berguna.
  useEffect(() => {
    if (!semesterId && activePeriodQuery.data?.semester) {
      setSemesterId(activePeriodQuery.data.semester.id);
    }
  }, [activePeriodQuery.data, semesterId]);

  useEffect(() => {
    if (!classId && classesQuery.data?.data?.length) {
      setClassId(classesQuery.data.data[0].id);
    }
  }, [classesQuery.data, classId]);

  const classes = classesQuery.data?.data ?? [];

  const scope = useMemo(() => {
    if (viewMode === 'class') return { school_class_id: toId(classId) };
    if (viewMode === 'teacher') return { employee_id: toId(teacherId) };
    return { room_id: toId(roomId) };
  }, [viewMode, classId, teacherId, roomId]);

  const scopeReady = viewMode === 'class' ? !!classId : viewMode === 'teacher' ? !!teacherId : !!roomId;

  const gridQuery = useQuery({
    queryKey: ['schedule-grid', semesterId, scope, date],
    enabled: !!semesterId && scopeReady,
    queryFn: () =>
      schedulesApi.grid({
        semester_id: toId(semesterId),
        ...scope,
        date: date || undefined,
      }),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['schedule-grid'] });

  const createMutation = useMutation({
    mutationFn: (payload: SchedulePayload) => schedulesApi.create(payload),
    onSuccess: () => {
      toast.success('Jadwal berhasil ditambahkan');
      invalidate();
      setFormOpen(false);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: SchedulePayload }) =>
      schedulesApi.update(id, payload),
    onSuccess: () => {
      toast.success('Jadwal berhasil diperbarui');
      invalidate();
      setFormOpen(false);
      setEditing(null);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => schedulesApi.remove(id),
    onSuccess: () => {
      toast.success('Jadwal berhasil dihapus');
      invalidate();
      setDeleting(null);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const grid = gridQuery.data;
  const entries = grid?.entries ?? [];
  const slots = grid?.slots ?? [];
  const days = grid?.days ?? [];
  const changes = grid?.changes ?? [];

  /** Hari yang punya jam pelajaran — Sabtu/Minggu tanpa jam otomatis tidak tampil. */
  const activeDays = useMemo(
    () => days.filter((d) => slots.some((s) => s.day_id === d.id)),
    [days, slots],
  );

  const slotsForDay = (dayId: number) =>
    slots.filter((s) => s.day_id === dayId).sort((a, b) => a.slot_number - b.slot_number);

  const entryFor = (dayId: number, slot: TimeSlot) =>
    entries.find(
      (e) =>
        e.day_id === dayId &&
        (e.time_slot_id ? e.time_slot_id === slot.id : e.start_time === slot.start_time),
    );

  /** Jadwal tanpa jam pelajaran (mis. dibuat sebelum jam diisi) tetap ditampilkan. */
  const looseEntries = (dayId: number) =>
    entries.filter((e) => {
      if (e.day_id !== dayId) return false;
      return !slotsForDay(dayId).some((s) =>
        e.time_slot_id ? e.time_slot_id === s.id : e.start_time === s.start_time,
      );
    });

  const changeFor = (scheduleId: number) =>
    changes.find((c) => c.schedule_id === scheduleId);

  const teachingSlots = slots.filter((s) => s.type === 'pelajaran').length;
  const filledCount = entries.length;

  const openCreate = (dayId: number, slot: TimeSlot) => {
    setEditing(null);
    createMutation.reset();
    updateMutation.reset();
    setDefaults({
      school_class_id: viewMode === 'class' ? toId(classId) : '',
      employee_id: viewMode === 'teacher' ? toId(teacherId) : '',
      room_id: viewMode === 'room' ? toId(roomId) : '',
      day_id: dayId,
      time_slot_id: slot.id,
      start_time: slot.start_time,
      end_time: slot.end_time,
      semester_id: toId(semesterId),
    });
    setFormOpen(true);
  };

  const openEdit = (row: Schedule) => {
    setEditing(row);
    setDefaults(undefined);
    createMutation.reset();
    updateMutation.reset();
    setFormOpen(true);
  };

  const openBlankCreate = () => {
    setEditing(null);
    setDefaults({
      school_class_id: viewMode === 'class' ? toId(classId) : '',
      semester_id: toId(semesterId),
    });
    createMutation.reset();
    updateMutation.reset();
    setFormOpen(true);
  };

  const submitForm = (payload: SchedulePayload) => {
    if (editing) updateMutation.mutate({ id: editing.id, payload });
    else createMutation.mutate(payload);
  };

  const renderCard = (row: Schedule, compact = false) => {
    const change = changeFor(row.id);

    return (
      <div
        className={`group relative rounded-lg border px-2 py-1.5 text-left ${toneFor(row.subject_id)} ${
          change?.type === 'batal' ? 'opacity-60' : ''
        }`}
      >
        <div className="flex items-start justify-between gap-1">
          <div className="min-w-0">
            <div className="truncate text-xs font-semibold text-slate-800">
              {row.subject?.name ?? 'Mapel'}
            </div>
            {!compact && (
              <>
                <div className="truncate text-[11px] text-slate-600">
                  {row.teacher?.full_name ?? 'Belum ada guru'}
                </div>
                <div className="truncate text-[11px] text-slate-500">
                  {row.school_class?.name}
                  {row.room ? ` · ${row.room.name}` : ''}
                </div>
              </>
            )}
            {compact && (
              <div className="truncate text-[11px] text-slate-500">
                {row.teacher?.full_name ?? '-'} · {row.school_class?.name}
              </div>
            )}
          </div>

          <Can perm="academic.update">
            <div className="flex shrink-0 gap-0.5 opacity-0 transition-opacity group-hover:opacity-100">
              <button
                type="button"
                title="Edit jadwal"
                className="rounded p-0.5 text-slate-500 hover:bg-white/70"
                onClick={() => openEdit(row)}
              >
                <Pencil className="h-3 w-3" />
              </button>
              <button
                type="button"
                title="Perubahan & guru pengganti"
                className="rounded p-0.5 text-slate-500 hover:bg-white/70"
                onClick={() => setChangeTarget(row)}
              >
                <CalendarClock className="h-3 w-3" />
              </button>
              <button
                type="button"
                title="Hapus jadwal"
                className="rounded p-0.5 text-red-500 hover:bg-white/70"
                onClick={() => setDeleting(row)}
              >
                <Trash2 className="h-3 w-3" />
              </button>
            </div>
          </Can>
        </div>

        {change && (
          <div className="mt-1">
            {change.type === 'batal' && <Badge tone="red">Dibatalkan</Badge>}
            {change.type === 'pengganti' && (
              <Badge tone="blue">
                Pengganti: {change.substitute_teacher?.full_name ?? '-'}
              </Badge>
            )}
            {change.type === 'pindah' && (
              <Badge tone="amber">
                Pindah {change.new_start_time}–{change.new_end_time}
              </Badge>
            )}
          </div>
        )}
      </div>
    );
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Jadwal Pelajaran</h1>
          <p className="text-sm text-slate-500">
            {grid
              ? `${filledCount} jam terisi dari ${teachingSlots * activeDays.length} slot pelajaran per pekan.`
              : 'Susun jadwal mingguan per kelas, guru, atau ruang.'}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="ghost" size="icon" onClick={() => gridQuery.refetch()} title="Muat ulang">
            <RefreshCw className={gridQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="academic.update">
            <Button variant="outline" onClick={() => setSlotsOpen(true)}>
              <Clock className="h-4 w-4" /> Jam Pelajaran
            </Button>
          </Can>
          <Can perm="academic.create">
            <Button onClick={openBlankCreate}>
              <Plus className="h-4 w-4" /> Tambah Jadwal
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="flex flex-col gap-3 lg:flex-row lg:items-end">
          <div className="w-full lg:w-48">
            <label className="mb-1 block text-xs font-medium text-slate-600">Semester</label>
            <SearchSelect
              options={semesterOptions}
              value={semesterId}
              onChange={(v) => setSemesterId(v)}
              placeholder="-- Pilih Semester --"
            />
          </div>

          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Sudut Pandang</label>
            <div className="inline-flex rounded-lg border border-slate-300 bg-white p-0.5">
              {(Object.keys(VIEW_LABEL) as ViewMode[]).map((mode) => (
                <button
                  key={mode}
                  type="button"
                  onClick={() => setViewMode(mode)}
                  className={`rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${
                    viewMode === mode
                      ? 'bg-brand-600 text-white'
                      : 'text-slate-600 hover:bg-slate-100'
                  }`}
                >
                  {VIEW_LABEL[mode]}
                </button>
              ))}
            </div>
          </div>

          <div className="w-full lg:w-56">
            <label className="mb-1 block text-xs font-medium text-slate-600">
              {viewMode === 'class' ? 'Kelas' : viewMode === 'teacher' ? 'Guru' : 'Ruangan'}
            </label>
            {viewMode === 'class' && (
              <Select value={classId} onChange={(e) => setClassId(e.target.value === '' ? '' : Number(e.target.value))}>
                <option value="">-- Pilih Kelas --</option>
                {classes.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name} ({c.code})
                  </option>
                ))}
              </Select>
            )}
            {viewMode === 'teacher' && (
              <SearchSelect
                options={teacherOptions}
                value={teacherId}
                onChange={(v) => setTeacherId(v)}
                placeholder="-- Pilih Guru --"
              />
            )}
            {viewMode === 'room' && (
              <SearchSelect
                options={roomOptions}
                value={roomId}
                onChange={(v) => setRoomId(v)}
                placeholder="-- Pilih Ruangan --"
              />
            )}
          </div>

          <div className="w-full lg:w-44">
            <label className="mb-1 block text-xs font-medium text-slate-600">
              Tanggal <span className="font-normal text-slate-400">(opsional)</span>
            </label>
            <div className="flex items-center gap-1">
              <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} />
              {date && (
                <Button variant="ghost" size="icon" title="Hapus tanggal" onClick={() => setDate('')}>
                  <Trash2 className="h-4 w-4" />
                </Button>
              )}
            </div>
          </div>
        </div>

        {date && (
          <p className="mt-2 text-xs text-slate-500">
            Menampilkan perubahan jadwal pada <span className="font-medium">{date}</span>. Perubahan
            harian tidak mengubah jadwal pokok.
          </p>
        )}
      </Card>

      <Card>
        {!semesterId || !scopeReady ? (
          <EmptyState
            title="Pilih semester dan sudut pandang"
            description="Tentukan semester serta kelas, guru, atau ruangan untuk melihat grid jadwal."
          />
        ) : gridQuery.isLoading ? (
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat jadwal...
          </div>
        ) : gridQuery.isError ? (
          <EmptyState
            title="Gagal memuat jadwal"
            description={getErrorMessage(gridQuery.error)}
            action={
              <Button size="sm" variant="outline" className="mt-2" onClick={() => gridQuery.refetch()}>
                Coba lagi
              </Button>
            }
          />
        ) : activeDays.length === 0 ? (
          <EmptyState
            title="Jam pelajaran belum diisi"
            description="Grid jadwal butuh jam pelajaran. Isi dulu lewat tombol Jam Pelajaran."
            action={
              <Can perm="academic.update">
                <Button size="sm" className="mt-2" onClick={() => setSlotsOpen(true)}>
                  <Clock className="h-4 w-4" /> Atur Jam Pelajaran
                </Button>
              </Can>
            }
          />
        ) : (
          <div className="overflow-x-auto p-3">
            <div className="flex min-w-max gap-3">
              {activeDays.map((day) => (
                <div key={day.id} className="w-52 shrink-0">
                  <div className="mb-2 flex items-center justify-center gap-1.5 rounded-lg bg-slate-100 py-2 text-sm font-semibold text-slate-700">
                    <CalendarDays className="h-4 w-4 text-slate-400" />
                    {day.name}
                  </div>

                  <div className="space-y-1.5">
                    {slotsForDay(day.id).map((slot) => {
                      const row = entryFor(day.id, slot);

                      if (slot.type !== 'pelajaran') {
                        return (
                          <div
                            key={slot.id}
                            className="rounded-lg border border-dashed border-slate-200 bg-slate-50 px-2 py-1.5 text-center"
                          >
                            <div className="text-[11px] font-medium uppercase tracking-wide text-slate-400">
                              {slot.label ?? slot.type}
                            </div>
                            <div className="font-mono text-[10px] text-slate-400">
                              {slot.start_time}–{slot.end_time}
                            </div>
                          </div>
                        );
                      }

                      return (
                        <div key={slot.id} className="relative">
                          <div className="mb-0.5 flex items-center gap-1 px-0.5">
                            <span className="text-[10px] font-medium text-slate-400">
                              {slot.label ?? `Jam ke-${slot.slot_number}`}
                            </span>
                            <span className="font-mono text-[10px] text-slate-300">
                              {slot.start_time}
                            </span>
                          </div>

                          {row ? (
                            renderCard(row)
                          ) : can('academic.create') ? (
                            <button
                              type="button"
                              onClick={() => openCreate(day.id, slot)}
                              className="flex h-[52px] w-full items-center justify-center rounded-lg border border-dashed border-slate-200 text-slate-300 transition-colors hover:border-brand-300 hover:bg-brand-50/50 hover:text-brand-500"
                              title="Tambah jadwal di jam ini"
                            >
                              <Plus className="h-4 w-4" />
                            </button>
                          ) : (
                            <div className="h-[52px] rounded-lg border border-dashed border-slate-100" />
                          )}
                        </div>
                      );
                    })}

                    {looseEntries(day.id).length > 0 && (
                      <div className="space-y-1.5 border-t border-dashed border-amber-200 pt-1.5">
                        <div className="flex items-center gap-1 text-[10px] font-medium uppercase tracking-wide text-amber-600">
                          <AlertTriangle className="h-3 w-3" /> Tanpa jam pelajaran
                        </div>
                        {looseEntries(day.id).map((row) => (
                          <div key={row.id}>
                            <div className="mb-0.5 px-0.5 font-mono text-[10px] text-slate-400">
                              {row.start_time}–{row.end_time}
                            </div>
                            {renderCard(row)}
                          </div>
                        ))}
                      </div>
                    )}
                  </div>
                </div>
              ))}
            </div>
          </div>
        )}
      </Card>

      <ScheduleFormModal
        open={formOpen}
        editing={editing}
        defaults={defaults}
        classes={classes}
        semesterId={toId(semesterId)}
        submitting={createMutation.isPending || updateMutation.isPending}
        serverErrors={
          getValidationErrors(editing ? updateMutation.error : createMutation.error)
        }
        onClose={() => {
          setFormOpen(false);
          setEditing(null);
          setDefaults(undefined);
        }}
        onSubmit={submitForm}
      />

      <ScheduleChangeModal
        open={!!changeTarget}
        schedule={changeTarget}
        canEdit={can('academic.update')}
        onClose={() => setChangeTarget(null)}
      />

      <TimeSlotsModal open={slotsOpen} canEdit={can('academic.update')} onClose={() => setSlotsOpen(false)} />

      <ConfirmDialog
        open={!!deleting}
        title="Hapus Jadwal"
        message={`Yakin ingin menghapus jadwal ${deleting?.subject?.name ?? ''} di ${
          deleting?.school_class?.name ?? ''
        }? Perubahan jadwal pada jadwal ini juga akan ikut terhapus.`}
        loading={deleteMutation.isPending}
        onConfirm={() => deleting && deleteMutation.mutate(deleting.id)}
        onCancel={() => setDeleting(null)}
      />
    </div>
  );
}
