import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useSearchParams } from 'react-router-dom';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { useAuthStore } from '@/stores/authStore';
import { transportationApi } from '../api/transportationApi';
import { DataTable, Panel, Stat, StatusPill } from '../components/TmsUi';
import type { AssignmentRow, TransportationRequestRow, WaitlistRow } from '../types';

/**
 * Siswa: permintaan, penugasan, dan daftar tunggu (§25-§28, §98).
 * Sistem menampilkan kapasitas rute, sisa kursi, dan konflik penugasan
 * sebelum admin menyimpan.
 */
export default function TransportationStudentsPage() {
  const can = useAuthStore((state) => state.can);
  const [params, setParams] = useSearchParams();
  const tab = params.get('tab') ?? 'assignments';
  const queryClient = useQueryClient();
  const [requestForm, setRequestForm] = useState({ student_id: '', request_type: 'NEW', route_id: '', pickup_stop_id: '', dropoff_stop_id: '', reason: '' });

  const assignments = useQuery({ queryKey: ['transportation', 'assignments'], queryFn: () => transportationApi.assignments({}) });
  const requests = useQuery({ queryKey: ['transportation', 'requests'], queryFn: () => transportationApi.requests({}) });
  const waitlist = useQuery({ queryKey: ['transportation', 'waitlist'], queryFn: () => transportationApi.waitlist({}) });
  const routes = useQuery({ queryKey: ['transportation', 'routes'], queryFn: () => transportationApi.routes() });

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['transportation', 'assignments'] });
    queryClient.invalidateQueries({ queryKey: ['transportation', 'requests'] });
    queryClient.invalidateQueries({ queryKey: ['transportation', 'waitlist'] });
  };

  const submitRequest = useMutation({
    mutationFn: () => transportationApi.submitRequest({
      request_type: requestForm.request_type,
      student_id: Number(requestForm.student_id),
      route_id: requestForm.route_id ? Number(requestForm.route_id) : undefined,
      pickup_stop_id: requestForm.pickup_stop_id ? Number(requestForm.pickup_stop_id) : undefined,
      dropoff_stop_id: requestForm.dropoff_stop_id ? Number(requestForm.dropoff_stop_id) : undefined,
      reason: requestForm.reason || undefined,
    }),
    onSuccess: (result) => {
      const status = (result as { status?: string }).status;
      toast.success(status === 'ON_WAITLIST' ? 'Rute penuh — permintaan masuk daftar tunggu' : 'Permintaan tervalidasi dan menunggu persetujuan');
      refresh();
    },
    onError: (error: unknown) => toast.error(readError(error, 'Permintaan ditolak validasi')),
  });

  const approve = useMutation({
    mutationFn: (id: number) => transportationApi.approveRequest(id, {}),
    onSuccess: () => { toast.success('Permintaan disetujui & penugasan dibuat'); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Persetujuan gagal')),
  });

  const suspend = useMutation({
    mutationFn: (id: number) => transportationApi.suspendAssignment(id, { reason: 'Ditangguhkan dari halaman penugasan' }),
    onSuccess: () => { toast.success('Penugasan ditangguhkan'); refresh(); },
  });

  const evaluateWaitlist = useMutation({
    mutationFn: () => transportationApi.evaluateWaitlist({}),
    onSuccess: (result) => { toast.success(`${(result as { offer_count?: number }).offer_count ?? 0} kandidat ditawari kursi`); refresh(); },
  });

  const acceptOffer = useMutation({
    mutationFn: (id: number) => transportationApi.acceptOffer(id),
    onSuccess: () => { toast.success('Tawaran kursi diterima — penugasan dibuat'); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Tawaran gagal diterima')),
  });

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Transportasi siswa</h1>
        <p className="text-sm text-slate-500">Permintaan wali melewati validasi, pemeriksaan kapasitas, lalu persetujuan.</p>
      </div>

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <Stat label="Penugasan aktif" value={(assignments.data ?? []).filter((row) => row.status === 'ACTIVE').length} />
        <Stat label="Permintaan menunggu" value={(requests.data ?? []).filter((row) => ['SUBMITTED', 'VALIDATED'].includes(row.status)).length} tone="warn" />
        <Stat label="Daftar tunggu" value={(waitlist.data ?? []).filter((row) => row.status === 'WAITING').length} tone="warn" />
        <Stat label="Penawaran kursi" value={(waitlist.data ?? []).filter((row) => row.status === 'OFFERED').length} />
      </div>

      <div className="flex flex-wrap gap-2">
        {[['assignments', 'Penugasan'], ['requests', 'Permintaan'], ['waitlist', 'Daftar tunggu']].map(([key, label]) => (
          <button key={key} type="button" onClick={() => { params.set('tab', key); setParams(params, { replace: true }); }}
            className={`rounded-lg px-3 py-1.5 text-sm font-medium ${tab === key ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700'}`}>
            {label}
          </button>
        ))}
      </div>

      {tab === 'assignments' ? (
        <Panel title="Penugasan siswa" description="Satu siswa tidak dapat memiliki dua penugasan aktif pada periode sama kecuali multi-layanan dibuka">
          <DataTable<AssignmentRow>
            loading={assignments.isLoading}
            rows={assignments.data ?? []}
            empty="Belum ada penugasan."
            columns={[
              { key: 'code', header: 'Penugasan', render: (row) => (
                <div>
                  <p className="font-medium text-slate-800">{row.assignment_code}</p>
                  <p className="text-xs text-slate-500">siswa #{row.student_id} · {row.transport_mode}</p>
                </div>
              ) },
              { key: 'route', header: 'Rute', render: (row) => row.route ? `${row.route.code} · ${row.route.name}` : '—' },
              { key: 'stops', header: 'Halte ambil → antar', render: (row) => `${row.pickup_stop?.name ?? '—'} → ${row.dropoff_stop?.name ?? '—'}` },
              { key: 'period', header: 'Periode', render: (row) => `${row.effective_from ?? '—'} s/d ${row.effective_until ?? 'selesai'}` },
              { key: 'status', header: 'Status', render: (row) => <StatusPill status={row.status} /> },
              { key: 'actions', header: '', render: (row) => can('transportation.student.manage') && row.status === 'ACTIVE' ? (
                <div className="flex gap-1">
                  <Button size="sm" variant="outline" onClick={() => suspend.mutate(row.id)}>Tangguhkan</Button>
                  <Button size="sm" variant="ghost" onClick={() => transportationApi.resumeAssignment(row.id).then(() => { toast.success('Diaktifkan kembali'); refresh(); })}>Aktifkan</Button>
                </div>
              ) : '—' },
            ]}
          />
        </Panel>
      ) : null}

      {tab === 'requests' ? (
        <>
          <Panel title="Permintaan transportasi" description="Validasi: siswa aktif, rute aktif, halte sesuai rute, kapasitas tersedia">
            <DataTable<TransportationRequestRow>
              loading={requests.isLoading}
              rows={requests.data ?? []}
              empty="Belum ada permintaan."
              columns={[
                { key: 'number', header: 'Permintaan', render: (row) => (
                  <div>
                    <p className="font-medium text-slate-800">{row.request_number}</p>
                    <p className="text-xs text-slate-500">{row.request_type} · siswa #{row.student_id ?? '—'} · prioritas {row.priority_level}</p>
                  </div>
                ) },
                { key: 'capacity', header: 'Kapasitas', render: (row) => row.capacity_check
                  ? `${row.capacity_check.assigned ?? 0}/${row.capacity_check.capacity ?? 0} (sisa ${row.capacity_check.available_seats ?? 0})`
                  : '—' },
                { key: 'status', header: 'Status', render: (row) => <StatusPill status={row.status} /> },
                { key: 'workflow', header: 'Alur kerja', render: (row) => row.workflow_reference ?? 'internal' },
                { key: 'actions', header: '', render: (row) => can('transportation.request.approve') && ['SUBMITTED', 'VALIDATED'].includes(row.status) ? (
                  <div className="flex gap-1">
                    <Button size="sm" onClick={() => approve.mutate(row.id)}>Setujui</Button>
                    <Button size="sm" variant="danger" onClick={() => transportationApi.rejectRequest(row.id, { reason: 'Tidak memenuhi syarat rute' }).then(() => { toast.success('Permintaan ditolak'); refresh(); })}>Tolak</Button>
                  </div>
                ) : '—' },
              ]}
            />
          </Panel>

          {can('transportation.request.create') ? (
            <Panel title="Ajukan permintaan" description="Wali hanya dapat mengajukan untuk anaknya sendiri (ditegakkan backend)">
              <div className="grid gap-2 sm:grid-cols-3 lg:grid-cols-6">
                <Input placeholder="ID siswa" value={requestForm.student_id} onChange={(event) => setRequestForm({ ...requestForm, student_id: event.target.value })} />
                <Input placeholder="Jenis (NEW/CHANGE_ROUTE/SUSPEND)" value={requestForm.request_type} onChange={(event) => setRequestForm({ ...requestForm, request_type: event.target.value })} />
                <Input placeholder="ID rute" value={requestForm.route_id} onChange={(event) => setRequestForm({ ...requestForm, route_id: event.target.value })} />
                <Input placeholder="ID halte ambil" value={requestForm.pickup_stop_id} onChange={(event) => setRequestForm({ ...requestForm, pickup_stop_id: event.target.value })} />
                <Input placeholder="ID halte antar" value={requestForm.dropoff_stop_id} onChange={(event) => setRequestForm({ ...requestForm, dropoff_stop_id: event.target.value })} />
                <Input placeholder="Alasan" value={requestForm.reason} onChange={(event) => setRequestForm({ ...requestForm, reason: event.target.value })} />
              </div>
              <Button className="mt-3" onClick={() => submitRequest.mutate()} loading={submitRequest.isPending}>Kirim permintaan</Button>
              <p className="mt-2 text-xs text-slate-500">
                Rute tersedia: {(routes.data ?? []).map((route) => `${route.code} (${route.name})`).join(' · ') || 'belum ada rute'}
              </p>
            </Panel>
          ) : null}
        </>
      ) : null}

      {tab === 'waitlist' ? (
        <Panel
          title="Daftar tunggu"
          description="Skor prioritas mempertimbangkan kebutuhan khusus, status mondok, saudara, tingkat kelas, dan lama menunggu"
          action={can('transportation.waitlist.manage') ? (
            <Button size="sm" onClick={() => evaluateWaitlist.mutate()} loading={evaluateWaitlist.isPending}>Evaluasi kursi tersedia</Button>
          ) : undefined}
        >
          <DataTable<WaitlistRow>
            loading={waitlist.isLoading}
            rows={waitlist.data ?? []}
            empty="Tidak ada siswa dalam daftar tunggu."
            columns={[
              { key: 'student', header: 'Siswa', render: (row) => `#${row.student_id}` },
              { key: 'route', header: 'Rute', render: (row) => `#${row.route_id ?? '—'}` },
              { key: 'score', header: 'Skor prioritas', render: (row) => `${row.priority_score} (${row.priority_class})` },
              { key: 'flags', header: 'Penanda', render: (row) => [row.special_need ? 'kebutuhan khusus' : null, row.boarding_status ? 'mondok' : null].filter(Boolean).join(', ') || '—' },
              { key: 'status', header: 'Status', render: (row) => <StatusPill status={row.status} /> },
              { key: 'offer', header: 'Tawaran', render: (row) => row.offer_expires_at ?? '—' },
              { key: 'actions', header: '', render: (row) => can('transportation.waitlist.manage') && ['OFFERED', 'WAITING'].includes(row.status) ? (
                <Button size="sm" onClick={() => acceptOffer.mutate(row.id)}>Terima kursi</Button>
              ) : '—' },
            ]}
          />
        </Panel>
      ) : null}
    </div>
  );
}

function readError(error: unknown, fallback: string): string {
  const message = (error as { response?: { data?: { message?: string } } })?.response?.data?.message;

  return message ?? fallback;
}
