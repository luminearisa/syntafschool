import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { transportationApi } from '../api/transportationApi';
import { DataTable, DateTime, Panel, Stat, StatusPill } from '../components/TmsUi';
import type { StudentTodayStatus } from '../types';

/**
 * Portal wali (§73/§99).
 * Wali hanya melihat anaknya sendiri — pembatasan ditegakkan di backend,
 * bukan sekadar disembunyikan di UI.
 */
export default function ParentTransportationPage() {
  const [selectedStudent, setSelectedStudent] = useState<number | null>(null);
  const [requestForm, setRequestForm] = useState({ request_type: 'CHANGE_STOP', route_id: '', pickup_stop_id: '', dropoff_stop_id: '', reason: '' });

  const children = useQuery({ queryKey: ['transportation', 'portal-children'], queryFn: () => transportationApi.portalChildren() });
  const activeStudent = selectedStudent ?? children.data?.[0]?.student_id ?? null;
  const overview = useQuery({
    queryKey: ['transportation', 'portal-child', activeStudent],
    queryFn: () => transportationApi.portalChild(activeStudent as number),
    enabled: activeStudent !== null,
  });

  const submitRequest = useMutation({
    mutationFn: () => transportationApi.portalRequest({
      student_id: activeStudent as number,
      request_type: requestForm.request_type,
      route_id: requestForm.route_id ? Number(requestForm.route_id) : undefined,
      pickup_stop_id: requestForm.pickup_stop_id ? Number(requestForm.pickup_stop_id) : undefined,
      dropoff_stop_id: requestForm.dropoff_stop_id ? Number(requestForm.dropoff_stop_id) : undefined,
      reason: requestForm.reason || undefined,
    }),
    onSuccess: () => { toast.success('Permintaan terkirim ke petugas transportasi'); overview.refetch(); },
    onError: (error: unknown) => toast.error(readError(error, 'Permintaan gagal dikirim')),
  });

  const today: StudentTodayStatus | undefined = overview.data?.today;

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Transportasi anak saya</h1>
        <p className="text-sm text-slate-500">Rute, halte, kendaraan, dan status naik/turun hari ini.</p>
      </div>

      <div className="flex flex-wrap gap-2">
        {(children.data ?? []).map((child) => (
          <button key={child.student_id} type="button" onClick={() => setSelectedStudent(child.student_id)}
            className={`rounded-lg px-3 py-1.5 text-sm font-medium ${activeStudent === child.student_id ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700'}`}>
            Siswa #{child.student_id}
          </button>
        ))}
        {(children.data ?? []).length === 0 ? <p className="text-sm text-slate-500">Tidak ada anak yang terhubung ke akun ini.</p> : null}
      </div>

      {overview.data ? (
        <>
          <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
            <Stat label="Penugasan aktif" value={overview.data.assignments.length} />
            <Stat label="Trip hari ini" value={today?.trips.length ?? 0} />
            <Stat label="Sudah naik" value={today?.trips.filter((row) => row.boarding_status === 'BOARDED').length ?? 0} tone="good" />
            <Stat label="Sudah turun" value={today?.trips.filter((row) => row.dropoff_status === 'DROPPED_OFF').length ?? 0} />
          </div>

          <Panel title="Rute & halte" description="Perubahan rute/halte diajukan lewat formulir di bawah dan harus disetujui sekolah">
            <DataTable<StudentTodayStatus['trips'][number]>
              rows={today?.trips ?? []}
              empty="Belum ada perjalanan hari ini."
              columns={[
                { key: 'trip', header: 'Trip', render: (row) => (
                  <div>
                    <p className="font-medium text-slate-800">{row.trip_number ?? '—'}</p>
                    <p className="text-xs text-slate-500">{row.direction} · {row.status}</p>
                  </div>
                ) },
                { key: 'vehicle', header: 'Kendaraan', render: (row) => row.vehicle?.vehicle_code ?? '—' },
                { key: 'driver', header: 'Pengemudi', render: (row) => row.driver?.full_name ?? '—' },
                { key: 'boarded', header: 'Naik', render: (row) => <><StatusPill status={row.boarding_status} /> <DateTime value={row.boarded_at} timeOnly /></> },
                { key: 'dropoff', header: 'Turun', render: (row) => <><StatusPill status={row.dropoff_status} /> <DateTime value={row.dropped_off_at} timeOnly /></> },
              ]}
            />

            <ul className="mt-4 space-y-2 text-sm">
              {overview.data.assignments.map((assignment) => (
                <li key={assignment.id} className="rounded-lg border border-slate-200 p-3">
                  <p className="font-medium text-slate-800">{assignment.route?.name ?? 'Rute'} ({assignment.route?.code ?? '—'})</p>
                  <p className="text-xs text-slate-600">
                    Jemput: {assignment.pickup_stop?.name ?? '—'} ({assignment.pickup_stop?.planned_arrival ?? '—'})
                    {' '}· Antar: {assignment.dropoff_stop?.name ?? '—'} ({assignment.dropoff_stop?.planned_arrival ?? '—'})
                  </p>
                  <p className="text-xs text-slate-500">Berlaku {assignment.effective_from ?? '—'} s/d {assignment.effective_until ?? 'selesai'}</p>
                </li>
              ))}
            </ul>
          </Panel>

          <Panel title="Ajukan perubahan" description="Perubahan halte, penangguhan sementara, atau layanan khusus">
            <div className="grid gap-2 sm:grid-cols-2">
              <Input placeholder="Jenis (CHANGE_STOP/CHANGE_ROUTE/SUSPEND/RESUME/TEMPORARY)" value={requestForm.request_type} onChange={(event) => setRequestForm({ ...requestForm, request_type: event.target.value })} />
              <Input placeholder="ID rute" value={requestForm.route_id} onChange={(event) => setRequestForm({ ...requestForm, route_id: event.target.value })} />
              <Input placeholder="ID halte jemput" value={requestForm.pickup_stop_id} onChange={(event) => setRequestForm({ ...requestForm, pickup_stop_id: event.target.value })} />
              <Input placeholder="ID halte antar" value={requestForm.dropoff_stop_id} onChange={(event) => setRequestForm({ ...requestForm, dropoff_stop_id: event.target.value })} />
            </div>
            <Input className="mt-2" placeholder="Alasan" value={requestForm.reason} onChange={(event) => setRequestForm({ ...requestForm, reason: event.target.value })} />
            <Button className="mt-3" onClick={() => submitRequest.mutate()} loading={submitRequest.isPending}>Kirim permintaan</Button>
          </Panel>
        </>
      ) : (
        <Panel title="Data transportasi anak" description="Memuat…">
          <p className="text-sm text-slate-500">Belum ada data untuk ditampilkan.</p>
        </Panel>
      )}
    </div>
  );
}

function readError(error: unknown, fallback: string): string {
  const message = (error as { response?: { data?: { message?: string } } })?.response?.data?.message;

  return message ?? fallback;
}
