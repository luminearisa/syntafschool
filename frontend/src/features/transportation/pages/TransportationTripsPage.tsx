import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { useAuthStore } from '@/stores/authStore';
import { transportationApi } from '../api/transportationApi';
import { DataTable, DateTime, Panel, Progress, Stat, StatusPill } from '../components/TmsUi';
import type { ManifestPassenger, Trip, TripManifestView } from '../types';

/**
 * Trip: daftar hari ini, kalender operasional, riwayat, dan detail trip
 * (manifes, halte langkah-demi-langkah, boarding/drop-off, penutupan).
 * Ini permukaan kerja dispatcher & pengawas sebelum turun ke lapangan.
 */
export default function TransportationTripsPage() {
  const can = useAuthStore((state) => state.can);
  const [params, setParams] = useSearchParams();
  const tab = params.get('tab') ?? 'today';
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
  const [selectedTripId, setSelectedTripId] = useState<number | null>(null);

  const trips = useQuery({
    queryKey: ['transportation', 'trips', date],
    queryFn: () => transportationApi.trips({ date }),
    enabled: tab !== 'history',
  });
  const history = useQuery({
    queryKey: ['transportation', 'trips', 'history'],
    queryFn: () => transportationApi.tripHistory({ from: date }),
    enabled: tab === 'history',
  });
  const calendar = useQuery({
    queryKey: ['transportation', 'trips', 'calendar', date],
    queryFn: () => transportationApi.tripCalendar({ from: date, to: date }),
    enabled: tab === 'calendar',
  });
  const manifest = useQuery({
    queryKey: ['transportation', 'manifest', selectedTripId],
    queryFn: () => transportationApi.manifest(selectedTripId as number),
    enabled: selectedTripId !== null,
  });

  const setTab = (next: string) => {
    params.set('tab', next);
    setParams(params, { replace: true });
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-2">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Trip & manifes</h1>
          <p className="text-sm text-slate-500">Rencana vs aktual, halte, penumpang, dan penutupan perjalanan.</p>
        </div>
        <div className="flex items-end gap-2">
          <label className="text-xs text-slate-500">
            Tanggal
            <Input type="date" value={date} onChange={(event) => setDate(event.target.value)} className="mt-1" />
          </label>
          <Button variant="outline" onClick={() => transportationApi.generateTrips({ date }).then((result) => {
            toast.success(`${(result.created_count as number) ?? 0} trip dibentuk untuk ${date}`);
            trips.refetch();
          })}>
            Bentuk trip harian
          </Button>
        </div>
      </div>

      <div className="flex flex-wrap gap-2">
        {[
          ['today', 'Trip hari ini'],
          ['calendar', 'Kalender'],
          ['manifest', 'Manifes & boarding'],
          ['history', 'Riwayat'],
        ].map(([key, label]) => (
          <button
            key={key}
            type="button"
            onClick={() => setTab(key)}
            className={`rounded-lg px-3 py-1.5 text-sm font-medium ${tab === key ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700'}`}
          >
            {label}
          </button>
        ))}
      </div>

      {tab !== 'history' && tab !== 'calendar' ? (
        <Panel title="Daftar trip" description={`${trips.data?.length ?? 0} trip pada ${date}`}>
          <DataTable<Trip>
            loading={trips.isLoading}
            rows={trips.data ?? []}
            empty="Belum ada trip. Bentuk trip harian dari jadwal terlebih dahulu."
            columns={[
              { key: 'trip', header: 'Trip', render: (row) => (
                <div>
                  <p className="font-medium text-slate-800">{row.trip_number}</p>
                  <p className="text-xs text-slate-500">{row.route?.code} · {row.direction} · {row.trip_type}</p>
                </div>
              ) },
              { key: 'time', header: 'Rencana', render: (row) => <DateTime value={row.planned.departure} timeOnly /> },
              { key: 'actual', header: 'Aktual', render: (row) => row.actual.start_at ? <DateTime value={row.actual.start_at} timeOnly /> : <span className="text-slate-400">belum jalan</span> },
              { key: 'driver', header: 'Pengemudi', render: (row) => row.driver?.full_name ?? '—' },
              { key: 'vehicle', header: 'Kendaraan', render: (row) => row.vehicle?.vehicle_code ?? '—' },
              { key: 'counts', header: 'Naik', render: (row) => <Progress value={row.counts.boarded} max={row.counts.planned || 1} /> },
              { key: 'status', header: 'Status', render: (row) => <StatusPill status={row.status} /> },
              { key: 'actions', header: '', render: (row) => (
                <div className="flex gap-1">
                  <Button size="sm" variant="outline" onClick={() => { setSelectedTripId(row.id); setTab('manifest'); }}>Manifes</Button>
                  {can('transportation.trip.dispatch') && row.status === 'PLANNED' ? (
                    <Button size="sm" onClick={() => transportationApi.dispatchTrip(row.id, {}).then(() => {
                      toast.success('Trip didispatch');
                      trips.refetch();
                    }).catch(() => toast.error('Dispatch diblokir — periksa checklist & penugasan'))}>Dispatch</Button>
                  ) : null}
                </div>
              ) },
            ]}
          />
        </Panel>
      ) : null}

      {tab === 'calendar' ? (
        <Panel title="Kalender operasional" description="Trip dan pengecualian jadwal (libur, ujian, pulang cepat)">
          {calendar.isLoading ? <p className="text-sm text-slate-500">Memuat…</p> : (
            <div className="space-y-3">
              {((calendar.data?.days as Array<Record<string, unknown>>) ?? []).map((day) => (
                <div key={String(day.date)} className="rounded-lg border border-slate-200 p-3">
                  <p className="text-sm font-medium text-slate-800">{String(day.date)}</p>
                  <p className="text-xs text-slate-500">
                    {String(day.trips)} trip · {String(day.planned_passengers)} penumpang direncanakan
                  </p>
                </div>
              ))}
              {((calendar.data?.exceptions as Array<Record<string, unknown>>) ?? []).length > 0 ? (
                <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                  <p className="font-medium">Pengecualian</p>
                  <ul className="mt-1 space-y-1">
                    {((calendar.data?.exceptions as Array<Record<string, unknown>>) ?? []).map((exception, index) => (
                      <li key={index}>
                        {String(exception.date)} · {String(exception.type)} ({String(exception.source)})
                        {exception.cancel_trips ? ' — trip dibatalkan' : ''}
                      </li>
                    ))}
                  </ul>
                </div>
              ) : null}
            </div>
          )}
        </Panel>
      ) : null}

      {tab === 'manifest' ? (
        selectedTripId ? (
          <ManifestView
            manifest={manifest.data}
            loading={manifest.isLoading}
            tripId={selectedTripId}
            onRefresh={() => manifest.refetch()}
            canOperate={can('transportation.boarding.record')}
          />
        ) : (
          <Panel title="Manifes & boarding" description="Pilih trip dari daftar untuk melihat manifes">
            <p className="text-sm text-slate-500">Buka tab “Trip hari ini”, lalu tekan tombol Manifes pada trip yang dituju.</p>
          </Panel>
        )
      ) : null}

      {tab === 'history' ? (
        <Panel title="Riwayat trip" description="Jarak, keterlambatan, dan jumlah penumpang per trip">
          <DataTable<Record<string, unknown>>
            loading={history.isLoading}
            rows={(history.data as Array<Record<string, unknown>>) ?? []}
            empty="Belum ada riwayat trip."
            columns={[
              { key: 'trip', header: 'Trip', render: (row) => String(row.trip_number) },
              { key: 'date', header: 'Tanggal', render: (row) => String(row.trip_date) },
              { key: 'status', header: 'Status', render: (row) => <StatusPill status={String(row.status)} /> },
              { key: 'distance', header: 'Jarak', render: (row) => row.distance_km ? `${row.distance_km} km` : '—' },
              { key: 'delay', header: 'Delay', render: (row) => `${row.delay_minutes ?? 0} mnt` },
              { key: 'boarded', header: 'Naik', render: (row) => String(row.boarded_count ?? 0) },
            ]}
          />
        </Panel>
      ) : null}

      <div className="text-sm">
        <Link to="/transportation/dispatch" className="text-slate-600 underline">Kembali ke papan dispatch</Link>
      </div>
    </div>
  );
}

