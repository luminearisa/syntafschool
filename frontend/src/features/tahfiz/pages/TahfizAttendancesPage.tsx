import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ClipboardList, Pencil, Plus, RefreshCw, Trash2, Users } from 'lucide-react';
import { toast } from 'sonner';
import { attendancesApi } from '../api/tahfizApi';
import { AttendanceFormModal } from '../components/AttendanceFormModal';
import {
  AttendanceBulkModal,
  type TahfizAttendanceBulkPayload,
} from '../components/AttendanceBulkModal';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Spinner } from '@/components/ui/Spinner';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { useHalaqahOptions, useParticipantOptions } from '../hooks/useTahfizOptions';
import { ATTENDANCE_STATUS_LABELS, attendanceTone } from '../lib/labels';
import { api, getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { PaginatedResponse } from '@/types';
import type {
  TahfizAttendance,
  TahfizAttendancePayload,
  TahfizAttendanceStatus,
} from '@/types/tahfiz';

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

/**
 * Kehadiran peserta Tahfiz.
 *
 * Mendukung pencatatan per peserta maupun absensi massal seluruh anggota
 * halaqah pada satu tanggal sekaligus.
 */
export function TahfizAttendancesPage() {
  const queryClient = useQueryClient();

  const [page, setPage] = useState(1);
  const [halaqahId, setHalaqahId] = useState<number | ''>('');
  const [participantId, setParticipantId] = useState<number | ''>('');
  const [status, setStatus] = useState<TahfizAttendanceStatus | ''>('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<TahfizAttendance | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [bulkOpen, setBulkOpen] = useState(false);
  const [bulkErrors, setBulkErrors] = useState<Record<string, string[]>>({});
  const [deleting, setDeleting] = useState<TahfizAttendance | null>(null);

  const { options: halaqahOptions } = useHalaqahOptions();
  const { options: participantOptions } = useParticipantOptions();

  const filters: Record<string, string | number> = {};
  if (halaqahId) filters.halaqah_id = halaqahId;
  if (participantId) filters.participant_id = participantId;
  if (status) filters.status = status;

  const listQuery = useQuery({
    queryKey: ['tahfiz', 'attendances', page, filters, from, to],
    queryFn: async () => {
      const res = await api.get<PaginatedResponse<TahfizAttendance>>('/tahfiz/attendances', {
        params: {
          page,
          per_page: 15,
          filter: Object.keys(filters).length ? filters : undefined,
          from: from || undefined,
          to: to || undefined,
        },
      });
      return res.data;
    },
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['tahfiz', 'attendances'] });

  const saveMutation = useMutation({
    mutationFn: (payload: TahfizAttendancePayload) =>
      editing ? attendancesApi.update(editing.id, payload) : attendancesApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Kehadiran diperbarui' : 'Kehadiran dicatat');
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

  const bulkMutation = useMutation({
    mutationFn: (payload: TahfizAttendanceBulkPayload) => attendancesApi.bulk(payload),
    onSuccess: (res) => {
      toast.success(res.message);
      setBulkOpen(false);
      setBulkErrors({});
      invalidate();
    },
    onError: (err) => {
      setBulkErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => attendancesApi.remove(id),
    onSuccess: () => {
      toast.success('Kehadiran dihapus');
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
          <h1 className="text-lg font-semibold text-slate-900">Kehadiran Tahfiz</h1>
          <p className="text-sm text-slate-500">
            Pencatatan kehadiran peserta per sesi/tanggal, termasuk absensi massal per halaqah.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
            <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="tahfiz.setoran">
            <Button
              variant="outline"
              onClick={() => {
                setBulkErrors({});
                setBulkOpen(true);
              }}
            >
              <Users className="h-4 w-4" /> Absensi Massal
            </Button>
            <Button
              onClick={() => {
                setEditing(null);
                setServerErrors({});
                setFormOpen(true);
              }}
            >
              <Plus className="h-4 w-4" /> Catat Kehadiran
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
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
            <label className="mb-1 block text-xs font-medium text-slate-600">Peserta</label>
            <SearchSelect
              options={participantOptions}
              value={participantId}
              onChange={(v) => {
                setParticipantId(toId(v));
                setPage(1);
              }}
              placeholder="-- Semua peserta --"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Status</label>
            <Select
              value={status}
              onChange={(e) => {
                setStatus(e.target.value as TahfizAttendanceStatus | '');
                setPage(1);
              }}
            >
              <option value="">Semua status</option>
              {(Object.keys(ATTENDANCE_STATUS_LABELS) as TahfizAttendanceStatus[]).map((key) => (
                <option key={key} value={key}>
                  {ATTENDANCE_STATUS_LABELS[key]}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Dari Tanggal</label>
            <Input
              type="date"
              value={from}
              onChange={(e) => {
                setFrom(e.target.value);
                setPage(1);
              }}
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Sampai Tanggal</label>
            <Input
              type="date"
              value={to}
              onChange={(e) => {
                setTo(e.target.value);
                setPage(1);
              }}
            />
          </div>
        </div>
      </Card>

      {listQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat kehadiran...
          </div>
        </Card>
      ) : listQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat kehadiran"
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
            title="Belum ada catatan kehadiran"
            description="Catat kehadiran satu peserta, atau gunakan absensi massal untuk seluruh halaqah."
          />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Tanggal</th>
                  <th className="px-4 py-2 font-medium">Peserta</th>
                  <th className="px-4 py-2 font-medium">Halaqah</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="px-4 py-2 font-medium">Jam Datang</th>
                  <th className="px-4 py-2 font-medium">Keterangan</th>
                  <th className="px-4 py-2 font-medium">Catatan</th>
                  <th className="w-28 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="border-b border-slate-50 last:border-0 align-top">
                    <td className="px-4 py-2 text-slate-700">{row.date ?? '-'}</td>
                    <td className="px-4 py-2 font-medium text-slate-800">
                      {row.participant?.name ?? '-'}
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">{row.halaqah?.name ?? '—'}</td>
                    <td className="px-4 py-2">
                      <Badge tone={attendanceTone(row.status)}>
                        {ATTENDANCE_STATUS_LABELS[row.status] ?? row.status}
                        {row.code ? ` (${row.code})` : ''}
                      </Badge>
                    </td>
                    <td className="px-4 py-2 text-slate-600">{row.arrival_time ?? '—'}</td>
                    <td className="max-w-[14rem] px-4 py-2 text-xs text-slate-500">
                      {row.permission_reason ?? '—'}
                    </td>
                    <td className="max-w-[14rem] px-4 py-2 text-xs text-slate-500">
                      {row.notes ?? '—'}
                    </td>
                    <td className="px-4 py-2">
                      <div className="flex flex-wrap gap-1">
                        <Can perm="tahfiz.setoran">
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
        <ClipboardList className="h-3.5 w-3.5" />
        Status izin/sakit wajib disertai keterangan. Kode status: H=Hadir, I=Izin, S=Sakit, A=Alpa,
        T=Terlambat.
      </div>

      <AttendanceFormModal
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

      <AttendanceBulkModal
        open={bulkOpen}
        submitting={bulkMutation.isPending}
        serverErrors={bulkErrors}
        onClose={() => setBulkOpen(false)}
        onSubmit={(payload) => bulkMutation.mutate(payload)}
      />

      <ConfirmDialog
        open={!!deleting}
        title="Hapus Kehadiran"
        message={`Hapus catatan kehadiran ${deleting?.participant?.name ?? ''} tanggal ${deleting?.date ?? ''}? Tindakan ini tidak bisa dibatalkan.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}