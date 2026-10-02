import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { transportationApi } from '../api/transportationApi';
import { DateTime, Panel, Stat, StatusPill } from '../components/TmsUi';
import type { TripStopLike } from '../api/transportationApi';

/**
 * Aplikasi pengemudi (§40/§41/§77).
 *
 * Tampilan dioptimalkan untuk ponsel: trip hari ini, trip aktif, halte
 * langkah-demi-langkah, checklist pra-trip, boarding/tidak hadir/turun,
 * laporan insiden, dan manifes offline.
 */
export default function DriverAppPage() {
  const queryClient = useQueryClient();
  const [odometer, setOdometer] = useState('');
  const [incidentNote, setIncidentNote] = useState('');
  const [token, setToken] = useState('');

  const today = useQuery({ queryKey: ['transportation', 'driver-today'], queryFn: () => transportationApi.driverToday({}) });
  const current = useQuery({ queryKey: ['transportation', 'driver-current'], queryFn: () => transportationApi.driverCurrentTrip() });
  const template = useQuery({ queryKey: ['transportation', 'inspection-template'], queryFn: () => transportationApi.inspectionTemplate() });

  const trip = current.data;
  const tripId = trip?.id ?? (today.data?.current_trip?.id ?? null);
  const stops: TripStopLike[] = (current.data?.stops as TripStopLike[] | undefined) ?? [];
  const passengers = current.data?.manifest.passengers ?? [];

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['transportation', 'driver-current'] });
    queryClient.invalidateQueries({ queryKey: ['transportation', 'driver-today'] });
  };

  const start = useMutation({
    mutationFn: () => transportationApi.startTrip(tripId as number, odometer ? { start_odometer: Number(odometer) } : {}),
    onSuccess: () => { toast.success('Perjalanan dimulai'); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Trip belum dapat dimulai')),
  });

  const arrive = useMutation({
    mutationFn: (stopId: number) => transportationApi.arriveStop(tripId as number, { stop_id: stopId }),
    onSuccess: () => { toast.success('Kedatangan halte tercatat'); refresh(); },
  });

  const depart = useMutation({
    mutationFn: (stopId: number) => transportationApi.departStop(tripId as number, { stop_id: stopId }),
    onSuccess: () => { toast.success('Perjalanan dilanjutkan'); refresh(); },
  });

  const board = useMutation({
    mutationFn: (passengerId: number) => transportationApi.board(tripId as number, { trip_passenger_id: passengerId, method: 'MANUAL' }),
    onSuccess: () => { toast.success('Penumpang naik — wali diberi tahu'); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Boarding gagal')),
  });

  const noShow = useMutation({
    mutationFn: (passengerId: number) => transportationApi.noShow(tripId as number, { trip_passenger_id: passengerId, reason: 'Tidak hadir di titik penjemputan' }),
    onSuccess: () => { toast.success('Tidak hadir dicatat'); refresh(); },
  });

  const dropoff = useMutation({
    mutationFn: (passengerId: number) => transportationApi.dropoff(tripId as number, { trip_passenger_id: passengerId, method: 'MANUAL' }),
    onSuccess: () => { toast.success('Penumpang turun tercatat'); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Drop-off gagal')),
  });

  const preTrip = useMutation({
    mutationFn: () => transportationApi.preTrip({
      vehicle_id: trip?.vehicle?.id as number,
      trip_id: tripId as number,
      odometer: odometer ? Number(odometer) : undefined,
      items: (template.data?.pre_trip ?? []).map((item) => ({ code: item.code, result: 'OK' })),
    }),
    onSuccess: () => { toast.success('Checklist pra-trip tersimpan'); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Checklist gagal disimpan')),
  });

  const complete = useMutation({
    mutationFn: () => transportationApi.completeTrip(tripId as number, odometer ? { end_odometer: Number(odometer) } : {}),
    onSuccess: () => { toast.success('Trip ditutup'); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Penutupan trip gagal')),
  });

  const report = useMutation({
    mutationFn: () => transportationApi.reportIncident({
      incident_type: 'BREAKDOWN', severity: 'HIGH', trip_id: tripId as number,
      description: incidentNote || 'Kendala dilaporkan pengemudi.', passengers_involved: true,
    }),
    onSuccess: () => { toast.success('Insiden dikirim — dispatcher diberi tahu'); setIncidentNote(''); refresh(); },
  });

  const scan = useMutation({
    mutationFn: () => transportationApi.board(tripId as number, { scanned_token: token, method: 'QR' }),
    onSuccess: () => { toast.success('Token diproses'); setToken(''); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Token tidak dikenali')),
  });

  const offline = useMutation({
    mutationFn: () => transportationApi.offlineManifest(tripId as number),
    onSuccess: (payload) => {
      localStorage.setItem(`tms-offline-manifest-${tripId}`, JSON.stringify(payload));
      toast.success('Manifes tersimpan untuk mode offline');
    },
  });

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Aplikasi pengemudi</h1>
        <p className="text-sm text-slate-500">
          {today.data?.driver ? `${today.data.driver.full_name ?? today.data.driver.driver_code} · ${today.data.driver.driver_status}` : 'Profil pengemudi belum terhubung ke akun ini'}
        </p>
      </div>

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <Stat label="Trip hari ini" value={today.data?.trips.length ?? 0} />
        <Stat label="Trip aktif" value={trip ? trip.trip_number : '—'} />
        <Stat label="Penumpang naik" value={trip?.counts.boarded ?? 0} tone="good" />
        <Stat label="Belum naik" value={(trip?.counts.planned ?? 0) - (trip?.counts.boarded ?? 0)} tone="warn" />
      </div>

      <Panel title="Trip saya hari ini" description="Urut waktu keberangkatan">
        <ul className="space-y-2">
          {(today.data?.trips ?? []).map((row) => (
            <li key={row.id} className="flex items-center justify-between rounded-lg border border-slate-200 p-3">
              <div>
                <p className="text-sm font-medium text-slate-800">{row.planned_departure} · {row.route ?? '—'}</p>
                <p className="text-xs text-slate-500">{row.trip_number} · {row.vehicle ?? 'kendaraan belum ditetapkan'} · {row.passengers} penumpang</p>
              </div>
              <StatusPill status={row.status} />
            </li>
          ))}
          {(today.data?.trips ?? []).length === 0 ? <li className="text-sm text-slate-500">Belum ada penugasan hari ini.</li> : null}
        </ul>
      </Panel>

      {trip ? (
        <>
          <Panel title={`Trip aktif ${trip.trip_number}`} description={`${trip.route?.name ?? '—'} · ${trip.vehicle?.vehicle_code ?? 'kendaraan belum ditetapkan'}`}
            action={<div className="flex flex-wrap gap-2">
              <Input placeholder="Odometer" value={odometer} onChange={(event) => setOdometer(event.target.value)} className="w-28" />
              {trip.status === 'DISPATCHED' || trip.status === 'PLANNED' ? (
                <>
                  <Button size="sm" onClick={() => preTrip.mutate()} loading={preTrip.isPending}>Checklist pra-trip</Button>
                  <Button size="sm" onClick={() => start.mutate()} loading={start.isPending}>Mulai trip</Button>
                </>
              ) : null}
              {['IN_TRANSIT', 'ARRIVED', 'BOARDING', 'DELAYED'].includes(trip.status) ? (
                <Button size="sm" variant="outline" onClick={() => complete.mutate()} loading={complete.isPending}>Tutup trip</Button>
              ) : null}
            </div>}
          >
            <div className="space-y-3">
              {stops.map((stop) => (
                <div key={stop.id} className="rounded-lg border border-slate-200 p-3">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                      <p className="text-sm font-medium text-slate-800">
                        Halte {stop.sequence} · {stop.name}
                      </p>
                      <p className="text-xs text-slate-500">
                        Rencana <DateTime value={stop.planned_arrival_at} timeOnly /> · aktual <DateTime value={stop.actual_arrival_at} timeOnly />
                        {' '}· {stop.planned_passengers} penumpang · naik {stop.boarded_count} · tidak hadir {stop.no_show_count}
                      </p>
                    </div>
                    <div className="flex flex-wrap gap-1">
                      <StatusPill status={stop.status} />
                      <Button size="sm" variant="outline" onClick={() => arrive.mutate(stop.id)}>Tiba</Button>
                      <Button size="sm" variant="ghost" onClick={() => depart.mutate(stop.id)}>Berangkat</Button>
                    </div>
                  </div>

                  <ul className="mt-2 space-y-1">
                    {passengers.filter((passenger) => passenger.pickup_stop_id === stop.id || passenger.dropoff_stop_id === stop.id).map((passenger) => (
                      <li key={passenger.id} className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-2 text-sm">
                        <span>
                          Kursi {passenger.seat_number ?? '—'} · siswa #{passenger.student_id ?? '—'}
                          {passenger.special_needs ? <span className="ml-1 text-xs text-amber-700">· kebutuhan khusus</span> : null}
                        </span>
                        <span className="flex flex-wrap items-center gap-1">
                          <StatusPill status={passenger.boarding_status} />
                          {passenger.boarding_status !== 'BOARDED' ? (
                            <>
                              <Button size="sm" onClick={() => board.mutate(passenger.id)}>Naik</Button>
                              <Button size="sm" variant="ghost" onClick={() => noShow.mutate(passenger.id)}>Tidak hadir</Button>
                            </>
                          ) : passenger.dropoff_status !== 'DROPPED_OFF' ? (
                            <Button size="sm" variant="outline" onClick={() => dropoff.mutate(passenger.id)}>Turun</Button>
                          ) : null}
                        </span>
                      </li>
                    ))}
                  </ul>
                </div>
              ))}
            </div>
          </Panel>

          <div className="grid gap-4 md:grid-cols-2">
            <Panel title="Pindai QR / kartu" description="Alternatif ketika perangkat RFID/NFC tersedia (§37/§38)">
              <Input placeholder="Token QR atau kartu" value={token} onChange={(event) => setToken(event.target.value)} />
              <Button className="mt-2" onClick={() => scan.mutate()} loading={scan.isPending}>Proses pemindaian</Button>
            </Panel>

            <Panel title="Mode offline & darurat" description="Manifes disimpan di perangkat agar tetap dapat dibuka tanpa jaringan (§89)">
              <div className="flex flex-wrap gap-2">
                <Button variant="outline" onClick={() => offline.mutate()} loading={offline.isPending}>Simpan manifes offline</Button>
              </div>
              <Input className="mt-3" placeholder="Catatan kendala" value={incidentNote} onChange={(event) => setIncidentNote(event.target.value)} />
              <Button className="mt-2" variant="danger" onClick={() => report.mutate()} loading={report.isPending}>Laporkan insiden</Button>
            </Panel>
          </div>
        </>
      ) : (
        <Panel title="Trip aktif" description="Belum ada perjalanan yang sedang berjalan">
          <p className="text-sm text-slate-500">Aksi tersedia setelah dispatcher mengirim trip (status DISPATCHED).</p>
        </Panel>
      )}
    </div>
  );
}

function readError(error: unknown, fallback: string): string {
  const message = (error as { response?: { data?: { message?: string } } })?.response?.data?.message;

  return message ?? fallback;
}