function ManifestView({ manifest, loading, tripId, onRefresh, canOperate }: {
  manifest: TripManifestView | undefined;
  loading: boolean;
  tripId: number;
  onRefresh: () => void;
  canOperate: boolean;
}) {
  const queryClient = useQueryClient();
  const [incidentOpen, setIncidentOpen] = useState(false);
  const [incidentType, setIncidentType] = useState('VEHICLE_PROBLEM');
  const [incidentSeverity, setIncidentSeverity] = useState('MEDIUM');
  const [incidentNote, setIncidentNote] = useState('');
  const [odometer, setOdometer] = useState('');

  const afterAction = () => {
    onRefresh();
    queryClient.invalidateQueries({ queryKey: ['transportation', 'trips'] });
  };

  const board = useMutation({
    mutationFn: (passenger: ManifestPassenger) => transportationApi.board(tripId, { trip_passenger_id: passenger.id, method: 'MANUAL', stop_id: passenger.pickup_stop_id ?? undefined }),
    onSuccess: () => { toast.success('Penumpang tercatat naik'); afterAction(); },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal mencatat naik')),
  });

  const dropoff = useMutation({
    mutationFn: (passenger: ManifestPassenger) => transportationApi.dropoff(tripId, { trip_passenger_id: passenger.id, method: 'MANUAL', stop_id: passenger.dropoff_stop_id ?? undefined }),
    onSuccess: () => { toast.success('Penumpang tercatat turun'); afterAction(); },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal mencatat turun')),
  });

  const noShow = useMutation({
    mutationFn: (passenger: ManifestPassenger) => transportationApi.noShow(tripId, { trip_passenger_id: passenger.id, reason: 'Tidak hadir di titik penjemputan' }),
    onSuccess: () => { toast.success('Tidak hadir dicatat & wali diberi tahu'); afterAction(); },
  });

  const complete = useMutation({
    mutationFn: () => transportationApi.completeTrip(tripId, odometer ? { end_odometer: Number(odometer) } : {}),
    onSuccess: (result) => { toast.success(`Trip selesai · ${(result as { distance_km?: number }).distance_km ?? 0} km`); afterAction(); },
    onError: (error: unknown) => toast.error(readError(error, 'Penutupan trip gagal')),
  });

  const reportIncident = useMutation({
    mutationFn: () => transportationApi.reportIncident({
      incident_type: incidentType, severity: incidentSeverity, trip_id: tripId,
      description: incidentNote || 'Insiden dilaporkan dari halaman trip.', passengers_involved: true,
    }),
    onSuccess: () => { toast.success('Insiden dilaporkan'); setIncidentOpen(false); setIncidentNote(''); afterAction(); },
  });

  const summary = manifest?.manifest;
  const passengers = useMemo(() => manifest?.passengers ?? [], [manifest]);

  if (loading || !manifest) {
    return <Panel title="Manifes" description="Memuat manifes…"><p className="text-sm text-slate-500">Memuat…</p></Panel>;
  }

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3 md:grid-cols-5">
        <Stat label="Diharapkan" value={summary?.expected_passengers ?? 0} />
        <Stat label="Naik" value={summary?.boarded_passengers ?? 0} tone="good" />
        <Stat label="Turun" value={summary?.dropped_off_passengers ?? 0} />
        <Stat label="Tidak hadir" value={summary?.no_show_passengers ?? 0} tone={summary?.no_show_passengers ? 'warn' : 'default'} />
        <Stat label="Okupansi" value={`${summary?.occupancy_percent ?? 0}%`} hint={`kapasitas ${summary?.capacity ?? 0}`} />
      </div>

      <Panel
        title={`Manifes ${manifest.trip.trip_number}`}
        description="Aksi naik/turun idempoten: satu peristiwa hanya diterapkan sekali (§88)"
        action={canOperate ? (
          <div className="flex flex-wrap items-end gap-2">
            <Input placeholder="Odometer akhir" value={odometer} onChange={(event) => setOdometer(event.target.value)} className="w-32" />
            <Button size="sm" onClick={() => complete.mutate()}>Tutup trip</Button>
            <Button size="sm" variant="danger" onClick={() => setIncidentOpen(true)}>Laporkan insiden</Button>
          </div>
        ) : undefined}
      >
        <DataTable<ManifestPassenger>
          rows={passengers}
          empty="Manifes kosong — periksa penugasan siswa pada rute ini."
          columns={[
            { key: 'seat', header: 'Kursi', render: (row) => row.seat_number ?? '—' },
            { key: 'student', header: 'Penumpang', render: (row) => (
              <div>
                <p className="font-medium text-slate-800">#{row.student_id ?? row.employee_id ?? '—'}</p>
                <p className="text-xs text-slate-500">{row.passenger_type}{row.special_needs ? ' · kebutuhan khusus' : ''}</p>
                {row.special_instruction ? <p className="text-xs text-amber-700">{row.special_instruction}</p> : null}
              </div>
            ) },
            { key: 'pickup', header: 'Ambil', render: (row) => {
              const stop = manifest.stops.find((item) => item.id === row.pickup_stop_id);
              return stop ? `${stop.sequence}. ${stop.name}` : '—';
            } },
            { key: 'boarding', header: 'Naik', render: (row) => <StatusPill status={row.boarding_status} /> },
            { key: 'dropoff', header: 'Turun', render: (row) => <StatusPill status={row.dropoff_status} /> },
            { key: 'time', header: 'Waktu', render: (row) => <DateTime value={row.boarded_at ?? row.dropped_off_at} timeOnly /> },
            { key: 'actions', header: '', render: (row) => canOperate ? (
              <div className="flex flex-wrap gap-1">
                {row.boarding_status !== 'BOARDED' ? (
                  <Button size="sm" onClick={() => board.mutate(row)}>Naik</Button>
                ) : null}
                {row.boarding_status === 'BOARDED' && row.dropoff_status !== 'DROPPED_OFF' ? (
                  <Button size="sm" variant="outline" onClick={() => dropoff.mutate(row)}>Turun</Button>
                ) : null}
                {row.boarding_status === 'EXPECTED' ? (
                  <Button size="sm" variant="ghost" onClick={() => noShow.mutate(row)}>Tidak hadir</Button>
                ) : null}
              </div>
            ) : '—' },
          ]}
        />
      </Panel>

      <Panel title="Halte trip" description="Rencana vs aktual per halte (§41/§42)">
        <DataTable<TripManifestView['stops'][number]>
          rows={manifest.stops}
          empty="Belum ada halte pada trip ini."
          columns={[
            { key: 'seq', header: '#', render: (row) => row.sequence },
            { key: 'name', header: 'Halte', render: (row) => row.name },
            { key: 'planned', header: 'Rencana', render: (row) => <DateTime value={row.planned_arrival_at} timeOnly /> },
            { key: 'actual', header: 'Aktual', render: (row) => <DateTime value={row.actual_arrival_at} timeOnly /> },
            { key: 'delay', header: 'Delay', render: (row) => row.arrival_delay_minutes ? `${row.arrival_delay_minutes} mnt` : '—' },
            { key: 'passengers', header: 'Penumpang', render: (row) => `${row.boarded_count}/${row.planned_passengers}` },
            { key: 'status', header: 'Status', render: (row) => <StatusPill status={row.status} /> },
          ]}
        />
      </Panel>

      <Modal open={incidentOpen} onClose={() => setIncidentOpen(false)} title="Laporkan insiden">
        <div className="space-y-3">
          <label className="block text-xs text-slate-500">
            Jenis insiden
            <Select value={incidentType} onChange={(event) => setIncidentType(event.target.value)} className="mt-1">
              {['ACCIDENT', 'BREAKDOWN', 'MEDICAL', 'STUDENT_BEHAVIOR', 'MISSING_PASSENGER', 'ROAD_BLOCK', 'SECURITY', 'SAFETY', 'DELAY', 'OTHER'].map((type) => (
                <option key={type} value={type}>{type}</option>
              ))}
            </Select>
          </label>
          <label className="block text-xs text-slate-500">
            Tingkat keparahan
            <Select value={incidentSeverity} onChange={(event) => setIncidentSeverity(event.target.value)} className="mt-1">
              {['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'].map((severity) => (
                <option key={severity} value={severity}>{severity}</option>
              ))}
            </Select>
          </label>
          <label className="block text-xs text-slate-500">
            Keterangan
            <Input value={incidentNote} onChange={(event) => setIncidentNote(event.target.value)} className="mt-1" placeholder="Kronologi singkat kejadian" />
          </label>
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setIncidentOpen(false)}>Batal</Button>
            <Button variant="danger" onClick={() => reportIncident.mutate()}>Kirim laporan</Button>
          </div>
        </div>
      </Modal>
    </div>
  );
}

function readError(error: unknown, fallback: string): string {
  const message = (error as { response?: { data?: { message?: string } } })?.response?.data?.message;

  return message ?? fallback;
}
