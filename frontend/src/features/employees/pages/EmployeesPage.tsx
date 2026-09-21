import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus, Pencil, Trash2, Search, Power, GraduationCap } from 'lucide-react';
import { toast } from 'sonner';
import { employeesApi } from '@/features/employees/api/employeesApi';
import { EmployeeFormModal } from '@/features/employees/components/EmployeeFormModal';
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
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { Employee, EmployeePayload, EmployeeType, EmploymentStatus } from '@/types';

const EMPLOYMENT_STATUS_TONES: Record<string, 'blue' | 'green' | 'amber' | 'gray'> = {
  PNS: 'blue',
  PPPK: 'green',
  GTY: 'amber',
  Honorer: 'gray',
};

export function EmployeesPage() {
  const qc = useQueryClient();

  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [typeFilter, setTypeFilter] = useState<EmployeeType | ''>('');
  const [statusFilter, setStatusFilter] = useState<EmploymentStatus | ''>('');
  const [activeFilter, setActiveFilter] = useState('');

  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<Employee | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [deleteTarget, setDeleteTarget] = useState<Employee | null>(null);

  const listQuery = useQuery({
    queryKey: ['employees', { page, search, typeFilter, statusFilter, activeFilter }],
    queryFn: () =>
      employeesApi.list({
        page,
        per_page: 15,
        search: search || undefined,
        employee_type: typeFilter || undefined,
        employment_status: statusFilter || undefined,
        is_active: activeFilter === '' ? undefined : activeFilter === 'active',
      }),
  });

  const employees = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  const saveMutation = useMutation({
    mutationFn: (payload: EmployeePayload) =>
      editing ? employeesApi.update(editing.id, payload) : employeesApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Pegawai diperbarui' : 'Pegawai ditambahkan');
      qc.invalidateQueries({ queryKey: ['employees'] });
      qc.invalidateQueries({ queryKey: ['lookup', 'employees'] });
      closeModal();
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const toggleMutation = useMutation({
    mutationFn: (id: number) => employeesApi.toggleActive(id),
    onSuccess: () => {
      toast.success('Status pegawai diubah');
      qc.invalidateQueries({ queryKey: ['employees'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => employeesApi.remove(id),
    onSuccess: () => {
      toast.success('Pegawai dihapus');
      qc.invalidateQueries({ queryKey: ['employees'] });
      qc.invalidateQueries({ queryKey: ['lookup', 'employees'] });
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

  function openEdit(employee: Employee) {
    setEditing(employee);
    setErrors({});
    setModalOpen(true);
  }

  function closeModal() {
    setModalOpen(false);
    setEditing(null);
  }

  return (
    <PermissionGuard permission="employee.view">
      <div className="space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h1 className="text-xl font-semibold text-slate-800">Data Pegawai</h1>
            <p className="text-sm text-slate-500">
              Kelola data guru dan staf, termasuk penugasan wali kelas dan pengajar.
            </p>
          </div>
          <Can perm="employee.create">
            <Button onClick={openCreate}>
              <Plus className="h-4 w-4" /> Tambah Pegawai
            </Button>
          </Can>
        </div>

        <div className="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4">
          <div className="relative min-w-[220px] flex-1">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <Input
              className="pl-9"
              placeholder="Cari nama / NIP / email..."
              value={search}
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(1);
              }}
            />
          </div>
          <div className="w-40">
            <Select
              value={typeFilter}
              onChange={(e) => {
                setTypeFilter(e.target.value as EmployeeType | '');
                setPage(1);
              }}
            >
              <option value="">Semua Jenis</option>
              <option value="guru">Guru</option>
              <option value="staf">Staf</option>
            </Select>
          </div>
          <div className="w-44">
            <Select
              value={statusFilter}
              onChange={(e) => {
                setStatusFilter(e.target.value as EmploymentStatus | '');
                setPage(1);
              }}
            >
              <option value="">Semua Status Kepegawaian</option>
              <option value="PNS">PNS</option>
              <option value="PPPK">PPPK</option>
              <option value="GTY">GTY</option>
              <option value="Honorer">Honorer</option>
            </Select>
          </div>
          <div className="w-36">
            <Select
              value={activeFilter}
              onChange={(e) => {
                setActiveFilter(e.target.value);
                setPage(1);
              }}
            >
              <option value="">Semua</option>
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
          ) : employees.length === 0 ? (
            <EmptyState title="Belum ada data pegawai" />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-4 py-3 font-medium">Pegawai</th>
                    <th className="px-4 py-3 font-medium">Jabatan</th>
                    <th className="px-4 py-3 font-medium">Jenis</th>
                    <th className="px-4 py-3 font-medium">Status</th>
                    <th className="px-4 py-3 font-medium">Akun</th>
                    <th className="px-4 py-3 text-right font-medium">Aksi</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {employees.map((emp) => (
                    <tr key={emp.id} className="hover:bg-slate-50/60">
                      <td className="px-4 py-3">
                        <p className="font-medium text-slate-800">{emp.full_name}</p>
                        <p className="text-xs text-slate-500">
                          {emp.nip ?? 'Tanpa NIP'}
                          {emp.email ? ` · ${emp.email}` : ''}
                        </p>
                      </td>
                      <td className="px-4 py-3 text-slate-600">{emp.position ?? '-'}</td>
                      <td className="px-4 py-3">
                        <div className="flex items-center gap-1.5">
                          <Badge tone={emp.employee_type === 'guru' ? 'brand' : 'gray'}>
                            {emp.employee_type === 'guru' ? 'Guru' : 'Staf'}
                          </Badge>
                          {emp.is_teacher && emp.employee_type === 'staf' && (
                            <span title="Ditandai sebagai pengajar">
                              <GraduationCap className="h-3.5 w-3.5 text-slate-400" />
                            </span>
                          )}
                        </div>
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex flex-wrap items-center gap-1">
                          <Badge tone={EMPLOYMENT_STATUS_TONES[emp.employment_status] ?? 'gray'}>
                            {emp.employment_status}
                          </Badge>
                          {emp.is_active ? (
                            <Badge tone="green">Aktif</Badge>
                          ) : (
                            <Badge tone="red">Nonaktif</Badge>
                          )}
                        </div>
                      </td>
                      <td className="px-4 py-3 text-xs text-slate-500">
                        {emp.has_account ? 'Ada' : '-'}
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex justify-end gap-1">
                          <Can perm="employee.update">
                            <Button
                              variant="ghost"
                              size="icon"
                              title={emp.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                              onClick={() => toggleMutation.mutate(emp.id)}
                            >
                              <Power className="h-4 w-4" />
                            </Button>
                          </Can>
                          <Can perm="employee.update">
                            <Button
                              variant="ghost"
                              size="icon"
                              title="Ubah"
                              onClick={() => openEdit(emp)}
                            >
                              <Pencil className="h-4 w-4" />
                            </Button>
                          </Can>
                          <Can perm="employee.delete">
                            <Button
                              variant="ghost"
                              size="icon"
                              title="Hapus"
                              className="text-red-600 hover:bg-red-50"
                              onClick={() => setDeleteTarget(emp)}
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

      <EmployeeFormModal
        open={modalOpen}
        editing={editing}
        submitting={saveMutation.isPending}
        serverErrors={errors}
        onClose={closeModal}
        onSubmit={(payload) => saveMutation.mutate(payload)}
      />

      <ConfirmDialog
        open={!!deleteTarget}
        title="Hapus Pegawai"
        message={`Yakin ingin menghapus "${deleteTarget?.full_name}"? Tindakan ini tidak dapat dibatalkan.`}
        loading={deleteMutation.isPending}
        onConfirm={() => deleteTarget && deleteMutation.mutate(deleteTarget.id)}
        onCancel={() => setDeleteTarget(null)}
      />
    </PermissionGuard>
  );
}

export default EmployeesPage;
