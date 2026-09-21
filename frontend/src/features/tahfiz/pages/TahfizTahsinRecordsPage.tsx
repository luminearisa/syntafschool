import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Plus, RefreshCw, Sparkles, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { tahsinRecordsApi } from '../api/tahfizApi';
import { TahsinRecordFormModal } from '../components/TahsinRecordFormModal';
import { useParticipantOptions } from '../hooks/useTahfizOptions';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Spinner } from '@/components/ui/Spinner';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { TahfizTahsinRecord, TahfizTahsinRecordPayload } from '@/types/tahfiz';

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

/** Daftar tahsin (perbaikan bacaan berbasis rubrik) dengan filter peserta dan periode. */
export function TahfizTahsinRecordsPage() {
  const queryClient = useQueryClient();

  const [page, setPage] = useState(1);
  const [participantId, setParticipantId] = useState<number | ''>('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<TahfizTahsinRecord | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [deleting, setDeleting] = useState<TahfizTahsinRecord | null>(null);

  const { options: participantOptions } = useParticipantOptions();

  const filters = {
    ...(participantId ? { participant_id: participantId } : {}),
    ...(from ? { date_from: from } : {}),
    ...(to ? { date_to: to } : {}),
  };

  const listQuery = useQuery({
    queryKey: ['tahfiz', 'tahsin-records', page, filters, ''],
    queryFn: () =>
      tahsinRecordsApi.list({
        page,
        per_page: 15,
        filter: Object.keys(filters).length ? filters : undefined,
      }),
  });

  const invalidate = () =>
    queryClient.invalidateQueries({ queryKey: ['tahfiz', 'tahsin-records'] });

  const saveMutation = useMutation({
    mutationFn: (payload: TahfizTahsinRecordPayload) =>
      editing ? tahsinRecordsApi.update(editing.id, payload) : tahsinRecordsApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Tahsin diperbarui' : 'Tahsin dicatat');
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
    mutationFn: (id: number) => tahsinRecordsApi.remove(id),
    onSuccess: () => {
      toast.success('Tahsin dihapus');
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
          <h1 className="text-lg font-semibold text-slate-900">Tahsin</h1>
          <p className="text-sm text-slate-500">
            Penilaian perbaikan bacaan berbasis rubrik — nilai akhir dihitung server.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
            <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="tahfiz.assess">
            <Button
              onClick={() => {
                setEditing(null);
                setServerErrors({});
                setFormOpen(true);
              }}
            >
              <Plus className="h-4 w-4" /> Catat Tahsin
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Peserta</label>
            <SearchSelect
              options={participantOptions}
              value={participantId}
              onChange={(v) => {
                setParticipantId(toId(v));
                setPage(1);
              }}
              placeholder="-- Semua --"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Dari</label>
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
            <label className="mb-1 block text-xs font-medium text-slate-600">Sampai</label>
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
            <Spinner /> Memuat tahsin...
          </div>
        </Card>
      ) : listQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat tahsin"
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
          <EmptyState title="Belum ada tahsin" description="Ubah filter atau catat tahsin baru." />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Tanggal</th>
                  <th className="px-4 py-2 font-medium">Peserta</th>
                  <th className="px-4 py-2 font-medium">Surah / Ayat</th>
                  <th className="px-4 py-2 font-medium">Rubrik</th>
                  <th className="px-3 py-2 text-center font-medium">Nilai Akhir</th>
                  <th className="px-3 py-2 text-center font-medium">Kriteria</th>
                  <th className="w-24 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="border-b border-slate-50 last:border-0 align-top">
                    <td className="px-4 py-2 font-mono text-xs text-slate-700">{row.date ?? '—'}</td>
                    <td className="px-4 py-2 text-slate-700">
                      {row.participant?.name ?? `Peserta #${row.participant_id}`}
                    </td>
                    <td className="px-4 py-2 text-slate-700">
                      {row.surah?.name ?? (row.surah_id ? `Surah #${row.surah_id}` : '—')}
                      {row.ayah_start != null && row.ayah_end != null && (
                        <div className="text-xs text-slate-400">
                          ayat {row.ayah_start}–{row.ayah_end}
                        </div>
                      )}
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">
                      {row.rubric?.name ?? (row.rubric_id ? `Rubrik #${row.rubric_id}` : '—')}
                    </td>
                    <td className="px-3 py-2 text-center">
                      {row.overall_score != null ? (
                        <Badge tone="brand">{row.overall_score}</Badge>
                      ) : (
                        <span className="text-xs text-slate-400">—</span>
                      )}
                    </td>
                    <td className="px-3 py-2 text-center text-slate-700">
                      {row.scores?.length ?? 0}
                    </td>
                    <td className="px-4 py-2">
                      <div className="flex flex-wrap gap-1">
                        <Can
                          perm="tahfiz.assess"
                          fallback={<span className="text-xs text-slate-400">—</span>}
                        >
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
        <Sparkles className="h-3.5 w-3.5" />
        Nilai akhir tahsin dihitung server dari skor per-kriteria dan bobot rubrik.
      </div>

      <TahsinRecordFormModal
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
        title="Hapus Tahsin"
        message={`Hapus catatan tahsin tanggal ${deleting?.date ?? ''} atas nama ${
          deleting?.participant?.name ?? ''
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