import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CalendarCheck2, Calculator, RefreshCw } from 'lucide-react';
import { toast } from 'sonner';
import { effectiveDaysApi } from '../api/academicCalendarApi';
import { academicsApi } from '@/features/academics/api/academicsApi';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Can } from '@/components/common/Can';
import { useOptions } from '@/hooks/useOptions';
import { getErrorMessage } from '@/lib/axios';
import type { EffectiveDay } from '@/types/academicCalendar';

const DAY_NAMES = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

/** SearchSelect mengembalikan `string | number | ''`; API memakai `number | ''`. */
const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

/** Nama hari Indonesia dari tanggal `YYYY-MM-DD`. */
function dayName(date: string): string {
  const [year, month, day] = date.split('-').map(Number);
  return DAY_NAMES[new Date(year, month - 1, day).getDay()] ?? '';
}

export function EffectiveDaysPage() {
  const queryClient = useQueryClient();

  const [semesterId, setSemesterId] = useState<number | ''>('');
  const [educationLevelId, setEducationLevelId] = useState<number | ''>('');
  const [overwrite, setOverwrite] = useState(false);

  const { options: semesterOptions } = useOptions('semesters');
  const { options: levelOptions } = useOptions('education-levels');

  const activePeriodQuery = useQuery({
    queryKey: ['academic', 'active'],
    queryFn: () => academicsApi.activePeriod(),
  });

  useEffect(() => {
    if (!semesterId && activePeriodQuery.data?.semester) {
      setSemesterId(activePeriodQuery.data.semester.id);
    }
  }, [activePeriodQuery.data, semesterId]);

  const summaryQuery = useQuery({
    queryKey: ['effective-days-summary', semesterId, educationLevelId],
    enabled: !!semesterId,
    queryFn: () =>
      effectiveDaysApi.summary({ semester_id: semesterId, education_level_id: educationLevelId }),
  });

  const daysQuery = useQuery({
    queryKey: ['effective-days', semesterId, educationLevelId],
    enabled: !!semesterId,
    queryFn: () =>
      effectiveDaysApi.list({
        semester_id: semesterId,
        education_level_id: educationLevelId,
        all: true,
      }),
  });

  const summary = summaryQuery.data;
  const days = daysQuery.data?.data ?? [];

  /** Kelompokkan tanggal per bulan untuk tabel rincian. */
  const daysByMonth = useMemo(() => {
    const map = new Map<string, EffectiveDay[]>();
    for (const day of days) {
      const key = day.date.slice(0, 7);
      const list = map.get(key) ?? [];
      list.push(day);
      map.set(key, list);
    }
    return map;
  }, [days]);

  const generateMutation = useMutation({
    mutationFn: () =>
      effectiveDaysApi.generate({
        semester_id: semesterId,
        education_level_id: educationLevelId,
        overwrite,
      }),
    onSuccess: (res) => {
      toast.success(res.message);
      queryClient.invalidateQueries({ queryKey: ['effective-days'] });
      queryClient.invalidateQueries({ queryKey: ['effective-days-summary'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const loading = summaryQuery.isLoading || daysQuery.isLoading;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Hari Efektif</h1>
          <p className="text-sm text-slate-500">
            Hitung hari sekolah (Senin–Sabtu) dikurangi hari libur pada semester.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          <label className="flex items-center gap-2 text-xs text-slate-600">
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-slate-300"
              checked={overwrite}
              onChange={(e) => setOverwrite(e.target.checked)}
            />
            Timpa data lama
          </label>
          <Button
            variant="ghost"
            size="icon"
            title="Muat ulang"
            onClick={() => {
              summaryQuery.refetch();
              daysQuery.refetch();
            }}
          >
            <RefreshCw className={summaryQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="academic.create">
            <Button
              disabled={!semesterId || generateMutation.isPending}
              loading={generateMutation.isPending}
              onClick={() => generateMutation.mutate()}
            >
              <Calculator className="h-4 w-4" /> Hitung Hari Efektif
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
          <div className="w-full sm:w-56">
            <label className="mb-1 block text-xs font-medium text-slate-600">Semester</label>
            <SearchSelect
              options={semesterOptions}
              value={semesterId}
              onChange={(v) => setSemesterId(toId(v))}
              placeholder="-- Pilih Semester --"
            />
          </div>
          <div className="w-full sm:w-56">
            <label className="mb-1 block text-xs font-medium text-slate-600">Tingkat</label>
            <SearchSelect
              options={levelOptions}
              value={educationLevelId}
              onChange={(v) => setEducationLevelId(toId(v))}
              placeholder="-- Semua Tingkat --"
            />
          </div>
        </div>
      </Card>

      {!semesterId ? (
        <Card>
          <EmptyState title="Pilih semester" description="Tentukan semester untuk melihat rekap hari efektif." />
        </Card>
      ) : loading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat hari efektif...
          </div>
        </Card>
      ) : summaryQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat rekap"
            description={getErrorMessage(summaryQuery.error)}
            action={
              <Button size="sm" variant="outline" className="mt-2" onClick={() => summaryQuery.refetch()}>
                Coba lagi
              </Button>
            }
          />
        </Card>
      ) : (
        <>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <SummaryCard label="Total Hari Sekolah" value={summary?.total_school_days ?? 0} tone="green" />
            <SummaryCard label="Total Libur" value={summary?.total_holidays ?? 0} tone="red" />
            <SummaryCard label="Hari Efektif" value={summary?.total_effective_days ?? 0} tone="brand" />
          </div>

          {(summary?.months.length ?? 0) === 0 ? (
            <Card>
              <EmptyState
                title="Hari efektif belum dihitung"
                description='Klik "Hitung Hari Efektif" untuk mengisi hari sekolah dari rentang semester.'
              />
            </Card>
          ) : (
            <div className="space-y-4">
              {summary?.months.map((month) => {
                const monthDays = daysByMonth.get(month.month) ?? [];

                return (
                  <Card key={month.month}>
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
                      <div className="flex items-center gap-2">
                        <CalendarCheck2 className="h-4 w-4 text-slate-400" />
                        <h2 className="text-sm font-semibold text-slate-800">{month.label}</h2>
                      </div>
                      <div className="flex flex-wrap items-center gap-2 text-xs">
                        <Badge tone="green">{month.school_days} hari sekolah</Badge>
                        <Badge tone="red">{month.holidays} libur</Badge>
                        <Badge tone="brand">{month.effective_days} efektif</Badge>
                      </div>
                    </div>

                    {monthDays.length === 0 ? (
                      <EmptyState title="Tidak ada rincian tanggal" />
                    ) : (
                      <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                          <thead>
                            <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                              <th className="px-4 py-2 font-medium">Tanggal</th>
                              <th className="px-4 py-2 font-medium">Hari</th>
                              <th className="px-4 py-2 font-medium">Status</th>
                              <th className="px-4 py-2 font-medium">Catatan</th>
                            </tr>
                          </thead>
                          <tbody>
                            {monthDays.map((day) => (
                              <tr key={day.id} className="border-b border-slate-50 last:border-0">
                                <td className="px-4 py-2 font-mono text-xs text-slate-600">{day.date}</td>
                                <td className="px-4 py-2 text-slate-700">{dayName(day.date)}</td>
                                <td className="px-4 py-2">
                                  {day.is_school_day ? (
                                    <Badge tone="green">Hari Sekolah</Badge>
                                  ) : (
                                    <Badge tone="red">Libur</Badge>
                                  )}
                                </td>
                                <td className="px-4 py-2 text-xs text-slate-500">{day.note ?? '-'}</td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    )}
                  </Card>
                );
              })}
            </div>
          )}
        </>
      )}
    </div>
  );
}

function SummaryCard({
  label,
  value,
  tone,
}: {
  label: string;
  value: number;
  tone: 'green' | 'red' | 'brand';
}) {
  const tones = {
    green: 'text-green-600',
    red: 'text-red-600',
    brand: 'text-brand-600',
  };

  return (
    <Card className="p-4">
      <p className="text-xs font-medium uppercase tracking-wide text-slate-400">{label}</p>
      <p className={`mt-1 text-2xl font-semibold ${tones[tone]}`}>{value}</p>
    </Card>
  );
}
