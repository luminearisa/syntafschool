import { useQuery } from '@tanstack/react-query';
import { attendanceRecapApi } from '../api/attendanceApi';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { getErrorMessage } from '@/lib/axios';
import { codeTone, percentageTone } from '../hooks/useAttendanceStatuses';

interface Props {
  open: boolean;
  studentId: number | null;
  studentName?: string;
  semesterId: number | '';
  onClose: () => void;
}

/**
 * Rincian rekap presensi satu siswa.
 *
 * Persentase dihitung terhadap hari efektif; kalau semester belum punya data
 * hari efektif, penyebutnya jatuh ke jumlah hari yang tercatat — hal itu
 * ditampilkan apa adanya supaya angka tidak disalahartikan.
 */
export function StudentRecapModal({ open, studentId, studentName, semesterId, onClose }: Props) {
  const recapQuery = useQuery({
    queryKey: ['attendance-recap', 'student', studentId, semesterId],
    enabled: open && !!studentId,
    queryFn: () => attendanceRecapApi.student(studentId as number, semesterId),
  });

  const recap = recapQuery.data;

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={`Rekap Presensi — ${studentName ?? 'Siswa'}`}
      size="md"
      footer={
        <Button variant="outline" onClick={onClose}>
          Tutup
        </Button>
      }
    >
      {recapQuery.isLoading ? (
        <div className="flex items-center justify-center gap-2 py-10 text-slate-400">
          <Spinner /> Memuat rekap...
        </div>
      ) : recapQuery.isError ? (
        <EmptyState
          title="Gagal memuat rekap"
          description={getErrorMessage(recapQuery.error)}
          action={
            <Button size="sm" variant="outline" className="mt-2" onClick={() => recapQuery.refetch()}>
              Coba lagi
            </Button>
          }
        />
      ) : !recap ? (
        <EmptyState title="Rekap tidak tersedia" />
      ) : (
        <div className="space-y-4">
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone={percentageTone(recap.percentage)}>
              {recap.percentage}% kehadiran
            </Badge>
            <Badge tone="gray">
              {recap.total_present} dari {recap.basis === 'effective_days' ? recap.effective_days : recap.recorded_days}{' '}
              hari
            </Badge>
            {recap.basis === 'recorded_days' && (
              <Badge tone="amber">Basis: hari tercatat (hari efektif belum dihitung)</Badge>
            )}
          </div>

          <div className="overflow-hidden rounded-lg border border-slate-200">
            <table className="w-full text-sm">
              <tbody>
                {[
                  { code: 'H', label: 'Hadir', value: recap.present },
                  { code: 'T', label: 'Terlambat', value: recap.late },
                  { code: 'S', label: 'Sakit', value: recap.sick },
                  { code: 'I', label: 'Izin', value: recap.permitted },
                  { code: 'A', label: 'Alpa', value: recap.absent },
                ].map((row) => (
                  <tr key={row.code} className="border-b border-slate-100 last:border-0">
                    <td className="w-32 px-4 py-2">
                      <Badge tone={codeTone(row.code)}>{row.label}</Badge>
                    </td>
                    <td className="px-4 py-2 text-right font-medium text-slate-700">{row.value}</td>
                  </tr>
                ))}
                <tr className="bg-slate-50">
                  <td className="px-4 py-2 text-xs font-medium uppercase tracking-wide text-slate-500">
                    Hari Efektif
                  </td>
                  <td className="px-4 py-2 text-right font-semibold text-slate-700">
                    {recap.effective_days ?? '—'}
                  </td>
                </tr>
                <tr className="bg-slate-50">
                  <td className="px-4 py-2 text-xs font-medium uppercase tracking-wide text-slate-500">
                    Hari Tercatat
                  </td>
                  <td className="px-4 py-2 text-right font-semibold text-slate-700">
                    {recap.recorded_days}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      )}
    </Modal>
  );
}
