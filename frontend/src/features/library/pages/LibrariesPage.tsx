import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Plus, RefreshCw, Repeat, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { librariesApi } from '../api/libraryApi';
import { LibraryFormModal } from '../components/LibraryFormModal';
import { LibraryTransitionModal } from '../components/LibraryTransitionModal';
import {
  LIBRARY_STATUS_LABELS,
  libraryStatusTone,
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
  Library,
  LibraryPayload,
  LibraryStatus,
  LibraryTransitionPayload,
} from '@/types/library';

/**
 * Daftar perpustakaan.
 *
 * Siklus status: draf → aktif → tidak aktif → diarsipkan (final). Perubahan
 * status mengikuti `allowed_transitions` dari server, bukan tombol bebas.
 */
export function LibrariesPage() {
  const queryClient = useQueryClient();

  const [page, setPage] = useState(1);
  const [status, setStatus] = useState<LibraryStatus | ''>('');
  const [search, setSearch] = useState('');

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<Library | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [transitioning, setTransitioning] = useState<Library | null>(null);
  const [transitionErrors, setTransitionErrors] = useState<Record<string, string[]>>({});
  const [deleting, setDeleting] = useState<Library | null>(null);

  const filters = { ...(status ? { status } : {}) };

  const listQuery = useQuery({
    queryKey: ['library', 'libraries', page, filters, search],
    queryFn: () =>
      librariesApi.list({
        page,
        per_page: 15,
        search: search || undefined,
        filter: Object.keys(filters).length ? filters : undefined,
      }),
  });

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['library', 'libraries'] });
    queryClient.invalidateQueries({ queryKey: ['library', 'options'] });
  };

  const saveMutation = useMutation({
    mutationFn: (payload: LibraryPayload) =>
      editing ? librariesApi.update(editing.id, payload) : librariesApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Perpustakaan diperbarui' : 'Perpustakaan dibuat');
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

  const transitionMutation = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: LibraryTransitionPayload }) =>
      librariesApi.transition(id, payload),
    onSuccess: (res) => {
      toast.success(res.message);
      setTransitioning(null);
      setTransitionErrors({});
      invalidate();
    },
    onError: (err) => {
      setTransitionErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => librariesApi.remove(id),
    onSuccess: () => {
      toast.success('Perpustakaan dihapus');
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
          <h1 className="text-lg font-semibold text-slate-900">Perpustakaan</h1>
          <p className="text-sm text-slate-500">
            Daftar perpustakaan, lokasi, jam operasional, dan konfigurasi dasar.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
            <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="library.create">
            <Button
              onClick={() => {
                setEditing(null);
                setServerErrors({});
                setFormOpen(true);
              }}
            >
              <Plus className="h-4 w-4" /> Tambah Perpustakaan
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Status</label>
            <Select
              value={status}
              onChange={(e) => {
                setStatus(e.target.value as LibraryStatus | '');
                setPage(1);
              }}
            >
              <option value="">Semua status</option>
              {(Object.keys(LIBRARY_STATUS_LABELS) as LibraryStatus[]).map((key) => (
                <option key={key} value={key}>
                  {LIBRARY_STATUS_LABELS[key]}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Cari</label>
            <Input
              value={search}
              placeholder="Cari kode atau nama..."
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
            <Spinner /> Memuat perpustakaan...
          </div>
        </Card>
      ) : listQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat perpustakaan"
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
            title="Belum ada perpustakaan"
            description="Tambahkan perpustakaan pertama untuk mulai mengelola lokasi dan konfigurasi."
          />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Kode</th>
                  <th className="px-4 py-2 font-medium">Nama</th>
                  <th className="px-4 py-2 font-medium">Sekolah</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="px-3 py-2 text-center font-medium">Lokasi</th>
                  <th className="px-3 py-2 text-center font-medium">Jam Ops.</th>
                  <th className="px-3 py-2 text-center font-medium">Penutupan</th>
                  <th className="px-3 py-2 text-center font-medium">Penomoran</th>
                  <th className="w-44 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="border-b border-slate-50 last:border-0 align-top">
                    <td className="px-4 py-2 font-mono text-xs text-slate-600">{row.code}</td>
                    <td className="px-4 py-2 font-medium text-slate-800">{row.name}</td>
                    <td className="px-4 py-2 text-slate-600">{row.school?.name ?? '-'}</td>
                    <td className="px-4 py-2">
                      <Badge tone={libraryStatusTone(row.status)}>
                        {LIBRARY_STATUS_LABELS[row.status] ?? row.status}
                      </Badge>
                    </td>
                    <td className="px-3 py-2 text-center text-slate-700">{row.locations_count}</td>
                    <td className="px-3 py-2 text-center text-slate-700">
                      {row.operating_hours_count}
                    </td>
                    <td className="px-3 py-2 text-center text-slate-700">{row.closures_count}</td>
                    <td className="px-3 py-2 text-center text-slate-700">
                      {row.numbering_configs_count}
                    </td>
                    <td className="px-4 py-2">
                      <div className="flex flex-wrap gap-1">
                        <Can
                          perm="library.update"
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
                          {row.allowed_transitions.length > 0 && (
                            <Button
                              variant="ghost"
                              size="icon"
                              title="Ubah Status"
                              onClick={() => {
                                setTransitionErrors({});
                                setTransitioning(row);
                              }}
                            >
                              <Repeat className="h-3.5 w-3.5" />
                            </Button>
                          )}
                        </Can>
                        <Can perm="library.delete">
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

      <LibraryFormModal
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

      <LibraryTransitionModal
        open={!!transitioning}
        library={transitioning}
        submitting={transitionMutation.isPending}
        serverErrors={transitionErrors}
        onClose={() => setTransitioning(null)}
        onSubmit={(payload) => {
          if (transitioning) transitionMutation.mutate({ id: transitioning.id, payload });
        }}
      />

      <ConfirmDialog
        open={!!deleting}
        title="Hapus Perpustakaan"
        message={`Hapus perpustakaan "${deleting?.name ?? ''}" (${deleting?.code ?? ''})? Tindakan ini tidak bisa dibatalkan.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}