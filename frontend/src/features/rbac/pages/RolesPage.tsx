import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus, Pencil, Trash2, ShieldCheck } from 'lucide-react';
import { toast } from 'sonner';
import { rbacApi } from '@/features/rbac/api/rbacApi';
import { Can } from '@/components/common/Can';
import { PermissionGuard } from '@/routes/PermissionGuard';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Badge } from '@/components/ui/Badge';
import { Modal } from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { Role } from '@/types';

const LANGUAGE: Record<string, string> = {
  view: 'Lihat',
  create: 'Tambah',
  update: 'Ubah',
  delete: 'Hapus',
  import: 'Impor',
  export: 'Ekspor',
  verify: 'Verifikasi',
};

const MODULE_LABEL: Record<string, string> = {
  'master-data': 'Master Data',
  academic: 'Akademik',
  student: 'Kesiswaan',
  employee: 'Kepegawaian',
  finance: 'Keuangan',
  assessment: 'Penilaian',
  tahfiz: 'Tahfiz / Al-Quran',
  boarding: 'Boarding / Pesantren',
  library: 'Perpustakaan',
  inventory: 'Inventaris',
  ppdb: 'PPDB',
  report: 'Laporan',
  user: 'Pengguna & Role',
  setting: 'Pengaturan',
};

const MODULE_ORDER = [
  'master-data',
  'academic',
  'student',
  'employee',
  'finance',
  'assessment',
  'tahfiz',
  'boarding',
  'library',
  'inventory',
  'ppdb',
  'report',
  'user',
  'setting',
];

