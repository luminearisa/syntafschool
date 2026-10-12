import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { Plus, Pencil, Trash2, Search, Link2, Eye } from 'lucide-react';
import { toast } from 'sonner';
import { guardiansApi } from '@/features/students/api/guardiansApi';
import { GuardianFormModal } from '@/features/students/components/GuardianFormModal';
import { Can } from '@/components/common/Can';
import { PermissionGuard } from '@/routes/PermissionGuard';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { Pagination } from '@/components/common/Pagination';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { useOptions } from '@/hooks/useOptions';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { Guardian, GuardianPayload } from '@/types';

export function GuardiansPage() {
  const qc = useQueryClient();
  const navigate = useNavigate();

  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [occupationFilter, setOccupationFilter] = useState<number | ''>('');
  const [unlinked, setUnlinked] = useState(false);

  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<Guardian | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [deleteTarget, setDeleteTarget] = useState<Guardian | null>(null);

  const { options: occupationOptions } = useOptions('occupations');

  const listQuery = useQuery({
    queryKey: ['guardians', { page, search, occupationFilter, unlinked }],
    queryFn: () =>
      guardiansApi.list({
        page,
        per_page: 15,
        search: search || undefined,
        occupation_id: occupationFilter,
        unlinked: unlinked || undefined,
      }),
  });

  const guardians = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  const saveMutation = useMutation({
    mutationFn: (payload: GuardianPayload) =>
      editing ? guardiansApi.update(editing.id, payload) : guardiansApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Data wali diperbarui' : 'Wali ditambahkan');
      qc.invalidateQueries({ queryKey: ['guardians'] });
      closeModal();
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => guardiansApi.remove(id),
    onSuccess: () => {
      toast.success('Wali dihapus');
      qc.invalidateQueries({ queryKey: ['guardians'] });
      setDeleteTarget(null);
    },
    onError: (err) => {
      toast.error(getErrorMessage(err));
      setDeleteTarget(null);
    },
  });

  function openCreate() {
    setEditing(null);
    setErrors({});
    setModalOpen(true);
  }

  function openEdit(guardian: Guardian) {
    setEditing(guardian);
    setErrors({});
    setModalOpen(true);
  }

  function closeModal() {
    setModalOpen(false);
    setEditing(null);
  }

  return (
    <PermissionGuard permission="student.view">
      <div className="space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h1 className="text-xl font-semibold text-slate-800">Wali Murid</h1>
            <p className="text-sm text-slate-500">
              Data wali/orang tua. Satu wali dapat terhubung ke beberapa siswa (mis. saudara kandung).
            </p>
          </div>
          <Can perm="student.create">
            <Button onClick={openCreate}>
              <Plus className="h-4 w-4" /> Tambah Wali
            </Button>
          </Can>
        </div>

        <div className="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4">
          <div className="relative min-w-[220px] flex-1">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <Input
              className="pl-9"
              placeholder="Cari nama / telepon / email..."
              value={search}
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(1);
              }}
            />
          </div>
          <div className="w-52">
            <Select
              value={occupationFilter}
              onChange={(e) => {
                setOccupationFilter(e.target.value === '' ? '' : Number(e.target.value));
                setPage(1);
              }}
            >
              <option value="">Semua Pekerjaan</option>
              {occupationOptions.map((o) => (
                <option key={String(o.value)} value={String(o.value)}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
          <label className="flex cursor-pointer items-center gap-2 pb-2 text-sm text-slate-700">
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
              checked={unlinked}
              onChange={(e) => {
                setUnlinked(e.target.checked);
                setPage(1);
              }}
            />
            Belum tertaut siswa
          </label>
        </div>

        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
          {listQuery.isLoading ? (
            <div className="flex justify-center py-16">
              <Spinner />
            </div>
          ) : guardians.length === 0 ? (
            <EmptyState title="Belum ada data wali murid" />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-4 py-3 font-medium">Wali</th>
                    <th className="px-4 py-3 font-medium">Pekerjaan</th>
                    <th className="px-4 py-3 font-medium">Kontak</th>
                    <th className="px-4 py-3 font-medium">Siswa Tertaut</th>
                    <th className="px-4 py-3 text-right font-medium">Aksi</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {guardians.map((g) => (
                    <tr key={g.id} className="hover:bg-slate-50/60">
                      <td className="px-4 py-3">
                        <Link to={`/students/guardians/${g.id}`} className="font-medium text-slate-800 hover:text-brand-700 hover:underline">
                          {g.full_name}
                        </Link>
                        <p className="text-xs text-slate-500">{g.gender?.name ?? '-'}</p>
                      </td>
                      <td className="px-4 py-3 text-slate-600">
                        {g.occupation?.name ?? <span className="text-slate-400">-</span>}
                      </td>
                      <td className="px-4 py-3 text-xs text-slate-600">
                        {g.phone ?? '-'}
                        {g.email ? <span className="block text-slate-400">{g.email}</span> : null}
                      </td>
                      <td className="px-4 py-3">
                        {(g.students ?? []).length === 0 ? (
                          <span className="text-xs text-slate-400">Belum tertaut</span>
                        ) : (
                          <div className="flex flex-wrap items-center gap-1">
                            <Link2 className="h-3.5 w-3.5 text-slate-400" />
                            {(g.students ?? []).map((s) => (
                              <Link key={s.id} to={`/students/${s.id}`} title="Buka profil siswa">
                                <Badge tone={s.is_primary ? 'amber' : 'gray'}>{s.full_name}</Badge>
                              </Link>
                            ))}
                          </div>
                        )}
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex justify-end gap-1">
                          <Button
                            variant="ghost"
                            size="icon"
                            title="Buka profil lengkap"
                            onClick={() => navigate(`/students/guardians/${g.id}`)}
                          >
                            <Eye className="h-4 w-4" />
                          </Button>
                          <Can perm="student.update">
                            <Button
                              variant="ghost"
                              size="icon"
                              title="Ubah"
                              onClick={() => openEdit(g)}
                            >
                              <Pencil className="h-4 w-4" />
                            </Button>
                          </Can>
                          <Can perm="student.delete">
                            <Button
                              variant="ghost"
                              size="icon"
                              title="Hapus"
                              className="text-red-600 hover:bg-red-50"
                              onClick={() => setDeleteTarget(g)}
                            >
                              <Trash2 className="h-4 w-4" />
                            </Button>
                          </Can>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </div>
      </div>

      <GuardianFormModal
        open={modalOpen}
        editing={editing}
        submitting={saveMutation.isPending}
        serverErrors={errors}
        onClose={closeModal}
        onSubmit={(payload) => saveMutation.mutate(payload)}
      />

      <ConfirmDialog
        open={!!deleteTarget}
        title="Hapus Wali Murid"
        message={`Yakin ingin menghapus "${deleteTarget?.full_name}"? Wali yang masih tertaut ke siswa tidak dapat dihapus.`}
        loading={deleteMutation.isPending}
        onConfirm={() => deleteTarget && deleteMutation.mutate(deleteTarget.id)}
        onCancel={() => setDeleteTarget(null)}
      />
    </PermissionGuard>
  );
}

export default GuardiansPage;
