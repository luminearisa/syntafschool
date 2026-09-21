import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CalendarClock, Pencil, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { schedulesApi } from '../api/tahfizApi';
import { ScheduleFormModal } from '../components/ScheduleFormModal';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Select } from '@/components/ui/Select';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Spinner } from '@/components/ui/Spinner';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { useHalaqahOptions } from '../hooks/useTahfizOptions';
import { ACTIVITY_TYPE_LABELS, DAY_LABELS, toOptions } from '../lib/labels';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type {
  TahfizActivityType,
  TahfizSchedule,
  TahfizSchedulePayload,
} from '@/types/tahfiz';

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

const ACTIVITY_OPTIONS = toOptions(ACTIVITY_TYPE_LABELS);

const DAY_OPTIONS = (Object.keys(DAY_LABELS) as unknown as string[])
  .map((key) => ({ value: Number(key), label: DAY_LABELS[Number(key)] }))
  .sort((a, b) => a.value - b.value);

function timeRange(row: TahfizSchedule): string {
  if (!row.start_time && !row.end_time) return '—';
  return `${row.start_time ?? '—'} – ${row.end_time ?? '—'}`;
}

/**
 * Jadwal berulang Tahfiz per halaqah dan jenis aktivitas.
 *
 * Jadwal menjadi sumber pembuatan sesi konkret lewat fitur "Generate" di
 * halaman Sesi.
 */
