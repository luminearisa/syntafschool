import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { useAuthStore } from '@/stores/authStore';
import { transportationApi } from '../api/transportationApi';
import { DataTable, DateTime, Panel, Stat, StatusPill } from '../components/TmsUi';
import type { DispatchBoard } from '../types';

/**
 * Papan dispatch (§96).
 *
 * Dispatcher melihat seluruh trip hari ini beserta kendaraan, pengemudi, dan
 * statusnya; aksi yang tersedia: validasi & kirim (dispatch), ganti kendaraan,
 * lapor keterlambatan, dan batalkan — semuanya melewati validasi backend
 * (dokumen, pemeliharaan, SIM, kualifikasi, checklist pra-trip).
 */
export default function TransportationDispatchBoardPage() {
  const can = useAuthStore((state) => state.can);
  const queryClient = useQueryClient();
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
  const [overrideReason, setOverrideReason] = useState('');
  const [delayMinutes, setDelayMinutes] = useState(10);
  const [delayReason, setDelayReason] = useState('TRAFFIC');
  const [selectedTrip, setSelectedTrip] = useState<number | null>(null);

  const board = useQuery({
    queryKey: ['transportation', 'dispatch-board', date],
    queryFn: () => transportationApi.dispatchBoard({ date }),
    refetchInterval: 45_000,
  });

  const vehicles = useQuery({ queryKey: ['transportation', 'vehicles'], queryFn: () => transportationApi.vehicles() });
  const drivers = useQuery({ queryKey: ['transportation', 'drivers'], queryFn: () => transportationApi.drivers() });

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['transportation', 'dispatch-board'] });
    queryClient.invalidateQueries({ queryKey: ['transportation', 'dashboard'] });
  };

  const dispatch = useMutation({
    mutationFn: ({ id, override }: { id: number; override: boolean }) =>
      transportationApi.dispatchTrip(id, override ? { allow_override: true, reason: overrideReason || 'Override dispatcher' } : {}),
    onSuccess: (result) => {
      toast.success(result.dispatched ? 'Trip dikirim ke jalan' : 'Dispatch diblokir validasi');
      refresh();
    },
    onError: () => toast.error('Dispatch gagal — periksa validasi trip'),
  });

  const assignVehicle = useMutation({
    mutationFn: ({ tripId, vehicleId }: { tripId: number; vehicleId: number }) =>
      transportationApi.assignVehicle(tripId, { vehicle_id: vehicleId, reason: 'Penugasan dari papan dispatch' }),
    onSuccess: () => { toast.success('Kendaraan ditugaskan'); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Kendaraan tidak memenuhi syarat')),
  });

  const assignDriver = useMutation({
    mutationFn: ({ tripId, driverId }: { tripId: number; driverId: number }) =>
      transportationApi.assignDriverToTrip(tripId, { driver_profile_id: driverId, role: 'PRIMARY' }),
    onSuccess: () => { toast.success('Pengemudi ditugaskan'); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Pengemudi tidak memenuhi syarat')),
  });

  const delay = useMutation({
    mutationFn: ({ tripId }: { tripId: number }) =>
      transportationApi.reportDelay(tripId, { minutes: Number(delayMinutes), reason: delayReason }),
    onSuccess: () => { toast.success('Keterlambatan dilaporkan & wali diberi tahu'); refresh(); },
  });

  const cancel = useMutation({
    mutationFn: ({ tripId }: { tripId: number }) =>
      transportationApi.cancelTrip(tripId, { reason: 'Dibatalkan dari papan dispatch' }),
    onSuccess: () => { toast.success('Trip dibatalkan'); refresh(); },
  });

  const data: DispatchBoard | undefined = board.data;
  const selected = data?.rows.find((row) => row.trip_id === selectedTrip) ?? null;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-2">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Transportation Control Center — hari ini</h1>
          <p className="text-sm text-slate-500">Pantau, atur ulang, dan kirim trip. Setiap blokir validasi tampil apa adanya.</p>
        </div>
        <div className="flex items-end gap-2">
          <label className="text-xs text-slate-500">
            Tanggal
            <Input type="date" value={date} onChange={(event) => setDate(event.target.value)} className="mt-1" />
          </label>
          <Button variant="outline" onClick={() => refresh()}>Segarkan</Button>
        </div>
      </div>

      {data ? (
        <div className="grid grid-cols-2 gap-3 md:grid-cols-4 lg:grid-cols-6">
          <Stat label="Trip" value={data.summary.trips} hint={`${data.summary.on_time} tepat waktu`} />
          <Stat label="Terlambat" value={data.summary.delayed} tone={data.summary.delayed ? 'warn' : 'good'} />
          <Stat label="Insiden" value={data.summary.incidents} tone={data.summary.incidents ? 'bad' : 'good'} />
          <Stat label="Diharapkan" value={data.summary.expected_passengers} hint="penumpang" />
          <Stat label="Naik" value={data.summary.boarded} tone="good" />
          <Stat label="Belum naik" value={data.summary.pending} tone={data.summary.pending ? 'warn' : 'good'} hint={`${data.summary.no_show} tidak hadir`} />
        </div>
      ) : null}

      <Panel title="Papan operasi" description="Klik baris untuk memilih trip, lalu jalankan aksi di panel kanan.">
        <DataTable<DispatchBoard['rows'][number]>
          loading={board.isLoading}
          rows={data?.rows ?? []}
          empty="Belum ada trip pada tanggal ini."
          columns={[
            { key: 'time', header: 'Berangkat', render: (row) => <DateTime value={row.departure} timeOnly /> },
            { key: 'route', header: 'Rute', render: (row) => (
              <button type="button" className="text-left" onClick={() => setSelectedTrip(row.trip_id)}>
                <span className="font-medium text-slate-800">{row.route}</span>
                <span className="block text-xs text-slate-500">{row.trip_number} · {row.route_code}</span>
              </button>
            ) },
            { key: 'driver', header: 'Pengemudi', render: (row) => row.driver },
            { key: 'vehicle', header: 'Kendaraan', render: (row) => row.vehicle },
            { key: 'passengers', header: 'Penumpang', render: (row) => `${row.passengers.boarded}/${row.passengers.planned}` },
            { key: 'status', header: 'Status', render: (row) => <StatusPill status={row.status} label={row.status_label} /> },
            { key: 'actions', header: '', render: (row) => (
              <div className="flex flex-wrap gap-1">
                {can('transportation.trip.dispatch') ? (
                  <Button size="sm" variant="outline" onClick={() => dispatch.mutate({ id: row.trip_id, override: false })}>Dispatch</Button>
                ) : null}
                <Button size="sm" variant="ghost" onClick={() => setSelectedTrip(row.trip_id)}>Atur</Button>
              </div>
            ) },
          ]}
        />
      </Panel>

      {selected ? (
        <div className="grid gap-4 lg:grid-cols-2">
          <Panel title={`Atur ulang ${selected.trip_number}`} description="Penugasan kendaraan & pengemudi dengan validasi otomatis">
            <div className="space-y-3">
              <div>
                <p className="mb-1 text-xs font-medium text-slate-500">Kendaraan tersedia</p>
                <Select
                  defaultValue=""
                  onChange={(event) => event.target.value && assignVehicle.mutate({ tripId: selected.trip_id, vehicleId: Number(event.target.value) })}
                >
                  <option value="">Pilih kendaraan…</option>
                  {(vehicles.data ?? []).map((vehicle) => (
                    <option key={vehicle.id} value={vehicle.id}>
                      {vehicle.vehicle_code} · {vehicle.status} · {vehicle.capacity.usable_capacity} kursi
                    </option>
                  ))}
                </Select>
              </div>
              <div>
                <p className="mb-1 text-xs font-medium text-slate-500">Pengemudi</p>
                <Select
                  defaultValue=""
                  onChange={(event) => event.target.value && assignDriver.mutate({ tripId: selected.trip_id, driverId: Number(event.target.value) })}
                >
                  <option value="">Pilih pengemudi…</option>
                  {(drivers.data ?? []).map((driver) => (
                    <option key={driver.id} value={driver.id}>
                      {driver.driver_code} · {driver.driver_status} · {driver.trips_today} trip hari ini
                    </option>
                  ))}
                </Select>
              </div>
              <div className="grid gap-2 sm:grid-cols-2">
                <label className="text-xs text-slate-500">
                  Alasan override (bila memaksa dispatch)
                  <Input value={overrideReason} onChange={(event) => setOverrideReason(event.target.value)} placeholder="mis. bus cadangan disetujui manajer" className="mt-1" />
                </label>
                <div className="flex items-end gap-2">
                  <Button variant="danger" onClick={() => dispatch.mutate({ id: selected.trip_id, override: true })}>Paksa dispatch</Button>
                </div>
              </div>
              <div className="flex flex-wrap gap-2 border-t border-slate-200 pt-3">
                <Button variant="outline" onClick={() => transportationApi.dispatchValidation(selected.trip_id).then((result) => {
                  toast.info(result.dispatchable ? 'Trip siap diberangkatkan' : `Blokir: ${result.blockers.map((b) => b.message).join('; ')}`);
                })}>
                  Periksa validasi
                </Button>
                <Button variant="ghost" onClick={() => cancel.mutate({ tripId: selected.trip_id })}>Batalkan trip</Button>
              </div>
            </div>
          </Panel>

          <Panel title="Laporkan keterlambatan" description="Notifikasi wali terkirim otomatis (§44)">
            <div className="grid gap-3 sm:grid-cols-2">
              <label className="text-xs text-slate-500">
                Menit
                <Input type="number" min={1} value={delayMinutes} onChange={(event) => setDelayMinutes(Number(event.target.value))} className="mt-1" />
              </label>
              <label className="text-xs text-slate-500">
                Alasan
                <Select value={delayReason} onChange={(event) => setDelayReason(event.target.value)} className="mt-1">
                  {['TRAFFIC', 'VEHICLE_PROBLEM', 'DRIVER_ISSUE', 'WEATHER', 'STUDENT_DELAY', 'ROAD_CLOSURE', 'ACCIDENT', 'OPERATIONAL', 'OTHER'].map((reason) => (
                    <option key={reason} value={reason}>{reason}</option>
                  ))}
                </Select>
              </label>
            </div>
            <Button className="mt-3" onClick={() => delay.mutate({ tripId: selected.trip_id })}>Kirim laporan keterlambatan</Button>
          </Panel>
        </div>
      ) : null}
    </div>
  );
}

function readError(error: unknown, fallback: string): string {
  const message = (error as { response?: { data?: { message?: string } } })?.response?.data?.message;

  return message ?? fallback;
}
