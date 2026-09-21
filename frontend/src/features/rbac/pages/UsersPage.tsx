import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus, Pencil, Trash2, Search, Power } from 'lucide-react';
import { toast } from 'sonner';
import { rbacApi } from '@/features/rbac/api/rbacApi';
import { Can } from '@/components/common/Can';
import { PermissionGuard } from '@/routes/PermissionGuard';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Label } from '@/components/ui/Label';
import { Badge } from '@/components/ui/Badge';
import { Modal } from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { Pagination } from '@/components/common/Pagination';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { User, UserPayload } from '@/types';

interface FormState {
  name: string;
  username: string;
  email: string;
  password: string;
  phone: string;
  is_active: boolean;
  roles: string[];
}

const emptyForm: FormState = {
  name: '',
  username: '',
  email: '',
  password: '',
  phone: '',
  is_active: true,
  roles: [],
};

export function UsersPage() {
  const qc = useQueryClient();

  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [roleFilter, setRoleFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');

  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<User | null>(null);
  const [form, setForm] = useState<FormState>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [deleteTarget, setDeleteTarget] = useState<User | null>(null);

  const { data: roles } = useQuery({
    queryKey: ['roles', 'all'],
    queryFn: () => rbacApi.allRoles(),
  });

  const listQuery = useQuery({
    queryKey: ['users', { page, search, roleFilter, statusFilter }],
    queryFn: () =>
      rbacApi.listUsers({
        page,
        per_page: 15,
        search: search || undefined,
        role: roleFilter || undefined,
        is_active:
          statusFilter === '' ? undefined : statusFilter === 'active',
      }),
  });

  const users = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  const saveMutation = useMutation({
    mutationFn: (payload: UserPayload) =>
      editing
        ? rbacApi.updateUser(editing.id, payload)
        : rbacApi.createUser(payload),
    onSuccess: () => {
      toast.success(editing ? 'Pengguna diperbarui' : 'Pengguna dibuat');
      qc.invalidateQueries({ queryKey: ['users'] });
      closeModal();
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const toggleMutation = useMutation({
    mutationFn: (id: number) => rbacApi.toggleUserActive(id),
    onSuccess: () => {
      toast.success('Status pengguna diubah');
      qc.invalidateQueries({ queryKey: ['users'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => rbacApi.removeUser(id),
    onSuccess: () => {
      toast.success('Pengguna dihapus');
      qc.invalidateQueries({ queryKey: ['users'] });
      setDeleteTarget(null);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const roleOptions = useMemo(
    () => roles?.map((r) => ({ value: r.name, label: r.name })) ?? [],
    [roles],
  );

  function openCreate() {
    setEditing(null);
    setForm(emptyForm);
    setErrors({});
    setModalOpen(true);
  }

  function openEdit(user: User) {
    setEditing(user);
    setForm({
      name: user.name,
      username: user.username ?? '',
      email: user.email,
      password: '',
      phone: user.phone ?? '',
      is_active: user.is_active,
      roles: user.roles ?? [],
    });
    setErrors({});
    setModalOpen(true);
  }

  function closeModal() {
    setModalOpen(false);
    setEditing(null);
  }

  function submit() {
    if (!form.name.trim() || !form.email.trim()) {
      toast.error('Nama dan email wajib diisi');
      return;
    }
    if (!editing && !form.password) {
      toast.error('Password wajib diisi untuk pengguna baru');
      return;
    }

    const payload: UserPayload = {
      name: form.name.trim(),
      username: form.username.trim() || null,
      email: form.email.trim(),
      phone: form.phone.trim() || null,
      is_active: form.is_active,
      roles: form.roles,
    };
    if (form.password) payload.password = form.password;

    saveMutation.mutate(payload);
  }

  function toggleRole(name: string) {
    setForm((f) => ({
      ...f,
      roles: f.roles.includes(name)
        ? f.roles.filter((r) => r !== name)
        : [...f.roles, name],
    }));
  }

  const err = (key: string) => errors[key]?.[0];

  return (
    <PermissionGuard permission="user.view">
      <div className="space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h1 className="text-xl font-semibold text-slate-800">Pengguna</h1>
            <p className="text-sm text-slate-500">
              Kelola akun pengguna dan peran (role) mereka.
            </p>
          </div>
          <Can perm="user.create">
            <Button onClick={openCreate}>
              <Plus className="h-4 w-4" /> Tambah Pengguna
            </Button>
          </Can>
        </div>

        <div className="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4">
          <div className="relative min-w-[220px] flex-1">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <Input
              className="pl-9"
              placeholder="Cari nama / email / username..."
              value={search}
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(1);
              }}
            />
          </div>
          <div className="w-44">
            <Select
              value={roleFilter}
              onChange={(e) => {
                setRoleFilter(e.target.value);
                setPage(1);
              }}
            >
              <option value="">Semua Role</option>
              {roleOptions.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
          <div className="w-36">
            <Select
              value={statusFilter}
              onChange={(e) => {
                setStatusFilter(e.target.value);
                setPage(1);
              }}
            >
              <option value="">Semua Status</option>
              <option value="active">Aktif</option>
              <option value="inactive">Nonaktif</option>
            </Select>
          </div>
        </div>

        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
          {listQuery.isLoading ? (
            <div className="flex justify-center py-16">
              <Spinner />
            </div>
          ) : users.length === 0 ? (
            <EmptyState title="Belum ada pengguna" />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-4 py-3 font-medium">Pengguna</th>
                    <th className="px-4 py-3 font-medium">Role</th>
                    <th className="px-4 py-3 font-medium">Status</th>
                    <th className="px-4 py-3 text-right font-medium">Aksi</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {users.map((u) => (
                    <tr key={u.id} className="hover:bg-slate-50/60">
                      <td className="px-4 py-3">
                        <p className="font-medium text-slate-800">{u.name}</p>
                        <p className="text-xs text-slate-500">{u.email}</p>
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex flex-wrap gap-1">
                          {(u.roles ?? []).map((r) => (
                            <Badge key={r} tone="brand">
                              {r}
                            </Badge>
                          ))}
                        </div>
                      </td>
                      <td className="px-4 py-3">
                        {u.is_active ? (
                          <Badge tone="green">Aktif</Badge>
                        ) : (
                          <Badge tone="red">Nonaktif</Badge>
                        )}
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex justify-end gap-1">
                          <Can perm="user.update">
                            <Button
                              variant="ghost"
                              size="icon"
                              title="Ubah status"
                              onClick={() => toggleMutation.mutate(u.id)}
                            >
                              <Power className="h-4 w-4" />
                            </Button>
                          </Can>
                          <Can perm="user.update">
                            <Button
                              variant="ghost"
                              size="icon"
                              title="Ubah"
                              onClick={() => openEdit(u)}
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
                              onClick={() => setDeleteTarget(u)}
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

      <Modal
        open={modalOpen}
        onClose={closeModal}
        title={editing ? 'Ubah Pengguna' : 'Tambah Pengguna'}
        size="lg"
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
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="sm:col-span-2">
            <Label htmlFor="name">Nama Lengkap</Label>
            <Input
              id="name"
              value={form.name}
              error={err('name')}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
            />
            {err('name') && <p className="mt-1 text-xs text-red-600">{err('name')}</p>}
          </div>
          <div>
            <Label htmlFor="email">Email</Label>
            <Input
              id="email"
              type="email"
              value={form.email}
              error={err('email')}
              onChange={(e) => setForm({ ...form, email: e.target.value })}
            />
            {err('email') && <p className="mt-1 text-xs text-red-600">{err('email')}</p>}
          </div>
          <div>
            <Label htmlFor="username">Username</Label>
            <Input
              id="username"
              value={form.username}
              error={err('username')}
              onChange={(e) => setForm({ ...form, username: e.target.value })}
            />
            {err('username') && <p className="mt-1 text-xs text-red-600">{err('username')}</p>}
          </div>
          <div>
            <Label htmlFor="phone">Telepon</Label>
            <Input
              id="phone"
              value={form.phone}
              onChange={(e) => setForm({ ...form, phone: e.target.value })}
            />
          </div>
          <div>
            <Label htmlFor="password">
              Password {editing && <span className="text-slate-400">(kosongkan bila tidak diubah)</span>}
            </Label>
            <Input
              id="password"
              type="password"
              value={form.password}
              error={err('password')}
              onChange={(e) => setForm({ ...form, password: e.target.value })}
            />
            {err('password') && <p className="mt-1 text-xs text-red-600">{err('password')}</p>}
          </div>
          <div className="flex items-end">
            <label className="flex items-center gap-2 text-sm text-slate-700">
              <input
                type="checkbox"
                className="h-4 w-4 rounded border-slate-300"
                checked={form.is_active}
                onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
              />
              Akun aktif
            </label>
          </div>
          <div className="sm:col-span-2">
            <Label>Role</Label>
            <div className="mt-1 grid grid-cols-2 gap-2 rounded-lg border border-slate-200 p-3 sm:grid-cols-3">
              {roleOptions.map((o) => (
                <label
                  key={o.value}
                  className="flex items-center gap-2 text-sm text-slate-700"
                >
                  <input
                    type="checkbox"
                    className="h-4 w-4 rounded border-slate-300"
                    checked={form.roles.includes(o.value)}
                    onChange={() => toggleRole(o.value)}
                  />
                  {o.label}
                </label>
              ))}
            </div>
          </div>
        </div>
      </Modal>

      <ConfirmDialog
        open={!!deleteTarget}
        title="Hapus Pengguna"
        message={`Yakin ingin menghapus "${deleteTarget?.name}"? Tindakan ini tidak dapat dibatalkan.`}
        loading={deleteMutation.isPending}
        onConfirm={() => deleteTarget && deleteMutation.mutate(deleteTarget.id)}
        onCancel={() => setDeleteTarget(null)}
      />
    </PermissionGuard>
  );
}

export default UsersPage;
