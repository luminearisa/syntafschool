import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircle2, FileClock, Plus, RefreshCw, XCircle } from 'lucide-react';
import { toast } from 'sonner';
import { attendanceCorrectionsApi } from '../api/attendanceApi';
import { CorrectionFormModal } from '../components/CorrectionFormModal';
import { RejectCorrectionModal } from '../components/RejectCorrectionModal';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { AttendanceCorrection, CorrectionStatus } from '@/types/attendance';

const STATUS_TONES: Record<CorrectionStatus, 'amber' | 'green' | 'red'> = {
  pending: 'amber',
  approved: 'green',
  rejected: 'red',
};

const STATUS_LABELS: Record<CorrectionStatus, string> = {
  pending: 'Menunggu',
  approved: 'Disetujui',
  rejected: 'Ditolak',
};

/**
 * Daftar pengajuan koreksi presensi.
 *
 * Presensi yang sudah tercatat tidak diubah di tempat; perubahan lewat jalur
 * ini agar status lama, alasan, dan pemutusnya terekam.
 */
export function AttendanceCorrectionsPage() {
  const queryClient = useQueryClient();

  const [page, setPage] = useState(1);
  const [status, setStatus] = useState<CorrectionStatus | ''>('pending');
  const [formOpen, setFormOpen] = useState(false);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [rejecting, setRejecting] = useState<AttendanceCorrection | null>(null);
  const [rejectErrors, setRejectErrors] = useState<Record<string, string[]>>({});

  const listQuery = useQuery({
    queryKey: ['attendance-corrections', page, status],
    queryFn: () =>
      attendanceCorrectionsApi.list({
        page,
        per_page: 15,
        filter: status ? { status } : undefined,
      }),
  });

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['attendance-corrections'] });
    queryClient.invalidateQueries({ queryKey: ['student-attendances'] });
  };

  const createMutation = useMutation({
    mutationFn: attendanceCorrectionsApi.create,
    onSuccess: () => {
      toast.success('Pengajuan koreksi berhasil dikirim');
      setFormOpen(false);
      setServerErrors({});
      invalidate();
    },
    onError: (err) => {
      setServerErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const approveMutation = useMutation({
    mutationFn: (id: number) => attendanceCorrectionsApi.approve(id),
    onSuccess: () => {
      toast.success('Koreksi disetujui dan status presensi diperbarui');
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rejectMutation = useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) =>
      attendanceCorrectionsApi.reject(id, reason),
    onSuccess: () => {
      toast.success('Koreksi ditolak');
      setRejecting(null);
      setRejectErrors({});
      invalidate();
    },
    onError: (err) => {
      setRejectErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Koreksi Presensi</h1>
          <p className="text-sm text-slate-500">
            Ajukan dan putuskan perubahan presensi tanpa menghapus jejak status lama.
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
                setServerErrors({});
                setFormOpen(true);
              }}
            >
              <Plus className="h-4 w-4" /> Ajukan Koreksi
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="w-full sm:w-56">
          <label className="mb-1 block text-xs font-medium text-slate-600">Status Pengajuan</label>
          <Select
            value={status}
            onChange={(e) => {
              setStatus(e.target.value as CorrectionStatus | '');
              setPage(1);
            }}
          >
            <option value="">Semua status</option>
            <option value="pending">Menunggu</option>
            <option value="approved">Disetujui</option>
            <option value="rejected">Ditolak</option>
          </Select>
        </div>
      </Card>

      {listQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat pengajuan...
          </div>
        </Card>
      ) : listQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat pengajuan"
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
            title="Belum ada pengajuan koreksi"
            description="Pengajuan yang dikirim guru akan muncul di sini untuk diputuskan."
          />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Siswa</th>
                  <th className="px-4 py-2 font-medium">Tanggal</th>
                  <th className="px-4 py-2 font-medium">Perubahan</th>
                  <th className="px-4 py-2 font-medium">Alasan</th>
                  <th className="px-4 py-2 font-medium">Pengaju</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="w-44 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="border-b border-slate-50 last:border-0 align-top">
                    <td className="px-4 py-2">
                      <div className="font-medium text-slate-800">
                        {row.attendance?.student?.full_name ?? '-'}
                      </div>
                      <div className="font-mono text-xs text-slate-400">
                        {row.attendance?.student?.nis ?? ''}
                      </div>
                    </td>
                    <td className="px-4 py-2 font-mono text-xs text-slate-600">
                      {row.attendance?.date ?? '-'}
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">
                      {row.old_status_name ?? '-'} →{' '}
                      <span className="font-medium text-slate-800">{row.new_status_name ?? '-'}</span>
                    </td>
                    <td className="max-w-xs px-4 py-2 text-xs text-slate-500">
                      <span className="block max-w-[16rem] truncate" title={row.reason}>
                        {row.reason}
                      </span>
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-500">
                      {row.requester?.name ?? '-'}
                      {row.approver?.name && (
                        <div className="text-slate-400">oleh {row.approver.name}</div>
                      )}
                    </td>
                    <td className="px-4 py-2">
                      <Badge tone={STATUS_TONES[row.status]}>{STATUS_LABELS[row.status]}</Badge>
                    </td>
                    <td className="px-4 py-2">
                      {row.is_pending ? (
                        <Can
                          perm="academic.approve"
                          fallback={<span className="text-xs text-slate-400">Menunggu pemutus</span>}
                        >
                          <div className="flex gap-2">
                            <Button
                              size="sm"
                              variant="outline"
                              loading={approveMutation.isPending}
                              onClick={() => approveMutation.mutate(row.id)}
                            >
                              <CheckCircle2 className="h-3.5 w-3.5" /> Setujui
                            </Button>
                            <Button
                              size="sm"
                              variant="ghost"
                              onClick={() => {
                                setRejectErrors({});
                                setRejecting(row);
                              }}
                            >
                              <XCircle className="h-3.5 w-3.5" /> Tolak
                            </Button>
                          </div>
                        </Can>
                      ) : (
                        <span className="text-xs text-slate-400">
                          {row.approved_at ? new Date(row.approved_at).toLocaleDateString('id-ID') : '—'}
                        </span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </Card>
      )}

      <div className="flex items-center gap-2 text-xs text-slate-400">
        <FileClock className="h-3.5 w-3.5" />
        Persetujuan koreksi memerlukan permission <code className="font-mono">academic.approve</code>.
      </div>

      <CorrectionFormModal
        open={formOpen}
        submitting={createMutation.isPending}
        serverErrors={serverErrors}
        onClose={() => setFormOpen(false)}
        onSubmit={(payload) => createMutation.mutate(payload)}
      />

      <RejectCorrectionModal
        open={!!rejecting}
        correction={rejecting}
        submitting={rejectMutation.isPending}
        serverErrors={rejectErrors}
        onClose={() => setRejecting(null)}
        onSubmit={(reason) => {
          if (rejecting) rejectMutation.mutate({ id: rejecting.id, reason });
        }}
      />
    </div>
  );
}
