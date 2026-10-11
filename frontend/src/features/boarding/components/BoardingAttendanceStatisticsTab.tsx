import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
  ArcElement,
  BarElement,
  CategoryScale,
  Chart as ChartJS,
  Filler,
  Legend,
  LinearScale,
  LineElement,
  PointElement,
  Tooltip,
} from 'chart.js';
import { Doughnut, Line } from 'react-chartjs-2';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Spinner } from '@/components/ui/Spinner';
import { FormField } from '../components/BoardingUi';
import { attendancesApi } from '../api/boardingApi';
import { useDormitoryOptions } from '../hooks/useBoardingOptions';

ChartJS.register(
  ArcElement,
  BarElement,
  CategoryScale,
  Filler,
  Legend,
  LinearScale,
  LineElement,
  PointElement,
  Tooltip,
);

const toId = (v: string | number | ''): number | '' => (v === '' ? '' : Number(v));

function daysAgo(n: number): string {
  const d = new Date();
  d.setDate(d.getDate() - n);
  return d.toISOString().slice(0, 10);
}

const fmtDay = (iso: string) => iso.slice(8, 10) + '/' + iso.slice(5, 7);

/** Grafik agregat absensi: distribusi status, tren harian, dan per asrama. */
export function BoardingAttendanceStatisticsTab() {
  const [from, setFrom] = useState(daysAgo(29));
  const [to, setTo] = useState(daysAgo(0));
  const [dormitoryId, setDormitoryId] = useState<number | ''>('');
  const { options: dormitoryOptions } = useDormitoryOptions();

  const query = useQuery({
    queryKey: ['boarding', 'att-statistics', from, to, dormitoryId],
    queryFn: () =>
      attendancesApi.statistics({
        from,
        to,
        ...(dormitoryId ? { dormitory_id: dormitoryId } : {}),
      }),
  });

  const data = query.data;

  const donut = useMemo(
    () => ({
      labels: (data?.status_distribution ?? []).map((t) => t.name),
      datasets: [
        {
          data: (data?.status_distribution ?? []).map((t) => t.count),
          backgroundColor: (data?.status_distribution ?? []).map((t) => t.color || '#94a3b8'),
          borderWidth: 0,
        },
      ],
    }),
    [data],
  );

  const trend = useMemo(
    () => ({
      labels: (data?.daily ?? []).map((d) => fmtDay(d.date)),
      datasets: [
        {
          label: 'Hadir',
          data: (data?.daily ?? []).map((d) => d.present),
          borderColor: '#16a34a',
          backgroundColor: 'rgba(22,163,74,0.15)',
          fill: true,
          tension: 0.3,
          pointRadius: 0,
        },
        {
          label: 'Terlambat',
          data: (data?.daily ?? []).map((d) => d.late),
          borderColor: '#f59e0b',
          backgroundColor: 'rgba(245,158,11,0.12)',
          fill: true,
          tension: 0.3,
          pointRadius: 0,
        },
        {
          label: 'Tidak hadir/izin',
          data: (data?.daily ?? []).map((d) => d.not_present),
          borderColor: '#ef4444',
          backgroundColor: 'rgba(239,68,68,0.10)',
          fill: true,
          tension: 0.3,
          pointRadius: 0,
        },
      ],
    }),
    [data],
  );

  const dorms = data?.by_dormitory ?? [];
  const topAbsentees = data?.top_absentees ?? [];
  const summary = data?.summary;

  return (
    <div className="space-y-3">
      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-4">
          <FormField label="Dari tanggal">
            <Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
          </FormField>
          <FormField label="Sampai tanggal">
            <Input type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} />
          </FormField>
          <FormField label="Asrama">
            <SearchSelect options={dormitoryOptions} value={dormitoryId} onChange={(v) => setDormitoryId(toId(v))} placeholder="-- Semua asrama --" />
          </FormField>
        </div>
      </Card>

      {query.isLoading ? (
        <Card><div className="flex items-center justify-center gap-2 py-14 text-slate-400"><Spinner /> Memuat statistik...</div></Card>
      ) : !data || summary?.records === 0 ? (
        <Card><EmptyState title="Belum ada data absensi pada rentang ini" /></Card>
      ) : (
        <>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <Card className="p-4 text-center">
              <p className="text-2xl font-semibold text-slate-800">{summary?.records ?? 0}</p>
              <p className="text-xs text-slate-500">Total catatan</p>
            </Card>
            <Card className="p-4 text-center">
              <p className="text-2xl font-semibold text-emerald-600">{summary?.present_percent ?? 0}%</p>
              <p className="text-xs text-slate-500">Rata-rata kehadiran</p>
            </Card>
            <Card className="p-4 text-center">
              <p className="text-2xl font-semibold text-amber-600">{summary?.late ?? 0}</p>
              <p className="text-xs text-slate-500">Terlambat</p>
            </Card>
            <Card className="p-4 text-center">
              <p className="text-2xl font-semibold text-red-600">{summary?.not_present ?? 0}</p>
              <p className="text-xs text-slate-500">Tidak hadir/izin</p>
            </Card>
          </div>

          <div className="grid grid-cols-1 gap-3 lg:grid-cols-5">
            <Card className="p-4 lg:col-span-2">
              <p className="mb-3 text-sm font-medium text-slate-700">Distribusi status</p>
              <div className="mx-auto max-w-[260px]">
                <Doughnut data={donut} options={{ plugins: { legend: { position: 'bottom' } }, cutout: '62%' }} />
              </div>
            </Card>
            <Card className="p-4 lg:col-span-3">
              <p className="mb-3 text-sm font-medium text-slate-700">Tren harian</p>
              <Line
                data={trend}
                options={{
                  responsive: true,
                  interaction: { mode: 'index', intersect: false },
                  plugins: { legend: { position: 'bottom' } },
                  scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                }}
              />
            </Card>
          </div>

          <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
            <Card className="p-4">
              <p className="mb-3 text-sm font-medium text-slate-700">Perbandingan asrama</p>
              {dorms.length === 0 ? (
                <p className="py-6 text-center text-sm text-slate-400">Tidak ada data asrama.</p>
              ) : (
                <ul className="space-y-2">
                  {dorms.map((d) => (
                    <li key={d.dormitory_id} className="space-y-1">
                      <div className="flex items-center justify-between text-sm">
                        <span className="font-medium text-slate-700">{d.name}</span>
                        <span className="text-xs text-slate-400">
                          {d.present}/{d.total} hadir ({d.present_percent}%)
                        </span>
                      </div>
                      <div className="flex h-2.5 w-full overflow-hidden rounded-full bg-slate-100">
                        <div className="h-full bg-emerald-500" style={{ width: `${(d.present / Math.max(1, d.total)) * 100}%` }} />
                        <div className="h-full bg-amber-400" style={{ width: `${(d.late / Math.max(1, d.total)) * 100}%` }} />
                        <div className="h-full bg-red-400" style={{ width: `${(d.not_present / Math.max(1, d.total)) * 100}%` }} />
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </Card>
            <Card className="p-4">
              <p className="mb-3 text-sm font-medium text-slate-700">Santri paling sering tidak hadir</p>
              {topAbsentees.length === 0 ? (
                <p className="py-6 text-center text-sm text-slate-400">Tidak ada ketidakhadiran pada rentang ini.</p>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                        <th className="py-2 pr-2 font-medium">Santri</th>
                        <th className="py-2 pr-2 font-medium">Kamar</th>
                        <th className="py-2 text-right font-medium">Tdk hadir</th>
                      </tr>
                    </thead>
                    <tbody>
                      {topAbsentees.map((s) => (
                        <tr key={s.student_id} className="border-b border-slate-50 last:border-0">
                          <td className="py-2 pr-2">
                            <p className="font-medium text-slate-800">{s.full_name}</p>
                            <p className="text-xs text-slate-400">{s.nis}</p>
                          </td>
                          <td className="py-2 pr-2 text-xs text-slate-600">
                            {s.dormitory !== '-' ? `${s.dormitory}${s.room !== '-' ? ` • ${s.room}` : ''}` : '—'}
                          </td>
                          <td className="py-2 text-right font-semibold text-red-600">{s.absent_count}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </Card>
          </div>
        </>
      )}
    </div>
  );
}
