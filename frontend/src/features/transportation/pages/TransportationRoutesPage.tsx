import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useSearchParams } from 'react-router-dom';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { useAuthStore } from '@/stores/authStore';
import { transportationApi } from '../api/transportationApi';
import { DataTable, Panel, Progress, Stat, StatusPill } from '../components/TmsUi';
import type { Route, RouteCapacity, RouteStop } from '../types';

/**
 * Rute & halte (§16-§21, §97).
 * Perubahan rute tidak destruktif: setiap penyuntingan menghasilkan versi baru
 * sehingga penugasan siswa lama tetap dapat ditelusuri.
 */
export default function TransportationRoutesPage() {
  const can = useAuthStore((state) => state.can);
  const [params, setParams] = useSearchParams();
  const tab = params.get('tab') ?? 'routes';
  const queryClient = useQueryClient();
  const [selectedRouteId, setSelectedRouteId] = useState<number | null>(null);
  const [form, setForm] = useState({ code: '', name: '', route_type: 'ROUND_TRIP', origin: '', destination: '', default_vehicle_capacity: '40' });
  const [stopForm, setStopForm] = useState({ name: '', stop_type: 'PICKUP_POINT', address: '', latitude: '', longitude: '', planned_arrival: '', planned_departure: '' });

  const routes = useQuery({ queryKey: ['transportation', 'routes'], queryFn: () => transportationApi.routes() });
  const overview = useQuery({ queryKey: ['transportation', 'route-capacity'], queryFn: () => transportationApi.routeCapacityOverview() });
  const stops = useQuery({
    queryKey: ['transportation', 'route-stops', selectedRouteId],
    queryFn: () => transportationApi.routeStops(selectedRouteId as number),
    enabled: selectedRouteId !== null,
  });

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['transportation', 'routes'] });
    queryClient.invalidateQueries({ queryKey: ['transportation', 'route-capacity'] });
  };

  const createRoute = useMutation({
    mutationFn: () => transportationApi.createRoute({
      code: form.code || undefined, name: form.name, route_type: form.route_type,
      origin: form.origin || undefined, destination: form.destination || undefined,
      default_vehicle_capacity: Number(form.default_vehicle_capacity),
    }),
    onSuccess: () => { toast.success('Rute dibuat dengan versi 1'); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Rute gagal dibuat')),
  });

  const addStop = useMutation({
    mutationFn: () => transportationApi.addStop(selectedRouteId as number, {
      name: stopForm.name, stop_type: stopForm.stop_type, address: stopForm.address || undefined,
      latitude: stopForm.latitude ? Number(stopForm.latitude) : undefined,
      longitude: stopForm.longitude ? Number(stopForm.longitude) : undefined,
      planned_arrival: stopForm.planned_arrival || undefined,
      planned_departure: stopForm.planned_departure || undefined,
    }),
    onSuccess: () => { toast.success('Halte ditambahkan'); queryClient.invalidateQueries({ queryKey: ['transportation', 'route-stops'] }); },
    onError: (error: unknown) => toast.error(readError(error, 'Halte gagal ditambahkan')),
  });

  const newVersion = useMutation({
    mutationFn: ({ id, summary }: { id: number; summary: string }) => transportationApi.updateRoute(id, { change_summary: summary }),
    onSuccess: (result) => {
      const version = (result as { version?: { version_number?: number } }).version;
      toast.success(`Versi rute ${version?.version_number ?? ''} dibuat (versi lama tetap tersimpan)`);
      refresh();
    },
  });

  const rows = routes.data ?? [];
  const capacityRows: RouteCapacity[] = overview.data?.routes ?? [];

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Rute & halte</h1>
        <p className="text-sm text-slate-500">Jarak dan durasi dihitung dari koordinat halte; versi rute tersimpan sebagai riwayat.</p>
      </div>

      <div className="flex flex-wrap gap-2">
        {[['routes', 'Rute'], ['stops', 'Halte'], ['versions', 'Versi & kapasitas']].map(([key, label]) => (
          <button key={key} type="button" onClick={() => { params.set('tab', key); setParams(params, { replace: true }); }}
            className={`rounded-lg px-3 py-1.5 text-sm font-medium ${tab === key ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700'}`}>
            {label}
          </button>
        ))}
      </div>

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <Stat label="Rute aktif" value={rows.length} />
        <Stat label="Total kursi" value={overview.data?.total_capacity ?? 0} />
        <Stat label="Terisi" value={overview.data?.total_assigned ?? 0} />
        <Stat label="Daftar tunggu" value={overview.data?.waitlist_total ?? 0} tone={(overview.data?.waitlist_total ?? 0) > 0 ? 'warn' : 'good'} />
      </div>

      <Panel title="Daftar rute" description="Pilih rute untuk mengelola halte & melihat kapasitas">
        <DataTable<Route>
          loading={routes.isLoading}
          rows={rows}
          empty="Belum ada rute."
          columns={[
            { key: 'code', header: 'Rute', render: (row) => (
              <button type="button" className="text-left" onClick={() => { setSelectedRouteId(row.id); params.set('tab', 'stops'); setParams(params, { replace: true }); }}>
                <span className="font-medium text-slate-800">{row.name}</span>
                <span className="block text-xs text-slate-500">{row.code} · {row.route_type} · v{row.version_number}</span>
              </button>
            ) },
            { key: 'path', header: 'Asal → Tujuan', render: (row) => `${row.origin ?? '—'} → ${row.destination ?? '—'}` },
            { key: 'distance', header: 'Jarak / Durasi', render: (row) => `${row.distance_km ?? '—'} km · ${row.estimated_duration_minutes ?? '—'} mnt` },
            { key: 'stops', header: 'Halte', render: (row) => row.stop_count },
            { key: 'status', header: 'Status', render: (row) => <StatusPill status={row.status} /> },
            { key: 'actions', header: '', render: (row) => can('transportation.route.manage') ? (
              <Button size="sm" variant="outline" onClick={() => newVersion.mutate({ id: row.id, summary: 'Penyesuaian rute dari UI' })}>Versi baru</Button>
            ) : '—' },
          ]}
        />
      </Panel>

      {tab === 'stops' ? (
        <Panel
          title={selectedRouteId ? `Halte rute #${selectedRouteId}` : 'Halte'}
          description="Urutan halte menentukan manifes dan rencana waktu trip"
          action={selectedRouteId ? (
            <Button size="sm" variant="ghost" onClick={() => transportationApi.route(selectedRouteId).then((detail) => {
              const constraints = detail.constraints as { valid?: boolean; violations?: Array<{ message: string }> } | undefined;
              toast.info(constraints?.valid ? 'Batasan rute terpenuhi' : `Pelanggaran: ${(constraints?.violations ?? []).map((v) => v.message).join('; ')}`);
            })}>Periksa batasan</Button>
          ) : undefined}
        >
          {selectedRouteId ? (
            <>
              <DataTable<RouteStop>
                loading={stops.isLoading}
                rows={stops.data ?? []}
                empty="Rute ini belum memiliki halte."
                columns={[
                  { key: 'seq', header: '#', render: (row) => row.sequence },
                  { key: 'name', header: 'Halte', render: (row) => (
                    <div>
                      <p className="font-medium text-slate-800">{row.name}</p>
                      <p className="text-xs text-slate-500">{row.stop_type} · {row.address ?? 'tanpa alamat'}</p>
                    </div>
                  ) },
                  { key: 'geo', header: 'Koordinat', render: (row) => row.latitude && row.longitude ? `${row.latitude}, ${row.longitude}` : <span className="text-slate-400">belum ada</span> },
                  { key: 'time', header: 'Rencana', render: (row) => `${row.planned_arrival ?? '—'} / ${row.planned_departure ?? '—'}` },
                  { key: 'capacity', header: 'Kapasitas', render: (row) => row.capacity ?? '—' },
                  { key: 'flags', header: 'Naik / Turun', render: (row) => `${row.pickup_allowed ? 'naik' : '—'} / ${row.dropoff_allowed ? 'turun' : '—'}` },
                ]}
              />
              {can('transportation.route.manage') ? (
                <div className="mt-3 grid gap-2 sm:grid-cols-4 lg:grid-cols-6">
                  <Input placeholder="Nama halte" value={stopForm.name} onChange={(event) => setStopForm({ ...stopForm, name: event.target.value })} />
                  <Select value={stopForm.stop_type} onChange={(event) => setStopForm({ ...stopForm, stop_type: event.target.value })}>
                    {['HOME', 'PICKUP_POINT', 'DROPOFF_POINT', 'SCHOOL', 'CAMPUS', 'DORMITORY', 'TRANSIT', 'SPECIAL'].map((type) => (
                      <option key={type} value={type}>{type}</option>
                    ))}
                  </Select>
                  <Input placeholder="Alamat" value={stopForm.address} onChange={(event) => setStopForm({ ...stopForm, address: event.target.value })} />
                  <Input placeholder="Latitude" value={stopForm.latitude} onChange={(event) => setStopForm({ ...stopForm, latitude: event.target.value })} />
                  <Input placeholder="Longitude" value={stopForm.longitude} onChange={(event) => setStopForm({ ...stopForm, longitude: event.target.value })} />
                  <Input placeholder="Jam rencana (06:05)" value={stopForm.planned_arrival} onChange={(event) => setStopForm({ ...stopForm, planned_arrival: event.target.value })} />
                  <Button onClick={() => addStop.mutate()} loading={addStop.isPending}>Tambah halte</Button>
                </div>
              ) : null}
            </>
          ) : (
            <p className="text-sm text-slate-500">Pilih rute dari daftar untuk melihat dan menambah halte.</p>
          )}
        </Panel>
      ) : null}

      {tab === 'versions' ? (
        <Panel title="Kapasitas & daftar tunggu per rute" description="Okupansi dihitung dari penugasan aktif pada tanggal berjalan">
          <DataTable<RouteCapacity>
            loading={overview.isLoading}
            rows={capacityRows}
            empty="Belum ada rute."
            columns={[
              { key: 'route', header: 'Rute', render: (row) => `${row.route_code} · ${row.route_name}` },
              { key: 'occupancy', header: 'Okupansi', render: (row) => <Progress value={row.assigned} max={row.capacity || 1} /> },
              { key: 'available', header: 'Sisa kursi', render: (row) => row.available_seats },
              { key: 'waitlist', header: 'Daftar tunggu', render: (row) => row.waitlist_count },
              { key: 'state', header: 'Status', render: (row) => row.is_full ? <StatusPill status="WAITING" label="penuh" /> : <StatusPill status="AVAILABLE" label="tersedia" /> },
            ]}
          />
        </Panel>
      ) : null}

      {can('transportation.route.manage') && tab === 'routes' ? (
        <Panel title="Buat rute baru" description="Rute baru otomatis mendapat versi 1 dan dapat diajukan untuk persetujuan">
          <div className="grid gap-2 sm:grid-cols-3 lg:grid-cols-6">
            <Input placeholder="Kode (ROUTE-004)" value={form.code} onChange={(event) => setForm({ ...form, code: event.target.value })} />
            <Input placeholder="Nama rute" value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} />
            <Select value={form.route_type} onChange={(event) => setForm({ ...form, route_type: event.target.value })}>
              {['PICKUP', 'DROPOFF', 'ROUND_TRIP', 'SHUTTLE', 'CAMPUS_TRANSFER', 'EVENT', 'SPECIAL'].map((type) => (
                <option key={type} value={type}>{type}</option>
              ))}
            </Select>
            <Input placeholder="Asal" value={form.origin} onChange={(event) => setForm({ ...form, origin: event.target.value })} />
            <Input placeholder="Tujuan" value={form.destination} onChange={(event) => setForm({ ...form, destination: event.target.value })} />
            <Input placeholder="Kapasitas" value={form.default_vehicle_capacity} onChange={(event) => setForm({ ...form, default_vehicle_capacity: event.target.value })} />
          </div>
          <Button className="mt-3" onClick={() => createRoute.mutate()} loading={createRoute.isPending}>Simpan rute</Button>
        </Panel>
      ) : null}
    </div>
  );
}

function readError(error: unknown, fallback: string): string {
  const message = (error as { response?: { data?: { message?: string } } })?.response?.data?.message;

  return message ?? fallback;
}
