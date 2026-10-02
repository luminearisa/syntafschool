import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { transportationApi } from '../api/transportationApi';
import { DataTable, Money, Panel, Progress, Stat, StatusPill } from '../components/TmsUi';
import type { RouteAnalyticsRow, Trip } from '../types';

/**
 * Analitik & laporan (§78-§82): metrik operasional, utilisasi rute/kendaraan,
 * laporan pengemudi (dengan disclaimer), biaya, dan penutupan harian.
 */
export default function TransportationReportsPage() {
  const today = new Date().toISOString().slice(0, 10);
  const monthStart = today.slice(0, 8) + '01';
  const [from, setFrom] = useState(monthStart);
  const [to, setTo] = useState(today);
  const [closeDate, setCloseDate] = useState(today);

  const metrics = useQuery({ queryKey: ['transportation', 'metrics', from, to], queryFn: () => transportationApi.metrics({ from, to }) });
  const routeAnalytics = useQuery({ queryKey: ['transportation', 'route-analytics', from, to], queryFn: () => transportationApi.routeAnalytics({ from, to }) });
  const vehicleAnalytics = useQuery({ queryKey: ['transportation', 'vehicle-analytics', from, to], queryFn: () => transportationApi.vehicleAnalytics({ from, to }) });
  const driverAnalytics = useQuery({ queryKey: ['transportation', 'driver-analytics', from, to], queryFn: () => transportationApi.driverAnalytics({ from, to }) });
  const costs = useQuery({ queryKey: ['transportation', 'costs', from, to], queryFn: () => transportationApi.costs({ from, to }) });
  const closings = useQuery({ queryKey: ['transportation', 'closings'], queryFn: () => transportationApi.closings({}) });
  const dailyReport = useQuery({ queryKey: ['transportation', 'daily-report', today], queryFn: () => transportationApi.dailyReport({ date: today }) });

  const closeDay = useMutation({
    mutationFn: () => transportationApi.closeDay({ date: closeDate, force: true }),
    onSuccess: () => { toast.success('Penutupan harian dihitung'); closings.refetch(); },
    onError: (error: unknown) => toast.error(readError(error, 'Penutupan gagal')),
  });

  const planCapacity = useMutation({
    mutationFn: () => transportationApi.planCapacity({ passengers: 120, vehicle_capacity: 40 }),
    onSuccess: (result) => {
      const plan = result as { required_vehicles?: number; required_drivers?: number; explanation?: string };
      toast.success(`${plan.explanation} → ${plan.required_vehicles} kendaraan, ${plan.required_drivers} pengemudi`);
    },
  });

  const data = metrics.data;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-2">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Analitik & laporan</h1>
          <p className="text-sm text-slate-500">Metrik operasional dari data nyata; laporan pengemudi bersifat operasional, bukan penilaian kinerja otomatis.</p>
        </div>
        <div className="flex items-end gap-2">
          <label className="text-xs text-slate-500">Dari<Input type="date" value={from} onChange={(event) => setFrom(event.target.value)} className="mt-1" /></label>
          <label className="text-xs text-slate-500">Sampai<Input type="date" value={to} onChange={(event) => setTo(event.target.value)} className="mt-1" /></label>
        </div>
      </div>

      {data ? (
        <div className="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6">
          <Stat label="Trip" value={data.trips.total} hint={`${data.trips.completed} selesai`} />
          <Stat label="Tepat waktu" value={`${data.on_time_rate}%`} tone={data.on_time_rate >= 90 ? 'good' : 'warn'} hint={`rata-rata delay ${data.average_delay_minutes} mnt`} />
          <Stat label="Penumpang" value={data.passengers.boarded} hint={`no-show ${data.passengers.no_show_rate}%`} />
          <Stat label="Okupansi" value={`${data.occupancy_percent}%`} />
          <Stat label="Efisiensi BBM" value={data.fuel.efficiency_km_per_liter ? `${data.fuel.efficiency_km_per_liter} km/l` : '—'} hint={`${data.distance_km} km`} />
          <Stat label="Biaya" value={<Money value={data.cost.total} compact />} hint={`per trip ${data.cost.cost_per_trip ? `Rp${Math.round(data.cost.cost_per_trip).toLocaleString('id-ID')}` : '—'}`} />
        </div>
      ) : null}

      <div className="grid gap-4 lg:grid-cols-2">
        <Panel title="Utilisasi rute" description="Okupansi, jarak, biaya, dan insiden per rute (§79)">
          <DataTable<RouteAnalyticsRow>
            loading={routeAnalytics.isLoading}
            rows={routeAnalytics.data?.routes ?? []}
            empty="Belum ada data rute pada periode ini."
            columns={[
              { key: 'route', header: 'Rute', render: (row) => `${row.route_code} · ${row.route_name}` },
              { key: 'occupancy', header: 'Okupansi', render: (row) => <Progress value={row.passengers_boarded} max={row.capacity * Math.max(1, row.trips) || 1} /> },
              { key: 'trips', header: 'Trip', render: (row) => row.trips },
              { key: 'delay', header: 'Rata delay', render: (row) => `${row.average_delay_minutes} mnt` },
              { key: 'cost', header: 'Biaya', render: (row) => <Money value={row.cost} compact /> },
              { key: 'incidents', header: 'Insiden', render: (row) => row.incidents },
            ]}
          />
        </Panel>

        <Panel title="Biaya per kategori" description="Transportasi tidak membuat ledger akuntansi; angka diteruskan ke Finance (§60/§61)">
          <DataTable<{ category: string; entries: number; amount: number }>
            loading={costs.isLoading}
            rows={Object.entries(costs.data?.by_category ?? {}).map(([category, row]) => ({ category, ...row }))}
            empty="Belum ada biaya pada periode ini."
            columns={[
              { key: 'category', header: 'Kategori', render: (row) => row.category },
              { key: 'entries', header: 'Catatan', render: (row) => row.entries },
              { key: 'amount', header: 'Jumlah', render: (row) => <Money value={row.amount} /> },
            ]}
          />
          <div className="mt-3 flex flex-wrap gap-4 text-xs text-slate-600">
            <span>Total: <Money value={costs.data?.total_cost ?? 0} /></span>
            <span>Per trip: {costs.data?.cost_per_trip ? <Money value={costs.data.cost_per_trip} /> : '—'}</span>
            <span>Per penumpang: {costs.data?.cost_per_passenger ? <Money value={costs.data.cost_per_passenger} /> : '—'}</span>
            <span>Belum diposting: {costs.data?.unposted ?? 0}</span>
          </div>
        </Panel>
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Panel title="Utilisasi kendaraan" description="Trip, jarak, BBM, pemeliharaan, dan insiden per kendaraan (§80)">
          <DataTable<{ vehicle_code: string; status: string; trips: number; distance_km: number; maintenance_cost: number; incidents: number; cost: number; fuel: { efficiency_km_per_liter: number | null } }>
            loading={vehicleAnalytics.isLoading}
            rows={vehicleAnalytics.data ?? []}
            empty="Belum ada data kendaraan."
            columns={[
              { key: 'code', header: 'Kendaraan', render: (row) => row.vehicle_code },
              { key: 'status', header: 'Status', render: (row) => <StatusPill status={row.status} /> },
              { key: 'trips', header: 'Trip', render: (row) => row.trips },
              { key: 'distance', header: 'Jarak', render: (row) => `${row.distance_km} km` },
              { key: 'fuel', header: 'km/l', render: (row) => row.fuel.efficiency_km_per_liter ?? '—' },
              { key: 'maintenance', header: 'Pemeliharaan', render: (row) => <Money value={row.maintenance_cost} compact /> },
              { key: 'incidents', header: 'Insiden', render: (row) => row.incidents },
            ]}
          />
        </Panel>

        <Panel title="Laporan operasional pengemudi" description={driverAnalytics.data?.disclaimer ?? 'Operasional'}>
          <DataTable<{ driver_code: string; trips: number; distance_km: number; delays: number; incidents: number; checklist_completion: number; training_status: { complete: boolean; valid: number; required: number } }>
            loading={driverAnalytics.isLoading}
            rows={driverAnalytics.data?.drivers ?? []}
            empty="Belum ada data pengemudi."
            columns={[
              { key: 'code', header: 'Pengemudi', render: (row) => row.driver_code },
              { key: 'trips', header: 'Trip', render: (row) => row.trips },
              { key: 'distance', header: 'Jarak', render: (row) => `${row.distance_km} km` },
              { key: 'delays', header: 'Keterlambatan', render: (row) => row.delays },
              { key: 'checklist', header: 'Checklist', render: (row) => `${row.checklist_completion}%` },
              { key: 'training', header: 'Pelatihan', render: (row) => row.training_status.complete
                ? <StatusPill status="ACTIVE" label="lengkap" />
                : <StatusPill status="WAITING" label={`${row.training_status.valid}/${row.training_status.required}`} /> },
            ]}
          />
        </Panel>
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Panel title="Penutupan harian" description="Setelah ditutup, perubahan wajib beralasan (Rule 13)">
          <div className="flex items-end gap-2">
            <Input type="date" value={closeDate} onChange={(event) => setCloseDate(event.target.value)} />
            <Button onClick={() => closeDay.mutate()} loading={closeDay.isPending}>Tutup hari</Button>
          </div>
          <div className="mt-3">
            <DataTable<Record<string, unknown>>
              loading={closings.isLoading}
              rows={closings.data ?? []}
              empty="Belum ada penutupan."
              columns={[
                { key: 'date', header: 'Tanggal', render: (row) => String(row.closing_date) },
                { key: 'trips', header: 'Trip', render: (row) => `${row.trips_completed ?? 0}/${row.trips_planned ?? 0}` },
                { key: 'passengers', header: 'Penumpang', render: (row) => String(row.passengers_transported ?? 0) },
                { key: 'cost', header: 'Biaya', render: (row) => <Money value={Number(row.total_operational_cost ?? 0)} compact /> },
                { key: 'status', header: 'Status', render: (row) => <StatusPill status={String(row.status)} /> },
              ]}
            />
          </div>
        </Panel>

        <Panel title="Laporan harian" description={`Manifes, boarding, drop-off (${today})`}>
          {dailyReport.isLoading ? <p className="text-sm text-slate-500">Memuat…</p> : (
            <div className="space-y-2 text-sm">
              <div className="flex justify-between"><span className="text-slate-600">Trip</span><span>{(dailyReport.data?.trip_report as unknown[] | undefined)?.length ?? 0}</span></div>
              <div className="flex justify-between"><span className="text-slate-600">Naik</span><span>{String((dailyReport.data?.boarding_report as { boarded?: number })?.boarded ?? 0)}</span></div>
              <div className="flex justify-between"><span className="text-slate-600">Tidak hadir</span><span>{String((dailyReport.data?.boarding_report as { no_show?: number })?.no_show ?? 0)}</span></div>
              <div className="flex justify-between"><span className="text-slate-600">Turun</span><span>{String((dailyReport.data?.dropoff_report as { dropped_off?: number })?.dropped_off ?? 0)}</span></div>
              <div className="flex justify-between"><span className="text-slate-600">Insiden</span><span>{((dailyReport.data?.incident_report as unknown[]) ?? []).length}</span></div>
            </div>
          )}
        </Panel>

        <Panel title="Perencanaan kapasitas" description="Kebutuhan kendaraan dari jumlah penumpang (§65)">
          <Button onClick={() => planCapacity.mutate()} loading={planCapacity.isPending}>Hitung 120 penumpang / 40 kursi</Button>
          <p className="mt-3 text-xs text-slate-500">
            Perhitungan memakai kapasitas kendaraan yang benar-benar tersedia dan menampilkan estimasi biaya beserta asumsinya.
          </p>
          {routeAnalytics.data?.period ? (
            <p className="mt-2 text-xs text-slate-500">Periode analitik: {routeAnalytics.data.period.from} → {routeAnalytics.data.period.to}</p>
          ) : null}
        </Panel>
      </div>

      <Panel title="Contoh laporan manajemen" description="Biaya per siswa, per rute, dan utilisasi armada">
        <Button variant="outline" onClick={() => transportationApi.managementReport({ from, to }).then((report) => {
          const cost = report.transportation_cost as number | undefined;
          toast.info(`Biaya periode ${from}–${to}: Rp${(cost ?? 0).toLocaleString('id-ID')} · siswa dilayani ${report.students_served ?? 0}`);
        })}>Ambil laporan manajemen</Button>
        <div className="mt-3">
          <DataTable<Trip>
            rows={[]}
            columns={[]}
            empty="Laporan lengkap tersedia melalui endpoint /transportation/reports/management."
          />
        </div>
      </Panel>
    </div>
  );
}

function readError(error: unknown, fallback: string): string {
  const message = (error as { response?: { data?: { message?: string } } })?.response?.data?.message;

  return message ?? fallback;
}