export function RolesPage() {
  const qc = useQueryClient();

  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<Role | null>(null);
  const [name, setName] = useState('');
  const [selected, setSelected] = useState<string[]>([]);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [deleteTarget, setDeleteTarget] = useState<Role | null>(null);

  const rolesQuery = useQuery({
    queryKey: ['roles', 'list'],
    queryFn: () => rbacApi.listRoles({ per_page: 100 }),
  });

  const permQuery = useQuery({
    queryKey: ['permissions'],
    queryFn: () => rbacApi.listPermissions(),
  });

  const grouped = permQuery.data?.grouped ?? {};
  const modules = MODULE_ORDER.filter((m) => grouped[m]);

  const saveMutation = useMutation({
    mutationFn: () =>
      editing
        ? rbacApi.updateRole(editing.id, { name, permissions: selected })
        : rbacApi.createRole({ name, permissions: selected }),
    onSuccess: () => {
      toast.success(editing ? 'Role diperbarui' : 'Role dibuat');
      qc.invalidateQueries({ queryKey: ['roles'] });
      closeModal();
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => rbacApi.removeRole(id),
    onSuccess: () => {
      toast.success('Role dihapus');
      qc.invalidateQueries({ queryKey: ['roles'] });
      setDeleteTarget(null);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const roles = rolesQuery.data?.data ?? [];
  const err = (key: string) => errors[key]?.[0];

  function openCreate() {
    setEditing(null);
    setName('');
    setSelected([]);
    setErrors({});
    setModalOpen(true);
  }

  function openEdit(role: Role) {
    setEditing(role);
    setName(role.name);
    setSelected(role.permissions ?? []);
    setErrors({});
    setModalOpen(true);
  }

  function closeModal() {
    setModalOpen(false);
    setEditing(null);
  }

  function toggle(perm: string) {
    setSelected((s) =>
      s.includes(perm) ? s.filter((p) => p !== perm) : [...s, perm],
    );
  }

  function toggleModule(module: string) {
    const perms = grouped[module] ?? [];
    const allOn = perms.every((p) => selected.includes(p));
    setSelected((s) =>
      allOn
        ? s.filter((p) => !perms.includes(p))
        : Array.from(new Set([...s, ...perms])),
    );
  }

  function submit() {
    if (!name.trim()) {
      toast.error('Nama role wajib diisi');
      return;
    }
    saveMutation.mutate();
  }

  const isProtected = (role: Role) =>
    ['super-admin', 'admin-sekolah'].includes(role.name);

  return (
    <PermissionGuard permission="user.view">
      <div className="space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h1 className="text-xl font-semibold text-slate-800">
              Role & Hak Akses
            </h1>
            <p className="text-sm text-slate-500">
              Atur role dan permission yang dimilikinya.
            </p>
          </div>
          <Can perm="user.create">
            <Button onClick={openCreate}>
              <Plus className="h-4 w-4" /> Tambah Role
            </Button>
          </Can>
        </div>

        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
          {rolesQuery.isLoading ? (
            <div className="flex justify-center py-16">
              <Spinner />
            </div>
          ) : roles.length === 0 ? (
            <EmptyState title="Belum ada role" />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-4 py-3 font-medium">Role</th>
                    <th className="px-4 py-3 font-medium">Jumlah Permission</th>
                    <th className="px-4 py-3 font-medium">Pengguna</th>
                    <th className="px-4 py-3 text-right font-medium">Aksi</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {roles.map((role) => (
                    <tr key={role.id} className="hover:bg-slate-50/60">
                      <td className="px-4 py-3">
                        <div className="flex items-center gap-2">
                          <ShieldCheck className="h-4 w-4 text-brand-600" />
                          <span className="font-medium text-slate-800">
                            {role.name}
                          </span>
                          {isProtected(role) && <Badge tone="amber">Sistem</Badge>}
                        </div>
                      </td>
                      <td className="px-4 py-3 text-slate-600">
                        {role.permissions?.length ?? 0}
                      </td>
                      <td className="px-4 py-3 text-slate-600">
                        {role.users_count ?? 0}
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex justify-end gap-1">
                          <Can perm="user.update">
                            <Button
                              variant="ghost"
                              size="icon"
                              title="Ubah"
                              onClick={() => openEdit(role)}
                            >
                              <Pencil className="h-4 w-4" />
                            </Button>
                          </Can>
                          <Can perm="user.delete">
                            <Button
                              variant="ghost"
                              size="icon"
                              title="Hapus"
                              className="text-red-600 hover:bg-red-50"
                              disabled={isProtected(role)}
                              onClick={() => setDeleteTarget(role)}
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
        </div>
      </div>

      <Modal
        open={modalOpen}
        onClose={closeModal}
        title={editing ? 'Ubah Role' : 'Tambah Role'}
        size="xl"
        footer={
          <>
            <Button variant="outline" onClick={closeModal}>
              Batal
            </Button>
            <Button onClick={submit} loading={saveMutation.isPending}>
              Simpan
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <div>
            <Label htmlFor="role-name">Nama Role</Label>
            <Input
              id="role-name"
              value={name}
              error={err('name')}
              disabled={editing?.name === 'super-admin'}
              onChange={(e) => setName(e.target.value)}
            />
            {err('name') && (
              <p className="mt-1 text-xs text-red-600">{err('name')}</p>
            )}
          </div>

          <div>
            <Label>Permission</Label>
            <div className="mt-2 space-y-3">
              {modules.map((module) => {
                const perms = grouped[module] ?? [];
                const allOn = perms.every((p) => selected.includes(p));
                return (
                  <div
                    key={module}
                    className="rounded-lg border border-slate-200 p-3"
                  >
                    <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
                      <input
                        type="checkbox"
                        className="h-4 w-4 rounded border-slate-300"
                        checked={allOn}
                        onChange={() => toggleModule(module)}
                      />
                      {MODULE_LABEL[module] ?? module}
                    </label>
                    <div className="mt-2 grid grid-cols-2 gap-2 pl-6 sm:grid-cols-3">
                      {perms.map((perm) => {
                        const action = perm.split('.')[1];
                        return (
                          <label
                            key={perm}
                            className="flex items-center gap-2 text-sm text-slate-600"
                          >
                            <input
                              type="checkbox"
                              className="h-4 w-4 rounded border-slate-300"
                              checked={selected.includes(perm)}
                              onChange={() => toggle(perm)}
                            />
                            {LANGUAGE[action] ?? action}
                          </label>
                        );
                      })}
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
        </div>
      </Modal>

      <ConfirmDialog
        open={!!deleteTarget}
        title="Hapus Role"
        message={`Yakin ingin menghapus role "${deleteTarget?.name}"?`}
        loading={deleteMutation.isPending}
        onConfirm={() => deleteTarget && deleteMutation.mutate(deleteTarget.id)}
        onCancel={() => setDeleteTarget(null)}
      />
    </PermissionGuard>
  );
}

export default RolesPage;
