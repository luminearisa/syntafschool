import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Activity, CalendarDays, Landmark, Percent, RefreshCw, Users } from 'lucide-react';
import { Link } from 'react-router-dom';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Spinner } from '@/components/ui/Spinner';
import { getErrorMessage } from '@/lib/axios';
import { usePermission } from '@/hooks/usePermission';
import { religiousApi } from '../api/religiousApi';
import {
  ErrorCard,
  localDate,
  MetricCard,
  prayerLabel,
  ReligiousPageHeader,
} from '../components/ReligiousUi';

export function ReligiousDashboardPage() {
  const { can } = usePermission();
  const [date, setDate] = useState(localDate());
  const dashboardQuery = useQuery({
    queryKey: ['religious', 'dashboard', date],
    queryFn: () => religiousApi.dashboard(date),
  });
  const metaQuery = useQuery({
    queryKey: ['religious', 'meta'],
    queryFn: religiousApi.meta,
  });
  const integrationsQuery = useQuery({
    queryKey: ['religious', 'integrations'],
    queryFn: religiousApi.integrations,
  });

  const dashboard = dashboardQuery.data;
  const attendance = dashboard?.prayer_attendance;
  const prayerNames = Object.fromEntries(
    (metaQuery.data?.prayer_types ?? []).map((prayer) => [prayer.code, prayer.name]),
  );
  const prayerRows = Object.entries(dashboard?.by_prayer ?? {});
  const integrations = Object.entries(integrationsQuery.data?.integrations ?? {});
  const provider = dashboard?.providers?.prayer_time ?? integrationsQuery.data?.prayer_time ?? {};
  const quickLinks = [
    { to: '/religious/prayers', label: 'Jadwal shalat', permission: 'religious.prayer.view' },
    { to: '/religious/attendance', label: 'Presensi shalat', permission: 'religious.attendance.view' },
    { to: '/religious/mosques', label: 'Data masjid', permission: 'religious.mosque.view' },
  ].filter((item) => can(item.permission));

  return (
    <div className="space-y-5">
      <ReligiousPageHeader
        title="Dashboard keagamaan"
        description="Ringkasan operasional masjid, jadwal shalat, presensi, dan kegiatan. Angka presensi bersifat deskriptif, bukan penilaian karakter."
        actions={
          <>
            <label className="sr-only" htmlFor="religious-dashboard-date">Tanggal ringkasan</label>
            <Input
              id="religious-dashboard-date"
              type="date"
              className="w-40"
              value={date}
              onChange={(event) => setDate(event.target.value)}
            />
            <Button
              variant="outline"
              size="icon"
              title="Muat ulang ringkasan"
              aria-label="Muat ulang ringkasan"
              onClick={() => {
                dashboardQuery.refetch();
                metaQuery.refetch();
                integrationsQuery.refetch();
              }}
            >
              <RefreshCw className={dashboardQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
            </Button>
          </>
        }
      />

      <div className="flex flex-wrap gap-2">
        {quickLinks.map(({ to, label }) => (
          <Link
            key={to}
            to={to}
            className="inline-flex h-8 items-center justify-center rounded-lg border border-slate-300 bg-white px-3 text-xs font-medium text-slate-700 transition-colors hover:bg-slate-50"
          >
            {label}
          </Link>
        ))}
      </div>

      {dashboardQuery.isLoading ? (
        <Card className="flex items-center justify-center gap-2 p-10 text-sm text-slate-500">
          <Spinner /> Memuat dashboard keagamaan...
        </Card>
      ) : dashboardQuery.isError ? (
        <ErrorCard
          title="Dashboard keagamaan belum dapat dibuka"
          message={getErrorMessage(dashboardQuery.error)}
          onRetry={() => dashboardQuery.refetch()}
        />
      ) : (
        <>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <MetricCard label="Masjid aktif" value={dashboard?.mosques ?? 0} icon={<Landmark className="h-5 w-5" />} />
            <MetricCard label="Jenis shalat" value={dashboard?.prayer_types ?? 0} detail="Termasuk jenis yang dikonfigurasi institusi" icon={<CalendarDays className="h-5 w-5" />} tone="blue" />
            <MetricCard label="Catatan presensi" value={attendance?.total ?? 0} detail={`${attendance?.present ?? 0} hadir · ${attendance?.late ?? 0} terlambat`} icon={<Users className="h-5 w-5" />} tone="amber" />
            <MetricCard label="Tingkat kehadiran" value={attendance?.attendance_rate == null ? '—' : `${attendance.attendance_rate}%`} detail="Hadir + terlambat" icon={<Percent className="h-5 w-5" />} />
          </div>

          <div className="grid grid-cols-1 gap-4 xl:grid-cols-[1.4fr_1fr]">
            <Card>
              <CardHeader className="flex flex-row items-center justify-between gap-3">
                <div>
                  <CardTitle className="text-sm">Presensi per shalat</CardTitle>
                  <p className="mt-1 text-xs text-slate-500">Ringkasan tanggal {date}</p>
                </div>
                <Badge tone="info">Data deskriptif</Badge>
              </CardHeader>
              <CardBody className="p-0">
                {prayerRows.length === 0 ? (
                  <p className="p-5 text-sm text-slate-500">Belum ada catatan presensi untuk tanggal ini.</p>
                ) : (
                  <div className="overflow-x-auto">
                    <table className="w-full min-w-[560px] text-sm">
                      <thead>
                        <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                          <th className="px-5 py-3 font-medium">Shalat</th>
                          <th className="px-3 py-3 text-right font-medium">Total</th>
                          <th className="px-3 py-3 text-right font-medium">Hadir</th>
                          <th className="px-3 py-3 text-right font-medium">Terlambat</th>
                          <th className="px-5 py-3 text-right font-medium">Absen / izin</th>
                        </tr>
                      </thead>
                      <tbody>
                        {prayerRows.map(([code, row]) => (
                          <tr key={code} className="border-b border-slate-50 last:border-0">
                            <td className="px-5 py-3 font-medium text-slate-800">{prayerLabel(code, prayerNames)}</td>
                            <td className="px-3 py-3 text-right text-slate-600">{row.total ?? 0}</td>
                            <td className="px-3 py-3 text-right text-emerald-700">{row.present ?? 0}</td>
                            <td className="px-3 py-3 text-right text-amber-700">{row.late ?? 0}</td>
                            <td className="px-5 py-3 text-right text-slate-600">{(row.absent ?? 0) + (row.excused ?? 0)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </CardBody>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="text-sm">Aktivitas hari ini</CardTitle>
              </CardHeader>
              <CardBody className="space-y-3">
                <div className="flex items-center justify-between rounded-lg bg-slate-50 p-3">
                  <div className="flex items-center gap-3">
                    <span className="rounded-lg bg-emerald-100 p-2 text-emerald-700"><Activity className="h-4 w-4" /></span>
                    <span className="text-sm text-slate-600">Kegiatan keagamaan</span>
                  </div>
                  <span className="text-lg font-semibold text-slate-900">{dashboard?.activities_today ?? 0}</span>
                </div>
                <div className="flex items-center justify-between rounded-lg bg-slate-50 p-3">
                  <div className="flex items-center gap-3">
                    <span className="rounded-lg bg-sky-100 p-2 text-sky-700"><CalendarDays className="h-4 w-4" /></span>
                    <span className="text-sm text-slate-600">Jumat mendatang</span>
                  </div>
                  <span className="text-lg font-semibold text-slate-900">{dashboard?.friday_upcoming ?? 0}</span>
                </div>
                {dashboard?.ramadan && Object.keys(dashboard.ramadan).length > 0 && (
                  <div className="rounded-lg border border-amber-100 bg-amber-50 p-3">
                    <p className="text-xs font-semibold uppercase tracking-wide text-amber-800">Ringkasan Ramadan</p>
                    <div className="mt-2 flex flex-wrap gap-2">
                      {Object.entries(dashboard.ramadan).slice(0, 4).map(([key, value]) => (
                        <Badge key={key} tone="warning">{key.replace(/_/g, ' ')}: {String(value ?? '—')}</Badge>
                      ))}
                    </div>
                  </div>
                )}
                {can('religious.ramadan.view') && (
                  <Link to="/religious/ramadan" className="inline-flex text-sm font-medium text-emerald-700 hover:text-emerald-800">
                    Buka program Ramadan <span className="ml-1" aria-hidden="true">→</span>
                  </Link>
                )}
              </CardBody>
            </Card>
          </div>

          <div className="grid grid-cols-1 gap-4 xl:grid-cols-[1fr_1fr]">
            <Card>
              <CardHeader>
                <CardTitle className="text-sm">Kesiapan sumber waktu shalat</CardTitle>
              </CardHeader>
              <CardBody>
                {Object.keys(provider).length === 0 ? (
                  <p className="text-sm text-slate-500">Status penyedia jadwal belum tersedia.</p>
                ) : (
                  <div className="flex flex-wrap items-center gap-2">
                    <Badge tone={provider.available ? 'success' : 'warning'}>
                      {provider.available ? 'Penyedia aktif' : 'Mode cadangan'}
                    </Badge>
                    {provider.active_provider != null && <Badge tone="neutral">Sumber: {String(provider.active_provider)}</Badge>}
                    {provider.reason != null && <p className="basis-full text-xs text-slate-500">{String(provider.reason)}</p>}
                  </div>
                )}
                {dashboard?.providers?.prayer_time && (
                  <p className="mt-3 text-xs leading-5 text-slate-500">
                    Jadwal yang dihasilkan tetap perlu dikaitkan dengan masjid atau kampus.
                  </p>
                )}
              </CardBody>
            </Card>

            <Card>
              <CardHeader className="flex flex-row items-center justify-between gap-3">
                <div>
                  <CardTitle className="text-sm">Integrasi modul</CardTitle>
                  <p className="mt-1 text-xs text-slate-500">Konektor lintas modul yang terdaftar</p>
                </div>
                {integrationsQuery.isFetching && <Spinner className="h-4 w-4" />}
              </CardHeader>
              <CardBody>
                {integrationsQuery.isError ? (
                  <p className="text-sm text-slate-500">{getErrorMessage(integrationsQuery.error)}</p>
                ) : integrations.length === 0 ? (
                  <p className="text-sm text-slate-500">Belum ada status integrasi yang tersedia.</p>
                ) : (
                  <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    {integrations.slice(0, 8).map(([name, item]) => (
                      <div key={name} className="flex items-center justify-between gap-2 rounded-lg border border-slate-100 px-3 py-2">
                        <span className="truncate text-xs font-medium capitalize text-slate-700">{name.replace(/_/g, ' ')}</span>
                        <Badge tone={item.available ? 'success' : 'neutral'}>
                          {item.available ? 'Tersedia' : 'Belum aktif'}
                        </Badge>
                      </div>
                    ))}
                  </div>
                )}
              </CardBody>
            </Card>
          </div>
          <p className="rounded-lg border border-slate-200 bg-white px-4 py-3 text-xs leading-5 text-slate-500">
            Data kehadiran digunakan untuk pemantauan operasional. Sistem tidak menyimpulkan karakter atau kualitas moral siswa dari statistik ini.
          </p>
        </>
      )}
      {metaQuery.isError && !dashboardQuery.isError && (
        <p className="text-xs text-slate-500">Daftar jenis shalat gagal dimuat: {getErrorMessage(metaQuery.error)}</p>
      )}
    </div>
  );
}
