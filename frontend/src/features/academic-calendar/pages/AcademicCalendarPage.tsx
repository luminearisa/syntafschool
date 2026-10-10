import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  CalendarDays,
  ChevronLeft,
  ChevronRight,
  Pencil,
  Plus,
  RefreshCw,
  Settings2,
  Trash2,
} from 'lucide-react';
import { toast } from 'sonner';
import { academicEventsApi, eventTypesApi } from '../api/academicCalendarApi';
import { EventFormModal } from '../components/EventFormModal';
import { EventTypeModal } from '../components/EventTypeModal';
import { academicsApi } from '@/features/academics/api/academicsApi';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Modal } from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { Can } from '@/components/common/Can';
import { useOptions } from '@/hooks/useOptions';
import { usePermission } from '@/hooks/usePermission';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type {
  AcademicEvent,
  AcademicEventPayload,
  EventType,
  EventTypePayload,
} from '@/types/academicCalendar';

const MONTH_LABELS = [
  'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
  'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
];

const WEEKDAYS = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];

const pad = (n: number) => String(n).padStart(2, '0');

/** SearchSelect mengembalikan `string | number | ''`; API memakai `number | ''`. */
const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

/** Label bulan Indonesia dari kunci `YYYY-MM`. */
function monthLabel(month: string): string {
  const [year, m] = month.split('-').map(Number);
  return `${MONTH_LABELS[m - 1] ?? m} ${year}`;
}

