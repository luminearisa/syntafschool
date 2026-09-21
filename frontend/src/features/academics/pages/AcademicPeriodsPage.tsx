import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CalendarCheck, CheckCircle2, RefreshCw } from 'lucide-react';
import { toast } from 'sonner';
import { academicsApi } from '../api/academicsApi';
import { masterDataApi } from '@/features/master-data/api/masterDataApi';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { Can } from '@/components/common/Can';
import { getErrorMessage } from '@/lib/axios';
import type { AcademicYear, Semester } from '@/types';

function formatDate(value?: string | null) {
  if (!value) return '-';
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
}

function StatCard({ title, primary, secondary, active }: { title: string; primary: string; secondary: string; active: boolean }) {
  return (
    <Card className="p-4">
      <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{title}</p>
      <div className="mt-1 flex items-center justify-between gap-2">
        <p className="text-lg font-semibold text-slate-900">{primary}</p>
        {active ? <Badge tone="green">Aktif</Badge> : <Badge tone="gray">Nonaktif</Badge>}
      </div>
      <p className="mt-0.5 text-xs text-slate-500">{secondary}</p>
    </Card>
  );
}

export function AcademicPeriodsPage() {
  const queryClient = useQueryClient();

  const periodQuery = useQuery({
    queryKey: ['academic', 'active'],
    queryFn: () => academicsApi.activePeriod(),
  });

  const yearsQuery = useQuery({
    queryKey: ['academic', 'years'],
    queryFn: () => masterDataApi.list('academic-years', { all: true }),
  });

  const semestersQuery = useQuery({
    queryKey: ['academic', 'semesters'],
    queryFn: () => masterDataApi.list('semesters', { all: true }),
  });

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['academic'] });
  };

  const activateYear = useMutation({
    mutationFn: (id: number) => academicsApi.activateYear(id),
    onSuccess: (data) => {
      toast.success(`Tahun ajaran ${data.name} diaktifkan`);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const activateSemester = useMutation({
    mutationFn: (id: number) => academicsApi.activateSemester(id),
    onSuccess: (data) => {
      toast.success(`Semester ${data.name} diaktifkan`);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const period = periodQuery.data;
  const years = (yearsQuery.data?.data ?? []) as unknown as AcademicYear[];
  const semesters = (semestersQuery.data?.data ?? []) as unknown as Semester[];

  const refreshAll = () => {
    periodQuery.refetch();
    yearsQuery.refetch();
    semestersQuery.refetch();
  };

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Tahun Ajaran & Semester</h1>
          <p className="text-sm text-slate-500">Kelola periode akademik aktif.</p>
        </div>
        <Button variant="ghost" size="icon" onClick={refreshAll} title="Muat ulang">
          <RefreshCw className={periodQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
        </Button>
      </div>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <StatCard
          title="Tahun Ajaran Aktif"
          primary={period?.academic_year?.name ?? 'Belum ada'}
          secondary={
            period?.academic_year
              ? `${formatDate(period.academic_year.start_date)} — ${formatDate(period.academic_year.end_date)}`
              : 'Aktifkan salah satu tahun ajaran'
          }
          active={!!period?.academic_year}
        />
        <StatCard
          title="Semester Aktif"
          primary={period?.semester ? `${period.semester.name} ${period.semester.academic_year?.name ?? ''}` : 'Belum ada'}
          secondary={
            period?.semester
              ? `${formatDate(period.semester.start_date)} — ${formatDate(period.semester.end_date)}`
              : 'Aktifkan salah satu semester'
          }
          active={!!period?.semester}
        />
      </div>

      {/* Tabel Tahun Ajaran */}
      <Card>
        <div className="flex items-center gap-2 border-b border-slate-100 px-4 py-3">
          <CalendarCheck className="h-4 w-4 text-slate-500" />
          <h2 className="text-sm font-semibold text-slate-900">Tahun Ajaran</h2>
        </div>
        {yearsQuery.isLoading ? (
          <div className="flex items-center justify-center gap-2 py-10 text-slate-400">
            <Spinner /> Memuat...
          </div>
        ) : years.length === 0 ? (
          <EmptyState title="Belum ada tahun ajaran" description="Tambahkan melalui Master Data › Tahun Ajaran." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 bg-slate-50/60 text-left text-xs uppercase tracking-wide text-slate-500">
                  <th className="px-4 py-2.5 font-medium">Tahun Ajaran</th>
                  <th className="px-4 py-2.5 font-medium">Mulai</th>
                  <th className="px-4 py-2.5 font-medium">Selesai</th>
                  <th className="px-4 py-2.5 text-center font-medium">Status</th>
                  <th className="px-4 py-2.5 text-right font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {years.map((y) => (
                  <tr key={y.id} className="border-b border-slate-50 hover:bg-slate-50/60">
                    <td className="px-4 py-2.5 font-medium text-slate-800">{y.name}</td>
                    <td className="px-4 py-2.5 text-slate-600">{formatDate(y.start_date)}</td>
                    <td className="px-4 py-2.5 text-slate-600">{formatDate(y.end_date)}</td>
                    <td className="px-4 py-2.5 text-center">
                      {y.is_active ? <Badge tone="green">Aktif</Badge> : <Badge tone="gray">Nonaktif</Badge>}
                    </td>
                    <td className="px-4 py-2.5 text-right">
                      <Can perm="academic.update">
                        {y.is_active ? (
                          <span className="inline-flex items-center gap-1 text-xs text-green-600">
                            <CheckCircle2 className="h-3.5 w-3.5" /> Terpilih
                          </span>
                        ) : (
                          <Button
                            size="sm"
                            variant="outline"
                            loading={activateYear.isPending && activateYear.variables === y.id}
                            onClick={() => activateYear.mutate(y.id)}
                          >
                            Aktifkan
                          </Button>
                        )}
                      </Can>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {/* Tabel Semester */}
      <Card>
        <div className="flex items-center gap-2 border-b border-slate-100 px-4 py-3">
          <CalendarCheck className="h-4 w-4 text-slate-500" />
          <h2 className="text-sm font-semibold text-slate-900">Semester</h2>
        </div>
        {semestersQuery.isLoading ? (
          <div className="flex items-center justify-center gap-2 py-10 text-slate-400">
            <Spinner /> Memuat...
          </div>
        ) : semesters.length === 0 ? (
          <EmptyState title="Belum ada semester" description="Tambahkan melalui Master Data › Semester." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 bg-slate-50/60 text-left text-xs uppercase tracking-wide text-slate-500">
                  <th className="px-4 py-2.5 font-medium">Semester</th>
                  <th className="px-4 py-2.5 font-medium">Tahun Ajaran</th>
                  <th className="px-4 py-2.5 font-medium">Mulai</th>
                  <th className="px-4 py-2.5 font-medium">Selesai</th>
                  <th className="px-4 py-2.5 text-center font-medium">Status</th>
                  <th className="px-4 py-2.5 text-right font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {semesters.map((s) => (
                  <tr key={s.id} className="border-b border-slate-50 hover:bg-slate-50/60">
                    <td className="px-4 py-2.5 font-medium text-slate-800">{s.name}</td>
                    <td className="px-4 py-2.5 text-slate-600">{s.academic_year?.name ?? '-'}</td>
                    <td className="px-4 py-2.5 text-slate-600">{formatDate(s.start_date)}</td>
                    <td className="px-4 py-2.5 text-slate-600">{formatDate(s.end_date)}</td>
                    <td className="px-4 py-2.5 text-center">
                      {s.is_active ? <Badge tone="green">Aktif</Badge> : <Badge tone="gray">Nonaktif</Badge>}
                    </td>
                    <td className="px-4 py-2.5 text-right">
                      <Can perm="academic.update">
                        {s.is_active ? (
                          <span className="inline-flex items-center gap-1 text-xs text-green-600">
                            <CheckCircle2 className="h-3.5 w-3.5" /> Terpilih
                          </span>
                        ) : (
                          <Button
                            size="sm"
                            variant="outline"
                            loading={activateSemester.isPending && activateSemester.variables === s.id}
                            onClick={() => activateSemester.mutate(s.id)}
                          >
                            Aktifkan
                          </Button>
                        )}
                      </Can>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
}
