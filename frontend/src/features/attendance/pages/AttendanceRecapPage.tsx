import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Eye, RefreshCw, Users } from 'lucide-react';
import { attendanceRecapApi } from '../api/attendanceApi';
import { StudentRecapModal } from '../components/StudentRecapModal';
import { percentageTone } from '../hooks/useAttendanceStatuses';
import { classesApi } from '@/features/academics/api/academicsApi';
import { academicsApi } from '@/features/academics/api/academicsApi';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { useOptions } from '@/hooks/useOptions';
import { getErrorMessage } from '@/lib/axios';
import type { ClassRecapItem } from '@/types/attendance';

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

/**
 * Rekap presensi per kelas.
 *
 * Persentase memakai penyebut hari efektif semester; bila data hari efektif
 * belum ada, penyebutnya jumlah hari tercatat dan hal itu ditandai di layar.
 */
export function AttendanceRecapPage() {
  const [classId, setClassId] = useState<number | ''>('');
  const [semesterId, setSemesterId] = useState<number | ''>('');
  const [detail, setDetail] = useState<ClassRecapItem | null>(null);

  const { options: semesterOptions } = useOptions('semesters');

  const classesQuery = useQuery({
    queryKey: ['classes', 'all-for-attendance-recap'],
    staleTime: 1000 * 60 * 10,
    queryFn: () => classesApi.list({ all: true, per_page: 300 }),
  });

  const activePeriodQuery = useQuery({
    queryKey: ['academic', 'active'],
    queryFn: () => academicsApi.activePeriod(),
  });

  useEffect(() => {
    if (!semesterId && activePeriodQuery.data?.semester) {
      setSemesterId(activePeriodQuery.data.semester.id);
    }
  }, [activePeriodQuery.data, semesterId]);

  const classes = classesQuery.data?.data ?? [];

  useEffect(() => {
    if (!classId && classes.length > 0) setClassId(classes[0].id);
  }, [classes, classId]);

  const recapQuery = useQuery({
    queryKey: ['attendance-recap', 'class', classId, semesterId],
    enabled: !!classId && !!semesterId,
    queryFn: () => attendanceRecapApi.class(classId as number, semesterId),
  });

  const recap = recapQuery.data;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Rekap Presensi</h1>
          <p className="text-sm text-slate-500">
            Persentase kehadiran dihitung terhadap hari efektif semester, bukan hari kalender.
          </p>
        </div>
        <Button
          variant="ghost"
          size="icon"
          title="Muat ulang"
          disabled={!classId || !semesterId}
          onClick={() => recapQuery.refetch()}
        >
          <RefreshCw className={recapQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
        </Button>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Kelas</label>
            <SearchSelect
              options={classes.map((c) => ({ value: c.id, label: `${c.name} — ${c.code}` }))}
              value={classId}
              onChange={(v) => setClassId(toId(v))}
              placeholder="-- Pilih Kelas --"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Semester</label>
            <SearchSelect
              options={semesterOptions}
              value={semesterId}
              onChange={(v) => setSemesterId(toId(v))}
              placeholder="-- Pilih Semester --"
            />
          </div>
        </div>
      </Card>

      {!classId || !semesterId ? (
        <Card>
          <EmptyState title="Pilih kelas dan semester" description="Rekap akan muncul setelah keduanya dipilih." />
        </Card>
      ) : recapQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Menghitung rekap...
          </div>
        </Card>
      ) : recapQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat rekap"
            description={getErrorMessage(recapQuery.error)}
            action={
              <Button size="sm" variant="outline" className="mt-2" onClick={() => recapQuery.refetch()}>
                Coba lagi
              </Button>
            }
          />
        </Card>
      ) : !recap ? (
        <Card>
          <EmptyState title="Rekap tidak tersedia" />
        </Card>
      ) : (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <SummaryCard label="Siswa" value={String(recap.student_count)} />
            <SummaryCard label="Rata-rata Kehadiran" value={`${recap.totals.average_percentage}%`} />
            <SummaryCard label="Hari Efektif" value={recap.effective_days !== null ? String(recap.effective_days) : '—'} />
            <SummaryCard
              label="Total Alpa"
              value={String(recap.totals.absent)}
            />
          </div>

          {recap.effective_days === null && (
            <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-700">
              Semester ini belum punya data hari efektif. Persentase dihitung terhadap jumlah hari
              yang sudah tercatat, jadi angkanya belum mencerminkan capaian semester penuh.
            </div>
          )}

          <Card>
            <div className="flex items-center gap-2 border-b border-slate-100 px-4 py-3">
              <Users className="h-4 w-4 text-slate-400" />
              <h2 className="text-sm font-semibold text-slate-800">Rekap per Siswa</h2>
            </div>

            {recap.items.length === 0 ? (
              <EmptyState
                title="Kelas ini belum punya siswa aktif"
                description="Tambahkan anggota kelas terlebih dahulu di menu Kelas."
              />
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                      <th className="w-10 px-4 py-2 font-medium">#</th>
                      <th className="px-4 py-2 font-medium">Siswa</th>
                      <th className="px-3 py-2 text-center font-medium">H</th>
                      <th className="px-3 py-2 text-center font-medium">T</th>
                      <th className="px-3 py-2 text-center font-medium">S</th>
                      <th className="px-3 py-2 text-center font-medium">I</th>
                      <th className="px-3 py-2 text-center font-medium">A</th>
                      <th className="px-4 py-2 font-medium">Kehadiran</th>
                      <th className="w-20 px-4 py-2 font-medium" />
                    </tr>
                  </thead>
                  <tbody>
                    {recap.items.map((item, index) => (
                      <tr key={item.student_id} className="border-b border-slate-50 last:border-0">
                        <td className="px-4 py-2 text-xs text-slate-400">{index + 1}</td>
                        <td className="px-4 py-2">
                          <div className="font-medium text-slate-800">{item.student.full_name}</div>
                          <div className="font-mono text-xs text-slate-400">{item.student.nis}</div>
                        </td>
                        <td className="px-3 py-2 text-center text-slate-700">{item.present}</td>
                        <td className="px-3 py-2 text-center text-slate-700">{item.late}</td>
                        <td className="px-3 py-2 text-center text-slate-700">{item.sick}</td>
                        <td className="px-3 py-2 text-center text-slate-700">{item.permitted}</td>
                        <td className="px-3 py-2 text-center font-medium text-red-600">{item.absent}</td>
                        <td className="px-4 py-2">
                          <Badge tone={percentageTone(item.percentage)}>{item.percentage}%</Badge>
                        </td>
                        <td className="px-4 py-2">
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setDetail(item)}
                          >
                            <Eye className="h-3.5 w-3.5" /> Detail
                          </Button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                  <tfoot>
                    <tr className="border-t border-slate-200 bg-slate-50 text-xs font-medium text-slate-600">
                      <td className="px-4 py-2" />
                      <td className="px-4 py-2">Total</td>
                      <td className="px-3 py-2 text-center">{recap.totals.present}</td>
                      <td className="px-3 py-2 text-center">{recap.totals.late}</td>
                      <td className="px-3 py-2 text-center">{recap.totals.sick}</td>
                      <td className="px-3 py-2 text-center">{recap.totals.permitted}</td>
                      <td className="px-3 py-2 text-center text-red-600">{recap.totals.absent}</td>
                      <td className="px-4 py-2" colSpan={2}>
                        Rata-rata {recap.totals.average_percentage}%
                      </td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            )}
          </Card>
        </>
      )}

      <StudentRecapModal
        open={!!detail}
        studentId={detail?.student_id ?? null}
        studentName={detail?.student.full_name}
        semesterId={semesterId}
        onClose={() => setDetail(null)}
      />
    </div>
  );
}

function SummaryCard({ label, value }: { label: string; value: string }) {
  return (
    <Card className="p-4">
      <p className="text-xs font-medium uppercase tracking-wide text-slate-400">{label}</p>
      <p className="mt-1 text-xl font-semibold text-slate-800">{value}</p>
    </Card>
  );
}
