import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { libraryClosuresApi } from '../api/libraryApi';
import { ClosureFormModal } from '../components/ClosureFormModal';
import { useLibraryOptions } from '../hooks/useLibraryOptions';
import {
  CLOSURE_STATUS_LABELS,
  CLOSURE_TYPE_LABELS,
  closureStatusTone,
  toOptions,
} from '../lib/labels';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type {
  LibraryClosure,
  LibraryClosurePayload,
  LibraryClosureStatus,
  LibraryClosureType,
} from '@/types/library';

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

/** Format tanggal ISO (YYYY-MM-DD) ke tampilan Indonesia. */
function formatDate(value: string | null): string {
  if (!value) return '—';
  const date = new Date(`${value}T00:00:00`);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
}

/**
 * Daftar penutupan perpustakaan (libur, pemeliharaan, acara).
 *
 * Penutupan dapat berlaku sehari penuh atau sebagian hari lewat jam mulai
 * dan selesai.
 */
export function LibraryClosuresPage() {
  const queryClient = useQueryClient();
  const { options: libraryOptions, isLoading: librariesLoading } = useLibraryOptions();

  const [libraryId, setLibraryId] = useState<number | ''>('');
  const [page, setPage] = useState(1);
  const [type, setType] = useState<LibraryClosureType | ''>('');
  const [status, setStatus] = useState<LibraryClosureStatus | ''>('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<LibraryClosure | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [deleting, setDeleting] = useState<LibraryClosure | null>(null);

  const filters: Record<string, string> = {};
  if (type) filters.type = type;
  if (status) filters.status = status;

  const listQuery = useQuery({
    queryKey: ['library', 'closures', libraryId, page, filters, from, to],
    enabled: libraryId !== '',
    queryFn: () =>
      libraryClosuresApi.list(Number(libraryId), {
        page,
        per_page: 15,
        ...(Object.keys(filters).length ? { filter: filters } : {}),
        ...(from ? { from } : {}),
        ...(to ? { to } : {}),
      }),
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['library'] });

  const saveMutation = useMutation({
    mutationFn: (payload: LibraryClosurePayload) =>
      editing
        ? libraryClosuresApi.update(Number(libraryId), editing.id, payload)
        : libraryClosuresApi.create(Number(libraryId), payload),
    onSuccess: () => {
      toast.success(editing ? 'Penutupan diperbarui' : 'Penutupan dibuat');
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
    mutationFn: (id: number) => libraryClosuresApi.remove(Number(libraryId), id),
    onSuccess: () => {
      toast.success('Penutupan dihapus');
      setDeleting(null);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Penutupan Perpustakaan</h1>
          <p className="text-sm text-slate-500">
            Catat hari libur, pemeliharaan, atau acara yang membuat layanan tutup.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button
            variant="ghost"
            size="icon"
            title="Muat ulang"
            disabled={libraryId === ''}
            onClick={() => listQuery.refetch()}
          >
            <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="library.closure.create">
            <Button
              disabled={libraryId === ''}
              onClick={() => {
                setEditing(null);
                setServerErrors({});
                setFormOpen(true);
              }}
            >
              <Plus className="h-4 w-4" /> Tambah Penutupan
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Perpustakaan</label>
            <SearchSelect
              options={libraryOptions}
              value={libraryId}
              disabled={librariesLoading}
              onChange={(v) => {
                setLibraryId(toId(v));
                setPage(1);
              }}
              placeholder="-- Pilih perpustakaan --"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Jenis</label>
            <Select
              value={type}
              onChange={(e) => {
                setType(e.target.value as LibraryClosureType | '');
                setPage(1);
              }}
            >
              <option value="">Semua jenis</option>
              {toOptions(CLOSURE_TYPE_LABELS).map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Status</label>
            <Select
              value={status}
              onChange={(e) => {
                setStatus(e.target.value as LibraryClosureStatus | '');
                setPage(1);
              }}
            >
              <option value="">Semua status</option>
              {toOptions(CLOSURE_STATUS_LABELS).map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
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

      {libraryId === '' ? (
        <Card>
          <EmptyState
            title="Pilih perpustakaan"
            description="Pilih salah satu perpustakaan untuk mengelola penutupannya."
          />
        </Card>
      ) : listQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat penutupan...
          </div>
        </Card>
      ) : listQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat penutupan"
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
            title="Belum ada penutupan"
            description="Tambahkan penutupan untuk menandai hari layanan tutup."
          />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Tanggal</th>
                  <th className="px-4 py-2 font-medium">Periode</th>
                  <th className="px-4 py-2 font-medium">Alasan</th>
                  <th className="px-4 py-2 font-medium">Jenis</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="w-24 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="border-b border-slate-50 last:border-0">
                    <td className="px-4 py-2 text-slate-700">{formatDate(row.date)}</td>
                    <td className="px-4 py-2 font-mono text-xs text-slate-600">
                      {row.is_full_day ? 'Sepanjang hari' : row.period_label}
                    </td>
                    <td className="max-w-[20rem] px-4 py-2 text-slate-700">{row.reason}</td>
                    <td className="px-4 py-2 text-slate-600">
                      {CLOSURE_TYPE_LABELS[row.type] ?? row.type}
                    </td>
                    <td className="px-4 py-2">
                      <Badge tone={closureStatusTone(row.status)}>
                        {CLOSURE_STATUS_LABELS[row.status] ?? row.status}
                      </Badge>
                    </td>
                    <td className="px-4 py-2">
                      <div className="flex flex-wrap gap-1">
                        <Can perm="library.closure.update">
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
                        <Can perm="library.closure.delete">
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

      <ClosureFormModal
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
        title="Hapus Penutupan"
        message={`Hapus penutupan pada ${formatDate(deleting?.date ?? null)}? Tindakan ini tidak bisa dibatalkan.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}