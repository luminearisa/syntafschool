import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { programsApi } from '../api/curriculumApi';
import { ProgramFormModal } from '../components/ProgramFormModal';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Input } from '@/components/ui/Input';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { Program, ProgramPayload } from '@/types/curriculum';

export function ProgramsPage() {
  const queryClient = useQueryClient();

  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<Program | null>(null);
  const [deleting, setDeleting] = useState<Program | null>(null);

  const listQuery = useQuery({
    queryKey: ['programs', search, page],
    queryFn: () => programsApi.list({ search, per_page: 20 }),
  });

  const programs = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['programs'] });

  const createMutation = useMutation({
    mutationFn: (payload: ProgramPayload) => programsApi.create(payload),
    onSuccess: () => {
      toast.success('Program berhasil ditambahkan');
      invalidate();
      setFormOpen(false);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: ProgramPayload }) =>
      programsApi.update(id, payload),
    onSuccess: () => {
      toast.success('Program berhasil diperbarui');
      invalidate();
      setFormOpen(false);
      setEditing(null);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => programsApi.remove(id),
    onSuccess: () => {
      toast.success('Program berhasil dihapus');
      invalidate();
      setDeleting(null);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const submitForm = (payload: ProgramPayload) => {
    if (editing) updateMutation.mutate({ id: editing.id, payload });
    else createMutation.mutate(payload);
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Program & Peminatan</h1>
          <p className="text-sm text-slate-500">Jurusan/peminatan siswa (IPA, IPS, Bahasa).</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
            <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="academic.create">
            <Button
              onClick={() => {
                setEditing(null);
                createMutation.reset();
                updateMutation.reset();
                setFormOpen(true);
              }}
            >
              <Plus className="h-4 w-4" /> Tambah
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-3">
        <Input
          value={search}
          placeholder="Cari kode atau nama program..."
          onChange={(e) => {
            setSearch(e.target.value);
            setPage(1);
          }}
        />
      </Card>

      <Card>
        {listQuery.isLoading ? (
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat program...
          </div>
        ) : listQuery.isError ? (
          <EmptyState
            title="Gagal memuat data"
            description={getErrorMessage(listQuery.error)}
            action={
              <Button size="sm" variant="outline" className="mt-2" onClick={() => listQuery.refetch()}>
                Coba lagi
              </Button>
            }
          />
        ) : programs.length === 0 ? (
          <EmptyState title="Belum ada program" />
        ) : (
          <>
            <ul className="divide-y divide-slate-100">
              {programs.map((program) => (
                <li key={program.id} className="flex items-center justify-between gap-3 px-4 py-3">
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="text-sm font-medium text-slate-800">{program.name}</span>
                      <Badge tone="gray">{program.code}</Badge>
                      {program.education_level && (
                        <Badge tone="blue">{program.education_level.name}</Badge>
                      )}
                      {!program.is_active && <Badge tone="amber">Nonaktif</Badge>}
                    </div>
                    {program.description && (
                      <p className="mt-0.5 text-xs text-slate-500">{program.description}</p>
                    )}
                  </div>
                  <div className="flex shrink-0 gap-1">
                    <Can perm="academic.update">
                      <Button
                        variant="ghost"
                        size="icon"
                        title="Edit"
                        onClick={() => {
                          setEditing(program);
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
                        title="Hapus"
                        onClick={() => setDeleting(program)}
                      >
                        <Trash2 className="h-4 w-4 text-red-500" />
                      </Button>
                    </Can>
                  </div>
                </li>
              ))}
            </ul>
            {meta && <Pagination meta={meta} onPageChange={setPage} />}
          </>
        )}
      </Card>

      <ProgramFormModal
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

      <ConfirmDialog
        open={!!deleting}
        title="Hapus Program"
        message={`Yakin ingin menghapus program "${deleting?.name ?? ''}"?`}
        loading={deleteMutation.isPending}
        onConfirm={() => deleting && deleteMutation.mutate(deleting.id)}
        onCancel={() => setDeleting(null)}
      />
    </div>
  );
}
