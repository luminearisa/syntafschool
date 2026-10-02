import { useQuery } from '@tanstack/react-query';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Link } from 'react-router-dom';
import { transportationApi } from '../api/transportationApi';
import { DataTable, DateTime, Money, Panel, Progress, Stat, StatusPill } from '../components/TmsUi';
import type { RouteCapacity, Trip } from '../types';

/**
 * Dasbor transportasi (§75).
 *
 * Angka yang ditampilkan adalah angka yang dipakai mengambil keputusan harian:
 * trip berjalan, kendaraan siap, pengemudi bertugas, penumpang belum naik,
 * insiden terbuka, dokumen mendekati kedaluwarsa, dan biaya hari ini.
 */
export default function TransportationDashboardPage() {
  const dashboard = useQuery({
    queryKey: ['transportation', 'dashboard'],
    queryFn: () => transportationApi.dashboard(),
    refetchInterval: 60_000,
  });
  const alerts = useQuery({ queryKey: ['transportation', 'alerts'], queryFn: () => transportationApi.alerts() });
  const trips = useQuery({ queryKey: ['transportation', 'trips', 'today'], queryFn: () => transportationApi.trips({}) });

  const data = dashboard.data;

  if (dashboard.isLoading || !data) {
    return <p className="text-sm text-slate-500">Memuat dasbor transportasi…</p>;
  }

  const capacityRows: RouteCapacity[] = data.route_capacity.routes;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Transportation Control Center</h1>
          <p className="text-sm text-slate-500">
            {data.date} · diperbarui {new Date(data.generated_at).toLocaleTimeString('id-ID')}
          </p>
        </div>
        <div className="flex gap-2">
          <Link to="/transportation/dispatch" className="rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white">
            Papan Dispatch
          </Link>
          <Link to="/transportation/driver-app" className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700">
            Aplikasi Pengemudi
          </Link>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4 lg:grid-cols-6">
        <Stat label="Trip hari ini" value={data.trips.total} hint={`${data.trips.completed} selesai · ${data.trips.active} berjalan`} />
        <Stat label="Terlambat" value={data.trips.delayed} tone={data.trips.delayed > 0 ? 'warn' : 'good'} hint={`${data.trips.unassigned} belum ditugaskan`} />
        <Stat label="Penumpang naik" value={data.passengers.boarded} hint={`dari ${data.passengers.expected} diharapkan`} />
        <Stat label="Belum naik" value={data.passengers.not_boarded} tone={data.passengers.not_boarded > 0 ? 'warn' : 'good'} hint={`${data.passengers.no_show} tidak hadir`} />
        <Stat label="Insiden terbuka" value={data.incidents.open} tone={data.incidents.open > 0 ? 'bad' : 'good'} hint={`${data.incidents.critical} kritis`} />
        <Stat label="Biaya hari ini" value={<Money value={data.cost.today_total} compact />} hint={data.cost.cost_per_passenger ? `${<Money value={data.cost.cost_per_passenger} />} / penumpang` : 'belum ada penumpang'} />
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Panel
          title="Kapasitas rute"
          description="Kapasitas, terisi, dan sisa kursi per rute (§27)"
          action={<Link to="/transportation/students" className="text-xs font-medium text-slate-600 underline">Kelola penugasan</Link>}
        >
          <DataTable<RouteCapacity>
            rows={capacityRows}
            columns={[
              { key: 'route', header: 'Rute', render: (row) => (
                <div>
                  <p className="font-medium text-slate-800">{row.route_name}</p>
                  <p className="text-xs text-slate-500">{row.route_code} · {row.vehicle_code ?? 'kendaraan belum ditetapkan'}</p>
                </div>
              ) },
              { key: 'capacity', header: 'Kursi', render: (row) => <Progress value={row.assigned} max={row.capacity} /> },
              { key: 'available', header: 'Sisa', render: (row) => row.available_seats },
              { key: 'waitlist', header: 'Daftar tunggu', render: (row) => row.waitlist_count > 0 ? <Badge tone="warning">{row.waitlist_count}</Badge> : '—' },
            ]}
          />
        </Panel>

        <Panel title="Status armada & pengemudi" description="Kesiapan operasional hari ini">
          <div className="grid grid-cols-2 gap-3">
            <Stat label="Kendaraan siap" value={data.vehicles.available} tone="good" hint={`${data.vehicles.on_trip} bertugas`} />
            <Stat label="Pemeliharaan" value={data.vehicles.maintenance} tone={data.vehicles.maintenance > 0 ? 'warn' : 'good'} hint={`${data.vehicles.inspection} inspeksi`} />
            <Stat label="Pengemudi bertugas" value={data.drivers.on_duty} hint={`${data.drivers.available} tersedia`} />
            <Stat label="Pengemudi tidak siap" value={data.drivers.unavailable} tone={data.drivers.unavailable > 0 ? 'warn' : 'good'} hint="cuti/off-duty/suspend" />
          </div>
          <div className="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
            <p className="font-medium">Peringatan dokumen</p>
            <p className="mt-1">
              {data.documents.expiring_soon} dokumen mendekati kedaluwarsa · {data.documents.expired} sudah kedaluwarsa
              {' '}<Link to="/transportation/fleet?tab=documents" className="underline">lihat dokumen</Link>
            </p>
          </div>
        </Panel>
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Panel title="Biaya per kategori (hari ini)" description="Biaya operasional dari catatan armada, bukan ledger akuntansi (§60)">
          {Object.keys(data.cost.by_category).length === 0 ? (
            <p className="text-sm text-slate-500">Belum ada biaya tercatat hari ini.</p>
          ) : (
            <ul className="space-y-2 text-sm">
              {Object.entries(data.cost.by_category).map(([category, row]) => (
                <li key={category} className="flex items-center justify-between">
                  <span className="text-slate-600">{category} <span className="text-xs text-slate-400">({row.entries})</span></span>
                  <span className="font-medium text-slate-800"><Money value={row.amount} /></span>
                </li>
              ))}
            </ul>
          )}
        </Panel>

        <Card>
          <CardHeader><CardTitle>Peringatan operasional</CardTitle></CardHeader>
          <CardBody className="space-y-2 text-sm">
            {alerts.isLoading ? <p className="text-slate-500">Memuat…</p> : (
              <>
                <Row label="Dokumen mendekati kedaluwarsa" value={String(alerts.data?.documents_expiring_count ?? 0)} />
                <Row label="Trip besok tanpa penugasan" value={String(alerts.data?.trips_without_assignment_tomorrow ?? 0)} />
                <Row label="Rute penuh" value={String((alerts.data?.full_routes as unknown[] | undefined)?.length ?? 0)} />
                <Row label="Insiden belum tuntas" value={String(alerts.data?.open_incidents ?? 0)} />
                <Row label="Biaya belum diposting ke keuangan" value={String(alerts.data?.pending_costs ?? 0)} />
                <Row label="Daftar tunggu menunggu kursi" value={String(alerts.data?.waiting_list_total ?? 0)} />
              </>
            )}
          </CardBody>
        </Card>
      </div>

      <Panel title="Trip hari ini" description="Urut waktu keberangkatan" action={<Link to="/transportation/trips" className="text-xs font-medium text-slate-600 underline">Semua trip & manifes</Link>}>
        <DataTable<Trip>
          loading={trips.isLoading}
          rows={trips.data ?? []}
          empty="Belum ada trip untuk hari ini."
          columns={[
            { key: 'trip', header: 'Trip', render: (row) => (
              <div>
                <p className="font-medium text-slate-800">{row.trip_number}</p>
                <p className="text-xs text-slate-500">{row.route?.name ?? '—'} · {row.direction}</p>
              </div>
            ) },
            { key: 'departure', header: 'Berangkat', render: (row) => <DateTime value={row.planned.departure} timeOnly /> },
            { key: 'driver', header: 'Pengemudi', render: (row) => row.driver?.full_name ?? <span className="text-slate-400">belum ditugaskan</span> },
            { key: 'vehicle', header: 'Kendaraan', render: (row) => row.vehicle?.vehicle_code ?? <span className="text-slate-400">belum ditugaskan</span> },
            { key: 'counts', header: 'Naik / Turun', render: (row) => `${row.counts.boarded} / ${row.counts.dropped_off}` },
            { key: 'delay', header: 'Delay', render: (row) => row.actual.delay_minutes > 0 ? `${row.actual.delay_minutes} mnt` : '—' },
            { key: 'status', header: 'Status', render: (row) => <StatusPill status={row.status} /> },
          ]}
        />
      </Panel>
    </div>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between border-b border-slate-100 pb-1 last:border-0">
      <span className="text-slate-600">{label}</span>
      <span className="font-medium text-slate-800">{value}</span>
    </div>
  );
}
