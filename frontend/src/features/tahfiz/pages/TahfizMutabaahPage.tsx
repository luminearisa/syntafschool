import { Fragment, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ChevronDown, ChevronRight, Pencil, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { mutabaahApi } from '../api/tahfizApi';
import { MutabaahFormModal } from '../components/MutabaahFormModal';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Spinner } from '@/components/ui/Spinner';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { useParticipantOptions } from '../hooks/useTahfizOptions';
import { api, getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { PaginatedResponse } from '@/types';
import type { TahfizMutabaah, TahfizMutabaahPayload } from '@/types/tahfiz';

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

/** Jumlah entri yang dianggap "terlaksana" (centang atau nilai > 0). */
function doneCount(row: TahfizMutabaah): number {
  return (row.entries ?? []).filter((e) => e.done || (e.value ?? 0) > 0).length;
}

/**
 * Rekap mutabaah harian peserta.
 *
 * Satu baris per peserta per tanggal; mengirim ulang tanggal yang sama
 * memperbarui data lama, bukan menambah duplikat.
 */
export function TahfizMutabaahPage() {
  const queryClient = useQueryClient();

  const [page, setPage] = useState(1);
  const [participantId, setParticipantId] = useState<number | ''>('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [expandedId, setExpandedId] = useState<number | null>(null);

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<TahfizMutabaah | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [deleting, setDeleting] = useState<TahfizMutabaah | null>(null);

  const { options: participantOptions } = useParticipantOptions();

  const filters: Record<string, string | number> = {};
  if (participantId) filters.participant_id = participantId;

  const listQuery = useQuery({
    queryKey: ['tahfiz', 'mutabaah', page, filters, from, to],
    queryFn: async () => {
      const res = await api.get<PaginatedResponse<TahfizMutabaah>>('/tahfiz/mutabaah', {
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

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['tahfiz', 'mutabaah'] });

  const saveMutation = useMutation({
    mutationFn: (payload: TahfizMutabaahPayload) =>
      editing ? mutabaahApi.update(editing.id, payload) : mutabaahApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Mutabaah diperbarui' : 'Mutabaah disimpan');
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
    mutationFn: (id: number) => mutabaahApi.remove(id),
    onSuccess: () => {
      toast.success('Mutabaah dihapus');
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
          <h1 className="text-lg font-semibold text-slate-900">Mutabaah Harian</h1>
          <p className="text-sm text-slate-500">
            Rekap ibadah & aktivitas harian peserta. Item mutabaah dikelola di master data.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
            <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="tahfiz.setoran">
            <Button
              onClick={() => {
                setEditing(null);
                setServerErrors({});
                setFormOpen(true);
              }}
            >
              <Plus className="h-4 w-4" /> Isi Mutabaah
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
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
            <Spinner /> Memuat mutabaah...
          </div>
        </Card>
      ) : listQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat mutabaah"
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
            title="Belum ada data mutabaah"
            description="Klik 'Isi Mutabaah' untuk mencatat aktivitas harian peserta."
          />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="w-10 px-4 py-2 font-medium" />
                  <th className="px-4 py-2 font-medium">Tanggal</th>
                  <th className="px-4 py-2 font-medium">Peserta</th>
                  <th className="px-3 py-2 text-center font-medium">Item</th>
                  <th className="px-3 py-2 text-center font-medium">Terlaksana</th>
                  <th className="px-4 py-2 font-medium">Catatan</th>
                  <th className="w-28 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => {
                  const expanded = expandedId === row.id;
                  const total = row.entries?.length ?? 0;
                  const done = doneCount(row);
                  return (
                    <Fragment key={row.id}>
                      <tr className="border-b border-slate-50 last:border-0 align-top">
                        <td className="px-4 py-2">
                          <button
                            type="button"
                            title={expanded ? 'Tutup detail' : 'Lihat detail'}
                            onClick={() => setExpandedId(expanded ? null : row.id)}
                            className="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                          >
                            {expanded ? (
                              <ChevronDown className="h-4 w-4" />
                            ) : (
                              <ChevronRight className="h-4 w-4" />
                            )}
                          </button>
                        </td>
                        <td className="px-4 py-2 text-slate-700">{row.date ?? '-'}</td>
                        <td className="px-4 py-2 font-medium text-slate-800">
                          {row.participant?.name ?? '-'}
                        </td>
                        <td className="px-3 py-2 text-center text-slate-700">{total}</td>
                        <td className="px-3 py-2 text-center">
                          <Badge tone={total > 0 && done === total ? 'green' : done > 0 ? 'amber' : 'gray'}>
                            {done} / {total}
                          </Badge>
                        </td>
                        <td className="max-w-[16rem] px-4 py-2 text-xs text-slate-500">
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
                      {expanded && (
                        <tr className="border-b border-slate-100 bg-slate-50/60">
                          <td colSpan={7} className="px-4 py-3">
                            {(row.entries ?? []).length === 0 ? (
                              <p className="text-xs text-slate-400">Tidak ada entri.</p>
                            ) : (
                              <div className="grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                                {(row.entries ?? []).map((entry) => (
                                  <div
                                    key={entry.id}
                                    className="flex items-center justify-between gap-3 rounded-lg bg-white px-3 py-1.5"
                                  >
                                    <span className="text-xs font-medium text-slate-700">
                                      {entry.item_label ?? entry.item?.label ?? 'Item'}
                                    </span>
                                    <span className="flex items-center gap-2 text-xs text-slate-500">
                                      {entry.value !== null ? (
                                        <Badge tone="blue">{entry.value}</Badge>
                                      ) : (
                                        <Badge tone={entry.done ? 'green' : 'gray'}>
                                          {entry.done ? 'Selesai' : 'Belum'}
                                        </Badge>
                                      )}
                                      {entry.note && <span className="italic">{entry.note}</span>}
                                    </span>
                                  </div>
                                ))}
                              </div>
                            )}
                          </td>
                        </tr>
                      )}
                    </Fragment>
                  );
                })}
              </tbody>
            </table>
          </div>
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </Card>
      )}

      <p className="text-xs text-slate-400">
        Satu baris per peserta per tanggal — mengirim ulang tanggal yang sama akan memperbarui data
        lama, bukan membuat duplikat.
      </p>

      <MutabaahFormModal
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
        title="Hapus Mutabaah"
        message={`Hapus mutabaah ${deleting?.participant?.name ?? ''} tanggal ${deleting?.date ?? ''}? Tindakan ini tidak bisa dibatalkan.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}