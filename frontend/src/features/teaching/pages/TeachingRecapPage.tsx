import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { BarChart3, RefreshCw } from 'lucide-react';
import { teachingJournalsApi } from '../api/teachingApi';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { academicsApi } from '@/features/academics/api/academicsApi';
import { useEmployeeOptions } from '@/hooks/useLookupOptions';
import { useOptions } from '@/hooks/useOptions';
import { getErrorMessage } from '@/lib/axios';

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

/** Persentase capaian: JP terealisasi dibanding JP terjadwal. */
function achievement(realized: number, scheduled: number): number {
  if (scheduled <= 0) return 0;
  return Math.round((realized / scheduled) * 100);
}

function achievementTone(percentage: number) {
  if (percentage >= 90) return 'green' as const;
  if (percentage >= 70) return 'amber' as const;
  return 'red' as const;
}

/**
 * Rekap beban mengajar.
 *
 * Membandingkan JP yang **terjadwal** dengan JP yang **terealisasi** menurut
 * jurnal. Selisihnya menunjukkan jam mengajar yang belum dijurnal — bukan
 * otomatis berarti guru tidak mengajar.
 */
export function TeachingRecapPage() {
  const [semesterId, setSemesterId] = useState<number | ''>('');
  const [teacherId, setTeacherId] = useState<number | ''>('');

  const { options: semesterOptions } = useOptions('semesters');
  const { options: teacherOptions } = useEmployeeOptions(true);

  const activePeriodQuery = useQuery({
    queryKey: ['academic', 'active'],
    queryFn: () => academicsApi.activePeriod(),
  });

  useEffect(() => {
    if (!semesterId && activePeriodQuery.data?.semester) {
      setSemesterId(activePeriodQuery.data.semester.id);
    }
  }, [activePeriodQuery.data, semesterId]);

  const recapQuery = useQuery({
    queryKey: ['teaching-journal-recap', semesterId, teacherId],
    enabled: !!semesterId,
    queryFn: () => teachingJournalsApi.recap({ semester_id: semesterId, teacher_id: teacherId }),
  });

  const recap = recapQuery.data;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Rekap Beban Mengajar</h1>
          <p className="text-sm text-slate-500">
            JP terjadwal dibandingkan JP yang terealisasi menurut jurnal mengajar.
          </p>
        </div>
        <Button
          variant="ghost"
          size="icon"
          title="Muat ulang"
          disabled={!semesterId}
          onClick={() => recapQuery.refetch()}
        >
          <RefreshCw className={recapQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
        </Button>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Semester</label>
            <SearchSelect
              options={semesterOptions}
              value={semesterId}
              onChange={(v) => setSemesterId(toId(v))}
              placeholder="-- Pilih Semester --"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Guru</label>
            <SearchSelect
              options={teacherOptions}
              value={teacherId}
              onChange={(v) => setTeacherId(toId(v))}
              placeholder="-- Semua Guru --"
            />
          </div>
        </div>
      </Card>

      {!semesterId ? (
        <Card>
          <EmptyState title="Pilih semester" description="Rekap beban mengajar dihitung per semester." />
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
      ) : !recap || recap.items.length === 0 ? (
        <Card>
          <EmptyState
            title="Belum ada data guru"
            description="Pastikan data pegawai berstatus pengajar sudah tersedia."
          />
        </Card>
      ) : (
        <>
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <SummaryCard label="Guru" value={String(recap.totals.teachers)} />
            <SummaryCard label="JP Terjadwal" value={String(recap.totals.scheduled_jp)} />
            <SummaryCard
              label="JP Terealisasi"
              value={String(recap.totals.realized_jp)}
              tone="green"
            />
            <SummaryCard
              label="Capaian"
              value={`${achievement(recap.totals.realized_jp, recap.totals.scheduled_jp)}%`}
            />
          </div>

          <Card>
            <div className="flex items-center gap-2 border-b border-slate-100 px-4 py-3">
              <BarChart3 className="h-4 w-4 text-slate-400" />
              <h2 className="text-sm font-semibold text-slate-800">Per Guru</h2>
            </div>

            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                    <th className="w-10 px-4 py-2 font-medium">#</th>
                    <th className="px-4 py-2 font-medium">Guru</th>
                    <th className="px-3 py-2 text-center font-medium">JP Terjadwal</th>
                    <th className="px-3 py-2 text-center font-medium">JP Terealisasi</th>
                    <th className="px-3 py-2 text-center font-medium">Jurnal</th>
                    <th className="px-3 py-2 text-center font-medium">Selesai</th>
                    <th className="px-4 py-2 font-medium">Capaian</th>
                  </tr>
                </thead>
                <tbody>
                  {recap.items.map((item, index) => {
                    const percentage = achievement(item.realized_jp, item.scheduled_jp);

                    return (
                      <tr key={item.teacher_id} className="border-b border-slate-50 last:border-0">
                        <td className="px-4 py-2 text-xs text-slate-400">{index + 1}</td>
                        <td className="px-4 py-2">
                          <div className="font-medium text-slate-800">{item.teacher.full_name}</div>
                          <div className="font-mono text-xs text-slate-400">
                            {item.teacher.nip ?? ''}
                          </div>
                        </td>
                        <td className="px-3 py-2 text-center text-slate-700">{item.scheduled_jp}</td>
                        <td className="px-3 py-2 text-center text-slate-700">{item.realized_jp}</td>
                        <td className="px-3 py-2 text-center text-slate-700">{item.journal_count}</td>
                        <td className="px-3 py-2 text-center text-slate-700">
                          {item.completed_count}
                        </td>
                        <td className="px-4 py-2">
                          <Badge tone={achievementTone(percentage)}>{percentage}%</Badge>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
                <tfoot>
                  <tr className="border-t border-slate-200 bg-slate-50 text-xs font-medium text-slate-600">
                    <td className="px-4 py-2" />
                    <td className="px-4 py-2">Total</td>
                    <td className="px-3 py-2 text-center">{recap.totals.scheduled_jp}</td>
                    <td className="px-3 py-2 text-center">{recap.totals.realized_jp}</td>
                    <td className="px-3 py-2 text-center">{recap.totals.journal_count}</td>
                    <td className="px-3 py-2 text-center">{recap.totals.completed_count}</td>
                    <td className="px-4 py-2">
                      {achievement(recap.totals.realized_jp, recap.totals.scheduled_jp)}%
                    </td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </Card>
        </>
      )}
    </div>
  );
}

function SummaryCard({
  label,
  value,
  tone = 'slate',
}: {
  label: string;
  value: string;
  tone?: 'slate' | 'green';
}) {
  return (
    <Card className="p-4">
      <p className="text-xs font-medium uppercase tracking-wide text-slate-400">{label}</p>
      <p
        className={`mt-1 text-xl font-semibold ${
          tone === 'green' ? 'text-green-600' : 'text-slate-800'
        }`}
      >
        {value}
      </p>
    </Card>
  );
}
