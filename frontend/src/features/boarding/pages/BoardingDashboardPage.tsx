import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Card } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { StatCard, StudentStatusBadge, fmtDateTime } from '../components/BoardingUi';
import { dashboardApi } from '../api/boardingApi';
import { getErrorMessage } from '@/lib/axios';

const statusOrder = [
  'in_dorm',
  'out_with_permission',
  'home',
  'sick',
  'isolation',
] as const;

export function BoardingDashboardPage() {
  const query = useQuery({ queryKey: ['boarding', 'dashboard'], queryFn: dashboardApi.stats });

  if (query.isLoading) {
    return (
      <Card>
        <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
          <Spinner /> Memuat dashboard asrama...
        </div>
      </Card>
    );
  }

  if (query.isError || !query.data) {
    return (
      <Card>
        <EmptyState
          title="Gagal memuat dashboard"
          description={getErrorMessage(query.error)}
          action={
            <button className="mt-2 text-sm text-brand-600" onClick={() => query.refetch()}>
              Coba lagi
            </button>
          }
        />
      </Card>
    );
  }

  const d = query.data;
  const bedPercent = d.beds.total > 0 ? Math.round((d.beds.occupied / d.beds.total) * 100) : 0;

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Dashboard Asrama</h1>
        <p className="text-sm text-slate-500">
          Angka hari ini untuk seluruh asrama (atau binaan Anda). Diperbarui{' '}
          {fmtDateTime(d.generated_at)}.
        </p>
      </div>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <StatCard label="Santri di Asrama" value={d.students.in_boarding} tone="brand" />
        <StatCard
          label="Sedang di Luar"
          value={d.students.out_now}
          hint="Izin keluar + kepulangan"
        />
        <StatCard
          label="Kamar Terisi"
          value={`${d.beds.occupied}/${d.beds.total}`}
          hint={`${bedPercent}% okupansi • ${d.beds.maintenance} pemeliharaan`}
        />
        <StatCard label="Isolasi" value={d.health.in_isolation} tone="red" hint="Sedang di ruang isolasi" />
      </div>

      <Card className="p-4">
        <h3 className="mb-3 text-sm font-semibold text-slate-800">Status Santri Hari Ini (§42)</h3>
        <div className="flex flex-wrap items-center gap-3">
          {statusOrder.map((s) => (
            <div key={s} className="flex items-center gap-2">
              <StudentStatusBadge status={s} />
              <span className="text-sm font-semibold text-slate-700">
                {d.students.status_counts[s] ?? 0}
              </span>
            </div>
          ))}
        </div>
      </Card>

      <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
        <Card className="p-4">
          <h3 className="mb-2 text-sm font-semibold text-slate-800">Kehadiran Hari Ini</h3>
          <p className="text-2xl font-semibold text-slate-900">{d.attendance.total}</p>
          <p className="text-xs text-slate-500">{d.attendance.late} terlambat</p>
          <ul className="mt-2 space-y-1">
            {Object.entries(d.attendance.by_type).map(([type, n]) => (
              <li key={type} className="flex justify-between text-xs text-slate-600">
                <span>{type}</span>
                <span className="font-medium">{n}</span>
              </li>
            ))}
          </ul>
        </Card>

        <Card className="p-4">
          <h3 className="mb-2 text-sm font-semibold text-slate-800">Kedisiplinan</h3>
          <ul className="space-y-1 text-sm text-slate-600">
            <li className="flex justify-between">
              <span>Pelanggaran bulan ini</span>
              <span className="font-semibold">{d.discipline.violations_month}</span>
            </li>
            <li className="flex justify-between">
              <span>{d.point_label} bulan ini</span>
              <span className="font-semibold">{d.discipline.points_month}</span>
            </li>
            <li className="flex justify-between">
              <span>Pelanggaran terbuka</span>
              <span className="font-semibold">{d.discipline.violations_open}</span>
            </li>
            <li className="flex justify-between">
              <span>Tindakan menunggu</span>
              <span className="font-semibold">{d.discipline.actions_pending}</span>
            </li>
          </ul>
        </Card>

        <Card className="p-4">
          <h3 className="mb-2 text-sm font-semibold text-slate-800">Perlu Tindakan</h3>
          <ul className="space-y-1 text-sm text-slate-600">
            <li className="flex justify-between">
              <span>Izin menunggu persetujuan</span>
              <span className="font-semibold">{d.permissions.awaiting_approval}</span>
            </li>
            <li className="flex justify-between">
              <span>Kepulangan menunggu</span>
              <span className="font-semibold">{d.homecomings.awaiting_approval}</span>
            </li>
            <li className="flex justify-between">
              <span>Pulang terlambat (bulan ini)</span>
              <span className="font-semibold">{d.permissions.late_returns_month}</span>
            </li>
            <li className="flex justify-between">
              <span>Tamu hari ini</span>
              <span className="font-semibold">{d.visitors.present}</span>
            </li>
            <li className="flex justify-between">
              <span>Catatan kesehatan aktif</span>
              <span className="font-semibold">{d.health.open_records}</span>
            </li>
            <li className="flex justify-between">
              <span>Prestasi bulan ini</span>
              <span className="font-semibold">{d.achievements.this_month}</span>
            </li>
          </ul>
        </Card>
      </div>

      <div className="flex flex-wrap gap-2 text-sm">
        <Link to="/boarding/monitoring" className="rounded-md bg-brand-50 px-3 py-1.5 text-brand-700 hover:bg-brand-100">
          Monitoring santri
        </Link>
        <Link to="/boarding/permissions" className="rounded-md bg-brand-50 px-3 py-1.5 text-brand-700 hover:bg-brand-100">
          Izin keluar
        </Link>
        <Link to="/boarding/violations" className="rounded-md bg-brand-50 px-3 py-1.5 text-brand-700 hover:bg-brand-100">
          Pelanggaran
        </Link>
        <Link to="/boarding/health" className="rounded-md bg-brand-50 px-3 py-1.5 text-brand-700 hover:bg-brand-100">
          Kesehatan
        </Link>
        <Link to="/boarding/reports" className="rounded-md bg-brand-50 px-3 py-1.5 text-brand-700 hover:bg-brand-100">
          Laporan
        </Link>
      </div>
    </div>
  );
}
