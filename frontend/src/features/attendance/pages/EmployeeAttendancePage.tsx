import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { BarChart3, Pencil, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { employeeAttendancesApi } from '../api/attendanceApi';
import { EmployeeAttendanceFormModal } from '../components/EmployeeAttendanceFormModal';
import { codeTone } from '../hooks/useAttendanceStatuses';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Spinner } from '@/components/ui/Spinner';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { useEmployeeOptions } from '@/hooks/useLookupOptions';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { EmployeeAttendance, EmployeeAttendancePayload } from '@/types/attendance';

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

function today(): string {
  const now = new Date();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  const day = String(now.getDate()).padStart(2, '0');
  return `${now.getFullYear()}-${month}-${day}`;
}

function firstDayOfMonth(): string {
  const now = new Date();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  return `${now.getFullYear()}-${month}-01`;
}

/**
 * Presensi pegawai (guru & staf).
 *
 * Berbeda dari presensi siswa yang dicatat per kelas, presensi pegawai dicatat
 * per orang per hari dan disimpan dengan pola upsert.
 */
export function EmployeeAttendancePage() {
  const queryClient = useQueryClient();

  const [page, setPage] = useState(1);
  const [employeeId, setEmployeeId] = useState<number | ''>('');
  const [from, setFrom] = useState(firstDayOfMonth());
  const [to, setTo] = useState(today());

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<EmployeeAttendance | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [deleting, setDeleting] = useState<EmployeeAttendance | null>(null);

  const [recapFrom, setRecapFrom] = useState(firstDayOfMonth());
  const [recapTo, setRecapTo] = useState(today());

  const { options: employeeOptions } = useEmployeeOptions(false);

  const listQuery = useQuery({
    queryKey: ['employee-attendances', page, employeeId, from, to],
    queryFn: () =>
      employeeAttendancesApi.list({
        page,
        per_page: 15,
        from: from || undefined,
        to: to || undefined,
        filter: employeeId ? { employee_id: employeeId } : undefined,
      }),
  });

  const recapEnabled = !!recapFrom && !!recapTo && recapFrom <= recapTo;

  const recapQuery = useQuery({
    queryKey: ['employee-attendance-recap', recapFrom, recapTo],
    enabled: recapEnabled,
    queryFn: () => employeeAttendancesApi.recap({ from: recapFrom, to: recapTo }),
  });

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['employee-attendances'] });
    queryClient.invalidateQueries({ queryKey: ['employee-attendance-recap'] });
  };

  const saveMutation = useMutation({
    mutationFn: (payload: EmployeeAttendancePayload) =>
      editing
        ? employeeAttendancesApi.update(editing.id, payload)
        : employeeAttendancesApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Presensi pegawai diperbarui' : 'Presensi pegawai dicatat');
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
    mutationFn: (id: number) => employeeAttendancesApi.remove(id),
    onSuccess: () => {
      toast.success('Presensi pegawai dihapus');
      setDeleting(null);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;
  const recap = recapQuery.data;

  const recapRows = useMemo(() => recap?.items ?? [], [recap]);

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Presensi Pegawai</h1>
          <p className="text-sm text-slate-500">
            Catat kehadiran guru dan staf. Menyimpan ulang pada tanggal yang sama akan memperbarui
            baris yang ada.
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
          <Can perm={['academic.attendance', 'academic.create']}>
            <Button
              onClick={() => {
                setEditing(null);
                setServerErrors({});
                setFormOpen(true);
              }}
            >
              <Plus className="h-4 w-4" /> Catat Presensi
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Pegawai</label>
            <SearchSelect
              options={employeeOptions}
              value={employeeId}
              onChange={(v) => {
                setEmployeeId(toId(v));
                setPage(1);
              }}
              placeholder="-- Semua Pegawai --"
            />
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

      {listQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat presensi pegawai...
          </div>
        </Card>
      ) : listQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat presensi"
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
            title="Belum ada presensi pada rentang ini"
            description="Ubah rentang tanggal atau catat presensi baru."
          />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Pegawai</th>
                  <th className="px-4 py-2 font-medium">Tanggal</th>
                  <th className="px-4 py-2 font-medium">Masuk</th>
                  <th className="px-4 py-2 font-medium">Pulang</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="px-4 py-2 font-medium">Jenis Izin</th>
                  <th className="px-4 py-2 font-medium">Catatan</th>
                  <th className="w-24 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="border-b border-slate-50 last:border-0">
                    <td className="px-4 py-2">
                      <div className="font-medium text-slate-800">
                        {row.employee?.full_name ?? '-'}
                      </div>
                      <div className="font-mono text-xs text-slate-400">
                        {row.employee?.nip ?? ''}
                      </div>
                    </td>
                    <td className="px-4 py-2 font-mono text-xs text-slate-600">{row.date}</td>
                    <td className="px-4 py-2 text-slate-700">{row.check_in ?? '—'}</td>
                    <td className="px-4 py-2 text-slate-700">{row.check_out ?? '—'}</td>
                    <td className="px-4 py-2">
                      <Badge tone={codeTone(row.status_code)}>{row.status_name ?? '-'}</Badge>
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-500">
                      {row.leave_type?.name ?? '—'}
                    </td>
                    <td className="max-w-[14rem] px-4 py-2 text-xs text-slate-500">
                      <span className="block truncate" title={row.notes ?? ''}>
                        {row.notes ?? '—'}
                      </span>
                    </td>
                    <td className="px-4 py-2">
                      <Can
                        perm={['academic.attendance', 'academic.update']}
                        fallback={<span className="text-xs text-slate-400">—</span>}
                      >
                        <div className="flex gap-1">
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
                          <Button
                            variant="ghost"
                            size="icon"
                            title="Hapus"
                            onClick={() => setDeleting(row)}
                          >
                            <Trash2 className="h-3.5 w-3.5 text-red-500" />
                          </Button>
                        </div>
                      </Can>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </Card>
      )}

      <Card>
        <div className="flex flex-wrap items-end justify-between gap-3 border-b border-slate-100 px-4 py-3">
          <div className="flex items-center gap-2">
            <BarChart3 className="h-4 w-4 text-slate-400" />
            <h2 className="text-sm font-semibold text-slate-800">Rekap Periode</h2>
          </div>
          <div className="flex flex-wrap items-end gap-3">
            <div className="w-40">
              <label className="mb-1 block text-xs font-medium text-slate-600">Dari</label>
              <Input type="date" value={recapFrom} onChange={(e) => setRecapFrom(e.target.value)} />
            </div>
            <div className="w-40">
              <label className="mb-1 block text-xs font-medium text-slate-600">Sampai</label>
              <Input type="date" value={recapTo} onChange={(e) => setRecapTo(e.target.value)} />
            </div>
          </div>
        </div>

        {!recapEnabled ? (
          <EmptyState title="Rentang tanggal belum valid" description="Tanggal awal harus sebelum atau sama dengan tanggal akhir." />
        ) : recapQuery.isLoading ? (
          <div className="flex items-center justify-center gap-2 py-12 text-slate-400">
            <Spinner /> Menghitung rekap...
          </div>
        ) : recapQuery.isError ? (
          <EmptyState
            title="Gagal memuat rekap"
            description={getErrorMessage(recapQuery.error)}
          />
        ) : recapRows.length === 0 ? (
          <EmptyState title="Tidak ada pegawai untuk direkap" />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Pegawai</th>
                  <th className="px-3 py-2 text-center font-medium">Hari Tercatat</th>
                  <th className="px-3 py-2 text-center font-medium">H</th>
                  <th className="px-3 py-2 text-center font-medium">T</th>
                  <th className="px-3 py-2 text-center font-medium">S</th>
                  <th className="px-3 py-2 text-center font-medium">I</th>
                  <th className="px-3 py-2 text-center font-medium">A</th>
                </tr>
              </thead>
              <tbody>
                {recapRows.map((item) => (
                  <tr key={item.employee_id} className="border-b border-slate-50 last:border-0">
                    <td className="px-4 py-2">
                      <div className="font-medium text-slate-800">{item.employee.full_name}</div>
                      <div className="font-mono text-xs text-slate-400">{item.employee.nip ?? ''}</div>
                    </td>
                    <td className="px-3 py-2 text-center text-slate-700">{item.total_days}</td>
                    <td className="px-3 py-2 text-center text-slate-700">{item.present}</td>
                    <td className="px-3 py-2 text-center text-slate-700">{item.late}</td>
                    <td className="px-3 py-2 text-center text-slate-700">{item.sick}</td>
                    <td className="px-3 py-2 text-center text-slate-700">{item.permitted}</td>
                    <td className="px-3 py-2 text-center font-medium text-red-600">{item.absent}</td>
                  </tr>
                ))}
              </tbody>
              {recap && (
                <tfoot>
                  <tr className="border-t border-slate-200 bg-slate-50 text-xs font-medium text-slate-600">
                    <td className="px-4 py-2">Total ({recap.totals.employees} pegawai)</td>
                    <td className="px-3 py-2" />
                    <td className="px-3 py-2 text-center">{recap.totals.present}</td>
                    <td className="px-3 py-2 text-center">{recap.totals.late}</td>
                    <td className="px-3 py-2 text-center">{recap.totals.sick}</td>
                    <td className="px-3 py-2 text-center">{recap.totals.permitted}</td>
                    <td className="px-3 py-2 text-center text-red-600">{recap.totals.absent}</td>
                  </tr>
                </tfoot>
              )}
            </table>
          </div>
        )}
      </Card>

      <EmployeeAttendanceFormModal
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
        title="Hapus Presensi Pegawai"
        message={`Hapus presensi ${deleting?.employee?.full_name ?? ''} pada ${deleting?.date ?? ''}? Tindakan ini tidak bisa dibatalkan.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}