export function TahfizSchedulesPage() {
  const queryClient = useQueryClient();

  const [page, setPage] = useState(1);
  const [halaqahId, setHalaqahId] = useState<number | ''>('');
  const [activityType, setActivityType] = useState<TahfizActivityType | ''>('');
  const [dayOfWeek, setDayOfWeek] = useState<number | ''>('');
  const [status, setStatus] = useState<'active' | 'inactive' | ''>('');

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<TahfizSchedule | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [deleting, setDeleting] = useState<TahfizSchedule | null>(null);

  const { options: halaqahOptions } = useHalaqahOptions();

  const filters: Record<string, string | number> = {};
  if (halaqahId) filters.halaqah_id = halaqahId;
  if (activityType) filters.activity_type = activityType;
  if (dayOfWeek !== '') filters.day_of_week = dayOfWeek;
  if (status) filters.status = status;

  const listQuery = useQuery({
    queryKey: ['tahfiz', 'schedules', page, filters],
    queryFn: () =>
      schedulesApi.list({
        page,
        per_page: 15,
        filter: Object.keys(filters).length ? filters : undefined,
      }),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['tahfiz', 'schedules'] });

  const saveMutation = useMutation({
    mutationFn: (payload: TahfizSchedulePayload) =>
      editing ? schedulesApi.update(editing.id, payload) : schedulesApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Jadwal diperbarui' : 'Jadwal dibuat');
      setFormOpen(false);
      setEditing(null);
      setServerErrors({});
      invalidate();
    },
    onError: (err) => {
      setServerErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => schedulesApi.remove(id),
    onSuccess: () => {
      toast.success('Jadwal dihapus');
      setDeleting(null);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Jadwal Tahfiz</h1>
          <p className="text-sm text-slate-500">
            Jadwal berulang per halaqah dan jenis aktivitas, termasuk masa berlaku.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
            <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="tahfiz.create">
            <Button
              onClick={() => {
                setEditing(null);
                setServerErrors({});
                setFormOpen(true);
              }}
            >
              <Plus className="h-4 w-4" /> Tambah Jadwal
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Halaqah</label>
            <SearchSelect
              options={halaqahOptions}
              value={halaqahId}
              onChange={(v) => {
                setHalaqahId(toId(v));
                setPage(1);
              }}
              placeholder="-- Semua halaqah --"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Aktivitas</label>
            <Select
              value={activityType}
              onChange={(e) => {
                setActivityType(e.target.value as TahfizActivityType | '');
                setPage(1);
              }}
            >
              <option value="">Semua aktivitas</option>
              {ACTIVITY_OPTIONS.map((o) => (
                <option key={String(o.value)} value={String(o.value)}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Hari</label>
            <Select
              value={dayOfWeek === '' ? '' : String(dayOfWeek)}
              onChange={(e) => {
                setDayOfWeek(e.target.value === '' ? '' : Number(e.target.value));
                setPage(1);
              }}
            >
              <option value="">Semua hari</option>
              {DAY_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Status</label>
            <Select
              value={status}
              onChange={(e) => {
                setStatus(e.target.value as 'active' | 'inactive' | '');
                setPage(1);
              }}
            >
              <option value="">Semua status</option>
              <option value="active">Aktif</option>
              <option value="inactive">Tidak Aktif</option>
            </Select>
          </div>
        </div>
      </Card>

      {listQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat jadwal...
          </div>
        </Card>
      ) : listQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat jadwal"
            description={getErrorMessage(listQuery.error)}
            action={
              <Button size="sm" variant="outline" className="mt-2" onClick={() => listQuery.refetch()}>
                Coba lagi
              </Button>
            }
          />
        </Card>
      ) : rows.length === 0 ? (
        <Card>
          <EmptyState
            title="Belum ada jadwal"
            description="Tambahkan jadwal berulang agar sesi dapat di-generate otomatis."
          />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Hari</th>
                  <th className="px-4 py-2 font-medium">Waktu</th>
                  <th className="px-4 py-2 font-medium">Aktivitas</th>
                  <th className="px-4 py-2 font-medium">Halaqah</th>
                  <th className="px-4 py-2 font-medium">Program</th>
                  <th className="px-4 py-2 font-medium">Instruktur</th>
                  <th className="px-4 py-2 font-medium">Masa Berlaku</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="w-24 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="border-b border-slate-50 last:border-0 align-top">
                    <td className="px-4 py-2 font-medium text-slate-800">
                      {row.day_label ?? DAY_LABELS[row.day_of_week] ?? '-'}
                    </td>
                    <td className="px-4 py-2 text-slate-600">{timeRange(row)}</td>
                    <td className="px-4 py-2 text-slate-700">
                      {ACTIVITY_TYPE_LABELS[row.activity_type] ?? row.activity_type}
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">{row.halaqah?.name ?? '—'}</td>
                    <td className="px-4 py-2 text-xs text-slate-600">{row.program?.name ?? '—'}</td>
                    <td className="px-4 py-2 text-xs text-slate-600">
                      {row.instructor?.full_name ?? '—'}
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-500">
                      {row.effective_from ?? '—'} s.d. {row.effective_until ?? '—'}
                    </td>
                    <td className="px-4 py-2">
                      <Badge tone={row.status === 'active' ? 'green' : 'gray'}>
                        {row.status === 'active' ? 'Aktif' : 'Tidak Aktif'}
                      </Badge>
                    </td>
                    <td className="px-4 py-2">
                      <div className="flex flex-wrap gap-1">
                        <Can perm="tahfiz.update">
                          <Button
                            variant="ghost"
                            size="icon"
                            title="Edit"
                            onClick={() => {
                              setEditing(row);
                              setServerErrors({});
                              setFormOpen(true);
                            }}
                          >
                            <Pencil className="h-3.5 w-3.5" />
                          </Button>
                        </Can>
                        <Can perm="tahfiz.delete">
                          <Button
                            variant="ghost"
                            size="icon"
                            title="Hapus"
                            onClick={() => setDeleting(row)}
                          >
                            <Trash2 className="h-3.5 w-3.5 text-red-500" />
                          </Button>
                        </Can>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </Card>
      )}

      <div className="flex items-center gap-2 text-xs text-slate-400">
        <CalendarClock className="h-3.5 w-3.5" />
        Hari mengikuti konvensi 0=Ahad … 6=Sabtu. Jadwal aktif dipakai saat generate sesi.
      </div>

      <ScheduleFormModal
        open={formOpen}
        editing={editing}
        submitting={saveMutation.isPending}
        serverErrors={serverErrors}
        onClose={() => {
          setFormOpen(false);
          setEditing(null);
        }}
        onSubmit={(payload) => saveMutation.mutate(payload)}
      />

      <ConfirmDialog
        open={!!deleting}
        title="Hapus Jadwal"
        message={`Hapus jadwal ${ACTIVITY_TYPE_LABELS[deleting?.activity_type ?? 'halaqah'] ?? ''} hari ${
          deleting ? DAY_LABELS[deleting.day_of_week] ?? '' : ''
        }? Tindakan ini tidak bisa dibatalkan.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}