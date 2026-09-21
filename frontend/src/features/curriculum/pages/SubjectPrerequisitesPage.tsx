import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowRight, Pencil, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { subjectPrerequisitesApi } from '../api/curriculumApi';
import { SubjectPrerequisiteFormModal } from '../components/SubjectPrerequisiteFormModal';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { useOptions } from '@/hooks/useOptions';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { SubjectPrerequisite, SubjectPrerequisitePayload } from '@/types/curriculum';

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

export function SubjectPrerequisitesPage() {
  const queryClient = useQueryClient();

  const [subjectId, setSubjectId] = useState<number | ''>('');
  const [page, setPage] = useState(1);
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<SubjectPrerequisite | null>(null);
  const [deleting, setDeleting] = useState<SubjectPrerequisite | null>(null);

  const { options: subjectOptions } = useOptions('subjects');

  const listQuery = useQuery({
    queryKey: ['subject-prerequisites', subjectId, page],
    queryFn: () => subjectPrerequisitesApi.list({ subject_id: subjectId, per_page: 20 }),
  });

  const prerequisites = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['subject-prerequisites'] });

  const createMutation = useMutation({
    mutationFn: (payload: SubjectPrerequisitePayload) => subjectPrerequisitesApi.create(payload),
    onSuccess: () => {
      toast.success('Prasyarat mapel berhasil ditambahkan');
      invalidate();
      setFormOpen(false);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: SubjectPrerequisitePayload }) =>
      subjectPrerequisitesApi.update(id, payload),
    onSuccess: () => {
      toast.success('Prasyarat mapel berhasil diperbarui');
      invalidate();
      setFormOpen(false);
      setEditing(null);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => subjectPrerequisitesApi.remove(id),
    onSuccess: () => {
      toast.success('Prasyarat mapel berhasil dihapus');
      invalidate();
      setDeleting(null);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const submitForm = (payload: SubjectPrerequisitePayload) => {
    if (editing) updateMutation.mutate({ id: editing.id, payload });
    else createMutation.mutate(payload);
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Prasyarat Mapel</h1>
          <p className="text-sm text-slate-500">
            Mapel yang harus ditempuh sebelum mengambil mapel tertentu.
          </p>
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
        <div className="w-full sm:w-72">
          <label className="mb-1 block text-xs font-medium text-slate-600">Filter Mapel</label>
          <SearchSelect
            options={subjectOptions}
            value={subjectId}
            onChange={(v) => {
              setSubjectId(toId(v));
              setPage(1);
            }}
            placeholder="-- Semua Mapel --"
          />
        </div>
      </Card>

      <Card>
        {listQuery.isLoading ? (
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat prasyarat mapel...
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
        ) : prerequisites.length === 0 ? (
          <EmptyState title="Belum ada prasyarat mapel" />
        ) : (
          <>
            <ul className="divide-y divide-slate-100">
              {prerequisites.map((row) => (
                <li key={row.id} className="flex items-center justify-between gap-3 px-4 py-3">
                  <div className="flex min-w-0 flex-wrap items-center gap-2">
                    <Badge tone="brand">{row.subject?.code ?? row.subject_id}</Badge>
                    <span className="text-sm font-medium text-slate-800">
                      {row.subject?.name ?? '-'}
                    </span>
                    <ArrowRight className="h-3.5 w-3.5 text-slate-400" />
                    <Badge tone="gray">{row.prerequisite?.code ?? row.prerequisite_subject_id}</Badge>
                    <span className="text-sm text-slate-600">
                      {row.prerequisite?.name ?? '-'}
                    </span>
                  </div>
                  <div className="flex shrink-0 gap-1">
                    <Can perm="academic.update">
                      <Button
                        variant="ghost"
                        size="icon"
                        title="Edit"
                        onClick={() => {
                          setEditing(row);
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
                        onClick={() => setDeleting(row)}
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

      <SubjectPrerequisiteFormModal
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
        title="Hapus Prasyarat"
        message={`Yakin ingin menghapus prasyarat "${deleting?.prerequisite?.name ?? ''}" untuk "${deleting?.subject?.name ?? ''}"?`}
        loading={deleteMutation.isPending}
        onConfirm={() => deleting && deleteMutation.mutate(deleting.id)}
        onCancel={() => setDeleting(null)}
      />
    </div>
  );
}
