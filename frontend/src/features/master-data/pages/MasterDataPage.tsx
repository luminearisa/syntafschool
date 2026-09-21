import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus, Pencil, Trash2, Search, RefreshCw } from 'lucide-react';
import { toast } from 'sonner';
import { masterDataApi } from '../api/masterDataApi';
import { DynamicForm } from '../components/DynamicForm';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { Pagination } from '@/components/common/Pagination';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { MasterResourceDef } from '@/constants/masterData';
import type { MasterRecord } from '@/types';

const PER_PAGE = 15;

/** Render nilai sel tabel */
function CellValue({ field, row }: { field: MasterResourceDef['columns'][number]; row: MasterRecord }) {
  const value = row[field.name];

  // Ambil dari relasi jika ada (contoh: province.name)
  if (value === undefined || value === null || value === '') {
    return <span className="text-slate-300">-</span>;
  }

  if (field.type === 'checkbox') {
    return value ? (
      <Badge tone="green">Ya</Badge>
    ) : (
      <Badge tone="gray">Tidak</Badge>
    );
  }

  if (field.type === 'date') {
    const d = new Date(String(value));
    return <span>{Number.isNaN(d.getTime()) ? String(value) : d.toLocaleDateString('id-ID')}</span>;
  }

  if (field.name === 'id') {
    return <span className="font-mono text-xs text-slate-500">{String(value)}</span>;
  }

  return <span>{String(value)}</span>;
}

