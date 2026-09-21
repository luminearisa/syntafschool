import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { Link } from 'react-router-dom';
import { toast } from 'sonner';
import { participantsApi } from '../api/tahfizApi';
import { ParticipantFormModal } from '../components/ParticipantFormModal';
import {
  PARTICIPANT_TYPE_LABELS,
  PARTICIPANT_STATUS_LABELS,
  GENDER_LABELS,
  toOptions,
} from '../lib/labels';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type {
  TahfizParticipant,
  TahfizParticipantPayload,
  TahfizParticipantStatus,
  TahfizParticipantType,
} from '@/types/tahfiz';

function participantStatusTone(status: TahfizParticipantStatus) {
  switch (status) {
    case 'active':
      return 'green' as const;
    case 'blacklisted':
      return 'red' as const;
    default:
      return 'gray' as const;
  }
}

/** Daftar peserta Tahfiz dengan filter tipe & status. */
export function TahfizParticipantsPage() {
  const queryClient = useQueryClient();

  const [page, setPage] = useState(1);
  const [type, setType] = useState<TahfizParticipantType | ''>('');
  const [status, setStatus] = useState<TahfizParticipantStatus | ''>('');
  const [search, setSearch] = useState('');

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<TahfizParticipant | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [deleting, setDeleting] = useState<TahfizParticipant | null>(null);

  const filters = {
    ...(type ? { participant_type: type } : {}),
    ...(status ? { status } : {}),
  };

  const listQuery = useQuery({
    queryKey: ['tahfiz', 'participants', page, filters, search],
    queryFn: () =>
      participantsApi.list({
        page,
        per_page: 15,
        search: search || undefined,
        filter: Object.keys(filters).length ? filters : undefined,
      }),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['tahfiz', 'participants'] });

  const saveMutation = useMutation({
    mutationFn: (payload: TahfizParticipantPayload) =>
      editing ? participantsApi.update(editing.id, payload) : participantsApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Peserta diperbarui' : 'Peserta dibuat');
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
    mutationFn: (id: number) => participantsApi.remove(id),
    onSuccess: () => {
      toast.success('Peserta dihapus');
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
          <h1 className="text-lg font-semibold text-slate-900">Peserta Tahfiz</h1>
          <p className="text-sm text-slate-500">
            Data peserta program Tahfiz/Al-Qur'an beserta jumlah pendaftarannya.
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
              <Plus className="h-4 w-4" /> Tambah Peserta
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Tipe</label>
            <Select
              value={type}
              onChange={(e) => {
                setType(e.target.value as TahfizParticipantType | '');
                setPage(1);
              }}
            >
              <option value="">Semua tipe</option>
              {toOptions(PARTICIPANT_TYPE_LABELS).map((o) => (
                <option key={String(o.value)} value={String(o.value)}>
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
                setStatus(e.target.value as TahfizParticipantStatus | '');
                setPage(1);
              }}
            >
              <option value="">Semua status</option>
              {toOptions(PARTICIPANT_STATUS_LABELS).map((o) => (
                <option key={String(o.value)} value={String(o.value)}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Cari</label>
            <Input
              value={search}
              placeholder="Cari nama, telepon, atau email..."
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(1);
              }}
            />
          </div>
        </div>
      </Card>

      {listQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat peserta...
          </div>
        </Card>
      ) : listQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat peserta"
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
            title="Belum ada peserta"
            description="Tambahkan peserta pertama untuk memulai program Tahfiz."
          />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Nama</th>
                  <th className="px-4 py-2 font-medium">Tipe</th>
                  <th className="px-4 py-2 font-medium">JK</th>
                  <th className="px-4 py-2 font-medium">Kontak</th>
                  <th className="px-3 py-2 text-center font-medium">Pendaftaran</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="w-24 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="border-b border-slate-50 last:border-0 align-top">
                    <td className="px-4 py-2">
                      <Link
                        to={`/tahfiz/participants/${row.id}`}
                        className="font-medium text-brand-700 hover:underline"
                      >
                        {row.name}
                      </Link>
                    </td>
                    <td className="px-4 py-2">
                      <Badge tone="blue">
                        {PARTICIPANT_TYPE_LABELS[row.participant_type] ?? row.participant_type}
                      </Badge>
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">
                      {row.gender ? GENDER_LABELS[row.gender] : '—'}
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">
                      {row.phone && <div>{row.phone}</div>}
                      {row.email && <div className="text-slate-400">{row.email}</div>}
                      {!row.phone && !row.email && '—'}
                    </td>
                    <td className="px-3 py-2 text-center text-slate-700">
                      {row.active_enrollments_count}/{row.enrollments_count}
                    </td>
                    <td className="px-4 py-2">
                      <Badge tone={participantStatusTone(row.status)}>
                        {PARTICIPANT_STATUS_LABELS[row.status] ?? row.status}
                      </Badge>
                    </td>
                    <td className="px-4 py-2">
                      <Can perm={['tahfiz.update', 'tahfiz.delete']} fallback={<span className="text-xs text-slate-400">—</span>}>
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
                      </Can>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </Card>
      )}

      <ParticipantFormModal
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
        title="Hapus Peserta"
        message={`Hapus peserta "${deleting?.name ?? ''}"? Tindakan ini tidak bisa dibatalkan.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}