export function AcademicCalendarPage() {
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const canManageEvents = can(['academic.update', 'academic.create']);

  const [semesterId, setSemesterId] = useState<number | ''>('');
  const [eventTypeId, setEventTypeId] = useState<number | ''>('');
  const [currentMonth, setCurrentMonth] = useState('');

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<AcademicEvent | null>(null);
  const [deleting, setDeleting] = useState<AcademicEvent | null>(null);

  const [typeManagerOpen, setTypeManagerOpen] = useState(false);
  const [typeFormOpen, setTypeFormOpen] = useState(false);
  const [editingType, setEditingType] = useState<EventType | null>(null);
  const [deletingType, setDeletingType] = useState<EventType | null>(null);

  const { options: semesterOptions } = useOptions('semesters');

  const activePeriodQuery = useQuery({
    queryKey: ['academic', 'active'],
    queryFn: () => academicsApi.activePeriod(),
  });

  useEffect(() => {
    if (!semesterId && activePeriodQuery.data?.semester) {
      setSemesterId(activePeriodQuery.data.semester.id);
    }
  }, [activePeriodQuery.data, semesterId]);

  const typesQuery = useQuery({
    queryKey: ['event-types', 'all'],
    queryFn: () => eventTypesApi.list({ all: true }),
  });
  const eventTypes = typesQuery.data?.data ?? [];

  const calendarQuery = useQuery({
    queryKey: ['academic-calendar', semesterId, eventTypeId],
    enabled: !!semesterId,
    queryFn: () => academicEventsApi.calendar(semesterId, eventTypeId),
  });

  const months = calendarQuery.data?.data.months ?? [];
  const totalEvents = calendarQuery.data?.meta.total ?? 0;

  // Pilih bulan pertama yang punya event (atau bulan pertama semester).
  useEffect(() => {
    if (months.length === 0) {
      setCurrentMonth('');
      return;
    }
    if (months.some((m) => m.month === currentMonth)) return;
    const withEvents = months.find((m) => m.events.length > 0);
    setCurrentMonth((withEvents ?? months[0]).month);
  }, [months, currentMonth]);

  const activeMonthIndex = months.findIndex((m) => m.month === currentMonth);
  const currentEvents = useMemo(
    () => months.find((m) => m.month === currentMonth)?.events ?? [],
    [months, currentMonth],
  );

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['academic-calendar'] });
  };

  /* ----------------------------- Event CRUD ----------------------------- */

  const createMutation = useMutation({
    mutationFn: (payload: AcademicEventPayload) => academicEventsApi.create(payload),
    onSuccess: () => {
      toast.success('Event akademik berhasil ditambahkan');
      invalidate();
      setFormOpen(false);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: AcademicEventPayload }) =>
      academicEventsApi.update(id, payload),
    onSuccess: () => {
      toast.success('Event akademik berhasil diperbarui');
      invalidate();
      setFormOpen(false);
      setEditing(null);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => academicEventsApi.remove(id),
    onSuccess: () => {
      toast.success('Event akademik berhasil dihapus');
      invalidate();
      setDeleting(null);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  /* --------------------------- Event type CRUD -------------------------- */

  const typeCreateMutation = useMutation({
    mutationFn: (payload: EventTypePayload) => eventTypesApi.create(payload),
    onSuccess: () => {
      toast.success('Jenis event berhasil ditambahkan');
      queryClient.invalidateQueries({ queryKey: ['event-types'] });
      setTypeFormOpen(false);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const typeUpdateMutation = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: EventTypePayload }) =>
      eventTypesApi.update(id, payload),
    onSuccess: () => {
      toast.success('Jenis event berhasil diperbarui');
      queryClient.invalidateQueries({ queryKey: ['event-types'] });
      setTypeFormOpen(false);
      setEditingType(null);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const typeDeleteMutation = useMutation({
    mutationFn: (id: number) => eventTypesApi.remove(id),
    onSuccess: () => {
      toast.success('Jenis event berhasil dihapus');
      queryClient.invalidateQueries({ queryKey: ['event-types'] });
      setDeletingType(null);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  /* ------------------------------- Render ------------------------------- */

  const grid = useMemo(() => {
    if (!currentMonth) return { leading: 0, days: 0, year: 0, month: 0 };

    const [year, month] = currentMonth.split('-').map(Number);
    const days = new Date(year, month, 0).getDate();
    const jsFirstDay = new Date(year, month - 1, 1).getDay(); // 0 = Minggu
    const leading = (jsFirstDay + 6) % 7; // kolom pertama Senin

    return { leading, days, year, month };
  }, [currentMonth]);

  const eventsOnDate = (date: string) =>
    currentEvents.filter((e) => e.start_date <= date && e.end_date >= date);

  const submitForm = (payload: AcademicEventPayload) => {
    if (editing) updateMutation.mutate({ id: editing.id, payload });
    else createMutation.mutate(payload);
  };

  const submitTypeForm = (payload: EventTypePayload) => {
    if (editingType) typeUpdateMutation.mutate({ id: editingType.id, payload });
    else typeCreateMutation.mutate(payload);
  };

  const canGoPrev = activeMonthIndex > 0;
  const canGoNext = activeMonthIndex >= 0 && activeMonthIndex < months.length - 1;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Kalender Akademik</h1>
          <p className="text-sm text-slate-500">
            {totalEvents > 0
              ? `${totalEvents} event pada semester ini.`
              : 'Belum ada event pada semester ini.'}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => calendarQuery.refetch()}>
            <RefreshCw className={calendarQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="academic.update">
            <Button variant="outline" onClick={() => setTypeManagerOpen(true)}>
              <Settings2 className="h-4 w-4" /> Jenis Event
            </Button>
          </Can>
          <Can perm="academic.create">
            <Button
              onClick={() => {
                setEditing(null);
                createMutation.reset();
                updateMutation.reset();
                setFormOpen(true);
              }}
            >
              <Plus className="h-4 w-4" /> Tambah Event
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
          <div className="w-full sm:w-56">
            <label className="mb-1 block text-xs font-medium text-slate-600">Semester</label>
            <SearchSelect
              options={semesterOptions}
              value={semesterId}
              onChange={(v) => setSemesterId(toId(v))}
              placeholder="-- Pilih Semester --"
            />
          </div>
          <div className="w-full sm:w-56">
            <label className="mb-1 block text-xs font-medium text-slate-600">Jenis Event</label>
            <SearchSelect
              options={eventTypes.map((t) => ({ value: t.id, label: t.name }))}
              value={eventTypeId}
              onChange={(v) => setEventTypeId(toId(v))}
              placeholder="-- Semua Jenis --"
            />
          </div>
        </div>
      </Card>

      <Card>
        {!semesterId ? (
          <EmptyState title="Pilih semester" description="Tentukan semester untuk melihat kalender akademik." />
        ) : calendarQuery.isLoading ? (
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat kalender...
          </div>
        ) : calendarQuery.isError ? (
          <EmptyState
            title="Gagal memuat kalender"
            description={getErrorMessage(calendarQuery.error)}
            action={
              <Button size="sm" variant="outline" className="mt-2" onClick={() => calendarQuery.refetch()}>
                Coba lagi
              </Button>
            }
          />
        ) : months.length === 0 ? (
          <EmptyState title="Semester belum punya rentang tanggal" />
        ) : (
          <div className="p-3">
            <div className="mb-3 flex items-center justify-between">
              <Button
                variant="outline"
                size="icon"
                disabled={!canGoPrev}
                onClick={() => canGoPrev && setCurrentMonth(months[activeMonthIndex - 1].month)}
              >
                <ChevronLeft className="h-4 w-4" />
              </Button>
              <div className="flex items-center gap-2 text-sm font-semibold text-slate-800">
                <CalendarDays className="h-4 w-4 text-slate-400" />
                {monthLabel(currentMonth)}
              </div>
              <Button
                variant="outline"
                size="icon"
                disabled={!canGoNext}
                onClick={() => canGoNext && setCurrentMonth(months[activeMonthIndex + 1].month)}
              >
                <ChevronRight className="h-4 w-4" />
              </Button>
            </div>

            <div className="grid grid-cols-7 gap-1">
              {WEEKDAYS.map((day) => (
                <div
                  key={day}
                  className="rounded-md bg-slate-100 py-1.5 text-center text-[11px] font-semibold uppercase tracking-wide text-slate-500"
                >
                  {day}
                </div>
              ))}

              {Array.from({ length: grid.leading }).map((_, i) => (
                <div key={`blank-${i}`} className="min-h-[76px] rounded-lg bg-slate-50/60" />
              ))}

              {Array.from({ length: grid.days }).map((_, i) => {
                const day = i + 1;
                const date = `${grid.year}-${pad(grid.month)}-${pad(day)}`;
                const dayEvents = eventsOnDate(date);
                const isSunday = new Date(grid.year, grid.month - 1, day).getDay() === 0;

                return (
                  <div
                    key={date}
                    className={`min-h-[76px] rounded-lg border p-1.5 ${
                      isSunday ? 'border-slate-100 bg-slate-50' : 'border-slate-200 bg-white'
                    }`}
                  >
                    <div className={`mb-1 text-[11px] font-medium ${isSunday ? 'text-red-400' : 'text-slate-500'}`}>
                      {day}
                    </div>
                    <div className="space-y-0.5">
                      {dayEvents.slice(0, 3).map((event) => (
                        <button
                          key={`${event.id}-${date}`}
                          type="button"
                          title={event.title}
                          disabled={!canManageEvents}
                          onClick={() => {
                            if (!canManageEvents) return;
                            setEditing(event);
                            createMutation.reset();
                            updateMutation.reset();
                            setFormOpen(true);
                          }}
                          className={`block w-full truncate rounded px-1 py-0.5 text-left text-[10px] font-medium ${
                            canManageEvents ? 'cursor-pointer' : 'cursor-default'
                          }`}
                          style={{
                            backgroundColor: `${event.event_type?.color ?? '#64748b'}20`,
                            color: event.event_type?.color ?? '#475569',
                            borderLeft: `3px solid ${event.event_type?.color ?? '#64748b'}`,
                          }}
                        >
                          {event.title}
                        </button>
                      ))}
                      {dayEvents.length > 3 && (
                        <div className="px-1 text-[10px] text-slate-400">+{dayEvents.length - 3} lagi</div>
                      )}
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
        )}
      </Card>

      <Card>
        <div className="flex items-center justify-between border-b border-slate-100 px-4 py-3">
          <h2 className="text-sm font-semibold text-slate-800">
            Event {currentMonth ? monthLabel(currentMonth) : ''}
          </h2>
          <span className="text-xs text-slate-400">{currentEvents.length} event</span>
        </div>

        {currentEvents.length === 0 ? (
          <EmptyState title="Tidak ada event" description="Belum ada event pada bulan ini." />
        ) : (
          <ul className="divide-y divide-slate-100">
            {currentEvents.map((event) => (
              <li key={event.id} className="flex items-start justify-between gap-3 px-4 py-3">
                <div className="flex min-w-0 items-start gap-3">
                  <span
                    className="mt-1 h-3 w-3 shrink-0 rounded-full"
                    style={{ backgroundColor: event.event_type?.color ?? '#64748b' }}
                  />
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="text-sm font-medium text-slate-800">{event.title}</span>
                      {event.event_type && <Badge tone="gray">{event.event_type.name}</Badge>}
                      {event.is_holiday && <Badge tone="red">Libur</Badge>}
                      {!event.is_active && <Badge tone="amber">Nonaktif</Badge>}
                    </div>
                    <p className="mt-0.5 text-xs text-slate-500">
                      {event.start_date === event.end_date
                        ? event.start_date
                        : `${event.start_date} – ${event.end_date}`}
                      {event.location ? ` · ${event.location}` : ''}
                    </p>
                    {event.description && (
                      <p className="mt-0.5 text-xs text-slate-400">{event.description}</p>
                    )}
                  </div>
                </div>

                <div className="flex shrink-0 gap-1">
                  <Can perm="academic.update">
                    <Button
                      variant="ghost"
                      size="icon"
                      title="Edit event"
                      onClick={() => {
                        setEditing(event);
                        createMutation.reset();
                        updateMutation.reset();
                        setFormOpen(true);
                      }}
                    >
                      <Pencil className="h-4 w-4" />
                    </Button>
                  </Can>
                  <Can perm="academic.delete">
                    <Button
                      variant="ghost"
                      size="icon"
                      title="Hapus event"
                      onClick={() => setDeleting(event)}
                    >
                      <Trash2 className="h-4 w-4 text-red-500" />
                    </Button>
                  </Can>
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>

      {/* --------------------------- Modals --------------------------- */}

      <EventFormModal
        open={formOpen}
        editing={editing}
        submitting={createMutation.isPending || updateMutation.isPending}
        serverErrors={getValidationErrors(editing ? updateMutation.error : createMutation.error)}
        onClose={() => {
          setFormOpen(false);
          setEditing(null);
        }}
        onSubmit={submitForm}
      />

      <Modal
        open={typeManagerOpen}
        onClose={() => setTypeManagerOpen(false)}
        title="Jenis Event"
        size="lg"
        footer={
          <>
            <Button variant="outline" onClick={() => setTypeManagerOpen(false)}>
              Tutup
            </Button>
            <Can perm="academic.create">
              <Button
                onClick={() => {
                  setEditingType(null);
                  typeCreateMutation.reset();
                  typeUpdateMutation.reset();
                  setTypeFormOpen(true);
                }}
              >
                <Plus className="h-4 w-4" /> Tambah
              </Button>
            </Can>
          </>
        }
      >
        {typesQuery.isLoading ? (
          <div className="flex items-center justify-center gap-2 py-10 text-slate-400">
            <Spinner /> Memuat jenis event...
          </div>
        ) : eventTypes.length === 0 ? (
          <EmptyState title="Belum ada jenis event" />
        ) : (
          <ul className="divide-y divide-slate-100">
            {eventTypes.map((type) => (
              <li key={type.id} className="flex items-center justify-between gap-3 py-2.5">
                <div className="flex min-w-0 items-center gap-3">
                  <span
                    className="h-3.5 w-3.5 shrink-0 rounded-full"
                    style={{ backgroundColor: type.color ?? '#64748b' }}
                  />
                  <div className="min-w-0">
                    <div className="flex items-center gap-2">
                      <span className="text-sm font-medium text-slate-800">{type.name}</span>
                      {type.is_holiday && <Badge tone="red">Libur</Badge>}
                      {!type.is_active && <Badge tone="amber">Nonaktif</Badge>}
                    </div>
                    <span className="font-mono text-[11px] text-slate-400">{type.code}</span>
                  </div>
                </div>

                <div className="flex shrink-0 gap-1">
                  <Can perm="academic.update">
                    <Button
                      variant="ghost"
                      size="icon"
                      title="Edit jenis event"
                      onClick={() => {
                        setEditingType(type);
                        typeCreateMutation.reset();
                        typeUpdateMutation.reset();
                        setTypeFormOpen(true);
                      }}
                    >
                      <Pencil className="h-4 w-4" />
                    </Button>
                  </Can>
                  <Can perm="academic.delete">
                    <Button
                      variant="ghost"
                      size="icon"
                      title="Hapus jenis event"
                      onClick={() => setDeletingType(type)}
                    >
                      <Trash2 className="h-4 w-4 text-red-500" />
                    </Button>
                  </Can>
                </div>
              </li>
            ))}
          </ul>
        )}
      </Modal>

      <EventTypeModal
        open={typeFormOpen}
        editing={editingType}
        submitting={typeCreateMutation.isPending || typeUpdateMutation.isPending}
        serverErrors={getValidationErrors(editingType ? typeUpdateMutation.error : typeCreateMutation.error)}
        onClose={() => {
          setTypeFormOpen(false);
          setEditingType(null);
        }}
        onSubmit={submitTypeForm}
      />

      <ConfirmDialog
        open={!!deleting}
        title="Hapus Event"
        message={`Yakin ingin menghapus event "${deleting?.title ?? ''}"?`}
        loading={deleteMutation.isPending}
        onConfirm={() => deleting && deleteMutation.mutate(deleting.id)}
        onCancel={() => setDeleting(null)}
      />

      <ConfirmDialog
        open={!!deletingType}
        title="Hapus Jenis Event"
        message={`Yakin ingin menghapus jenis event "${deletingType?.name ?? ''}"?`}
        loading={typeDeleteMutation.isPending}
        onConfirm={() => deletingType && typeDeleteMutation.mutate(deletingType.id)}
        onCancel={() => setDeletingType(null)}
      />
    </div>
  );
}
