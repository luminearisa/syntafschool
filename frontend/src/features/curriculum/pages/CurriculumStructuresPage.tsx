import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Copy, Pencil, Plus, RefreshCw, Rocket, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { curriculumStructuresApi } from '../api/curriculumApi';
import { StructureFormModal } from '../components/StructureFormModal';
import { ApplyCurriculumModal } from '../components/ApplyCurriculumModal';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { useOptions } from '@/hooks/useOptions';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { CurriculumStructure, CurriculumStructurePayload } from '@/types/curriculum';

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

export function CurriculumStructuresPage() {
  const queryClient = useQueryClient();

  const [curriculumId, setCurriculumId] = useState<number | ''>('');
  const [levelId, setLevelId] = useState<number | ''>('');
  const [page, setPage] = useState(1);

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<CurriculumStructure | null>(null);
  const [deleting, setDeleting] = useState<CurriculumStructure | null>(null);
  const [duplicating, setDuplicating] = useState<CurriculumStructure | null>(null);
  const [duplicateVersion, setDuplicateVersion] = useState<number | ''>('');
  const [duplicateName, setDuplicateName] = useState('');
  const [applyOpen, setApplyOpen] = useState(false);

  const { options: curriculumOptions } = useOptions('curricula');
  const { options: levelOptions } = useOptions('education-levels');

  const listQuery = useQuery({
    queryKey: ['curriculum-structures', curriculumId, levelId, page],
    queryFn: () =>
      curriculumStructuresApi.list({
        curriculum_id: curriculumId,
        education_level_id: levelId,
        per_page: 20,
      }),
  });

  const structures = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['curriculum-structures'] });

  const createMutation = useMutation({
    mutationFn: (payload: CurriculumStructurePayload) => curriculumStructuresApi.create(payload),
    onSuccess: () => {
      toast.success('Struktur kurikulum berhasil ditambahkan');
      invalidate();
      setFormOpen(false);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: CurriculumStructurePayload }) =>
      curriculumStructuresApi.update(id, payload),
    onSuccess: () => {
      toast.success('Struktur kurikulum berhasil diperbarui');
      invalidate();
      setFormOpen(false);
      setEditing(null);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => curriculumStructuresApi.remove(id),
    onSuccess: () => {
      toast.success('Struktur kurikulum berhasil dihapus');
      invalidate();
      setDeleting(null);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const duplicateMutation = useMutation({
    mutationFn: ({ id, version, name }: { id: number; version: number; name?: string }) =>
      curriculumStructuresApi.duplicate(id, { version, name }),
    onSuccess: () => {
      toast.success('Struktur kurikulum berhasil diduplikasi');
      invalidate();
      setDuplicating(null);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const submitForm = (payload: CurriculumStructurePayload) => {
    if (editing) updateMutation.mutate({ id: editing.id, payload });
    else createMutation.mutate(payload);
  };

  const openDuplicate = (structure: CurriculumStructure) => {
    duplicateMutation.reset();
    setDuplicateVersion(structure.version + 1);
    setDuplicateName(`${structure.name} (v${structure.version + 1})`);
    setDuplicating(structure);
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Struktur Kurikulum</h1>
          <p className="text-sm text-slate-500">
            Susun mapel, kelompok, semester, dan alokasi JP per jenjang.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button
            variant="ghost"
            size="icon"
            title="Muat ulang"
            onClick={() => listQuery.refetch()}
          >
            <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="academic.create">
            <Button variant="outline" onClick={() => setApplyOpen(true)}>
              <Rocket className="h-4 w-4" /> Terapkan ke Kelas
            </Button>
          </Can>
          <Can perm="academic.create">
            <Button
              onClick={() => {
                setEditing(null);
                createMutation.reset();
                updateMutation.reset();
                setFormOpen(true);
              }}
            >
              <Plus className="h-4 w-4" /> Tambah Struktur
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
          <div className="w-full sm:w-64">
            <label className="mb-1 block text-xs font-medium text-slate-600">Kurikulum</label>
            <SearchSelect
              options={curriculumOptions}
              value={curriculumId}
              onChange={(v) => {
                setCurriculumId(toId(v));
                setPage(1);
              }}
              placeholder="-- Semua Kurikulum --"
            />
          </div>
          <div className="w-full sm:w-64">
            <label className="mb-1 block text-xs font-medium text-slate-600">Jenjang</label>
            <SearchSelect
              options={levelOptions}
              value={levelId}
              onChange={(v) => {
                setLevelId(toId(v));
                setPage(1);
              }}
              placeholder="-- Semua Jenjang --"
            />
          </div>
        </div>
      </Card>

      <Card>
        {listQuery.isLoading ? (
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat struktur kurikulum...
          </div>
        ) : listQuery.isError ? (
          <EmptyState
            title="Gagal memuat struktur"
            description={getErrorMessage(listQuery.error)}
            action={
              <Button size="sm" variant="outline" className="mt-2" onClick={() => listQuery.refetch()}>
                Coba lagi
              </Button>
            }
          />
        ) : structures.length === 0 ? (
          <EmptyState
            title="Belum ada struktur kurikulum"
            description="Tambahkan struktur untuk menentukan mapel per jenjang."
          />
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                    <th className="px-4 py-2 font-medium">Nama</th>
                    <th className="px-4 py-2 font-medium">Kurikulum</th>
                    <th className="px-4 py-2 font-medium">Jenjang</th>
                    <th className="px-4 py-2 font-medium">Versi</th>
                    <th className="px-4 py-2 font-medium">Mapel</th>
                    <th className="px-4 py-2 font-medium">Total JP</th>
                    <th className="px-4 py-2 font-medium">Status</th>
                    <th className="px-4 py-2 text-right font-medium">Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  {structures.map((structure) => (
                    <tr key={structure.id} className="border-b border-slate-50 last:border-0">
                      <td className="px-4 py-2 font-medium text-slate-800">{structure.name}</td>
                      <td className="px-4 py-2 text-slate-600">
                        {structure.curriculum?.name ?? '-'}
                      </td>
                      <td className="px-4 py-2 text-slate-600">
                        {structure.education_level?.name ?? '-'}
                      </td>
                      <td className="px-4 py-2">
                        <Badge tone="brand">v{structure.version}</Badge>
                      </td>
                      <td className="px-4 py-2 text-slate-600">{structure.items_count ?? 0}</td>
                      <td className="px-4 py-2 text-slate-600">
                        {structure.total_weekly_hours ?? 0}
                      </td>
                      <td className="px-4 py-2">
                        {structure.is_active ? (
                          <Badge tone="green">Aktif</Badge>
                        ) : (
                          <Badge tone="amber">Nonaktif</Badge>
                        )}
                      </td>
                      <td className="px-4 py-2">
                        <div className="flex justify-end gap-1">
                          <Can perm="academic.create">
                            <Button
                              variant="ghost"
                              size="icon"
                              title="Duplikasi ke versi baru"
                              onClick={() => openDuplicate(structure)}
                            >
                              <Copy className="h-4 w-4" />
                            </Button>
                          </Can>
                          <Can perm="academic.update">
                            <Button
                              variant="ghost"
                              size="icon"
                              title="Edit struktur"
                              onClick={() => {
                                setEditing(structure);
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
                              title="Hapus struktur"
                              onClick={() => setDeleting(structure)}
                            >
                              <Trash2 className="h-4 w-4 text-red-500" />
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
          </>
        )}
      </Card>

      <StructureFormModal
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

      <ApplyCurriculumModal open={applyOpen} onClose={() => setApplyOpen(false)} />

      <Modal
        open={!!duplicating}
        onClose={() => setDuplicating(null)}
        title="Duplikasi Struktur Kurikulum"
        size="sm"
        footer={
          <>
            <Button variant="outline" onClick={() => setDuplicating(null)} disabled={duplicateMutation.isPending}>
              Batal
            </Button>
            <Button
              loading={duplicateMutation.isPending}
              onClick={() =>
                duplicating &&
                duplicateMutation.mutate({
                  id: duplicating.id,
                  version: Number(duplicateVersion),
                  name: duplicateName || undefined,
                })
              }
            >
              Duplikasi
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <p className="text-sm text-slate-600">
            Seluruh mapel pada struktur ini akan disalin ke versi baru.
          </p>
          <div>
            <Label required>Versi Baru</Label>
            <Input
              type="number"
              min={1}
              value={duplicateVersion}
              error={getValidationErrors(duplicateMutation.error)?.version?.[0]}
              onChange={(e) =>
                setDuplicateVersion(e.target.value === '' ? '' : Number(e.target.value))
              }
            />
          </div>
          <div>
            <Label>Nama</Label>
            <Input
              value={duplicateName}
              error={getValidationErrors(duplicateMutation.error)?.name?.[0]}
              onChange={(e) => setDuplicateName(e.target.value)}
            />
          </div>
        </div>
      </Modal>

      <ConfirmDialog
        open={!!deleting}
        title="Hapus Struktur Kurikulum"
        message={`Yakin ingin menghapus struktur "${deleting?.name ?? ''}"? Struktur yang sudah dipakai kelas tidak bisa dihapus.`}
        loading={deleteMutation.isPending}
        onConfirm={() => deleting && deleteMutation.mutate(deleting.id)}
        onCancel={() => setDeleting(null)}
      />
    </div>
  );
}