export function MasterDataPage({ def }: { def: MasterResourceDef }) {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [searchInput, setSearchInput] = useState('');
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<MasterRecord | null>(null);
  const [deleting, setDeleting] = useState<MasterRecord | null>(null);

  const queryKey = ['master', def.key, page, search];

  const { data, isLoading, isError, error, refetch, isFetching } = useQuery({
    queryKey,
    queryFn: () =>
      masterDataApi.list(def.key, { page, per_page: PER_PAGE, search }),
  });

  const createMutation = useMutation({
    mutationFn: (payload: Record<string, unknown>) =>
      masterDataApi.create(def.key, payload),
    onSuccess: () => {
      toast.success(`${def.label} berhasil ditambahkan`);
      queryClient.invalidateQueries({ queryKey: ['master', def.key] });
      setFormOpen(false);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, payload }: { id: number | string; payload: Record<string, unknown> }) =>
      masterDataApi.update(def.key, id, payload),
    onSuccess: () => {
      toast.success(`${def.label} berhasil diperbarui`);
      queryClient.invalidateQueries({ queryKey: ['master', def.key] });
      setFormOpen(false);
      setEditing(null);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number | string) => masterDataApi.remove(def.key, id),
    onSuccess: () => {
      toast.success(`${def.label} berhasil dihapus`);
      queryClient.invalidateQueries({ queryKey: ['master', def.key] });
      setDeleting(null);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = data?.data ?? [];
  const meta = data?.meta;
  const tableColumns = useMemo(
    () => def.columns.filter((c) => c.inTable),
    [def],
  );

  const submitForm = (payload: Record<string, unknown>) => {
    if (editing) {
      updateMutation.mutate({ id: editing.id, payload });
    } else {
      createMutation.mutate(payload);
    }
  };

  const activeError = createMutation.error || updateMutation.error;
  const serverErrors = getValidationErrors(activeError);

  const openCreate = () => {
    setEditing(null);
    createMutation.reset();
    updateMutation.reset();
    setFormOpen(true);
  };

  const openEdit = (row: MasterRecord) => {
    setEditing(row);
    createMutation.reset();
    updateMutation.reset();
    setFormOpen(true);
  };

  return (
    <div className="space-y-4">
      {/* Toolbar */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <form
          className="flex flex-1 items-center gap-2"
          onSubmit={(e) => {
            e.preventDefault();
            setPage(1);
            setSearch(searchInput);
          }}
        >
          <div className="relative w-full max-w-xs">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <Input
              className="pl-9"
              placeholder={`Cari ${def.labelPlural.toLowerCase()}...`}
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
            />
          </div>
          <Button type="submit" variant="outline" size="md">
            Cari
          </Button>
        </form>

        <div className="flex items-center gap-2">
          <Button
            variant="ghost"
            size="icon"
            onClick={() => refetch()}
            title="Muat ulang"
          >
            <RefreshCw className={isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          {!def.readOnly && (
            <Button onClick={openCreate}>
              <Plus className="h-4 w-4" />
              Tambah {def.label}
            </Button>
          )}
        </div>
      </div>

      {def.readOnly && (
        <p className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700">
          Data ini bersifat <strong>read-only</strong> (hanya dapat dilihat).
        </p>
      )}

      {/* Tabel */}
      <Card>
        {isLoading ? (
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat data...
          </div>
        ) : isError ? (
          <EmptyState
            title="Gagal memuat data"
            description={getErrorMessage(error)}
            action={
              <Button size="sm" variant="outline" className="mt-2" onClick={() => refetch()}>
                Coba lagi
              </Button>
            }
          />
        ) : rows.length === 0 ? (
          <EmptyState
            title={`Belum ada ${def.labelPlural.toLowerCase()}`}
            description={search ? 'Coba kata kunci lain.' : undefined}
          />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 bg-slate-50/60 text-left text-xs uppercase tracking-wide text-slate-500">
                  {tableColumns.map((c) => (
                    <th
                      key={c.name}
                      className={`px-4 py-3 font-medium ${c.align === 'right' ? 'text-right' : c.align === 'center' ? 'text-center' : ''}`}
                    >
                      {c.label}
                    </th>
                  ))}
                  {!def.readOnly && <th className="px-4 py-3 text-right font-medium">Aksi</th>}
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={String(row.id)} className="border-b border-slate-50 hover:bg-slate-50/60">
                    {tableColumns.map((c) => (
                      <td
                        key={c.name}
                        className={`px-4 py-3 text-slate-700 ${c.align === 'right' ? 'text-right' : c.align === 'center' ? 'text-center' : ''}`}
                      >
                        <CellValue field={c} row={row} />
                      </td>
                    ))}
                    {!def.readOnly && (
                      <td className="px-4 py-3 text-right">
                        <div className="flex justify-end gap-1">
                          <Button variant="ghost" size="icon" onClick={() => openEdit(row)} title="Edit">
                            <Pencil className="h-4 w-4" />
                          </Button>
                          <Button
                            variant="ghost"
                            size="icon"
                            className="text-red-500 hover:bg-red-50"
                            onClick={() => setDeleting(row)}
                            title="Hapus"
                          >
                            <Trash2 className="h-4 w-4" />
                          </Button>
                        </div>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {meta && <Pagination meta={meta} onPageChange={setPage} />}
      </Card>

      {/* Form modal */}
      <Modal
        open={formOpen}
        onClose={() => {
          setFormOpen(false);
          setEditing(null);
        }}
        title={editing ? `Edit ${def.label}` : `Tambah ${def.label}`}
        size="lg"
      >
        <DynamicForm
          key={editing?.id ?? 'new'}
          fields={def.fields}
          initial={editing ?? undefined}
          submitting={createMutation.isPending || updateMutation.isPending}
          serverErrors={serverErrors}
          submitLabel={editing ? 'Perbarui' : 'Simpan'}
          onSubmit={submitForm}
          onCancel={() => {
            setFormOpen(false);
            setEditing(null);
          }}
        />
      </Modal>

      {/* Konfirmasi hapus */}
      <ConfirmDialog
        open={!!deleting}
        title="Hapus Data"
        message={`Yakin ingin menghapus ${def.label} "${(deleting?.name as string) ?? deleting?.id ?? ''}"? Tindakan ini tidak dapat dibatalkan.`}
        loading={deleteMutation.isPending}
        onConfirm={() => deleting && deleteMutation.mutate(deleting.id)}
        onCancel={() => setDeleting(null)}
      />
    </div>
  );
}
